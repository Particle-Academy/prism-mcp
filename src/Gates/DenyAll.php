<?php

declare(strict_types=1);

namespace Prism\Mcp\Gates;

use Prism\Mcp\Contracts\ToolGate;

/**
 * Refuses everything.
 *
 * Useful for a kill switch — flip one config key and every remote tool call in
 * the application stops, without deleting server configuration and without a
 * deploy. That is worth having on the day a server you consume is compromised.
 */
class DenyAll implements ToolGate
{
    #[\Override]
    public function allows(string $server, string $tool, array $arguments): bool
    {
        return false;
    }

    #[\Override]
    public function name(): string
    {
        return 'deny-all';
    }
}
