<?php

declare(strict_types=1);

namespace Prism\Mcp\Enums;

/**
 * Headers `2026-07-28` requires on every Streamable HTTP POST.
 *
 * They exist so an HTTP intermediary — a gateway, a WAF, a rate limiter — can
 * route and police MCP traffic without parsing the JSON-RPC body. That is also
 * why `Mcp-Name` and the mirrored `Mcp-Param-*` values are visible to every hop
 * in between, and why the spec warns against annotating secrets for mirroring.
 */
enum RequestHeader: string
{
    case ProtocolVersion = 'MCP-Protocol-Version';
    case Method = 'Mcp-Method';
    case Name = 'Mcp-Name';

    /** Prefix for parameters mirrored out of the tool's input schema. */
    public const PARAM_PREFIX = 'Mcp-Param-';
}
