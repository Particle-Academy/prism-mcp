<?php

declare(strict_types=1);

namespace Prism\Mcp\Client;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Prism\Mcp\Exceptions\MirroredParameterRefused;
use Prism\Mcp\Exceptions\ProtocolFailure;
use Prism\Mcp\Support\MirroredParameters;
use Prism\Mcp\Support\ToolDefinition;

/**
 * One server, spoken to.
 *
 * Everything here is scoped to a single MCP server and holds no opinion about
 * Prism. The Prism-facing half lives in `Tools\RemoteTool`, so that the thing
 * which knows the protocol and the thing which knows the model are separable
 * and separately testable.
 */
class Client
{
    /** @var list<ToolDefinition>|null */
    protected ?array $memoised = null;

    public function __construct(
        protected readonly ServerConfig $server,
        protected readonly Protocol $protocol,
        protected readonly CacheRepository $cache,
    ) {}

    public function server(): ServerConfig
    {
        return $this->server;
    }

    /**
     * The server's tool list.
     *
     * Cached, and this is a correctness matter rather than a nicety: without it,
     * every generation pays a network round trip before the model sees a single
     * token, and a slow server becomes a slow application. Relay understood this
     * and `laravel/mcp`'s client — as of v1.0.0-beta.1 — does not cache at all.
     *
     * The TTL comes from the server unless configuration overrides it.
     * `2026-07-28` requires `ttlMs` and `cacheScope` on every list result
     * precisely so a client does not have to guess.
     *
     * @return list<ToolDefinition>
     */
    public function definitions(): array
    {
        if ($this->memoised !== null) {
            return $this->memoised;
        }

        $ttl = $this->server->cacheTtlSeconds;

        if ($ttl === 0) {
            return $this->memoised = $this->fetchDefinitions();
        }

        $key = $this->server->toolCacheKey();

        /** @var array<int, array<string, mixed>>|null $cached */
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $this->memoised = $this->hydrate($cached);
        }

        [$payloads, $serverTtl, $scope] = $this->negotiateAndFetch();

        // A `private` result is scoped to the caller. Writing it into a shared
        // cache would serve one user's tool list to the next, so it is memoised
        // for this request and never persisted. The alternative — keying by
        // authenticated user — guesses at an identity the protocol never told
        // us, and guessing wrong is the failure this avoids.
        $shareable = $scope !== 'private';

        if ($shareable) {
            $this->cache->put($key, $payloads, $ttl ?? $serverTtl ?? 300);
        }

        return $this->memoised = $this->hydrate($payloads);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, string>  $headers
     */
    public function callTool(string $tool, array $arguments, array $headers = []): ToolResult
    {
        $result = $this->protocol->call(
            'tools/call',
            ['name' => $tool, 'arguments' => (object) $arguments],
            name: $tool,
            extraHeaders: $headers,
        );

        return ToolResult::from($this->server->name, $result);
    }

    /**
     * Forget this server's cached tool list.
     *
     * Exposed because a cache with no invalidation is a cache you cannot fix
     * from the outside, and the thing being cached is chosen by a third party.
     */
    public function forget(): void
    {
        $this->memoised = null;
        $this->cache->forget($this->server->toolCacheKey());
    }

    /**
     * @return list<ToolDefinition>
     */
    protected function fetchDefinitions(): array
    {
        [$payloads] = $this->negotiateAndFetch();

        return $this->hydrate($payloads);
    }

    /**
     * `server/discover`, then `tools/list`.
     *
     * Discovery is OPTIONAL in the spec — a client MAY call it — and it costs a
     * round trip, so it is worth saying why it is not skipped. Without it, a
     * server from the previous protocol era answers the first real request with
     * an opaque `-32602` or a parse error, and the operator goes looking at
     * their arguments instead of at the version. One extra call buys a refusal
     * that names both sides.
     *
     * It sits behind the cache deliberately: a warm tool list means no network
     * at all, which is the point of caching in the first place.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int|null, 2: string|null}
     */
    protected function negotiateAndFetch(): array
    {
        $this->protocol->discover();

        return $this->fetchPayloads();
    }

    /**
     * Every page of `tools/list`.
     *
     * Pagination is not optional to implement. A server with more tools than one
     * page returns a cursor, and a client that ignores it silently offers the
     * model a subset — which reads, again, as the model choosing not to use the
     * missing tool.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int|null, 2: string|null}
     */
    protected function fetchPayloads(): array
    {
        $payloads = [];
        $cursor = null;
        $ttlSeconds = null;
        $scope = null;
        $pages = 0;

        do {
            $result = $this->protocol->call('tools/list', $cursor === null ? [] : ['cursor' => $cursor]);

            $tools = $result['tools'] ?? null;

            if (! is_array($tools)) {
                throw ProtocolFailure::malformed($this->server->name, 'tools/list returned no `tools` array');
            }

            foreach ($tools as $tool) {
                if (is_array($tool)) {
                    $payloads[] = $tool;
                }
            }

            // The tightest bound wins across pages: a client that caches for the
            // longest TTL it saw would outlive the shortest-lived page.
            $pageTtl = $result['ttlMs'] ?? null;

            if (is_numeric($pageTtl)) {
                $seconds = (int) ((int) $pageTtl / 1000);
                $ttlSeconds = $ttlSeconds === null ? $seconds : min($ttlSeconds, $seconds);
            }

            if (($result['cacheScope'] ?? null) === 'private') {
                $scope = 'private';
            }

            $next = $result['nextCursor'] ?? null;
            $cursor = is_string($next) && $next !== '' ? $next : null;

            $pages++;

            // A server that returns a cursor forever is a denial of service, not
            // a large catalogue.
            if ($pages > 100) {
                throw ProtocolFailure::malformed(
                    $this->server->name,
                    'tools/list paginated past 100 pages without ending',
                );
            }
        } while ($cursor !== null);

        return [$payloads, $ttlSeconds, $scope];
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @return list<ToolDefinition>
     */
    protected function hydrate(array $payloads): array
    {
        $definitions = [];

        foreach ($payloads as $payload) {
            $definition = ToolDefinition::from($this->server->name, $payload);

            try {
                // Validated here and thrown away: the point is the MUST that a
                // tool with illegal mirroring is excluded from the list. Doing
                // it at hydration means such a tool is never offered to the
                // model, rather than failing at call time once the model has
                // already decided to use it.
                MirroredParameters::fromSchema($definition->name, $definition->inputSchema);
            } catch (MirroredParameterRefused $refused) {
                Log::warning($refused->getMessage(), [
                    'server' => $this->server->name,
                    'code' => $refused->code(),
                ]);

                continue;
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }
}
