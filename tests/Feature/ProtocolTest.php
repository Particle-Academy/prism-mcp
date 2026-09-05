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

it('reads -32601 on server/discover as a version signal, not a bad argument', function (): void {
    // The other half of the version story, and the half that was missing.
    // -32022 can only come from a server that KNOWS about 2026-07-28 and is
    // declining it. A server on an earlier revision has never heard of
    // `server/discover` and answers "method not found" — which used to fall
    // through as a raw protocol failure and send the operator hunting through
    // their own configuration. `laravel/mcp` v1.0.0-beta.1 does exactly this.
    fakeMcpServer([
        'server/discover' => [[
            'error' => [
                'code' => -32601,
                'message' => 'The method [server/discover] was not found.',
            ],
        ]],
    ]);

    expect(fn () => PrismMcp::server('acme')->tools())
        ->toThrow(UnsupportedProtocolVersion::class, 'earlier revision');
});

it('still reports an unrelated -32601 as itself', function (): void {
    // The inference is scoped to `server/discover`, where the spec makes the
    // method a MUST. A method-not-found on anything else says nothing about
    // the revision, and dressing it up as a version problem would be the
    // guess this client is careful not to make.
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [[
            'error' => [
                'code' => -32601,
                'message' => 'The method [tools/list] was not found.',
            ],
        ]],
    ]);

    expect(fn () => PrismMcp::server('acme')->tools())
        ->toThrow(ProtocolFailure::class, 'tools/list');
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

it('keeps an empty MAP apart from an empty LIST across the wire, because a digest is a pin', function (): void {
    // The half a corpus runner cannot prove on its own. `tests/Unit/ToolDigestCorpusTest`
    // hands `ToolDefinition::from()` a payload THIS repository decoded, so it
    // says nothing about what a server's JSON does on the way in — and PHP's
    // ordinary assoc decode collapses `{}` onto `[]`, which is the whole defect.
    // This row runs a real `tools/list` response through the transport and
    // asserts the digest equals the value the other two languages recorded for
    // the same tool, so a pin computed here transfers.
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => [[
            'name' => 'search',
            'description' => 'd',
            // `(object) []` so the fake server puts `{}` on the wire, which is
            // what a zero-argument tool's schema actually looks like.
            'inputSchema' => ['type' => 'object', 'properties' => (object) []],
        ]]]],
    ]);

    $corpus = json_decode((string) file_get_contents(__DIR__.'/../fixtures/mcp-tool-digest.json'), true);
    $expected = null;

    foreach ($corpus['cases'] as $case) {
        if ($case['id'] === 'dig-0003') {
            $expected = $case['digest']['ts'];
        }
    }

    expect(PrismMcp::server('acme')->tools()[0]->definition()->digest())->toBe($expected);
});

it('still offers a property whose schema is an empty object', function (): void {
    // The consumer half of the same change. An empty property schema means "any
    // value" and is legal JSON Schema; keeping it distinguishable from `[]` must
    // not cost the model the parameter, because a dropped parameter is the
    // silent kind of loss — the tool is still offered, just without the argument
    // it needs.
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => [[
            'name' => 'search',
            'description' => 'd',
            'inputSchema' => ['type' => 'object', 'properties' => ['anything' => (object) []]],
        ]]]],
    ]);

    expect(PrismMcp::server('acme')->tools()[0]->parametersAsArray())->toHaveKey('anything');
});
