<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

class UnsupportedTransport extends McpException
{
    public static function stdio(string $server): self
    {
        return new self(sprintf(
            'The MCP server [%s] asks for the stdio transport, which this release does not implement. '
            .'stdio is deferred rather than forgotten — see the deferred table in the README. '
            .'It spawns a local child process from a command string, so it lands behind an explicit opt-in '
            .'rather than as a peer of an http URL.',
            $server,
        ));
    }

    public static function named(string $server, string $transport): self
    {
        return new self(sprintf(
            'The MCP server [%s] asks for the [%s] transport. This release speaks `http` only.',
            $server,
            $transport,
        ));
    }

    #[\Override]
    public function code(): string
    {
        return 'unsupported_transport';
    }
}
