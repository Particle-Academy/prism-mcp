<?php

declare(strict_types=1);

namespace Prism\Mcp\Client;

use Prism\Mcp\Exceptions\ServerNotConfigured;
use Prism\Mcp\Exceptions\UnsupportedTransport;
use Prism\Mcp\Trust\TrustPolicy;

/**
 * One configured MCP server, resolved and validated.
 *
 * Immutable on purpose. It is built from mutable config or a mutable builder,
 * and everything downstream — the transport, the cache key, the trust check —
 * reads a frozen snapshot. Same reason Prism freezes a `Request` before a
 * provider sees it: one place where the settings stop moving.
 */
class ServerConfig
{
    /**
     * @param  array<string, string>  $headers
     */
    protected function __construct(
        public readonly string $name,
        public readonly string $url,
        public readonly array $headers,
        public readonly float $timeout,
        public readonly TrustPolicy $trust,
        public readonly ?int $cacheTtlSeconds,
        public readonly int $maxResultBytes,
        public readonly ?string $gate,
        public readonly bool $frameResults,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(string $name, array $config): self
    {
        $transport = $config['transport'] ?? 'http';

        if ($transport === 'stdio') {
            throw UnsupportedTransport::stdio($name);
        }

        if ($transport !== 'http') {
            throw UnsupportedTransport::named($name, is_string($transport) ? $transport : 'unknown');
        }

        $url = $config['url'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            throw ServerNotConfigured::missingUrl($name);
        }

        /** @var array<string, string> $headers */
        $headers = is_array($config['headers'] ?? null)
            ? array_filter($config['headers'], is_string(...))
            : [];

        $cacheTtl = $config['cache_ttl'] ?? null;

        return new self(
            name: $name,
            url: $url,
            headers: $headers,
            timeout: is_numeric($config['timeout'] ?? null) ? (float) $config['timeout'] : 30.0,
            trust: TrustPolicy::fromConfig(is_array($config['trust'] ?? null) ? $config['trust'] : []),
            cacheTtlSeconds: is_numeric($cacheTtl) ? (int) $cacheTtl : null,
            maxResultBytes: is_numeric($config['max_result_bytes'] ?? null)
                ? (int) $config['max_result_bytes']
                : 262_144,
            gate: is_string($config['gate'] ?? null) ? $config['gate'] : null,
            frameResults: (bool) ($config['frame_results'] ?? true),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            name: $overrides['name'] ?? $this->name,
            url: $overrides['url'] ?? $this->url,
            headers: $overrides['headers'] ?? $this->headers,
            timeout: $overrides['timeout'] ?? $this->timeout,
            trust: $overrides['trust'] ?? $this->trust,
            cacheTtlSeconds: array_key_exists('cacheTtlSeconds', $overrides)
                ? $overrides['cacheTtlSeconds']
                : $this->cacheTtlSeconds,
            maxResultBytes: $overrides['maxResultBytes'] ?? $this->maxResultBytes,
            gate: array_key_exists('gate', $overrides) ? $overrides['gate'] : $this->gate,
            frameResults: $overrides['frameResults'] ?? $this->frameResults,
        );
    }

    /**
     * The cache key for this server's tool list.
     *
     * The URL is hashed rather than interpolated: it can carry a token in a
     * query string, and a cache key is the kind of thing that ends up in a log
     * line or a Redis `KEYS` dump. Including it at all is what stops a
     * repointed server from serving the previous one's cached tools.
     */
    public function toolCacheKey(?string $scopeSuffix = null): string
    {
        return implode(':', array_filter([
            'prism-mcp',
            'tools',
            $this->name,
            substr(hash('sha256', $this->url), 0, 16),
            $this->credentialFingerprint(),
            $scopeSuffix,
        ]));
    }

    /**
     * A fingerprint of the credentials this connection uses.
     *
     * Two connections to the same URL with different tokens are not the same
     * cache entry. A server is entitled to return a different tool list per
     * caller, and only some of them label that `cacheScope: private` — so
     * keying on the URL alone would serve one principal's tools to another the
     * moment a server forgot the label.
     *
     * Hashed with the URL as a salt-ish prefix so the key never carries the
     * credential itself: cache keys end up in logs and in `KEYS` dumps.
     */
    protected function credentialFingerprint(): string
    {
        $material = [];

        foreach ($this->headers as $header => $value) {
            $name = strtolower($header);

            if (in_array($name, ['authorization', 'cookie', 'x-api-key'], true)) {
                $material[$name] = $value;
            }
        }

        if ($material === []) {
            return '';
        }

        ksort($material);

        return substr(hash('sha256', $this->url.'|'.json_encode($material)), 0, 16);
    }
}
