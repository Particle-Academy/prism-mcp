<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Prism\Mcp\Facades\PrismMcp;

beforeEach(function (): void {
    configureServer(['tools' => '*']);
});

it('does not refetch the tool list on a second connection', function (): void {
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => [toolPayload('search')], 'ttlMs' => 60_000]],
    ]);

    PrismMcp::server('acme')->tools();
    $afterFirst = count(Http::recorded());

    // A fresh pending connection, as a second request in the same worker would
    // build. Without the cache this is a handshake plus a round trip in front of
    // every generation — laravel/mcp's client, as of v1.0.0-beta.1, has no
    // caching at all and pays exactly that.
    PrismMcp::server('acme')->tools();

    expect(count(Http::recorded()))->toBe($afterFirst);
});

it('serves a warm cache without even negotiating', function (): void {
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => [toolPayload('search')], 'ttlMs' => 60_000]],
    ]);

    PrismMcp::server('acme')->tools();

    Http::fake(fn () => throw new RuntimeException('the network should not have been touched'));

    // The cache sits IN FRONT of discovery, not behind it. A cache that still
    // paid for a handshake would halve the saving for no reason.
    expect(PrismMcp::server('acme')->tools())->toHaveCount(1);
});

it('honours the server ttl when configuration does not override it', function (): void {
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => [toolPayload('search')], 'ttlMs' => 1_000]],
    ]);

    PrismMcp::server('acme')->tools();

    // 2026-07-28 requires ttlMs on every list result precisely so a client does
    // not have to guess one. 1000ms became 1s.
    $key = PrismMcp::server('acme')->config()->toolCacheKey();

    expect(cache()->has($key))->toBeTrue();

    $this->travel(2)->seconds();

    expect(cache()->has($key))->toBeFalse();
});

it('takes the tightest ttl across paginated pages', function (): void {
    Http::fakeSequence()
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => discoverResult()], 200)
        ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => [
            'tools' => [toolPayload('one')], 'ttlMs' => 600_000, 'nextCursor' => 'p2',
        ]], 200)
        ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => [
            'tools' => [toolPayload('two')], 'ttlMs' => 1_000,
        ]], 200);

    PrismMcp::server('acme')->tools();

    $key = PrismMcp::server('acme')->config()->toolCacheKey();

    // Caching for the longest TTL seen would outlive the shortest-lived page,
    // which is the same as ignoring that page's TTL entirely.
    $this->travel(2)->seconds();

    expect(cache()->has($key))->toBeFalse();
});

it('never writes a private tool list into a shared cache', function (): void {
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [[
            'tools' => [toolPayload('search')],
            'ttlMs' => 600_000,
            'cacheScope' => 'private',
        ]],
    ]);

    PrismMcp::server('acme')->tools();

    // `private` means scoped to the caller. Persisting it would serve one user's
    // tool list to the next — and keying it by "the authenticated user" would be
    // guessing at an identity the protocol never told us.
    expect(cache()->has(PrismMcp::server('acme')->config()->toolCacheKey()))->toBeFalse();
});

it('can be told not to cache at all', function (): void {
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => [toolPayload('search')], 'ttlMs' => 600_000]],
    ]);

    PrismMcp::server('acme')->withoutCache()->tools();

    expect(cache()->has(PrismMcp::server('acme')->config()->toolCacheKey()))->toBeFalse();
});

it('keys the cache on the url so a repointed server does not serve stale tools', function (): void {
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => [toolPayload('search')], 'ttlMs' => 600_000]],
    ]);

    PrismMcp::server('acme')->tools();

    $before = PrismMcp::server('acme')->config()->toolCacheKey();

    configureServer(['tools' => '*'], 'acme', 'https://elsewhere.test/mcp');

    expect(PrismMcp::server('acme')->config()->toolCacheKey())->not->toBe($before);
});
