<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Prism\Mcp\Exceptions\TransportFailure;
use Prism\Mcp\Facades\PrismMcp;
use Prism\Mcp\Transport\HttpTransport;

require_once __DIR__.'/../Support/RedirectLoopback.php';

/*
 * Redirects, against real loopback servers.
 *
 * A request carrying caller headers follows a redirect only when the scheme,
 * host and port stay the same. Anything else is refused before a second
 * request is made, so the other server receives nothing at all.
 */

const MCP_REDIRECT_KEY = 'fake-key-0001';
const MCP_REDIRECT_TOKEN = 'fake-token-0002';

beforeEach(function (): void {
    $this->origin = RedirectLoopback::start('127.0.0.1');
    $this->elsewhere = RedirectLoopback::start('127.0.0.2');
    $this->elsewhere->answer(body: '{"jsonrpc":"2.0","id":1,"result":{}}');
});

afterEach(function (): void {
    $this->origin->stop();
    $this->elsewhere->stop();
});

function mcpRedirectTransport(string $url): HttpTransport
{
    return (new HttpTransport(app(Factory::class), $url, 'acme'))
        ->withHeaders(['X-Api-Key' => MCP_REDIRECT_KEY, 'Authorization' => 'Bearer '.MCP_REDIRECT_TOKEN])
        ->withTimeout(5);
}

/** @return TransportFailure|null the failure the call raised, if any */
function mcpSendCatching(HttpTransport $transport): ?TransportFailure
{
    try {
        $transport->send('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');
    } catch (TransportFailure $e) {
        return $e;
    }

    return null;
}

it('does not follow a redirect to another host and port', function (int $status): void {
    $this->origin->answer([['match' => '/mcp', 'status' => $status, 'location' => $this->elsewhere->origin().'/landed']]);

    $failure = mcpSendCatching(mcpRedirectTransport($this->origin->origin().'/mcp'));

    expect($this->elsewhere->requests())->toBe([])
        ->and($this->origin->requests())->toHaveCount(1)
        ->and($failure)->toBeInstanceOf(TransportFailure::class)
        ->and($failure?->getMessage())->toContain('redirect')
        ->not->toContain(MCP_REDIRECT_KEY)
        ->not->toContain(MCP_REDIRECT_TOKEN);
})->with([301, 302, 303, 307, 308]);

it('does not follow a redirect to the same host on another port', function (): void {
    $other = RedirectLoopback::start('127.0.0.1');

    try {
        $this->origin->answer([['match' => '/mcp', 'status' => 307, 'location' => $other->origin().'/landed']]);

        $failure = mcpSendCatching(mcpRedirectTransport($this->origin->origin().'/mcp'));

        expect($other->requests())->toBe([])
            ->and($failure)->toBeInstanceOf(TransportFailure::class);
    } finally {
        $other->stop();
    }
});

it('does not follow a redirect that changes the scheme', function (): void {
    $this->origin->answer([['match' => '/mcp', 'status' => 308, 'location' => 'https://{host}/landed']]);

    $failure = mcpSendCatching(mcpRedirectTransport($this->origin->origin().'/mcp'));

    expect($this->origin->requests())->toHaveCount(1)
        ->and($failure)->toBeInstanceOf(TransportFailure::class)
        ->and($failure?->getMessage())->toContain('redirect');
});

