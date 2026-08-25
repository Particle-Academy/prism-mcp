<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Prism\Mcp\Exceptions\ResultTooLarge;
use Prism\Mcp\Exceptions\TransportFailure;
use Prism\Mcp\Facades\PrismMcp;
use Prism\Mcp\Support\Legible;
use Prism\Mcp\Transport\HttpTransport;

/**
 * Rows that exist because a security review of this package found them, not
 * because they were designed in. Each one is a hole that was open.
 */
it('refuses a transport response too large to hold in memory', function (): void {
    // The timeout bounds how LONG a server may take. Nothing else bounded how
    // MUCH it could send, and the body is decoded into memory before any
    // tool-level size check gets a look at it.
    configureServer(['tools' => '*']);

    Http::fake([
        'https://mcp.test/mcp' => Http::response(str_repeat('x', 2_000), 200),
    ]);

    $connection = PrismMcp::client('https://mcp.test/mcp')->trustingEveryTool();

    $transport = (new HttpTransport(
        app(Factory::class),
        'https://mcp.test/mcp',
        'acme',
    ))->withMaxResponseBytes(1_000);

    $failure = null;

    try {
        $transport->send('{}');
    } catch (TransportFailure $e) {
        $failure = $e;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->getMessage())->toContain('transport limit');
});

it('does not share a cached tool list between different credentials', function (): void {
    // A server may legitimately return a different tool list per caller, and
    // only some of them label that `cacheScope: private`. Keying on the URL
    // alone would serve one principal's tools to another the moment a server
    // forgot the label.
    $alice = PrismMcp::client('https://mcp.test/mcp')->withToken('alice')->config()->toolCacheKey();
    $bob = PrismMcp::client('https://mcp.test/mcp')->withToken('bob')->config()->toolCacheKey();
    $anon = PrismMcp::client('https://mcp.test/mcp')->config()->toolCacheKey();

    expect($alice)->not->toBe($bob)
        ->and($alice)->not->toBe($anon)
        // And the key must never carry the credential itself — cache keys end
        // up in logs and in `KEYS` dumps.
        ->and($alice)->not->toContain('alice')
        ->and($bob)->not->toContain('bob');
});

it('keeps the same cache key for the same credentials', function (): void {
    $first = PrismMcp::client('https://mcp.test/mcp')->withToken('alice')->config()->toolCacheKey();
    $again = PrismMcp::client('https://mcp.test/mcp')->withToken('alice')->config()->toolCacheKey();

    // A key that varied per call would be a cache that never hits, which is the
    // same as no cache at all but slower to notice.
    expect($first)->toBe($again);
});

it('collapses a server-supplied name that would forge a log line', function (): void {
    $forged = "search\nlevel=INFO msg=\"nothing to see here\"";

    // One value, one line. A name carrying a newline forges a second log entry,
    // which is how an audit trail stops being one.
    expect(Legible::name($forged))
        ->not->toContain("\n")
        ->toBe('search level=INFO msg="nothing to see here"');

    expect(Legible::name(str_repeat('a', 500)))->toEndWith('…');
    expect(Legible::name("\x00\x00"))->toBe('(unnamed)');
});

it('guards the error path exactly as hard as the success path', function (): void {
    configureServer(['tools' => ['search']]);

    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => [toolPayload('search')]]],
        'tools/call' => [[
            'content' => [['type' => 'text', 'text' => str_repeat('y', 5_000)]],
            'isError' => true,
        ]],
    ]);

    $tool = PrismMcp::server('acme')->withMaxResultBytes(1_000)->tools()[0];

    // The size cap has to hold on the error path too, or a server sets
    // `isError` and floods the context anyway.
    expect(fn () => $tool->__invoke(query: 'anything'))
        ->toThrow(ResultTooLarge::class);
});
