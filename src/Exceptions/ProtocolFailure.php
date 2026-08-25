<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

class ProtocolFailure extends McpException
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        string $message,
        public readonly ?int $rpcCode = null,
        public readonly ?array $data = null,
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $server, string $detail): self
    {
        return new self(sprintf(
            'The MCP server [%s] sent something that is not a JSON-RPC 2.0 response: %s',
            $server,
            $detail,
        ));
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function rpcError(string $server, int $rpcCode, string $message, ?array $data = null): self
    {
        return new self(
            sprintf('The MCP server [%s] returned JSON-RPC error %d: %s', $server, $rpcCode, $message),
            $rpcCode,
            $data,
        );
    }

    #[\Override]
    public function code(): string
    {
        return 'protocol_failure';
    }
}
