<?php

declare(strict_types=1);

use Prism\Mcp\Exceptions\MirroredParameterRefused;
use Prism\Mcp\Facades\PrismMcp;

/**
 * The exception, and the boundary where it meets the rule it excepts.
 *
 * The ecosystem rule (pattern 03, decision 0011) is that something which cannot
 * be done THROWS and never degrades silently. This package carves out one
 * exception: a tool whose `x-mcp-header` annotations break the spec is DROPPED,
 * because the spec makes that exclusion a client MUST and one malformed tool
 * should not make a healthy server unusable.
 *
 * Decision 0013 is the reason this file exists rather than a single row next to
 * the others. Testing the drop under a wildcard proves the exception holds where
 * nothing threatened the rule. The case that matters is the crossing.
 */
function serverOffering(array $tools): void
{
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => $tools]],
    ]);
}

function malformedTool(string $name): array
{
    return toolPayload($name, [
        'inputSchema' => [
            'type' => 'object',
            // `number` is excluded by the spec: a client MUST drop this tool.
            'properties' => ['score' => ['type' => 'number', 'x-mcp-header' => 'Score']],
        ],
    ]);
}

it('drops a malformed tool swept up by a wildcard', function (): void {
    // The exception, at its centre. The consumer asked for whatever the server
    // offers; one fewer is still an answer to that question.
    serverOffering([toolPayload('good'), malformedTool('bad')]);
    configureServer(['tools' => '*']);

    $names = array_map(fn ($t): string => $t->name(), PrismMcp::server('acme')->tools());

    expect($names)->toBe(['mcp__acme__good']);
});

it('THROWS when the malformed tool is one the consumer named', function (): void {
    // The boundary. They asked for THIS tool by name. Returning successfully
    // without it is the Perplexity `withTools()` failure exactly: a run that
    // completes, with the model appearing to decline a tool it never had.
    serverOffering([toolPayload('good'), malformedTool('bad')]);
    configureServer(['tools' => ['good', 'bad']]);

    $refusal = null;

    try {
        PrismMcp::server('acme')->tools();
    } catch (MirroredParameterRefused $e) {
        $refusal = $e;
    }

    expect($refusal)->not->toBeNull()
        ->and($refusal->code())->toBe('mirrored_parameter_refused')
        ->and($refusal->getMessage())->toContain('in your trust declaration');
});

it('does not throw for a malformed tool the consumer never named', function (): void {
    // The other side of the same boundary: naming `good` must not turn an
    // unrelated malformed tool into an error. A safety check with that blast
    // radius is one somebody disables.
    serverOffering([toolPayload('good'), malformedTool('bad')]);
    configureServer(['tools' => ['good']]);

    expect(PrismMcp::server('acme')->tools())->toHaveCount(1);
});

it('makes an exclusion observable in the result, not only in a log', function (): void {
    // Decision 0011: degrading silently is acceptable only when the degradation
    // is visible in the result. A log line is not the result — nobody reads it
    // during the run, and the caller otherwise holds a shorter array with no way
    // to tell that is what they got.
    serverOffering([toolPayload('good'), malformedTool('bad')]);
    configureServer(['tools' => '*']);

    $client = PrismMcp::server('acme')->client();
    $client->definitions();

    expect($client->excluded())->toHaveKey('bad')
        ->and($client->excluded()['bad'])->toContain('excluded from the tool list');
});

it('still excludes a malformed tool when the list came from cache', function (): void {
    // The exclusion is recomputed on hydration rather than baked into what was
    // stored. A cache that persisted the DECISION rather than the payload would
    // let a warm cache serve a tool the cold path refuses.
    serverOffering([toolPayload('good'), malformedTool('bad')]);
    configureServer(['tools' => '*']);

    PrismMcp::server('acme')->tools();

    $client = PrismMcp::server('acme')->client();

    expect($client->definitions())->toHaveCount(1)
        ->and($client->excluded())->toHaveKey('bad');
});
