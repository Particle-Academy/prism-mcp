<?php

declare(strict_types=1);

namespace Prism\Mcp\Exceptions;

use RuntimeException;

/**
 * Every failure this package raises carries a stable, machine-readable code.
 *
 * The sentence is for a human and is explicitly OUTSIDE the contract: reword it
 * freely, but never change the code without meaning to. Branch on `code()`,
 * never on `getMessage()`.
 *
 * See prism-parity `docs/decisions/0004-error-codes.md`. Prism core does not do
 * this yet — that is finding F-1, filed rather than reached in and fixed.
 */
abstract class McpException extends RuntimeException
{
    /** The stable code. Never derived from the message. */
    abstract public function code(): string;
}
