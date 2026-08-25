<?php

declare(strict_types=1);

namespace Prism\Mcp;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;
use Prism\Mcp\Client\PendingConnection;
use Prism\Mcp\Client\ServerConfig;
use Prism\Mcp\Contracts\ToolGate;
use Prism\Mcp\Exceptions\ServerNotConfigured;
use Prism\Mcp\Gates\AllowAll;
use Prism\Mcp\Gates\DenyAll;
use Prism\Mcp\Gates\FeatureGate;
use Prism\Mcp\Gates\LaravelGate;
use Prism\Prism\Tool;

/**
 * The entry point. Two ways in, and they differ only in where the settings come
 * from.
 *
 *     PrismMcp::server('github')->tools();                       // configured
 *     PrismMcp::client('https://example.com/mcp')->trusting([…])  // ad hoc
 *
 * Both return a pending connection, both refuse without a trust declaration.
 * The ad-hoc form exists because a URL in a variable is how people try a server
 * out, and making that path skip the trust check to be convenient would put the
 * hole exactly where the exploration happens.
 */
class McpManager
{
    public function __construct(
        protected readonly Container $container,
        protected readonly ConfigRepository $config,
        protected readonly HttpFactory $http,
        protected readonly CacheFactory $cache,
    ) {}

    /**
     * A server named in `config/prism-mcp.php`.
     */
    public function server(string $name): PendingConnection
    {
        /** @var array<string, mixed>|null $servers */
        $servers = $this->config->get('prism-mcp.servers');

        if (! is_array($servers) || ! array_key_exists($name, $servers) || ! is_array($servers[$name])) {
            throw ServerNotConfigured::named($name);
        }

        return $this->pending(ServerConfig::fromArray($name, $this->withDefaults($servers[$name])));
    }

    /**
     * A server by URL, with no configuration entry.
     *
     * @param  array<string, mixed>  $options
     */
    public function client(string $url, array $options = []): PendingConnection
    {
        $label = $options['name'] ?? parse_url($url, PHP_URL_HOST) ?: $url;

        return $this->pending(ServerConfig::fromArray(
            is_string($label) ? $label : $url,
            $this->withDefaults([...$options, 'url' => $url]),
        ));
    }

    /**
     * Every configured server's tools, in one list.
     *
     * Servers that refuse are NOT skipped. A helper that quietly dropped an
     * untrusted or unreachable server would hand the model a shorter tool list
     * than the application believes it configured, and that is the silence this
     * package exists to remove.
     *
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        /** @var array<string, mixed> $servers */
        $servers = $this->config->get('prism-mcp.servers', []);

        $tools = [];

        foreach (array_keys($servers) as $name) {
            $tools = [...$tools, ...$this->server((string) $name)->tools()];
        }

        return $tools;
    }

    /**
     * Resolve a gate by its configuration key.
     */
    public function gate(?string $name): ToolGate
    {
        return match ($name) {
            null, 'allow', 'allow-all' => $this->container->make(AllowAll::class),
            'deny', 'deny-all' => $this->container->make(DenyAll::class),
            'laravel', 'gate' => $this->container->make(LaravelGate::class),
            'fms', 'feature' => $this->container->make(FeatureGate::class),
            default => $this->resolveCustomGate($name),
        };
    }

    protected function resolveCustomGate(string $name): ToolGate
    {
        if (! $this->container->bound($name) && ! class_exists($name)) {
            throw new ServerNotConfigured(sprintf(
                'The gate [%s] is neither a known gate name (allow, deny, laravel, fms) nor a resolvable class.',
                $name,
            ));
        }

        $gate = $this->container->make($name);

        if (! $gate instanceof ToolGate) {
            throw new ServerNotConfigured(sprintf(
                'The gate [%s] does not implement %s.',
                $name,
                ToolGate::class,
            ));
        }

        return $gate;
    }

    protected function pending(ServerConfig $server): PendingConnection
    {
        $pending = new PendingConnection(
            $this->container,
            $this->http,
            $this->cache,
            $server,
            $this->clientInfo(),
        );

        if ($server->gate !== null) {
            $pending->usingGate($this->gate($server->gate));
        }

        return $pending;
    }

    /**
     * Server-level settings fall back to package defaults, so an operator sets
     * a timeout once rather than on every server and forgets one.
     *
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    protected function withDefaults(array $server): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = $this->config->get('prism-mcp.defaults', []);

        return [...$defaults, ...$server];
    }

    /**
     * What this client tells a server it is.
     *
     * The spec is explicit that `clientInfo` is self-reported and that
     * implementations SHOULD NOT rely on it for security decisions — which cuts
     * both ways. It is sent because it makes a server's logs legible, not
     * because it proves anything.
     *
     * @return array<string, mixed>
     */
    protected function clientInfo(): array
    {
        return [
            'name' => (string) $this->config->get('prism-mcp.client.name', 'prism-mcp'),
            'version' => (string) $this->config->get('prism-mcp.client.version', '0.1.0'),
        ];
    }
}
