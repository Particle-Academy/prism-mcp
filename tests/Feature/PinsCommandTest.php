<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * `prism-mcp:pins`.
 *
 * Replaces a tinker loop the README used to ask people to type out. Pinning is
 * the one defence against a rewritten tool that actually holds, and a defence
 * whose first step is "open a REPL and write a foreach" is one people skip.
 */
beforeEach(function (): void {
    configureServer(['tools' => '*']);
});

/**
 * @param  list<array<string, mixed>>  $tools
 */
function serverPublishing(array $tools): void
{
    fakeMcpServer([
        'server/discover' => [discoverResult()],
        'tools/list' => [['tools' => $tools]],
    ]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function runPins(array $arguments): array
{
    // Artisan::call rather than $this->artisan(): the PendingCommand assertions
    // do not populate Artisan::output(), and these tests are about what the
    // command PRINTS.
    return [Artisan::call('prism-mcp:pins', $arguments), Artisan::output()];
}

it('prints a digest per tool and a trust block to paste', function (): void {
    serverPublishing([
        ['name' => 'search', 'description' => 'Search repositories', 'inputSchema' => ['type' => 'object']],
        ['name' => 'read_file', 'description' => 'Read a file', 'inputSchema' => ['type' => 'object']],
    ]);

    [$code, $output] = runPins(['server' => 'acme']);

    expect($code)->toBe(0)
        ->and($output)->toContain('sha256:')
        ->and($output)->toContain("'tools' => ['search', 'read_file'],")
        ->and($output)->toContain("'search' => 'sha256:")
        ->and($output)->toContain("'read_file' => 'sha256:")
        // The digest is a promise about a definition, not about its safety.
        ->and($output)->toContain('Read a description before you pin it');
});

it('does not require the tools to be trusted first', function (): void {
    // The command exists to be run BEFORE you decide what to trust, so
    // requiring a trust declaration would make it useless for its only purpose.
    // This configures an EMPTY allowlist — which every other path in the
    // package refuses loudly — and expects the digests anyway.
    configureServer(['tools' => []]);

    serverPublishing([
        ['name' => 'search', 'description' => 'Search repositories', 'inputSchema' => ['type' => 'object']],
    ]);

    [$code, $output] = runPins(['server' => 'acme']);

    expect($code)->toBe(0)->and($output)->toContain('sha256:');
});

it('strips terminal escapes from server-authored text', function (): void {
    // Everything printed except the digest was written by the party being
    // inspected. A terminal renders ANSI escapes, so a description carrying
    // them could repaint this table — move the cursor, recolour a line, hide a
    // row. The output of a command whose whole job is "look at this before you
    // trust it" must not be paintable by whoever is being looked at.
    serverPublishing([[
        'name' => 'search',
        'description' => "Harmless\e[2K\e[1A\e[31m pretending to be a warning",
        'inputSchema' => ['type' => 'object'],
    ]]);

    [, $output] = runPins(['server' => 'acme']);

    expect($output)->not->toContain("\e[2K")
        ->and($output)->not->toContain("\e[1A")
        ->and($output)->toContain('Harmless');
});

it('says when a named tool is not published rather than quietly returning fewer', function (): void {
    // Silently returning fewer rows than were asked for is how somebody pins
    // three of four tools and believes they pinned all four.
    serverPublishing([
        ['name' => 'search', 'description' => 'Search', 'inputSchema' => ['type' => 'object']],
    ]);

    [$code, $output] = runPins(['server' => 'acme', '--tool' => ['search', 'delete_everything']]);

    expect($code)->toBe(0)
        ->and($output)->toContain('does not publish a tool named')
        ->and($output)->toContain('delete_everything');
});

it('emits json when asked', function (): void {
    serverPublishing([
        ['name' => 'search', 'description' => 'Search', 'inputSchema' => ['type' => 'object']],
    ]);

    [, $output] = runPins(['server' => 'acme', '--json' => true]);

    $decoded = json_decode(trim($output), true);

    expect($decoded)->toBeArray()
        ->and($decoded['search'])->toStartWith('sha256:');
});

it('reports an unknown server by name', function (): void {
    expect(runPins(['server' => 'nope'])[0])->toBe(1);
});
