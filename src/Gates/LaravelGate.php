<?php

declare(strict_types=1);

namespace Prism\Mcp\Gates;

use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Prism\Mcp\Contracts\ToolGate;

/**
 * Laravel's own Gate — the framework's answer to "may this actor do this".
 *
 * Asks one ability, `mcp.call` (decision 0010's `<package>.<verb>`), with the
 * server, tool and arguments as
 * context, rather than deriving an ability name per tool. Deriving would mean
 * the set of abilities an application must define changes whenever a third-party
 * server adds a tool, and an ability nobody defined is denied by default — so
 * the server would be able to break your authorization by publishing something.
 *
 *     Gate::define('mcp.call', function (?User $user, string $server, string $tool, array $args) {
 *         return $user?->can('use-external-tools') ?? false;
 *     });
 */
class LaravelGate implements ToolGate
{
    /**
     * The ability has no default here ON PURPOSE.
     *
     * A default would be a second copy of a value that already lives in
     * `config/prism-mcp.php`, and duplicated values drift — that is decision
     * 0003's argument, and this repository has watched it happen elsewhere. The
     * config file is the single source; the service provider reads it and passes
     * it in. Anyone constructing this directly says which ability they mean.
     */
    public function __construct(
        protected readonly GateContract $gate,
        protected readonly string $ability,
    ) {}

    #[\Override]
    public function allows(string $server, string $tool, array $arguments): bool
    {
        return $this->gate->allows($this->ability, [$server, $tool, $arguments]);
    }

    #[\Override]
    public function name(): string
    {
        return 'laravel-gate:'.$this->ability;
    }
}
