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
    /** The most hops followed, whatever the application allows. */
    public const MAX_HOPS = 5;

    /**
     * @param  Closure(): Throwable  $refusal  what to throw when the origin changes
     * @param  int  $max  the most hops to follow; see hopLimit()
     * @param  mixed  $hook  the application's own on_redirect, called only for a hop that passed the origin check
     * @return array{allow_redirects: array{max: int, on_redirect: Closure}}
     */
    public static function options(string $url, Closure $refusal, int $max = self::MAX_HOPS, mixed $hook = null): array
    {
        $origin = self::origin($url);
        $max = max(1, min($max, self::MAX_HOPS));

        return ['allow_redirects' => [
            'max' => $max,
            'on_redirect' => static function (mixed $request, mixed $response, Stringable $next) use ($origin, $refusal, $hook): void {
                if ($origin === null || self::origin((string) $next) !== $origin) {
                    throw $refusal();
                }

                if (is_callable($hook)) {
                    $hook($request, $response, $next);
                }
            },
        ]];
    }

    /**
     * How many hops an `allow_redirects` setting allows, or null when it
     * allows none. One definition, kept identical in prism-mcp,
     * prism-human-plus and prism-browser.
     *
     * Read the way the HTTP client reads it, and never wider: `true` is the
     * default of five; any other value that is not an array is off (the client
     * treats an empty one as off and rejects the rest); an empty array is off;
     * an array without `max` takes the default; a `max` that is not a positive
     * integer (or a string of digits) is off, since zero is the client's off
     * and a negative stops at the first hop. The result is never above five.
     */
    public static function hopLimit(mixed $setting): ?int
    {
        if ($setting === true) {
            return self::MAX_HOPS;
        }

        if (! is_array($setting) || $setting === []) {
            return null;
        }

        if (! array_key_exists('max', $setting)) {
            return self::MAX_HOPS;
        }

        $max = $setting['max'];

        if (is_string($max) && $max !== '' && ctype_digit($max)) {
            $max = (int) $max;
        }

        if (! is_int($max) || $max < 1) {
            return null;
        }

        return min($max, self::MAX_HOPS);
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