it('follows a redirect that keeps the scheme, host and port', function (): void {
    $this->origin->answer(
        [['match' => '/mcp', 'status' => 307, 'location' => '/landed']],
        body: '{"jsonrpc":"2.0","id":1,"result":{"ok":true}}',
    );

    $body = mcpRedirectTransport($this->origin->origin().'/mcp')->send('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

    $requests = $this->origin->requests();

    expect($body)->toBe('{"jsonrpc":"2.0","id":1,"result":{"ok":true}}')
        ->and($requests)->toHaveCount(2)
        ->and($requests[1]['uri'])->toBe('/landed')
        ->and($requests[1]['headers']['X-Api-Key'] ?? null)->toBe(MCP_REDIRECT_KEY)
        ->and($this->elsewhere->requests())->toBe([]);
});

it('does not follow a cross-origin redirect from a configured connection', function (): void {
    $this->origin->answer([['match' => '/mcp', 'status' => 302, 'location' => $this->elsewhere->origin().'/landed']]);

    $failure = null;

    try {
        PrismMcp::client($this->origin->origin().'/mcp')
            ->withHeaders(['X-Api-Key' => MCP_REDIRECT_KEY])
            ->withToken(MCP_REDIRECT_TOKEN)
            ->withTimeout(5)
            ->withoutCache()
            ->trustingEveryTool()
            ->tools();
    } catch (TransportFailure $e) {
        $failure = $e;
    }

    expect($this->elsewhere->requests())->toBe([])
        ->and($this->elsewhere->received())->not->toContain(MCP_REDIRECT_KEY)
        ->and($failure)->toBeInstanceOf(TransportFailure::class)
        ->and($failure?->getMessage())->not->toContain(MCP_REDIRECT_KEY)
        ->not->toContain(MCP_REDIRECT_TOKEN);
});

/*
 * Hostile Locations.
 *
 * Each of these has defeated a same-origin check somewhere: a host hidden in
 * userinfo, a fragment or query that moves where the authority ends, a
 * scheme-relative or backslashed URL, a host or port spelled differently.
 * Whatever the client makes of them, the other origin must receive nothing,
 * and the only exception a caller sees is TransportFailure.
 *
 * {A} is the configured origin's host:port, {E} the other server's, {PA} and
 * {PE} their ports.
 */
function mcpRedirectLocation(string $template, RedirectLoopback $origin, RedirectLoopback $elsewhere): string
{
    return strtr($template, [
        '{A}' => $origin->host.':'.$origin->port,
        '{E}' => $elsewhere->host.':'.$elsewhere->port,
        '{PA}' => (string) $origin->port,
        '{PE}' => (string) $elsewhere->port,
    ]);
}

it('sends nothing to another origin whatever the Location says', function (string $template): void {
    $this->origin->answer(
        [['match' => '/mcp', 'status' => 307, 'location' => mcpRedirectLocation($template, $this->origin, $this->elsewhere)]],
        body: '{"jsonrpc":"2.0","id":1,"result":{"ok":true}}',
    );

    $failure = mcpSendCatching(mcpRedirectTransport($this->origin->origin().'/mcp'));

    expect($this->elsewhere->requests())->toBe([])
        ->and($this->elsewhere->received())->not->toContain(MCP_REDIRECT_KEY);

    if ($failure === null) {
        // The client read the Location as a URL on the configured origin and
        // followed it there; that is the only success allowed.
        $requests = $this->origin->requests();
        expect($requests)->toHaveCount(2)
            ->and($requests[1]['uri'])->toStartWith('/landed');
    } else {
        expect($this->origin->requests())->toHaveCount(1)
            ->and($failure->getMessage())->not->toContain(MCP_REDIRECT_KEY)
            ->and($failure->getMessage())->not->toContain(MCP_REDIRECT_TOKEN);
    }
})->with([
    'scheme-relative' => '//{E}/landed',
    'backslashes after the scheme' => 'http:\\\\{E}/landed',
    'configured origin as userinfo' => 'http://{A}@{E}/landed',
    'fragment before the at sign' => 'http://{E}#@{A}/landed',
    'query before the at sign' => 'http://{E}?@{A}/landed',
    'backslash before the at sign' => 'http://{E}\\@{A}/landed',
    'encoded hash before the at sign' => 'http://{E}%23@{A}/landed',
    'encoded at sign' => 'http://{A}%40{E}/landed',
    'upper-case scheme to another host' => 'HTTP://{E}/landed',
    'explicit default port' => 'http://127.0.0.1:80/landed',
    'explicit default port with a leading zero' => 'http://127.0.0.1:080/landed',
    'leading zero on the other port' => 'http://127.0.0.2:0{PE}/landed',
    'trailing-dot host' => 'http://127.0.0.2.:{PE}/landed',
    'IPv6-mapped address' => 'http://[::ffff:127.0.0.2]:{PE}/landed',
    'IPv6-mapped address, hex form' => 'http://[::ffff:7f00:2]:{PE}/landed',
    'https to another host' => 'https://{E}/landed',
    'a non-http scheme' => 'ftp://{E}/landed',
    'a file URL' => 'file:///etc/passwd',
    'port out of range' => 'http://127.0.0.2:99999/landed',
    'two ports' => 'http://{E}:1/landed',
    'unclosed IPv6 bracket' => 'http://[::1/landed',
]);

it('follows a Location that stays on the configured origin', function (string $template): void {
    $this->origin->answer(
        [['match' => '/mcp', 'status' => 307, 'location' => mcpRedirectLocation($template, $this->origin, $this->elsewhere)]],
        body: '{"jsonrpc":"2.0","id":1,"result":{"ok":true}}',
    );

    $body = mcpRedirectTransport($this->origin->origin().'/mcp')->send('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

    $requests = $this->origin->requests();

    expect($body)->toBe('{"jsonrpc":"2.0","id":1,"result":{"ok":true}}')
        ->and($requests)->toHaveCount(2)
        ->and($requests[1]['uri'])->toBe('/landed')
        ->and($requests[1]['headers']['X-Api-Key'] ?? null)->toBe(MCP_REDIRECT_KEY)
        ->and($this->elsewhere->requests())->toBe([]);
})->with([
    'relative path' => 'landed',
    'absolute path' => '/landed',
    'explicit same port' => 'http://{A}/landed',
    'upper-case scheme' => 'HTTP://{A}/landed',
]);

it('follows a Location whose host differs from the configured one only in case', function (): void {
    $this->origin->answer(
        [['match' => '/mcp', 'status' => 307, 'location' => 'http://LOCALHOST:'.$this->origin->port.'/landed']],
        body: '{"jsonrpc":"2.0","id":1,"result":{"ok":true}}',
    );

    $body = mcpRedirectTransport('http://LocalHost:'.$this->origin->port.'/mcp')
        ->send('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

    expect($body)->toBe('{"jsonrpc":"2.0","id":1,"result":{"ok":true}}')
        ->and($this->origin->requests())->toHaveCount(2);
});

it('reports a redirect loop as a transport failure', function (): void {
    $this->origin->answer([['match' => '/mcp', 'status' => 307, 'location' => '/mcp']]);

    $failure = mcpSendCatching(mcpRedirectTransport($this->origin->origin().'/mcp'));

    expect($failure)->toBeInstanceOf(TransportFailure::class)
        ->and($this->origin->requests())->toHaveCount(6)
        ->and($this->elsewhere->requests())->toBe([]);
});

it('reports a malformed Location as a transport failure', function (): void {
    $this->origin->answer([['match' => '/mcp', 'status' => 302, 'location' => 'http://[::1/landed']]);

    $failure = mcpSendCatching(mcpRedirectTransport($this->origin->origin().'/mcp'));

    expect($failure)->toBeInstanceOf(TransportFailure::class)
        ->and($this->origin->requests())->toHaveCount(1);
});

it('follows no redirect at all when the application turned redirects off', function (string $location): void {
    app(Factory::class)->globalOptions(['allow_redirects' => false]);

    $this->origin->answer(
        [['match' => '/mcp', 'status' => 307, 'location' => mcpRedirectLocation($location, $this->origin, $this->elsewhere)]],
        body: '{"jsonrpc":"2.0","id":1,"result":{"ok":true}}',
    );

    $failure = mcpSendCatching(mcpRedirectTransport($this->origin->origin().'/mcp'));

    expect($failure)->toBeInstanceOf(TransportFailure::class)
        ->and($this->origin->requests())->toHaveCount(1)
        ->and($this->elsewhere->requests())->toBe([]);
})->with([
    'same origin' => '/landed',
    'another origin' => 'http://{E}/landed',
]);
