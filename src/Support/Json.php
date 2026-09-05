<?php

declare(strict_types=1);

namespace Prism\Mcp\Support;

use JsonException;
use stdClass;

/**
 * JSON decoding that can keep `{}` and `[]` apart.
 *
 * PHP has one array type, so `json_decode($json, true)` collapses an empty JSON
 * object and an empty JSON array onto the identical value — and re-encodes both
 * as `[]`. That is normally harmless. It stops being harmless the moment the
 * decoded value is HASHED, because a digest is a pin's material: a schema
 * carrying `"properties": {}` digests in PHP as though the server had written
 * `"properties": []`, and no other language agrees. The operator sees a pin that
 * refuses a tool they trust, which is indistinguishable from a rug pull, and the
 * usual response to that is to delete the pin.
 *
 * So the fix is not to guess which empty arrays "meant" an object — PHP cannot
 * know, and a rule that promoted every empty array would turn the entirely
 * ordinary `"required": []` into `{}` and break agreement in the other
 * direction. The fix is to stop discarding the answer in the first place.
 *
 * `decode()` with `$preservingContainerTypes` therefore decodes objects as
 * objects and then flattens them back to arrays EXCEPT where they are empty,
 * which is the only case an array cannot express. An empty JSON object survives
 * as a `stdClass`, so `json_encode` renders it `{}` again.
 *
 * Recorded in prism-parity as finding F-3, and in the envelope's port gaps
 * register as G-20.
 */
final class Json
{
    /**
     * @throws JsonException
     */
    public static function decode(string $raw, bool $preservingContainerTypes = false): mixed
    {
        if (! $preservingContainerTypes) {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }

        return self::flatten(json_decode($raw, false, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * An empty `stdClass` reaching code that expects a map.
     *
     * Callers that only ever wanted a PHP array — everything except the digest —
     * ask for this rather than growing an `instanceof` of their own, so the one
     * place that knows about the sentinel is this file.
     */
    public static function asMap(mixed $value): mixed
    {
        return $value instanceof stdClass && get_object_vars($value) === [] ? [] : $value;
    }

    /**
     * Objects become arrays; EMPTY objects stay objects.
     *
     * Depth is already bounded at 512 by `json_decode` itself, so this recursion
     * cannot be driven deeper than the decode that produced its input.
     */
    private static function flatten(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);

            return $properties === [] ? $value : array_map(self::flatten(...), $properties);
        }

        return is_array($value) ? array_map(self::flatten(...), $value) : $value;
    }
}
