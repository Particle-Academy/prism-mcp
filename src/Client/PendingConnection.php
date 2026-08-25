<?php

declare(strict_types=1);

namespace Prism\Mcp\Client;

use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Prism\Mcp\Contracts\ToolGate;
use Prism\Mcp\Gates\AllowAll;
use Prism\Mcp\Tools\RemoteTool;
use Prism\Mcp\Transport\HttpTransport;
use Prism\Mcp\Trust\ResultGuard;
use Prism\Mcp\Trust\TrustPolicy;
use Prism\Prism\Tool;

/**
 * A mutable builder that freezes when you ask it for something.
 *
 * Same shape as Prism's own pending request — every method returns `$this`,
 * nothing is validated on the way in, and the checks all happen at the one
 * boundary where the configuration stops moving. In this package that boundary
 * is `tools()`, and the most important thing it does is refuse a server nobody
 * declared trust in, BEFORE a single description has been fetched.
 *
 * See prism-parity `docs/patterns/02-pending-request.md` for the model this
 * follows; it is described once, there, rather than restated here.
 */
class PendingConnection
{
    protected ?ToolGate $gate = null;

    /** @var (Closure(string, string, string): string)|null */
    protected $resultFilter = null;

    protected ?string $cacheStore = null;

    public function __construct(
        protected readonly Container $container,
        protected readonly HttpFactory $http,
        protected readonly CacheFactory $cache,
        protected ServerConfig $config,
        /** @var array<string, mixed> */
        protected readonly array $clientInfo,
    ) {}

    /**
     * Declare which of this server's tools you trust to reach the model.
     *
     * @param  list<string>  $tools
     */
    public function trusting(array $tools): static
    {
        $this->config = $this->config->with(['trust' => TrustPolicy::allowing($tools)]);

        return $this;
    }

    /**
     * Accept whatever this server publishes — today, and next week without
     * telling you.
     *
     * Spelled out rather than available as `trusting('*')` because it should be
     * awkward to type by accident. It is a legitimate choice for a server you
     * operate. It is not a default.
     */
    public function trustingEveryTool(): static
    {
        $this->config = $this->config->with(['trust' => TrustPolicy::everyTool()]);

        return $this;
    }

    /**
     * Pin tool definitions to digests you have reviewed.
     *
     * @param  array<string, string>  $pins  tool name => digest
     */
    public function pinning(array $pins): static
    {
        $this->config = $this->config->with(['trust' => $this->config->trust->withPins($pins)]);

        return $this;
    }

    public function withTimeout(float $seconds): static
    {
        $this->config = $this->config->with(['timeout' => $seconds]);

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): static
    {
        $this->config = $this->config->with(['headers' => [...$this->config->headers, ...$headers]]);

        return $this;
    }

    public function withToken(string $token): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    public function usingGate(ToolGate $gate): static
    {
        $this->gate = $gate;

        return $this;
    }

    /**
     * @param  Closure(string $text, string $server, string $tool): string  $filter
     */
    public function filteringResults(Closure $filter): static
    {
        $this->resultFilter = $filter;

        return $this;
    }

    public function withMaxResultBytes(int $bytes): static
    {
        $this->config = $this->config->with(['maxResultBytes' => $bytes]);

        return $this;
    }

    /**
     * Stop framing results as untrusted output.
     *
     * Available because framing costs tokens on every call and an application
     * that has its own containment may not want a second layer. Not recommended,
     * and named so that turning it off is a visible line in a diff.
     */
    public function withoutProvenanceFraming(): static
    {
        $this->config = $this->config->with(['frameResults' => false]);

        return $this;
    }

    public function withoutCache(): static
    {
        $this->config = $this->config->with(['cacheTtlSeconds' => 0]);

        return $this;
    }

    public function cachingFor(int $seconds, ?string $store = null): static
    {
        $this->config = $this->config->with(['cacheTtlSeconds' => $seconds]);
        $this->cacheStore = $store;

        return $this;
    }

    public function config(): ServerConfig
    {
        return $this->config;
    }

    /**
     * The protocol client, for prompts, resources and anything this package has
     * not wrapped yet.
     *
     * Deliberately unguarded: it is the escape hatch, and an escape hatch that
     * silently applied the trust layer would be lying about which one you are
     * using.
     */
    public function client(): Client
    {
        $transport = (new HttpTransport($this->http, $this->config->url, $this->config->name))
            ->withHeaders($this->config->headers)
            ->withTimeout($this->config->timeout);

        return new Client(
            $this->config,
            new Protocol($transport, $this->clientInfo),
            $this->cache->store($this->cacheStore),
        );
    }

    /**
     * The freeze.
     *
     * Trust is asserted FIRST, before the transport is touched. Refusing after
     * fetching would already have told the server it has an audience, and would
     * mean a misconfigured application makes network calls it had no permission
     * to make.
     *
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        $this->config->trust->assertDeclaredFor($this->config->name);

        $client = $this->client();
        $definitions = $client->definitions();
        $gate = $this->gate ?? $this->container->make(AllowAll::class);

        $guard = new ResultGuard($this->config->maxResultBytes, $this->config->frameResults);

        if ($this->resultFilter instanceof Closure) {
            $guard->filtering($this->resultFilter);
        }

        $offered = array_map(fn ($definition): string => $definition->name, $definitions);
        $missing = $this->config->trust->namedButNotOffered($offered);

        if ($missing !== []) {
            // Not fatal — a server is entitled to withdraw a tool. But a name in
            // an allowlist that matches nothing is a typo often enough that
            // swallowing it means someone spends an afternoon wondering why the
            // model never calls it.
            Log::warning(sprintf(
                'The MCP server [%s] does not offer these trusted tools: %s.',
                $this->config->name,
                implode(', ', $missing),
            ), ['server' => $this->config->name]);
        }

        $tools = [];

        foreach ($definitions as $definition) {
            if (! $this->config->trust->allows($definition->name)) {
                continue;
            }

            $this->config->trust->assertPinHolds($this->config->name, $definition);

            $tools[] = new RemoteTool(
                $client,
                $definition,
                $gate,
                $guard,
                $this->prefix($definition->name),
            );
        }

        return $tools;
    }

    /**
     * Namespace the tool name.
     *
     * Two servers may both publish `search`, and handing a model two tools with
     * one name means it cannot address either of them reliably. The prefix also
     * makes it legible in a trace which server a call went to.
     *
     * Providers constrain the character set and the length — OpenAI allows
     * `[A-Za-z0-9_-]` up to 64 — so anything outside that is folded, and a name
     * that would overflow keeps its head and its tail with a digest in between
     * rather than being cut. A truncated name can collide with another truncated
     * name; a digested one cannot.
     */
    protected function prefix(string $tool): string
    {
        $safe = fn (string $value): string => (string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $value);

        $name = sprintf('mcp__%s__%s', $safe($this->config->name), $safe($tool));

        if (strlen($name) <= 64) {
            return $name;
        }

        $digest = substr(hash('sha256', $name), 0, 8);

        return substr($name, 0, 34).'__'.$digest.'__'.substr($name, -18);
    }
}
