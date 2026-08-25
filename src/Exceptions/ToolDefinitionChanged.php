<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

use Prism\Mcp\Support\Legible;

/**
 * The asymmetry that makes a remote tool different from a local one.
 *
 * A tool the application wrote changes when someone deploys it. A tool a third
 * party publishes changes whenever they like, between two calls, with no signal
 * on this side at all. A server can ship a benign `search` tool, wait to be
 * trusted, and then rewrite its description into an instruction — the attack the
 * MCP security literature calls a rug pull.
 *
 * Pinning is opt-in because it needs a digest someone deliberately recorded, and
 * a pin that drifted silently would be worse than no pin. When it is on and the
 * definition moves, this refuses rather than calling the new tool.
 */
class ToolDefinitionChanged extends McpException
{
    public static function pinBroken(string $server, string $tool, string $expected, string $actual): self
    {
        return new self(sprintf(
            "The MCP tool [%s] on server [%s] no longer matches its pinned definition.\n\n"
            ."  pinned: %s\n"
            ."  served: %s\n\n"
            .'Its name, description, or input schema changed since the pin was recorded. Review what the server '
            .'now publishes — the description is what the model reads as instructions — and update the pin '
            .'deliberately once you have. This refuses rather than reports, because a legitimately changed '
            .'definition and a rewritten one are the same bytes.',
            Legible::name($tool),
            $server,
            $expected,
            $actual,
        ));
    }

    #[\Override]
    public function code(): string
    {
        return 'tool_definition_changed';
    }
}
