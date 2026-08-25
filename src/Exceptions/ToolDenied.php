<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

use Prism\Mcp\Support\Legible;

/**
 * The gate said no at CALL time.
 *
 * Distinct from ServerNotTrusted, which fires at discovery time. Trust decides
 * whether the model is ever told a tool exists; the gate decides whether a
 * particular actor may run it right now. A tool can be trusted and still denied,
 * and the two failures want different fixes.
 */
class ToolDenied extends McpException
{
    public static function byGate(string $server, string $tool, string $gate): self
    {
        return new self(sprintf(
            'The [%s] gate refused the MCP tool [%s] on server [%s].',
            $gate,
            Legible::name($tool),
            $server,
        ));
    }

    #[\Override]
    public function code(): string
    {
        return 'tool_denied';
    }
}
