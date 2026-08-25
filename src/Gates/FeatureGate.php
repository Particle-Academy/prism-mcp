<?php

declare(strict_types=1);

namespace Prism\Mcp\Gates;

use Illuminate\Contracts\Container\Container;
use Prism\Mcp\Contracts\ToolGate;
use Prism\Mcp\Exceptions\ServerNotConfigured;

/**
 * Fancy's Feature Management System, adapted.
 *
 * FMS answers ENTITLEMENT — "is this subject granted this feature" — and its own
 * contract says so out loud: `isEntitled()` exists as an explicit alias
 * precisely so a call site declares which of the two questions it is asking.
 *
 * That makes it a good fit for the entitlement-shaped MCP question — which
 * servers, and which classes of tool, a given tenant or plan may reach at all —
 * and a poor fit for the authorization-shaped one, "may this particular actor
 * run `delete_repository` right now". Using it for the second means a billing
 * plan decides a security outcome, and the day someone's subscription lapses
 * mid-incident is the day that becomes obvious. Use `LaravelGate` for that, or
 * both: they compose.
 *
 * FMS is a `suggest`, not a `require`. It floors at PHP 8.4 and Laravel 13,
 * while everything else in this ecosystem supports PHP 8.2+ and Laravel 12+, so
 * depending on it would make this the narrowest-support package in the set for
 * a capability most consumers will not reach for. Resolved from the container at
 * call time, and refused clearly when absent.
 */
class FeatureGate implements ToolGate
{
    /** The FMS binding. Referenced as a string so this file loads without FMS. */
    protected const MANAGER = 'ParticleAcademy\\Fms\\Contracts\\FeatureManagerInterface';

    /**
     * No defaults, for the same reason as LaravelGate: `config/prism-mcp.php`
     * is the single source for these and a constructor default would be a
     * second copy of them, free to drift.
     */
    public function __construct(
        protected readonly Container $container,
        protected readonly string $feature,
        protected readonly bool $perServer,
    ) {}

    #[\Override]
    public function allows(string $server, string $tool, array $arguments): bool
    {
        if (! $this->container->bound(self::MANAGER)) {
            throw new ServerNotConfigured(
                'The `feature` gate needs particle-academy/laravel-fms, which is not installed. '
                .'Install it, or point `gate` at `laravel` (Laravel Gates) or a gate of your own. '
                .'FMS requires PHP 8.4 and Laravel 13, which is why it is suggested rather than required.'
            );
        }

        $manager = $this->container->make(self::MANAGER);

        // Entitlement is asked per SERVER by default, not per tool. A tool name
        // is chosen by the third party, so keying entitlement on it would let a
        // server invent a feature key your plan happens to grant.
        $key = $this->perServer
            ? sprintf('%s.%s', $this->feature, $server)
            : $this->feature;

        // `isEntitled` is FMS's own explicit name for the entitlement read,
        // added in 0.11.0 precisely so a call site declares which question it
        // is asking. Untyped here because the package is optional.
        return (bool) $manager->isEntitled($key);
    }

    #[\Override]
    public function name(): string
    {
        return 'fms:'.$this->feature;
    }
}
