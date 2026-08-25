<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A canned `2026-07-28` server.
 *
 * Responses are keyed by JSON-RPC method and answered in order, so a test says
 * what the server does rather than how the transport is wired.
 *
 * @param  array<string, list<array<string, mixed>>>  $byMethod
 */
function fakeMcpServer(array $byMethod, string $url = 'https://mcp.test/mcp'): void
{
    $counters = [];

    Http::fake([
        $url => function ($request) use ($byMethod, &$counters) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($request->body(), true);
            $method = $payload['method'] ?? '';
            $id = $payload['id'] ?? 1;

            $counters[$method] = ($counters[$method] ?? -1) + 1;

            $responses = $byMethod[$method] ?? [];

            if ($responses === []) {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => ['code' => -32601, 'message' => "no canned response for [{$method}]"],
                ]);
            }

            // The last canned response repeats, so a test that only cares about
            // the first call does not have to pad the list.
            $index = min($counters[$method], count($responses) - 1);
            $response = $responses[$index];

            if (isset($response['error'])) {
                return Http::response(['jsonrpc' => '2.0', 'id' => $id, 'error' => $response['error']]);
            }

            return Http::response(['jsonrpc' => '2.0', 'id' => $id, 'result' => $response]);
        },
    ]);
}

/**
 * The `server/discover` result a conforming 2026-07-28 server returns.
 *
 * @return array<string, mixed>
 */
function discoverResult(): array
{
    return [
        'supportedVersions' => ['2026-07-28'],
        'capabilities' => ['tools' => (object) []],
        'instructions' => null,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function toolPayload(string $name, array $overrides = []): array
{
    return [
        'name' => $name,
        'description' => "Does {$name}.",
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'What to look for.'],
            ],
            'required' => ['query'],
        ],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $trust
 */
function configureServer(array $trust, string $name = 'acme', string $url = 'https://mcp.test/mcp'): void
{
    config()->set("prism-mcp.servers.{$name}", [
        'transport' => 'http',
        'url' => $url,
        'trust' => $trust,
    ]);
}
