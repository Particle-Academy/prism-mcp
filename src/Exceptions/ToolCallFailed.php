<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

use Prism\Mcp\Support\Legible;

class ToolCallFailed extends McpException
{
    public static function reportedByServer(string $server, string $tool, string $detail): self
    {
        return new self(sprintf(
            'The MCP tool [%s] on server [%s] reported an error: %s',
            Legible::name($tool),
            $server,
            $detail,
        ));
    }

    #[\Override]
    public function code(): string
    {
        return 'tool_call_failed';
    }
}
