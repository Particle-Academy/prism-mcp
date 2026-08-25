<?php

declare(strict_types=1);

namespace Prism\Mcp\Trust;

use Prism\Mcp\Exceptions\ServerNotTrusted;
use Prism\Mcp\Exceptions\ToolDefinitionChanged;
use Prism\Mcp\Support\ToolDefinition;

/**
 * What a consumer has said, explicitly, that they trust a server to put in front
 * of their model.
 *
 * The default is NOTHING. Not "everything", not "everything with a warning" —
 * an undeclared server refuses at discovery, before a single description has
 * reached a prompt. That is the one decision in this package that a convenience
 * argument will keep attacking, so it is worth saying why it holds:
 *
 * a tool list is not data the model summarises. It is instructions the model
 * follows. Every other input a Laravel application takes from a third party is
 * escaped, validated or bounded before it reaches anything that acts on it, and
 * this one arrives pre-authorised in every framework that ships MCP support.
 *
 * Declaring trust costs one line. Not declaring it costs a prompt-injection
 * surface nobody chose.
 */
class TrustPolicy
{
    /**
     * @param  list<string>|null  $allowedTools  null = undeclared, [] = declared empty
     * @param  array<string, string>  $pins  tool name => expected definition digest
     */
    protected function __construct(
        protected readonly ?array $allowedTools,
        protected readonly bool $everyTool,
        protected readonly array $pins,
    ) {}

    /**
     * The state a server is in when nobody said anything.
     */
    public static function undeclared(): self
    {
        return new self(null, false, []);
    }

    /**
     * `$tools` is `array-key`-indexed rather than a list because callers reach
     * here through `array_filter` on config, which preserves gaps.
     *
     * @param  array<array-key, string>  $tools
     * @param  array<string, string>  $pins
     */
    public static function allowing(array $tools, array $pins = []): self
    {
        return new self(array_values($tools), false, $pins);
    }

    /**
     * @param  array<string, string>  $pins
     */
    public static function everyTool(array $pins = []): self
    {
        return new self(null, true, $pins);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $tools = $config['tools'] ?? null;
        $pins = $config['pins'] ?? [];

        /** @var array<string, string> $pins */
        $pins = is_array($pins) ? array_filter($pins, is_string(...)) : [];

        if ($tools === '*') {
            return self::everyTool($pins);
        }

        if (! is_array($tools)) {
            return new self(null, false, $pins);
        }

        return self::allowing(array_values(array_filter($tools, is_string(...))), $pins);
    }

    public function isDeclared(): bool
    {
        return $this->everyTool || $this->allowedTools !== null;
    }

    /**
     * The same declaration with pins attached.
     *
     * A copy rather than a mutation, because a policy is read by the thing that
     * decides whether a third party may address your model, and something that
     * can be changed after it has been checked is not a decision.
     *
     * @param  array<string, string>  $pins
     */
    public function withPins(array $pins): self
    {
        return new self($this->allowedTools, $this->everyTool, [...$this->pins, ...$pins]);
    }

    /**
     * Fails when nobody declared anything, and separately when someone declared
     * an empty list.
     *
     * They are different mistakes and they deserve different sentences. An empty
     * allowlist is the more dangerous of the two, because it LOOKS configured —
     * the model silently gets zero tools and the run reads as the model choosing
     * not to use any of them, which is precisely the failure mode the Perplexity
     * incident taught this ecosystem to refuse.
     */
    public function assertDeclaredFor(string $server): void
    {
        if ($this->everyTool) {
            return;
        }

        if ($this->allowedTools === null) {
            throw ServerNotTrusted::undeclared($server);
        }

        if ($this->allowedTools === []) {
            throw ServerNotTrusted::emptyAllowlist($server);
        }
    }

    public function allows(string $tool): bool
    {
        return $this->everyTool || in_array($tool, $this->allowedTools ?? [], true);
    }

    /**
     * Pinning is per-tool and opt-in.
     *
     * A tool with no pin passes — otherwise turning pinning on for one tool
     * would refuse every other tool on the server, and a safety feature with
     * that blast radius simply gets turned off.
     */
    public function assertPinHolds(string $server, ToolDefinition $definition): void
    {
        $expected = $this->pins[$definition->name] ?? null;

        if ($expected === null) {
            return;
        }

        $actual = $definition->digest();

        if (! hash_equals($expected, $actual)) {
            throw ToolDefinitionChanged::pinBroken($server, $definition->name, $expected, $actual);
        }
    }

    public function hasPinFor(string $tool): bool
    {
        return isset($this->pins[$tool]);
    }

    /**
     * The tools a consumer named that the server did not offer.
     *
     * Worth surfacing rather than ignoring: a name in an allowlist that matches
     * nothing is either a typo or a tool the server withdrew, and both are
     * things an operator wants to know before wondering why the model never
     * calls it.
     *
     * @param  list<string>  $offered
     * @return list<string>
     */
    public function namedButNotOffered(array $offered): array
    {
        if ($this->everyTool || $this->allowedTools === null) {
            return [];
        }

        return array_values(array_diff($this->allowedTools, $offered));
    }
}
