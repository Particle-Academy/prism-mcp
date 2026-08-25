<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Prism\Mcp\Exceptions\ProtocolFailure;
use Prism\Mcp\Exceptions\ServerTimedOut;
use Prism\Mcp\Exceptions\TransportFailure;
use Prism\Mcp\Exceptions\UnsupportedProtocolVersion;
use Prism\Mcp\Facades\PrismMcp;

beforeEach(function (): void {
    configureServer(['tools' => '*']);
});

it('sends the metadata and headers 2026-07-28 requires on every request', function (): void {
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => []]],
    ]);

    PrismMcp::server('acme')->tools();

    Http::assertSent(function ($request): bool {
        $payload = json_decode($request->body(), true);
        $meta = $payload['params']['_meta'] ?? [];

        // Both of these are MUSTs. A conforming server answers -32602 without
        // them, and the failure would arrive looking like a bad argument.
        expect($meta)->toHaveKey('io.modelcontextprotocol/protocolVersion', '2026-07-28')
            ->and($meta)->toHaveKey('io.modelcontextprotocol/clientCapabilities')
            ->and($meta)->toHaveKey('io.modelcontextprotocol/clientInfo');

        expect($request->header('MCP-Protocol-Version')[0])->toBe('2026-07-28')
            ->and($request->header('Mcp-Method')[0])->toBe($payload['method']);

        return true;
    });
});

it('encodes client capabilities as an object, not an array', function (): void {
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => []]],
    ]);

    PrismMcp::server('acme')->tools();

    Http::assertSent(function ($request): bool {
        // PHP renders an empty array as `[]` and the schema wants `{}`. This is
        // the same absent-versus-empty trap that has caught every port in this
        // ecosystem at least once, so it gets a row of its own.
        expect($request->body())->toContain('"io.modelcontextprotocol/clientCapabilities":{}');

        return true;
    });
});

it('names both sides when the server speaks a different era', function (): void {
    fakeMcpServer([
        'server/discover' => [['supportedVersions' => ['2025-11-25', '2025-06-18']]],
    ]);

    $refusal = null;

    try {
        PrismMcp::server('acme')->tools();
    } catch (UnsupportedProtocolVersion $e) {
        $refusal = $e;
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->code())->toBe('unsupported_protocol_version')
        // Naming neither side is a support ticket; naming both is a fix.
        ->and($refusal->getMessage())->toContain('2025-11-25')
        ->and($refusal->getMessage())->toContain('2026-07-28');
});

it('turns a -32022 rejection into the same honest refusal', function (): void {
    fakeMcpServer([
        'server/discover' => [[
            'error' => [
                'code' => -32022,
                'message' => 'unsupported protocol version',
                'data' => ['supported' => ['2025-06-18']],
            ],
        ]],
    ]);

    expect(fn () => PrismMcp::server('acme')->tools())
        ->toThrow(UnsupportedProtocolVersion::class, '2025-06-18');
});

it('refuses a response whose id does not match the request', function (): void {
    Http::fake([
        'https://mcp.test/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 9999,
            'result' => ['supportedVersions' => ['2026-07-28']],
        ]),
    ]);

    // A response for a different request is a correlation failure. Accepting it
    // is how a client hands back another call's data.
    expect(fn () => PrismMcp::server('acme')->tools())
        ->toThrow(ProtocolFailure::class, 'did not match request');
});

it('refuses an envelope carrying both a result and an error', function (): void {
    Http::fake([
        'https://mcp.test/mcp' => Http::response([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['supportedVersions' => ['2026-07-28']],
            'error' => ['code' => -1, 'message' => 'also this'],
        ]),
    ]);

    expect(fn () => PrismMcp::server('acme')->tools())
        ->toThrow(ProtocolFailure::class, 'both a result and an error');
});

it('reads the last JSON-RPC frame of an event stream, not the first', function (): void {
    // A streaming response interleaves progress with the result. Taking the
    // first frame would return a notification and call it an answer.
    $stream = implode("\n", [
        'event: message',
        'data: {"jsonrpc":"2.0","method":"notifications/progress","params":{"progress":1}}',
        '',
        'event: message',
        'data: {"jsonrpc":"2.0","id":1,"result":{"supportedVersions":["2026-07-28"]}}',
        '',
    ]);

    Http::fakeSequence()
        ->push($stream, 200, ['Content-Type' => 'text/event-stream'])
        ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => [toolPayload('search')]]], 200);

    expect(PrismMcp::server('acme')->tools())->toHaveCount(1);
});

it('fails a slow server by name rather than hanging a worker', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $failure = null;

    try {
        PrismMcp::server('acme')->tools();
    } catch (ServerTimedOut $e) {
        $failure = $e;
    }

    expect($failure)->not->toBeNull()
        ->and($failure->code())->toBe('server_timed_out');
});

it('does not call an unreachable server a timeout', function (): void {
    // A timeout is retryable and a refused connection usually is not, so a
    // caller wants to branch on the difference. Guzzle folds both into one
    // exception type, which is why this row exists.
    Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

    $failure = null;

    try {
        PrismMcp::server('acme')->tools();
    } catch (TransportFailure $e) {
        $failure = $e;
    }

    expect($failure)->not->toBeNull()
        ->and($failure)->not->toBeInstanceOf(ServerTimedOut::class)
        ->and($failure->code())->toBe('transport_failure');
});

it('walks every page of a paginated tool list', function (): void {
    Http::fakeSequence()
        ->push(['jsonrpc' => '2.0', 'id' => 1, 'result' => discoverResult()], 200)
        ->push(['jsonrpc' => '2.0', 'id' => 2, 'result' => [
            'tools' => [toolPayload('one')],
            'nextCursor' => 'page-2',
        ]], 200)
        ->push(['jsonrpc' => '2.0', 'id' => 3, 'result' => [
            'tools' => [toolPayload('two')],
        ]], 200);

    // Ignoring the cursor silently offers the model a subset, which reads as the
    // model choosing not to use the missing tool.
    expect(PrismMcp::server('acme')->tools())->toHaveCount(2);
});

it('stops a server that paginates forever', function (): void {
    Http::fake([
        'https://mcp.test/mcp' => function ($request) {
            $payload = json_decode($request->body(), true);

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => $payload['id'],
                'result' => $payload['method'] === 'server/discover'
                    ? discoverResult()
                    : ['tools' => [toolPayload('one')], 'nextCursor' => 'always-another'],
            ]);
        },
    ]);

    expect(fn () => PrismMcp::server('acme')->tools())
        ->toThrow(ProtocolFailure::class, 'past 100 pages');
});
