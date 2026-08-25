<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

class ServerNotConfigured extends McpException
{
    public static function named(string $server): self
    {
        return new self(sprintf(
            'No MCP server named [%s] is configured. Add it under `mcp.servers` in config/prism-mcp.php, '
            .'or connect to a URL directly with PrismMcp::client(\'https://…/mcp\').',
            $server,
        ));
    }

    public static function missingUrl(string $server): self
    {
        return new self(sprintf(
            'The MCP server [%s] has no `url`. An http server needs one; there is no default worth guessing.',
            $server,
        ));
    }

    #[\Override]
    public function code(): string
    {
        return 'server_not_configured';
    }
}
