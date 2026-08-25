<?php

declare(strict_types=1);

namespace Prism\Mcp\Client;

use JsonException;
use Prism\Mcp\Contracts\Transport;
use Prism\Mcp\Enums\MetaKey;
use Prism\Mcp\Enums\ProtocolVersion;
use Prism\Mcp\Enums\RequestHeader;
use Prism\Mcp\Exceptions\ProtocolFailure;
use Prism\Mcp\Exceptions\UnsupportedProtocolVersion;
use Prism\Mcp\Support\MirroredParameters;

/**
 * JSON-RPC framing for `2026-07-28`.
 *
 * There is no handshake and no session. `initialize` and
 * `notifications/initialized` were removed in this revision and the protocol
 * became stateless: every request carries its own protocol version, client
 * capabilities and client info in `_meta`, and a server accepts or rejects each
 * one independently.
 *
 * That is why this object holds no connection state worth the name. It is a
 * request builder and a response validator, and the absence of anything else is
 * the design rather than an omission.
 */
class Protocol
{
    protected int $nextId = 1;

    /**
     * @param  array<string, mixed>  $clientInfo
     */
    public function __construct(
        protected readonly Transport $transport,
        protected readonly array $clientInfo,
    ) {}

    /**
     * `server/discover` — one round trip for versions, capabilities and
     * instructions.
     *
     * Optional in the spec: a client MAY call it, and a server MUST implement
     * it. It is called here because the alternative is discovering a version
     * mismatch as an opaque `-32602` on the first real request, which sends the
     * operator looking at their arguments rather than at the version.
     *
     * @return array<string, mixed>
     */
    public function discover(): array
    {
        try {
            $result = $this->call('server/discover');
        } catch (ProtocolFailure $failure) {
            // -32022 carries the versions the server does speak. Turning that
            // into a message naming both sides is the whole reason to catch it.
            if ($failure->rpcCode === -32022) {
                throw UnsupportedProtocolVersion::between(
                    $this->transport->label(),
                    $this->supportedFrom($failure->data),
                );
            }

            throw $failure;
        }

        $supported = $result['supportedVersions'] ?? null;

        if (is_array($supported)) {
            /** @var list<string> $versions */
            $versions = array_values(array_filter($supported, is_string(...)));

            if (! in_array(ProtocolVersion::LATEST->value, $versions, true)) {
                throw UnsupportedProtocolVersion::between($this->transport->label(), $versions);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $extraHeaders
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = [], ?string $name = null, array $extraHeaders = []): array
    {
        $id = $this->nextId++;

        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => (object) $this->withMeta($params),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw ProtocolFailure::malformed($this->transport->label(), 'the request could not be encoded as JSON');
        }

        $raw = $this->transport->send($payload, [
            ...$this->headers($method, $name),
            ...$extraHeaders,
        ]);

        return $this->resultOf($raw, $id);
    }

    /**
     * The `_meta` block `2026-07-28` requires on every request.
     *
     * `protocolVersion` and `clientCapabilities` are MUSTs — a conforming server
     * answers `-32602` without them. `clientCapabilities` is an empty object
     * cast rather than an empty array, because PHP renders `[]` as a JSON array
     * and the schema wants an object; that distinction has bitten every port in
     * this ecosystem at least once.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function withMeta(array $params): array
    {
        /** @var array<string, mixed> $existing */
        $existing = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];

        $params['_meta'] = [
            MetaKey::ProtocolVersion->value => ProtocolVersion::LATEST->value,
            MetaKey::ClientCapabilities->value => (object) [],
            MetaKey::ClientInfo->value => $this->clientInfo,
            ...$existing,
        ];

        return $params;
    }

    /**
     * @return array<string, string>
     */
    protected function headers(string $method, ?string $name): array
    {
        $headers = [
            RequestHeader::ProtocolVersion->value => ProtocolVersion::LATEST->value,
            RequestHeader::Method->value => $method,
        ];

        if ($name !== null) {
            // Same sentinel encoding as a mirrored parameter: a tool name is
            // server-chosen and there is no guarantee it is header-safe.
            $headers[RequestHeader::Name->value] = MirroredParameters::encode($name);
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resultOf(string $raw, int $expectedId): array
    {
        try {
            /** @var mixed $response */
            $response = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ProtocolFailure::malformed($this->transport->label(), $e->getMessage());
        }

        if (! is_array($response) || ($response['jsonrpc'] ?? null) !== '2.0') {
            throw ProtocolFailure::malformed($this->transport->label(), 'the envelope is not JSON-RPC 2.0');
        }

        // Matching the id is not ceremony. A response for a different request is
        // a correlation failure, and treating one as an answer is how a client
        // returns another call's data.
        if (($response['id'] ?? null) !== $expectedId) {
            throw ProtocolFailure::malformed(
                $this->transport->label(),
                sprintf('the response id did not match request %d', $expectedId),
            );
        }

        $hasResult = array_key_exists('result', $response);
        $hasError = array_key_exists('error', $response);

        if ($hasResult === $hasError) {
            throw ProtocolFailure::malformed(
                $this->transport->label(),
                'the response carries both a result and an error, or neither',
            );
        }

        if ($hasError) {
            $error = is_array($response['error']) ? $response['error'] : [];
            $message = $error['message'] ?? null;
            $code = $error['code'] ?? null;
            $data = $error['data'] ?? null;

            throw ProtocolFailure::rpcError(
                $this->transport->label(),
                is_int($code) ? $code : 0,
                is_string($message) ? $message : 'no message',
                is_array($data) ? $data : null,
            );
        }

        return is_array($response['result']) ? $response['result'] : [];
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return list<string>
     */
    protected function supportedFrom(?array $data): array
    {
        $supported = $data['supported'] ?? null;

        if (! is_array($supported)) {
            return [];
        }

        return array_values(array_filter($supported, is_string(...)));
    }
}
