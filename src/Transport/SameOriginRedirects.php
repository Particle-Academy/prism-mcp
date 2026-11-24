<?php

declare(strict_types=1);

namespace Prism\Mcp\Transport;

use Closure;
use Stringable;
use Throwable;

/**
 * Redirect handling for requests that carry caller-configured headers.
 *
 * A redirect is followed only when it keeps the scheme, host and effective
 * port of the URL the caller configured. Anything else is refused before the
 * next request is built, so the other server receives nothing. The HTTP
 * client's own redirect handling drops `Authorization` and `Cookie` on a
 * cross-origin hop but re-sends every other header, and a caller's headers are
 * not a list this package can know in advance.
 *
 * Every hop is checked against the configured origin rather than the previous
 * hop, which is the same answer when each hop must match, and needs nothing
 * from the request objects.
 */
final class SameOriginRedirects
{
    /**
     * @param  Closure(): Throwable  $refusal  what to throw when the origin changes
     * @return array{allow_redirects: array{max: int, on_redirect: Closure}}
     */
    public static function options(string $url, Closure $refusal): array
    {
        $origin = self::origin($url);

        return ['allow_redirects' => [
            'max' => 5,
            'on_redirect' => static function (mixed $request, mixed $response, Stringable $next) use ($origin, $refusal): void {
                if ($origin === null || self::origin((string) $next) !== $origin) {
                    throw $refusal();
                }
            },
        ]];
    }

    /**
     * `scheme://host:port`, with the port made explicit, or null for anything
     * that is not an absolute http(s) URL.
     */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };

        return $port === null ? null : $scheme.'://'.strtolower($parts['host']).':'.$port;
    }
}
