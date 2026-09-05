<?php

declare(strict_types=1);

namespace Prism\Mcp\Support;

use Prism\Mcp\Exceptions\ProtocolFailure;
use stdClass;

/**
 * One tool as a server describes it — validated on arrival, and digestible.
 *
 * Everything on this object is attacker-controlled text in the threat model
 * that matters: the description, the title and every string inside the input
 * schema reach the model as instructions. Nothing here sanitises that, because
 * sanitising prose is theatre. What it does is make the definition a stable,
 * comparable value so that a CHANGE to it can be detected — which is the one
 * defence against a rug pull that actually holds.
 */
class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $annotations
     */
    protected function __construct(
        public readonly string $name,
        public readonly ?string $title,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly array $annotations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function from(string $server, array $payload): self
    {
        $name = $payload['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            throw ProtocolFailure::malformed($server, 'a tool in tools/list has no usable name');
        }

        $title = $payload['title'] ?? null;
        $description = $payload['description'] ?? null;

        // A server that sent `{}` here reaches us as the empty-object sentinel
        // `Support\Json` preserves. The rest of this class wants a PHP array, and
        // the emptiness is all `digest()` needs — an empty schema renders `{}`
        // whether it arrived that way or was absent entirely.
        $inputSchema = Json::asMap($payload['inputSchema'] ?? []);
        $annotations = Json::asMap($payload['annotations'] ?? []);

        if (! is_array($inputSchema) || ! is_array($annotations)) {
            throw ProtocolFailure::malformed($server, sprintf('the tool [%s] has a malformed schema', Legible::name($name)));
        }

        return new self(
            name: $name,
            title: is_string($title) ? $title : null,
            // A tool with no description still has to be callable — the model
            // just gets less to go on. Refusing here would reject servers that
            // are merely terse.
            description: is_string($description) ? $description : '',
            inputSchema: $inputSchema,
            annotations: $annotations,
        );
    }

    /**
     * A stable digest of everything the model will see.
     *
     * Deliberately covers name, title, description and input schema and NOT
     * `annotations` or `_meta`. Annotations are hints the spec already tells
     * clients to distrust — MCP's own maintainers published that an untrusted
     * server can claim `readOnlyHint: true` and delete your files anyway — so
     * pinning them would create churn without buying anything.
     *
     * Keys are sorted recursively, so a server reordering its JSON does not
     * read as a rewritten tool.
     *
     * **An empty `inputSchema` is digested as `{}`, never as `[]`.** An MCP
     * input schema is a JSON Schema, and a JSON Schema is an object; PHP is the
     * only language here that cannot say so, and rendering `[]` made every pin
     * for a schemaless tool language-specific. Nested empty maps keep their type
     * because `Support\Json` never discarded it — this line covers the top level,
     * where the value may also be a default this class supplied rather than
     * anything the server sent.
     */
    public function digest(): string
    {
        /** @var array<string, mixed> $material */
        $material = [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema === [] ? new stdClass : $this->inputSchema,
        ];

        return 'sha256:'.substr(
            hash('sha256', (string) json_encode(self::sortDeep($material), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            0,
            32,
        );
    }

    /**
     * Keys are `array-key` rather than `string` deliberately: `{"0": {...}}` is
     * legal JSON and `json_decode` hands back an int key for it. Claiming
     * `array<string, mixed>` here would make the caller's guard look redundant
     * when it is the only thing standing between us and a type error.
     *
     * @return array<array-key, mixed>
     */
    public function properties(): array
    {
        $properties = $this->inputSchema['properties'] ?? [];

        return is_array($properties) ? $properties : [];
    }

    /**
     * @return list<string>
     */
    public function required(): array
    {
        $required = $this->inputSchema['required'] ?? [];

        if (! is_array($required)) {
            return [];
        }

        return array_values(array_filter($required, is_string(...)));
    }

    /**
     * A `stdClass` falls through untouched, which is correct rather than
     * accidental: the only objects in this material are the EMPTY maps
     * `Support\Json` preserved, and an empty map has no keys to sort.
     *
     * @param  mixed  $value
     */
    protected static function sortDeep($value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(self::sortDeep(...), $value);

        if (! array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }
}
