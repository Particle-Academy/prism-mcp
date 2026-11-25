<?php

declare(strict_types=1);

namespace Prism\Mcp\Transport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Prism\Mcp\Contracts\Transport;
use Prism\Mcp\Exceptions\McpException;
use Prism\Mcp\Exceptions\ProtocolFailure;
use Prism\Mcp\Exceptions\ServerTimedOut;
use Prism\Mcp\Exceptions\TransportFailure;
use RuntimeException;

/**
 * Streamable HTTP, `2026-07-28`.
 *
 * POST-only. The GET stream endpoint, `Mcp-Session-Id` and `Last-Event-ID`
 * resumability were all removed in this revision, so there is nothing here that
 * opens a second connection or remembers anything between calls.
 *
 * A server MAY still answer a POST with `text/event-stream` when it wants to
 * emit progress before the result, so both content types are accepted and the
 * final JSON-RPC frame is what counts.
 */
class HttpTransport implements Transport
{
    protected float $timeoutSeconds = 30.0;

    protected float $connectTimeoutSeconds = 5.0;

    /**
     * The most JSON-RPC this transport will read from one response.
     *
     * A timeout bounds how LONG a server can take. Nothing otherwise bounds how
     * MUCH it can send, and the whole body is decoded into memory — so a server
     * answering with a gigabyte exhausts the worker before any tool-level size
     * check gets a chance to run. 8 MB is far above any legitimate tool list and
     * far below anything that hurts.
     */
    protected int $maxResponseBytes = 8_388_608;

    /** @var array<string, string> */
    protected array $headers = [];

    public function __construct(
        protected readonly HttpFactory $http,
        protected readonly string $url,
        protected readonly string $serverLabel,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): static
    {
        $this->headers = [...$this->headers, ...$headers];

        return $this;
    }

    #[\Override]
    public function withTimeout(float $seconds): static
    {
        $this->timeoutSeconds = $seconds;

        // A connect timeout longer than the total is meaningless, and the
        // default is only a default.
        $this->connectTimeoutSeconds = min($this->connectTimeoutSeconds, $seconds);

        return $this;
    }

    #[\Override]
    public function timeout(): float
    {
        return $this->timeoutSeconds;
    }

    #[\Override]
    public function label(): string
    {
        return $this->serverLabel;
    }

    #[\Override]
    public function send(string $payload, array $headers = []): string
    {
        try {
            $response = $this->request()
                ->withHeaders($headers)
                ->withBody($payload, 'application/json')
                ->post($this->url);
        } catch (ConnectionException $e) {
            // Laravel folds a timeout, a refused connection, and a redirect the
            // client would not complete (too many hops, a non-http scheme) into
            // ConnectionException. A timeout is retryable and the rest usually
            // are not, so the two are separated here rather than downstream.
            //
            // Neither keeps the client's exception or its message: the message
            // ends with the configured URL, query string included, and the
            // exception holds the full request, headers included.
            throw $this->looksLikeTimeout($e)
                ? ServerTimedOut::after($this->serverLabel, $this->timeoutSeconds)
                : TransportFailure::unreachable($this->serverLabel);
        } catch (McpException $e) {
            // Ours, from the redirect check; already the right type.
            throw $e;
        } catch (RuntimeException|InvalidArgumentException $e) {
            // What the HTTP client throws below Laravel that is not a
            // ConnectionException: a Location it cannot parse. Callers catch
            // McpException, so nothing from the client escapes past it.
            throw TransportFailure::exchange($this->serverLabel, $e);
        }

        // Anything but a 2xx is not an answer. A 3xx arrives here when the
        // application turned redirects off, or when it carried no Location.
        if (! $response->successful()) {
            throw TransportFailure::status($this->serverLabel, $response->status());
        }

        $body = $response->body();

        if (strlen($body) > $this->maxResponseBytes) {
            throw TransportFailure::oversized($this->serverLabel, strlen($body), $this->maxResponseBytes);
        }

        return str_contains((string) $response->header('Content-Type'), 'text/event-stream')
            ? $this->lastFrameOf($body)
            : $body;
    }

    public function withMaxResponseBytes(int $bytes): static
    {
        $this->maxResponseBytes = $bytes;

        return $this;
    }

    protected function request(): PendingRequest
    {
        $request = $this->http
            ->timeout($this->timeoutSeconds)
            ->connectTimeout($this->connectTimeoutSeconds);

        // An application that turned redirects off everywhere keeps them off
        // here. Otherwise they are followed only while the scheme, host and
        // port stay the same; see SameOriginRedirects for why the client's own
        // handling is not used.
        if (! $this->redirectsTurnedOff($request->getOptions())) {
            $request->withOptions(SameOriginRedirects::options(
                $this->url,
                fn (): TransportFailure => TransportFailure::redirected($this->serverLabel),
            ));
        }

        return $request
            ->withHeaders([
                // Both, in this order. A `2026-07-28` server answers either, and
                // announcing only JSON would refuse a server that legitimately
                // wants to stream progress before the result.
                'Accept' => 'application/json, text/event-stream',
                ...$this->headers,
            ]);
    }

    /**
     * Whether the application's own options already turn redirects off.
     *
     * The HTTP client treats any empty `allow_redirects` (false, 0, '', null)
     * as off, and an array with an empty `max` (Laravel's maxRedirects(0)) as
     * off too. Each of those is kept; only an absent setting or one that
     * follows redirects is narrowed to the same origin.
     *
     * @param  array<string, mixed>  $options
     */
    protected function redirectsTurnedOff(array $options): bool
    {
        if (! array_key_exists('allow_redirects', $options)) {
            return false;
        }

        $setting = $options['allow_redirects'];

        if (empty($setting)) {
            return true;
        }

        return is_array($setting) && array_key_exists('max', $setting) && empty($setting['max']);
    }

    /**
     * The last `data:` frame carrying a JSON-RPC envelope.
     *
     * A streaming response interleaves progress notifications with the result.
     * Taking the FIRST frame would return a notification and call it an answer.
     */
    protected function lastFrameOf(string $body): string
    {
        $found = null;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            if (! str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(Str::after($line, 'data:'));

            if ($data === '' || ! str_contains($data, '"jsonrpc"')) {
                continue;
            }

            $found = $data;
        }

        if ($found === null) {
            throw ProtocolFailure::malformed(
                $this->serverLabel,
                'the event stream carried no JSON-RPC frame',
            );
        }

        return $found;
    }

    /**
     * Guzzle reports timeouts through the same exception type as a refused
     * connection, distinguishable only by the message. Matching on prose is
     * exactly what decision 0004 argues against — so this is deliberately a
     * conservative test, and being wrong costs a caller the wrong exception
     * type rather than a wrong result.
     */
    protected function looksLikeTimeout(ConnectionException $e): bool
    {
        return Str::contains(Str::lower($e->getMessage()), ['timed out', 'timeout']);
    }
}
