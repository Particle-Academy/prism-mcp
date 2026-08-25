<?php

declare(strict_types=1);

namespace Prism\Mcp\Enums;

/**
 * The reserved `_meta` keys `2026-07-28` defines.
 *
 * `protocolVersion` and `clientCapabilities` are REQUIRED on every request;
 * omitting either is a `-32602` from a conforming server. `clientInfo` is a
 * SHOULD.
 */
enum MetaKey: string
{
    case ProtocolVersion = 'io.modelcontextprotocol/protocolVersion';
    case ClientCapabilities = 'io.modelcontextprotocol/clientCapabilities';
    case ClientInfo = 'io.modelcontextprotocol/clientInfo';
    case ServerInfo = 'io.modelcontextprotocol/serverInfo';
    case LogLevel = 'io.modelcontextprotocol/logLevel';
}
