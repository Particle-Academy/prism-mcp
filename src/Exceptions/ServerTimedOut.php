<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

use Throwable;

/**
 * Its own type, separate from TransportFailure, because a caller wants to
 * branch on it: a timeout is retryable and a 404 is not.
 *
 * A slow server must never stall a generation. Every call this package makes is
 * bounded, and the bound failing is a named error rather than a hung worker.
 */
class ServerTimedOut extends McpException
{
    public static function after(string $server, float $seconds, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'The MCP server [%s] did not answer within %ss. Raise `timeout` for that server if it is '
                .'legitimately slow — but a generation should not wait on it either way.',
                $server,
                rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.'),
            ),
            0,
            $previous,
        );
    }

    #[\Override]
    public function code(): string
    {
        return 'server_timed_out';
    }
}
