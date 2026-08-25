<?php

declare(strict_types=1);

namespace Prism\Mcp\Enums;

/**
 * The MCP revisions this client knows about.
 *
 * Only `V2026_07_28` is SPOKEN. The older cases exist so that a server
 * announcing one can be refused by name rather than by "unsupported version" —
 * an error that names both sides is actionable and one that names neither is a
 * support ticket.
 *
 * The split matters more than it looks. `2026-07-28` removed `initialize`
 * outright and made the protocol stateless: every request carries its own
 * protocol version, capabilities and client info in `_meta`. Everything from
 * `2025-11-25` back does the opposite — a stateful handshake, then a session.
 * They are two protocols wearing one name, which is why speaking both is a
 * whole second implementation rather than a compatibility branch, and why the
 * legacy era is recorded as deferred rather than quietly attempted.
 *
 * `prism-php/relay` is the cautionary tale: it hardcoded `2024-11-05`, and five
 * months without a release left it two eras behind with nothing in the package
 * saying so.
 */
enum ProtocolVersion: string
{
    case V2026_07_28 = '2026-07-28';
    case V2025_11_25 = '2025-11-25';
    case V2025_06_18 = '2025-06-18';
    case V2025_03_26 = '2025-03-26';
    case V2024_11_05 = '2024-11-05';

    public const LATEST = self::V2026_07_28;

    /**
     * The revisions this client will actually talk.
     *
     * @return list<string>
     */
    public static function spoken(): array
    {
        return [self::V2026_07_28->value];
    }

    public function isSpoken(): bool
    {
        return in_array($this->value, self::spoken(), true);
    }

    /**
     * Whether this revision uses the stateless per-request metadata era
     * (`2026-07-28` and later) rather than the `initialize` handshake.
     */
    public function isStateless(): bool
    {
        return $this === self::V2026_07_28;
    }
}
