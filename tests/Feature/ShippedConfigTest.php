<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Prism\Mcp\Facades\PrismMcp;
use Prism\Mcp\Gates\AllowAll;
use Prism\Mcp\Gates\LaravelGate;

/**
 * Decision 0012 — a test that supplies its own configuration never tests the
 * shipped one.
 *
 * Every other test in this suite writes `prism-mcp.servers.*` before reading
 * it, which is correct isolation and a closed loop. These read the PUBLISHED
 * config and prove the values in it reach behaviour, so a wrong key or a wrong
 * default cannot ship green.
 *
 * Each was watched to fail before being kept.
 */
it('merges the published config under the published key', function (): void {
    // Not a spelling check on its own — steps two and three below are what make
    // it one. But if this key is wrong, nothing else in the package is reachable.
    expect(config('prism-mcp'))->toBeArray()
        ->and(config('prism-mcp.defaults'))->toBeArray();

    // And nothing left behind under a name a consumer's own app might own.
    // Decision 0010: config files and keys carry the `prism-` prefix.
    expect(config('mcp'))->toBeNull();
});

it('ships the ability name the ecosystem convention requires', function (): void {
    // Decision 0010: abilities are `<package>.<verb>`. This asserts the SHIPPED
    // default, and then proves it reaches the Gate — an assertion that the
    // default equals a literal would pass even if nothing read it.
    expect(config('prism-mcp.gates.laravel.ability'))->toBe('mcp.call');

    $asked = null;

    // Nullable type hint, so the ability is reachable for a guest. Without it
    // Laravel short-circuits to false and never calls the closure — which would
    // make this row pass for the wrong reason.
    Gate::define('mcp.call', function (?Authenticatable $user, string $server, string $tool, array $args) use (&$asked): bool {
        $asked = $tool;

        return false;
    });

    $gate = app(LaravelGate::class);

    expect($gate->allows('acme', 'search', []))->toBeFalse()
        // Step three: the shipped value reached the thing it configures.
        ->and($asked)->toBe('search')
        ->and($gate->name())->toBe('laravel-gate:mcp.call');
});

it('ships defaults that make a fresh install work without a config file', function (): void {
    // prism-harness shipped a Redis default that broke every install without
    // Redis, and its suite stayed green because every test set the store
    // explicitly. These are the values a consumer gets having published nothing.
    expect(config('prism-mcp.defaults.timeout'))->toBe(30)
        ->and(config('prism-mcp.defaults.max_result_bytes'))->toBe(262_144)
        ->and(config('prism-mcp.defaults.frame_results'))->toBeTrue()
        // `null` means "honour the server's ttlMs", which is the only default
        // that does not guess on the consumer's behalf.
        ->and(config('prism-mcp.defaults.cache_ttl'))->toBeNull()
        // No gate configured means AllowAll — safe only because discovery is
        // already deny-by-default. If that ever changes, this row should too.
        ->and(config('prism-mcp.defaults.gate'))->toBeNull();

    expect(PrismMcp::gate(config('prism-mcp.defaults.gate')))->toBeInstanceOf(AllowAll::class);
});

it('reaches behaviour from the shipped defaults, not from a test-written value', function (): void {
    // A server entry with NOTHING but a url and a trust block. Everything else
    // has to come from the published defaults, which is the case a consumer
    // actually hits and the one every other test in this suite bypasses.
    config()->set('prism-mcp.servers.bare', [
        'url' => 'https://mcp.test/mcp',
        'trust' => ['tools' => '*'],
    ]);

    $config = PrismMcp::server('bare')->config();

    expect($config->timeout)->toBe(30.0)
        ->and($config->maxResultBytes)->toBe(262_144)
        ->and($config->frameResults)->toBeTrue();

    // Step three, again: move the shipped default and watch it arrive.
    config()->set('prism-mcp.defaults.max_result_bytes', 1_024);

    expect(PrismMcp::server('bare')->config()->maxResultBytes)->toBe(1_024);
});

it('ships a servers key that is empty rather than absent', function (): void {
    // An absent key and an empty one behave identically here — until someone
    // adds a server and finds the key was never published, so their entry sits
    // under a heading nothing reads.
    expect(config('prism-mcp.servers'))->toBeArray()->toBeEmpty();
});
