<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Prism\Mcp\Exceptions\McpException;
use Prism\Mcp\Exceptions\ServerTimedOut;
use Prism\Mcp\Exceptions\TransportFailure;
use Prism\Mcp\Facades\PrismMcp;

/*
 * What a failed connection says about itself.
 *
 * A configured URL can carry a credential in its query string, and the HTTP
 * client's own message for a failed connection ends with that URL. The
 * exception a caller logs, and every exception chained under it, must not
 * carry it.
 */

const MCP_QUERY_SECRET = 'fake-query-token-0003';
const MCP_HEADER_SECRET = 'fake-header-key-0004';

/** A loopback port nothing listens on. */
function mcpDeadLoopbackPort(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($probe === false) {
        throw new RuntimeException("Cannot bind 127.0.0.1: {$error}");
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);

    return (int) substr($name, (int) strrpos($name, ':') + 1);
}

/** @return list<Throwable> the exception and everything chained under it */
function mcpExceptionChain(Throwable $e): array
{
    $chain = [];
    for ($current = $e; $current !== null; $current = $current->getPrevious()) {
        $chain[] = $current;
    }

    return $chain;
}

it('does not put the configured URL query or headers in an unreachable-server failure', function (): void {
    $port = mcpDeadLoopbackPort();

    config()->set('prism-mcp.servers.acme', [
        'transport' => 'http',
        'url' => "http://127.0.0.1:{$port}/mcp?token=".MCP_QUERY_SECRET,
        'headers' => ['X-Api-Key' => MCP_HEADER_SECRET],
        'trust' => ['tools' => '*'],
        'timeout' => 5,
    ]);

    $failure = null;

    try {
        PrismMcp::server('acme')->tools();
    } catch (McpException $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(TransportFailure::class)
        ->and($failure)->not->toBeInstanceOf(ServerTimedOut::class);

    foreach (mcpExceptionChain($failure) as $link) {
        expect($link->getMessage())->not->toContain(MCP_QUERY_SECRET)
            ->and($link->getMessage())->not->toContain(MCP_HEADER_SECRET)
            ->and($link->getMessage())->not->toContain((string) $port);
    }

    // The HTTP client's exception holds the full request, headers included.
    expect($failure->getPrevious())->toBeNull();
});

it('does not put the configured URL query in a timeout failure', function (): void {
    configureServer(['tools' => '*'], url: 'https://mcp.test/mcp?token='.MCP_QUERY_SECRET);

    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out after 5000 milliseconds for https://mcp.test/mcp?token='.MCP_QUERY_SECRET,
    ));

    $failure = null;

    try {
        PrismMcp::server('acme')->tools();
    } catch (McpException $e) {
        $failure = $e;
    }

    expect($failure)->toBeInstanceOf(ServerTimedOut::class);

    foreach (mcpExceptionChain($failure) as $link) {
        expect($link->getMessage())->not->toContain(MCP_QUERY_SECRET);
    }

    expect($failure->getPrevious())->toBeNull();
});

const MCP_TOKEN_SECRET = 'fake-bearer-token-0005';
const MCP_PASSWORD_SECRET = 'fake-url-password-0006';
const MCP_URL_QUERY_SECRET = 'fake-url-query-token-0007';

/** Every place a logger or error tracker reads an exception's text from. */
function mcpExpectNoSecretIn(Throwable $failure, string ...$secrets): void
{
    expect($failure)->toBeInstanceOf(TransportFailure::class)
        ->and($failure->getPrevious())->toBeNull();

    foreach (mcpExceptionChain($failure) as $link) {
        foreach ($secrets as $secret) {
            expect($link->getMessage())->not->toContain($secret);
        }
    }

    foreach ($secrets as $secret) {
        expect((string) $failure)->not->toContain($secret);
    }
}

it('does not put a header value the HTTP client rejects in the failure', function (string $headers, string $value): void {
    $port = mcpDeadLoopbackPort();

    config()->set('prism-mcp.servers.acme', [
        'transport' => 'http',
        'url' => "http://127.0.0.1:{$port}/mcp",
        'headers' => $headers === 'config' ? ['X-Api-Key' => $value] : [],
        'trust' => ['tools' => '*'],
        'timeout' => 5,
    ]);

    $failure = null;

    try {
        $connection = PrismMcp::server('acme');
        if ($headers === 'token') {
            $connection->withToken($value);
        }
        $connection->tools();
    } catch (McpException $e) {
        $failure = $e;
    }

    expect($failure)->not->toBeNull();
    mcpExpectNoSecretIn($failure, MCP_TOKEN_SECRET);
})->with([
    'token ending in CRLF' => ['token', MCP_TOKEN_SECRET."\r\n"],
    'token ending in LF' => ['token', MCP_TOKEN_SECRET."\n"],
    'token with an inner CR' => ['token', MCP_TOKEN_SECRET."\rX-Other: 1"],
    'configured header ending in LF' => ['config', MCP_TOKEN_SECRET."\n"],
    'configured header with a NUL' => ['config', MCP_TOKEN_SECRET."\0"],
]);

it('does not put a configured URL the HTTP client cannot parse in the failure', function (string $url): void {
    config()->set('prism-mcp.servers.acme', [
        'transport' => 'http',
        'url' => strtr($url, ['{PW}' => MCP_PASSWORD_SECRET, '{Q}' => MCP_URL_QUERY_SECRET]),
        'trust' => ['tools' => '*'],
        'timeout' => 5,
    ]);

    $failure = null;

    try {
        PrismMcp::server('acme')->tools();
    } catch (McpException $e) {
        $failure = $e;
    }

    expect($failure)->not->toBeNull();
    mcpExpectNoSecretIn($failure, MCP_PASSWORD_SECRET, MCP_URL_QUERY_SECRET);
})->with([
    'port out of range' => ['http://usr:{PW}@127.0.0.1:99999/mcp?token={Q}'],
    'unclosed IPv6 host' => ['http://usr:{PW}@[::1/mcp?token={Q}'],
]);
