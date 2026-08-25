<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

/**
 * Connecting is not consent.
 *
 * A tool description reaches the model as instructions. Handing over a tool list
 * from a server nobody declared trust in is handing a third party a writable
 * slot in your prompt, and doing that as a side effect of a URL appearing in
 * config is not a decision anyone made.
 *
 * So this refuses, and it names both ways out rather than only saying no. The
 * shape is borrowed from Prism core's Perplexity `withTools()` refusal: that
 * provider accepted tools for its entire life and silently never offered them
 * to the model, which read as the model choosing not to call them. A loud
 * refusal is a five-minute fix at integration time. A quiet default is a defect
 * that looks like model behaviour.
 */
class ServerNotTrusted extends McpException
{
    public static function undeclared(string $server): self
    {
        return new self(sprintf(
            "The MCP server [%s] has no trust declaration, so its tools were not offered to the model.\n\n"
            .'Every tool this server publishes — its name, its description, its parameter descriptions and its '
            .'enum values — is text a third party writes and the model reads as instructions. Declare what you '
            ."trust before that happens:\n\n"
            ."    'trust' => ['tools' => ['search_repositories', 'get_file_contents']]\n\n"
            ."or, if you genuinely accept whatever this server publishes today and publishes next week:\n\n"
            ."    'trust' => ['tools' => '*']\n\n"
            .'Fluently: ->trusting([...]) or ->trustingEveryTool().',
            $server,
        ));
    }

    public static function emptyAllowlist(string $server): self
    {
        return new self(sprintf(
            'The MCP server [%s] has an empty tool allowlist. An empty allowlist and no allowlist are different '
            .'mistakes, and neither should pass silently — an empty one hands the model zero tools while looking '
            .'configured. Name the tools you trust, or trust every tool explicitly.',
            $server,
        ));
    }

    #[\Override]
    public function code(): string
    {
        return 'server_not_trusted';
    }
}
