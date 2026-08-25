<?php

declare(strict_types=1);

namespace Prism\Mcp\Support;

/**
 * Make a server-supplied string safe to put in a log line or an error message.
 *
 * A tool name arrives from a party we do not control, and it lands in three
 * places that all assume one value is one line: a `Log::warning`, an exception
 * message, and whatever aggregator reads them afterwards. A name containing
 * `"\nlevel=INFO tool=safe_tool"` forges a second log entry, which is how an
 * audit trail stops being one.
 *
 * Not sanitisation of content — the description still reaches the model
 * verbatim, because that is the point of the framing layer. This is only about
 * the strings this package itself formats INTO its own diagnostics.
 */
final class Legible
{
    private const MAX = 120;

    /**
     * Collapse control characters, and bound the length.
     */
    public static function name(string $value): string
    {
        $collapsed = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)
            ?? preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value)
            ?? '';

        $collapsed = trim($collapsed);

        if ($collapsed === '') {
            return '(unnamed)';
        }

        // A name is an identifier. One long enough to push the rest of a log
        // line out of view is doing something other than identifying.
        return mb_strlen($collapsed) > self::MAX
            ? mb_substr($collapsed, 0, self::MAX).'…'
            : $collapsed;
    }
}
