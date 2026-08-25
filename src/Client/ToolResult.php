<?php

declare(strict_types=1);

namespace Prism\Mcp\Client;

use Prism\Mcp\Exceptions\ProtocolFailure;

/**
 * The result of one `tools/call`.
 *
 * `isError` is part of the RESULT, not a JSON-RPC error — a tool that fails is
 * a successful protocol exchange reporting a failed tool. Conflating the two is
 * how a tool failure surfaces as a transport problem.
 */
class ToolResult
{
    /**
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>|null  $structuredContent
     */
    protected function __construct(
        public readonly array $content,
        public readonly bool $isError,
        public readonly ?array $structuredContent,
    ) {}

    /**
     * @param  array<string, mixed>  $result
     */
    public static function from(string $server, array $result): self
    {
        $content = $result['content'] ?? [];
        $isError = $result['isError'] ?? false;
        $structured = $result['structuredContent'] ?? null;

        if (! is_array($content)) {
            throw ProtocolFailure::malformed($server, 'a tools/call result has a non-array `content`');
        }

        return new self(
            content: array_values(array_filter($content, is_array(...))),
            // A non-boolean `isError` is treated as an error rather than
            // ignored: a server that sends `"true"` means it. A JSON null has
            // already collapsed to false through the `??` above.
            isError: $isError !== false,
            structuredContent: is_array($structured) ? $structured : null,
        );
    }

    /**
     * Every text part, joined.
     *
     * Non-text parts — images, audio, resource links — are named rather than
     * dropped. Silently discarding half a result would leave the model
     * answering from an excerpt while believing it had the whole thing.
     */
    public function text(): string
    {
        $parts = [];

        foreach ($this->content as $item) {
            $type = $item['type'] ?? null;
            $text = $item['text'] ?? null;

            if ($type === 'text' && is_string($text)) {
                $parts[] = $text;

                continue;
            }

            $parts[] = sprintf(
                '[%s content omitted: this release passes text through only]',
                is_string($type) ? $type : 'unknown',
            );
        }

        return implode("\n", $parts);
    }
}
