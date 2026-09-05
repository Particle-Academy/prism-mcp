<?php

declare(strict_types=1);

namespace Prism\Mcp\Tools;

use Prism\Mcp\Client\Client;
use Prism\Mcp\Contracts\ToolGate;
use Prism\Mcp\Exceptions\ToolCallFailed;
use Prism\Mcp\Exceptions\ToolDenied;
use Prism\Mcp\Support\Json;
use Prism\Mcp\Support\MirroredParameters;
use Prism\Mcp\Support\ToolDefinition;
use Prism\Mcp\Trust\ResultGuard;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool;

/**
 * A tool on somebody else's server, wearing Prism's `Tool` so the rest of Prism
 * cannot tell the difference — and one layer that makes sure the DIFFERENCE is
 * still enforced.
 *
 *     $tools = PrismMcp::server('github')->tools();
 *
 *     Prism::text()->withTools($tools)->withPrompt($question)->asText();
 *
 * Subclassing `Tool` rather than wrapping it is deliberate. Prism's tool loop
 * type-hints `Tool`, so anything else would need core to change, and core is a
 * provider API shuttle that does not grow opinions for satellites.
 *
 * Every call passes three checks that a local tool does not need:
 *
 *   1. The gate, because trust decided the tool may EXIST and the gate decides
 *      whether this actor may run it now.
 *   2. Mirrored parameters, recomputed per call, because their values come from
 *      the model and land in HTTP headers.
 *   3. The result guard, because what comes back re-enters the model's context.
 */
class RemoteTool extends Tool
{
    public function __construct(
        protected readonly Client $client,
        protected readonly ToolDefinition $definition,
        protected readonly ToolGate $gate,
        protected readonly ResultGuard $guard,
        protected readonly string $prefixedName,
    ) {
        parent::__construct();

        $this->as($prefixedName)->for($this->definition->description);

        $required = $this->definition->required();

        foreach ($this->definition->properties() as $name => $schema) {
            // `Json::asMap` because a property declared as `{}` — "any value" —
            // arrives as the empty-object sentinel the digest needs. Without it
            // the parameter would be dropped from the tool and the model would
            // simply never be offered it, which is the silent kind of loss.
            $schema = Json::asMap($schema);

            if (! is_string($name) || ! is_array($schema)) {
                continue;
            }

            // RawSchema, not a mapped Prism schema. A server's input schema is
            // arbitrary JSON Schema 2020-12 — `2026-07-28` loosened it further —
            // and re-expressing it in Prism's schema vocabulary would silently
            // drop every keyword that vocabulary has no word for. Passing it
            // through means the model sees what the server actually published.
            $this->withParameter(new RawSchema($name, $schema), in_array($name, $required, true));
        }
    }

    public function definition(): ToolDefinition
    {
        return $this->definition;
    }

    public function serverName(): string
    {
        return $this->client->server()->name;
    }

    /** The tool's name on the server, before namespacing. */
    public function remoteName(): string
    {
        return $this->definition->name;
    }

    /**
     * @param  string|int|float|bool|array<string, mixed>  $args
     */
    public function __invoke(...$args): string
    {
        /** @var array<string, mixed> $arguments */
        $arguments = $this->normalise($args);

        $server = $this->client->server();

        if (! $this->gate->allows($server->name, $this->definition->name, $arguments)) {
            throw ToolDenied::byGate($server->name, $this->definition->name, $this->gate->name());
        }

        $headers = MirroredParameters::fromSchema($this->definition->name, $this->definition->inputSchema)
            ->headersFor($this->definition->name, $arguments);

        $result = $this->client->callTool($this->definition->name, $arguments, $headers);

        if ($result->isError) {
            // A failing tool is not a failing package. Raised as a typed
            // exception so Prism's own tool error handling can turn it into a
            // ToolError the model can read and retry from, rather than killing
            // the run.
            //
            // And BECAUSE the model reads it, the error text goes through the
            // same guard as a successful one. It is the identical channel from
            // an attacker's point of view — an unguarded error path would be a
            // hole straight through the framing and the size cap, reachable by
            // any server willing to set `isError`.
            throw ToolCallFailed::reportedByServer(
                $server->name,
                $this->definition->name,
                $this->guard->guard($server->name, $this->definition->name, $result->text()),
            );
        }

        return $this->guard->guard($server->name, $this->definition->name, $result->text());
    }

    /**
     * Prism spreads named arguments, so `$args` is normally already an
     * associative array. Positional calls are mapped back onto declared
     * parameter names in declaration order rather than being passed through — a
     * positional argument means nothing to a JSON-RPC `arguments` object.
     *
     * @param  array<int|string, mixed>  $args
     * @return array<string, mixed>
     */
    protected function normalise(array $args): array
    {
        if ($args === []) {
            return [];
        }

        if (! array_is_list($args)) {
            /** @var array<string, mixed> $args */
            return $args;
        }

        // A single array argument is the whole argument bag.
        if (count($args) === 1 && is_array($args[0]) && ! array_is_list($args[0])) {
            /** @var array<string, mixed> $bag */
            $bag = $args[0];

            return $bag;
        }

        $names = array_keys($this->parameters());
        $mapped = [];

        foreach ($args as $index => $value) {
            if (isset($names[$index])) {
                $mapped[$names[$index]] = $value;
            }
        }

        return $mapped;
    }
}
