<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

use Prism\Mcp\Enums\ProtocolVersion;

/**
 * Naming both sides is the whole point. "Unsupported protocol version" with no
 * numbers in it tells an operator nothing they can act on.
 */
class UnsupportedProtocolVersion extends McpException
{
    /**
     * @param  list<string>  $offered
     */
    public static function between(string $server, array $offered): self
    {
        return new self(sprintf(
            'The MCP server [%s] speaks [%s]. This client speaks [%s]. '
            .'Revisions before %s use the removed `initialize` handshake — a different protocol, not an older '
            .'one — so this is a refusal rather than a downgrade.',
            $server,
            $offered === [] ? 'nothing it would name' : implode(', ', $offered),
            implode(', ', ProtocolVersion::spoken()),
            ProtocolVersion::LATEST->value,
        ));
    }

    /**
     * A server that answers `-32601` for `server/discover`.
     *
     * `2026-07-28` makes `server/discover` a server MUST, so a server reporting
     * the method does not exist is not implementing this revision — almost
     * always because it implements an older one, where discovery did not exist
     * and `initialize` did.
     *
     * The wording reports what happened and names the likely cause rather than
     * asserting it, because -32601 can also come from a proxy or a URL pointing
     * somewhere that is not an MCP endpoint at all. An operator who reads this
     * and finds it was a bad path has still been sent to the right place; one
     * who reads a bare "method not found" goes looking at their own arguments,
     * which is precisely the failure `discover()` exists to prevent.
     */
    public static function notDiscoverable(string $server): self
    {
        return new self(sprintf(
            'The MCP server [%s] answered `server/discover` with JSON-RPC -32601 (method not found). '
            .'That method is a server MUST in %s, so this server is very likely implementing an earlier '
            .'revision — those use the removed `initialize` handshake, which is a different protocol rather '
            .'than an older one, and this client does not speak it. '
            .'(`laravel/mcp` v1.0.0-beta.1 is in exactly this position.) '
            .'If the version is not the cause, check that the URL points at an MCP endpoint.',
            $server,
            ProtocolVersion::LATEST->value,
        ));
    }

    #[\Override]
    public function code(): string
    {
        return 'unsupported_protocol_version';
    }
}
