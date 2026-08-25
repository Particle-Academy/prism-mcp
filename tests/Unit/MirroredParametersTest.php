<?php

declare(strict_types=1);

use Prism\Mcp\Exceptions\MirroredParameterRefused;
use Prism\Mcp\Support\MirroredParameters;

function schemaWith(array $queryExtras, array $extra = []): array
{
    return [
        'type' => 'object',
        'properties' => [
            'query' => ['type' => 'string', ...$queryExtras],
        ],
        ...$extra,
    ];
}

it('mirrors an annotated argument into an Mcp-Param header', function (): void {
    $mirrored = MirroredParameters::fromSchema('search', schemaWith(['x-mcp-header' => 'Region']));

    expect($mirrored->headersFor('search', ['query' => 'us-west1']))
        ->toBe(['Mcp-Param-Region' => 'us-west1']);
});

it('leaves an absent argument out rather than sending an empty header', function (): void {
    $mirrored = MirroredParameters::fromSchema('search', schemaWith(['x-mcp-header' => 'Region']));

    // Absent and empty are different. A header present with no value says
    // something the caller did not.
    expect($mirrored->headersFor('search', []))->toBe([]);
});

it('base64-encodes anything that is not plain printable ascii', function (): void {
    $mirrored = MirroredParameters::fromSchema('search', schemaWith(['x-mcp-header' => 'Region']));

    // The reason this matters: a raw CR/LF in a header value is header
    // injection, and the value here came from the model.
    $headers = $mirrored->headersFor('search', ['query' => "us-west1\r\nX-Injected: yes"]);

    expect($headers['Mcp-Param-Region'])->toStartWith('=?base64?')
        ->and($headers['Mcp-Param-Region'])->not->toContain("\r")
        ->and($headers['Mcp-Param-Region'])->not->toContain("\n");
});

it('refuses a header name that is not a valid token', function (): void {
    expect(fn () => MirroredParameters::fromSchema('search', schemaWith(['x-mcp-header' => 'Bad Header'])))
        ->toThrow(MirroredParameterRefused::class, 'not a valid header name token');
});

it('refuses a number, which the spec excludes', function (): void {
    expect(fn () => MirroredParameters::fromSchema('search', [
        'type' => 'object',
        'properties' => ['score' => ['type' => 'number', 'x-mcp-header' => 'Score']],
    ]))->toThrow(MirroredParameterRefused::class, 'string, integer or boolean');
});

it('refuses an annotation that is not statically reachable', function (): void {
    // Under `items`, whether the property exists depends on the arguments, so
    // the client could not decide up front which headers a call carries. A
    // server that put one here believes it will be honoured, which is why this
    // is a refusal and not a skip.
    expect(fn () => MirroredParameters::fromSchema('search', [
        'type' => 'object',
        'properties' => [
            'tags' => [
                'type' => 'array',
                'items' => ['type' => 'string', 'x-mcp-header' => 'Tag'],
            ],
        ],
    ]))->toThrow(MirroredParameterRefused::class, 'outside the statically reachable properties');
});

it('refuses the same header name twice, case-insensitively', function (): void {
    expect(fn () => MirroredParameters::fromSchema('search', [
        'type' => 'object',
        'properties' => [
            'a' => ['type' => 'string', 'x-mcp-header' => 'Region'],
            'b' => ['type' => 'string', 'x-mcp-header' => 'region'],
        ],
    ]))->toThrow(MirroredParameterRefused::class, 'annotated more than once');
});

it('bounds schema depth', function (): void {
    $schema = ['type' => 'string'];

    for ($i = 0; $i < 40; $i++) {
        $schema = ['type' => 'object', 'properties' => ['nested' => $schema]];
    }

    // A schema comes from a party we do not control, and a deeply nested one is
    // a denial of service before it is anything else.
    expect(fn () => MirroredParameters::fromSchema('search', $schema))
        ->toThrow(MirroredParameterRefused::class, 'nests deeper than 32 levels');
});

it('mirrors a nested property by its full path', function (): void {
    $mirrored = MirroredParameters::fromSchema('search', [
        'type' => 'object',
        'properties' => [
            'filter' => [
                'type' => 'object',
                'properties' => [
                    'region' => ['type' => 'string', 'x-mcp-header' => 'Region'],
                ],
            ],
        ],
    ]);

    expect($mirrored->headersFor('search', ['filter' => ['region' => 'eu-west2']]))
        ->toBe(['Mcp-Param-Region' => 'eu-west2']);
});
