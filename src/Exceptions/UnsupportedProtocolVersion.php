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

    #[\Override]
    public function code(): string
    {
        return 'unsupported_protocol_version';
    }
}
