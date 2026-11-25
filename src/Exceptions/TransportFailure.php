<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

use Throwable;

class TransportFailure extends McpException
{
    public static function unreachable(string $server, string $detail, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('The MCP server [%s] could not be reached: %s', $server, $detail),
            0,
            $previous,
        );
    }

    public static function status(string $server, int $status): self
    {
        return new self(sprintf(
            'The MCP server [%s] answered HTTP %d.',
            $server,
            $status,
        ));
    }

    /**
     * The server answered with a redirect to a different scheme, host or port.
     * Neither URL is in the message: the configured one may carry a credential.
     */
    public static function redirected(string $server): self
    {
        return new self(sprintf(
            'The MCP server [%s] answered with a redirect to a different origin; it was not followed.',
            $server,
        ));
    }

    /**
     * The HTTP client gave up on the exchange: a Location it could not parse,
     * a scheme it would not follow, too many redirects. The client's message
     * stays on the previous exception only, because it can carry a URL.
     */
    public static function exchange(string $server, Throwable $previous): self
    {
        return new self(
            sprintf('The MCP server [%s] could not be read: the HTTP exchange failed.', $server),
            0,
            $previous,
        );
    }

    /**
     * A timeout bounds how long a server may take. This bounds how much it may
     * send — the other half, and the one nothing else covers, because the body
     * is decoded into memory before any tool-level check can look at it.
     */
    public static function oversized(string $server, int $bytes, int $limit): self
    {
        return new self(sprintf(
            'The MCP server [%s] sent a %s-byte response, over the %s-byte transport limit.',
            $server,
            number_format($bytes),
            number_format($limit),
        ));
    }

    #[\Override]
    public function code(): string
    {
        return 'transport_failure';
    }
}
