<?php

declare(strict_types=1);

namespace Prism\Mcp\Contracts;

/**
 * One request out, one response back.
 *
 * `2026-07-28` made the protocol stateless — no `initialize`, no session id, no
 * server-initiated requests — so a transport no longer needs to model a
 * conversation. It needs to send bytes with headers and return bytes, within a
 * bound, and say who it was talking to when that fails.
 *
 * That is a deliberately small surface. The previous era's transports carried
 * session state, an SSE read loop and resumability; reproducing that shape here
 * would be carrying scaffolding for a protocol this client does not speak.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     * @return string The raw response body.
     */
    public function send(string $payload, array $headers = []): string;

    /**
     * A human-readable name for this endpoint, used in error messages.
     *
     * Never the URL with credentials in it — errors get logged.
     */
    public function label(): string;

    public function withTimeout(float $seconds): static;

    public function timeout(): float;
}
