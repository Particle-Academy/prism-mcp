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
