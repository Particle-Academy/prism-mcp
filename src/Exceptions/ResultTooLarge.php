<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

use Prism\Mcp\Support\Legible;

/**
 * A stability failure before it is a security one.
 *
 * An unbounded tool result goes straight into the model's context. One server
 * answering with 400KB evicts the system prompt, and the run then fails
 * somewhere unrelated with an error naming a token count — three layers from the
 * thing the caller can act on. It is also unbounded cost: a remote party
 * choosing how many tokens you pay for.
 *
 * Refusing at the boundary fails where the fault is (pattern 07). Truncating
 * would be worse: the model would receive a sentence cut mid-word and treat it
 * as the whole answer, with nothing anywhere saying so.
 */
class ResultTooLarge extends McpException
{
    public static function from(string $server, string $tool, int $bytes, int $limit): self
    {
        return new self(sprintf(
            'The MCP tool [%s] on server [%s] returned %s bytes, over the %s-byte limit for that server. '
            .'Refused rather than truncated: a silently cut result reads to the model as a complete one. '
            .'Raise `max_result_bytes` for that server if it is legitimately this verbose.',
            Legible::name($tool),
            $server,
            number_format($bytes),
            number_format($limit),
        ));
    }

    #[\Override]
    public function code(): string
    {
        return 'result_too_large';
    }
}
