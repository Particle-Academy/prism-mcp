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
