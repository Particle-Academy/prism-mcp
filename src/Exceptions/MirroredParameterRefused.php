<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

use Prism\Mcp\Support\Legible;

/**
 * `2026-07-28` lets a server annotate an input-schema property with
 * `x-mcp-header`, and a client MUST copy that argument into an `Mcp-Param-*`
 * request header. The spec bounds it tightly — RFC 9110 token names, primitive
 * types only, statically reachable properties only — and requires a client to
 * EXCLUDE from its tool list any tool that breaks those rules.
 *
 * That exclusion is a security property rather than pedantry. The annotation
 * moves a model-supplied value out of the JSON body and into an HTTP header,
 * where every intermediary can read it and where a value carrying CRLF would be
 * header injection. A tool that gets this wrong is dropped, loudly, rather than
 * partially honoured.
 */
class MirroredParameterRefused extends McpException
{
    public static function because(string $tool, string $reason): self
    {
        return new self(sprintf(
            'The MCP tool [%s] was excluded from the tool list: %s. A server may mirror tool arguments into '
            .'request headers with `x-mcp-header`, and the rules on that are narrow because the value ends up '
            .'visible to every hop between here and the server.',
            $tool,
            $reason,
        ));
    }

    /**
     * The same refusal, raised rather than logged, because the consumer named
     * this tool explicitly.
     *
     * Dropping a wildcard-swept tool is a degradation the caller can live with.
     * Dropping a tool they asked for by name and returning successfully is a
     * result that lies about what it is.
     */
    public static function becauseNamed(string $server, string $tool, string $reason): self
    {
        return new self(sprintf(
            'The MCP tool [%s] is in your trust declaration for server [%s], but it cannot be offered to the '
            ."model.\n\n%s\n\n"
            .'Raised rather than skipped because you named this tool. A tool swept up by a wildcard can be '
            .'dropped quietly; one you asked for cannot, or the run completes with the model appearing to '
            .'decline a tool it was never given. Remove it from the allowlist, or ask the server operator to '
            .'fix the annotation.',
            Legible::name($tool),
            $server,
            $reason,
        ));
    }

    #[\Override]
    public function code(): string
    {
        return 'mirrored_parameter_refused';
    }
}
