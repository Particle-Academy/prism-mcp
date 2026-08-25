<?php

declare(strict_types=1);

namespace Prism\Mcp\Gates;

use Prism\Mcp\Contracts\ToolGate;

/**
 * The default gate, and the reason it is safe to be permissive.
 *
 * This looks like the wrong default until you notice where the real check
 * already happened: nothing reaches a gate that a consumer has not already
 * named in a trust declaration. Trust is the mandatory step; the gate is the
 * second, per-actor one, and requiring both to be configured before a package
 * does anything would make the strict default so tiresome that it gets turned
 * off wholesale.
 *
 * So: discovery is deny-by-default and refuses loudly, invocation is
 * allow-by-default among tools already declared. Configure a gate when "which
 * tools exist" and "who may run them" have different answers — which they do as
 * soon as more than one kind of user shares an application.
 */
class AllowAll implements ToolGate
{
    #[\Override]
    public function allows(string $server, string $tool, array $arguments): bool
    {
        return true;
    }

    #[\Override]
    public function name(): string
    {
        return 'allow-all';
    }
}
