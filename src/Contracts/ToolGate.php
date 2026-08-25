<?php

declare(strict_types=1);

namespace Prism\Mcp\Contracts;

/**
 * "May this actor run this tool, right now?"
 *
 * A contract rather than a hard dependency, and that is the decision rather than
 * an accident. Two candidate mechanisms exist in this ecosystem and they answer
 * different questions:
 *
 *   - Laravel Gates and Policies answer AUTHORIZATION — may this actor perform
 *     this action. That is what a tool call is.
 *   - Fancy's Feature Management System answers ENTITLEMENT — does this account's
 *     plan include this capability, and how much of it has been used. That is a
 *     real MCP question too (which servers may a tenant reach at all), but it is
 *     not the same question, and wiring one to answer the other ends with a
 *     billing plan deciding a security outcome.
 *
 * Neither is required. `laravel-fms` additionally floors at PHP 8.4 and Laravel
 * 13, so depending on it would make this the narrowest-support package in the
 * ecosystem for a capability most consumers will not use.
 *
 * `prism-harness` intends to own tool permissions. When it does, it implements
 * this interface and nothing here changes.
 */
interface ToolGate
{
    /**
     * @param  array<string, mixed>  $arguments  The arguments the model chose.
     */
    public function allows(string $server, string $tool, array $arguments): bool;

    /**
     * Names this gate in the refusal. "denied" with no attribution sends an
     * operator looking in the wrong package.
     */
    public function name(): string;
}
