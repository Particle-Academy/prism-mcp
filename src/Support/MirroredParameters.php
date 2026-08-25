<?php

declare(strict_types=1);

namespace Prism\Mcp\Support;

use Prism\Mcp\Enums\RequestHeader;
use Prism\Mcp\Exceptions\MirroredParameterRefused;

/**
 * `x-mcp-header` — the `2026-07-28` annotation that mirrors a tool argument into
 * an `Mcp-Param-*` request header so gateways can route on it without parsing
 * the body.
 *
 * A client MUST support this, and MUST exclude a tool whose annotations break
 * the rules from its tool list. Both halves are implemented here, and the
 * exclusion is the half that matters: the annotation moves a MODEL-SUPPLIED
 * value into an HTTP header. A server that could get an unvalidated value there
 * gets header injection, and every intermediary between here and the server can
 * read whatever lands in it.
 *
 * The spec's own warning is worth repeating in code: servers SHOULD NOT annotate
 * secrets for mirroring. This client cannot tell a secret from a region name, so
 * it enforces the shape and leaves the judgement documented.
 */
class MirroredParameters
{
    public const ANNOTATION = 'x-mcp-header';

    /** RFC 9110 token. Anything outside it is not a legal header name. */
    private const TOKEN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/D';

    /** Primitive schema types the spec allows. `number` is deliberately absent. */
    private const TYPES = ['string', 'integer', 'boolean'];

    /**
     * @param  array<string, array{path: list<string>, type: string}>  $parameters  header name => descriptor
     */
    protected function __construct(protected readonly array $parameters) {}

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, mixed>  $inputSchema
     *
     * @throws MirroredParameterRefused
     */
    public static function fromSchema(string $tool, array $inputSchema): self
    {
        $found = [];

        self::walk($tool, $inputSchema, [], $found, depth: 0);

        $parameters = [];

        foreach ($found as [$name, $path, $type]) {
            $key = strtolower($name);

            if (isset($parameters[$key])) {
                throw MirroredParameterRefused::because(
                    $tool,
                    sprintf('the header name [%s] is annotated more than once', $name),
                );
            }

            $parameters[$key] = ['name' => $name, 'path' => $path, 'type' => $type];
        }

        /** @var array<string, array{path: list<string>, type: string}> $normalised */
        $normalised = [];

        foreach ($parameters as $parameter) {
            /** @var array{name: string, path: list<string>, type: string} $parameter */
            $normalised[$parameter['name']] = ['path' => $parameter['path'], 'type' => $parameter['type']];
        }

        return new self($normalised);
    }

    public function isEmpty(): bool
    {
        return $this->parameters === [];
    }

    /**
     * Build the headers for one call.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, string>
     */
    public function headersFor(string $tool, array $arguments): array
    {
        $headers = [];

        foreach ($this->parameters as $name => $descriptor) {
            $value = $this->valueAt($arguments, $descriptor['path']);

            // Absent is not empty. A parameter the model did not supply simply
            // has no header, which is different from one supplied as "".
            if ($value === null) {
                continue;
            }

            $headers[RequestHeader::PARAM_PREFIX.$name] = self::encode(
                $this->stringify($tool, $name, $value, $descriptor['type']),
            );
        }

        return $headers;
    }

    /**
     * Values that are not plain printable ASCII travel base64 in a sentinel, so
     * a header can never carry a raw CR/LF or a byte a proxy would mangle.
     */
    public static function encode(string $value): string
    {
        $needsEncoding = preg_match('/^[\x20-\x7E]*$/D', $value) !== 1
            || $value !== trim($value)
            || str_starts_with($value, '=?');

        return $needsEncoding
            ? '=?base64?'.base64_encode($value).'?='
            : $value;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $path
     */
    protected function valueAt(array $arguments, array $path): mixed
    {
        $cursor = $arguments;

        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return is_array($cursor) ? null : $cursor;
    }

    protected function stringify(string $tool, string $name, mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                default => throw MirroredParameterRefused::because(
                    $tool,
                    sprintf('the [%s] argument is not a boolean and cannot be mirrored as one', $name),
                ),
            },
            'integer' => match (true) {
                is_int($value) => (string) $value,
                default => throw MirroredParameterRefused::because(
                    $tool,
                    sprintf('the [%s] argument is not an integer and cannot be mirrored as one', $name),
                ),
            },
            default => match (true) {
                is_string($value) => $value,
                default => throw MirroredParameterRefused::because(
                    $tool,
                    sprintf('the [%s] argument is not a string and cannot be mirrored as one', $name),
                ),
            },
        };
    }

    /**
     * Walk only the statically reachable `properties` chain.
     *
     * The spec restricts annotations to properties reachable by a pure
     * `properties` path — never through `items`, `$ref`, `oneOf`/`anyOf`/`allOf`
     * or `if`/`then`/`else`. The reason is that anywhere else, whether the
     * property exists depends on the arguments, so the client could not decide
     * up front which headers a call carries.
     *
     * An annotation found off that path is a REFUSAL rather than something to
     * skip: a server that put one there believes it will be honoured.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $path
     * @param  list<array{0: string, 1: list<string>, 2: string}>  $found
     */
    protected static function walk(string $tool, array $schema, array $path, array &$found, int $depth): void
    {
        // A cheap bound. Schemas come from a party we do not control, and a
        // deeply nested one is a denial-of-service before it is anything else.
        if ($depth > 32) {
            throw MirroredParameterRefused::because($tool, 'its input schema nests deeper than 32 levels');
        }

        foreach ($schema as $key => $value) {
            if ($key === self::ANNOTATION && $path !== []) {
                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            if ($key !== 'properties') {
                if (self::containsAnnotation($value)) {
                    throw MirroredParameterRefused::because(
                        $tool,
                        sprintf(
                            'an [%s] annotation sits under [%s], outside the statically reachable properties',
                            self::ANNOTATION,
                            $key,
                        ),
                    );
                }

                continue;
            }

            foreach ($value as $property => $child) {
                if (! is_array($child)) {
                    continue;
                }

                $childPath = [...$path, (string) $property];

                if (array_key_exists(self::ANNOTATION, $child)) {
                    $found[] = self::annotation($tool, $child, $childPath);
                }

                self::walk($tool, $child, $childPath, $found, $depth + 1);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $path
     * @return array{0: string, 1: list<string>, 2: string}
     */
    protected static function annotation(string $tool, array $schema, array $path): array
    {
        $name = $schema[self::ANNOTATION];

        if (! is_string($name) || preg_match(self::TOKEN, $name) !== 1) {
            throw MirroredParameterRefused::because(
                $tool,
                sprintf('the [%s] value on [%s] is not a valid header name token', self::ANNOTATION, implode('.', $path)),
            );
        }

        $type = $schema['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            throw MirroredParameterRefused::because(
                $tool,
                sprintf(
                    'the [%s] annotation on [%s] must sit on a string, integer or boolean',
                    self::ANNOTATION,
                    implode('.', $path),
                ),
            );
        }

        return [$name, $path, $type];
    }

    /**
     * @param  array<array-key, mixed>  $schema
     */
    protected static function containsAnnotation(array $schema): bool
    {
        foreach ($schema as $key => $value) {
            if ($key === self::ANNOTATION) {
                return true;
            }

            if (is_array($value) && self::containsAnnotation($value)) {
                return true;
            }
        }

        return false;
    }
}
