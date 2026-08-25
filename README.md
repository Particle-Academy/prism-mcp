# prism-mcp

**Model Context Protocol for Prism.** Tools from a server you do not own, offered
to your model as ordinary Prism tools — across a trust boundary you can see.

```php
use Prism\Mcp\Facades\PrismMcp;
use Prism\Prism\Prism;

$tools = PrismMcp::server('github')->tools();

Prism::text()
    ->using('anthropic', 'claude-sonnet-4-5')
    ->withTools($tools)
    ->withPrompt('Which of our open PRs touch the billing module?')
    ->asText();
```

> **Status: first slice.** The client, over Streamable HTTP, speaking MCP
> revision `2026-07-28`. What is deliberately not here yet is listed under
> [Deferred](#deferred) — as a plan, not an oversight.

This package follows the Prism model. The model is described **once**, in
[`prism-parity/docs/patterns/`](https://github.com/Particle-Academy/prism-parity/tree/main/docs/patterns),
and the decisions binding every package in the ecosystem live in
[`docs/decisions/`](https://github.com/Particle-Academy/prism-parity/tree/main/docs/decisions).
Nothing here restates them, because restated documentation drifts exactly like
restated code and nothing tests prose.

---

## The trust boundary

**This is the section to read. Everything else in this package is plumbing.**

### What you are actually trusting

When you connect to an MCP server, you are not fetching data. You are letting a
third party **write into your model's instructions**, and then letting your model
**act on what they write back**.

Concretely, all of the following are authored by whoever operates that server,
and all of them reach the model as text it treats as authoritative:

| Surface | When it arrives | What it can do |
|---|---|---|
| Tool **name** | discovery | Shape what the model thinks the tool is for |
| Tool **description** | discovery | Arbitrary instructions, read every turn |
| **Parameter** names and descriptions | discovery | The same, once per parameter |
| **Enum values** in the input schema | discovery | The same again, and rarely reviewed |
| Tool **annotations** | discovery | Claims about safety that may simply be false |
| Tool **results** | every call | Instructions delivered mid-run, pre-framed as trusted output |

Two properties make this different from every other untrusted input a Laravel
application already handles well.

**It is instructions, not data.** You escape user input before it reaches HTML
and bind it before it reaches SQL. There is no equivalent escape for text that
reaches a language model, because instruction and data are the same channel.
The MCP specification says so itself: *"descriptions of tool behavior such as
annotations should be considered untrusted, unless obtained from a trusted
server."*

**It can change under you.** A tool your application wrote changes when someone
deploys. A tool a third party publishes changes whenever they like, between two
calls, with nothing on your side to notice. A server can publish a benign
`search` tool, wait to be adopted, and then rewrite its description. The MCP
security literature calls that a rug pull, and no amount of reviewing the tool
once prevents it.

And what an injected instruction can reach is not limited to that server. Once
the model is following it, every **other** tool in the same run is available —
your own database tools, your own mail tools. A compromised MCP server is a
foothold in the whole tool set, not just in its own.

### What this package does about it

Four things, in order of how much they actually buy. This ordering is deliberate,
and the honesty about the last one matters as much as the first.

#### 1. Nothing reaches the model until you say so

**A server with no trust declaration refuses**, and it refuses *before any
request is sent* — so a misconfigured application never even tells the server it
has an audience.

```php
// config/prism-mcp.php
'github' => [
    'url' => env('MCP_GITHUB_URL'),
    'trust' => [
        'tools' => ['search_repositories', 'get_file_contents'],
    ],
],
```

Or, when you genuinely accept whatever that server publishes today and publishes
next week — reasonable for a server you operate yourself:

```php
'trust' => ['tools' => '*'],
```

There is no way to skip this. `PrismMcp::client('https://…/mcp')->tools()` — the
ad-hoc form people reach for when trying a server out — refuses just as firmly,
because exempting the exploratory path puts the hole exactly where the
exploration happens.

An **empty** allowlist is a separate, louder refusal from a **missing** one. An
empty one looks configured and silently hands the model zero tools, and a run
with no tools reads as *the model choosing not to use any* — which is precisely
the failure mode Prism core's Perplexity `withTools()` incident taught this
ecosystem to refuse rather than tolerate.

#### 2. Pin a definition and a rewrite is refused, not obeyed

Opt-in, per tool. Record the digest of a definition you have actually read:

```php
'trust' => [
    'tools' => ['search_repositories'],
    'pins' => ['search_repositories' => 'sha256:…'],
],
```

```bash
php artisan tinker
>>> foreach (PrismMcp::server('github')->client()->definitions() as $d) {
...     printf("%-30s %s\n", $d->name, $d->digest());
... }
```

The digest covers name, title, description and input schema — everything the
model will read. It deliberately excludes annotations, which the spec already
tells clients to distrust, and it sorts keys recursively so a server reordering
its JSON does not read as a rewritten tool.

This is the one defence against a rug pull that actually holds, because it does
not depend on recognising malice — only on noticing change.

#### 3. Results are bounded, framed, and filterable

The discussion around MCP focuses on tool *descriptions*. The **result** path is
worse and gets less attention: a description is read once at discovery, while a
result arrives mid-run, already framed as the trusted output of a tool the model
itself chose to call.

- **Bounded.** Over `max_result_bytes` (256 KB by default) the call is
  **refused, not truncated**. A silently cut result reads to a model as a
  complete one. This is the piece that carries the most weight, and it is a
  stability and cost property before it is a security one — an unbounded result
  evicts your system prompt and lets a remote party choose how many tokens you
  pay for.
- **Framed.** Results are wrapped in a delimiter naming the source server, with a
  **per-call random nonce** so a server that learns the delimiter cannot close it
  early and have the rest of its output read as trusted text again.
- **Filterable.** `->filteringResults(fn ($text, $server, $tool) => …)` runs
  before framing, for applications that know what a given server should return.

The **error** path gets exactly the same treatment. Prism turns a thrown tool
exception into a `ToolError` the model reads, so `isError: true` is the identical
channel from an attacker's point of view — an unguarded error path would be a
hole straight through the framing and the cap, openable by any server willing to
set one boolean. (It was one, in the first draft of this package. The review
found it and the test that proves it is fixed is
`it('does not let an error result bypass the guard')`.)

#### 3b. A tool that is dropped is dropped visibly

A tool whose `x-mcp-header` annotations break the spec's rules is **excluded**
from the list — the specification makes that a client MUST, and one malformed
tool should not make an otherwise healthy server unusable.

That exclusion is this package's one carve-out from the ecosystem rule that
things which cannot be done **throw** rather than degrade quietly. So it is
bounded at both ends:

- Under `trustingEveryTool()` the tool is dropped, and the exclusion is readable
  from `->client()->excluded()` — not only from a log line, because nobody reads
  a log during a run.
- Under an **explicit allowlist naming that tool**, it **throws**. You asked for
  that tool by name; returning successfully without it is the Perplexity
  `withTools()` failure exactly — a run that completes with the model appearing
  to decline a tool it was never given.

#### 4. A gate decides who may run what

Trust decides which tools the model is **told about**. A gate decides whether a
given actor may **run** one. Different questions, and both worth answering.

```php
// Laravel's Gate — authorization.
Gate::define('mcp.call', fn (?User $user, string $server, string $tool, array $args) =>
    $user?->can('use-external-tools') ?? false);
```

Adapters ship for Laravel Gates and for Fancy's Feature Management System, and
neither is a hard dependency — see [Gating](#gating) for why they answer
different questions and which to reach for.

### What this package does NOT do, and will not pretend to

**It does not scan results for injection.** No regex, no heuristic, no
"suspicious phrase" list. Pattern-matching for prompt injection produces false
confidence and false positives, and MCP's own maintainers have published the
argument plainly: nothing in static metadata *"tells the model to ignore
malicious instructions it reads"*, and a guarantee against exfiltration *"is a
job for network controls or sandboxing, not a boolean hint."* Shipping a check
that does not hold is worse than shipping none, because someone will rely on it.

**Framing is a mitigation, not a fix.** The delimiter gives the model the
information it needs to distrust the text — which it otherwise does not have. A
determined injection can still work. Anyone telling you their MCP client makes
remote tools safe is selling something.

**Annotations are not a safety mechanism.** `readOnlyHint: true` is a claim by a
party you do not control. This package passes annotations through and pins
nothing to them.

**Trust is not transitive.** Trusting a server's `search` tool is not trusting
what `search` returns, and it is certainly not trusting whatever server *that*
server talks to.

### One thing this package cannot guard, so guard it yourself

**A server URL must never come from user input.**

`PrismMcp::client($url)` fetches whatever you point it at, and this package does
not block private or loopback addresses — because `http://127.0.0.1:3000/mcp` is
how you run a local MCP server, and refusing it would break the ordinary case to
prevent a misuse. So the SSRF boundary is yours: server URLs belong in config or
in an operator-controlled setting, never in a request parameter.

The same goes for the `gate` key, which resolves a class name from the
container. Both are configuration surfaces, and configuration is trusted here in
the way every Laravel package trusts it.

### The residual risk, stated plainly

With every feature here switched on, a malicious server can still: emit an
instruction inside a result that the model follows; make its tool's legitimate
purpose subtly wrong in a way review does not catch; and observe every argument
your model sends it. What this package buys you is that these are **decisions you
made** rather than defaults you inherited, that a definition cannot change
underneath you unnoticed, and that the blast radius is bounded in size, in
permission and in which tools were ever offered.

For a genuinely adversarial server, the answer is network controls and process
isolation, and it always was.

---

## Installation

```bash
composer require particle-academy/prism-mcp
php artisan vendor:publish --tag=prism-mcp-config
```

Requires PHP 8.2+, Laravel 12.61+ or 13.12+, and
[`particle-academy/prism`](https://github.com/Particle-Academy/prism) 0.114+.

---

## Usage

### A configured server

```php
$tools = PrismMcp::server('github')->tools();
```

### Every configured server at once

```php
$tools = PrismMcp::tools();
```

A server that refuses is **not skipped** — the exception surfaces. A helper that
quietly dropped an untrusted or unreachable server would hand the model a shorter
tool list than the application believes it configured, and that silence is the
thing this package exists to remove.

### Ad hoc, with trust declared inline

```php
$tools = PrismMcp::client('https://mcp.example.com/mcp')
    ->withToken(config('services.example.token'))
    ->trusting(['search'])
    ->withTimeout(10)
    ->tools();
```

### The builder

| Method | Does |
|---|---|
| `->trusting([...])` | Allowlist the named tools |
| `->trustingEveryTool()` | Accept whatever the server publishes |
| `->pinning([$tool => $digest])` | Refuse a tool whose definition changed |
| `->withTimeout($seconds)` | Bound every call to this server |
| `->withToken($token)` / `->withHeaders([...])` | Authenticate |
| `->usingGate($gate)` | Decide who may run a trusted tool |
| `->filteringResults($closure)` | Transform results before framing |
| `->withMaxResultBytes($bytes)` | Change the refusal threshold |
| `->withoutProvenanceFraming()` | Stop framing results (not recommended) |
| `->withoutCache()` / `->cachingFor($seconds)` | Control tool-list caching |
| `->client()` | The raw protocol client, unguarded |

Every method returns the builder and nothing is validated on the way in; the
checks all happen at `tools()`. That is Prism's
[pending-request pattern](https://github.com/Particle-Academy/prism-parity/blob/main/docs/patterns/02-pending-request.md),
applied here.

---

## Performance

**Tool discovery is cached.** Without it, every generation pays a protocol
negotiation plus a network round trip *before the model sees a single token* — a
slow MCP server becomes a slow application, and the cost is paid on requests that
never call a tool at all.

- The TTL comes from the server's `ttlMs`, which revision `2026-07-28` requires
  it to send, unless configuration overrides it.
- A warm cache short-circuits **everything**, including negotiation. A cache that
  still paid for a handshake would halve the saving for no reason.
- A result marked `cacheScope: private` is never written to a shared cache.
- Across paginated pages, the **tightest** TTL wins.

**A slow server cannot stall a generation.** Every call is bounded, and the bound
failing is `ServerTimedOut` — distinct from `TransportFailure`, because one is
retryable and the other usually is not.

**A large server cannot exhaust a worker.** A timeout bounds how *long* a server
may take; a separate 8 MB transport bound covers how *much* it may send, because
the body is decoded into memory before any tool-level size check can look at it.

**Cache entries are keyed on credentials, not just the URL.** A server may
legitimately return a different tool list per caller and only some of them label
that `cacheScope: private`; keying on the URL alone would serve one principal's
tools to another the moment a server forgot the label. The key carries a hash,
never the credential — cache keys end up in logs.

---

## Gating

Two adapters ship. Neither is required, and they answer **different questions**.

| | Question | Reach for it when |
|---|---|---|
| `LaravelGate` | **Authorization** — may this actor perform this action? | "May this user run `delete_repository`?" |
| `FeatureGate` (FMS) | **Entitlement** — is this subject granted this capability? | "Which servers may this tenant reach at all?" |

They compose, and conflating them has a specific failure mode: wire entitlement
to an authorization question and someone's lapsed subscription becomes what
stops a destructive action. FMS's own contract makes the distinction explicitly —
it added `isEntitled()` as a named alias so a call site declares which question
it is asking.

`LaravelGate` asks **one** ability, `mcp.call` — the ecosystem's
`<package>.<verb>` convention — receiving
`($server, $tool, $arguments)`. It does not derive an ability per tool, because
an ability nobody defined is denied by default — so a third party adding a tool
could otherwise break your authorization by publishing something.

Bring your own by implementing `Prism\Mcp\Contracts\ToolGate` and naming the
class in `gate`. When `prism-harness` grows tool permissions, it implements the
same contract and nothing here changes.

> `particle-academy/laravel-fms` requires PHP 8.4 and Laravel 13. It is a
> `suggest` rather than a `require` so this package keeps the same PHP 8.2+ /
> Laravel 12+ floor as the rest of the ecosystem.

---

## Errors

Every failure carries a **stable code**. The sentence is for a human and is
explicitly outside the contract — branch on `code()`, never on `getMessage()`.
See [decision 0004](https://github.com/Particle-Academy/prism-parity/blob/main/docs/decisions/0004-error-codes.md).

| Code | Raised when |
|---|---|
| `server_not_configured` | No such server, or it has no URL |
| `server_not_trusted` | No trust declaration, or an empty allowlist |
| `tool_definition_changed` | A pinned definition no longer matches |
| `tool_denied` | The gate refused this call |
| `result_too_large` | A result exceeded `max_result_bytes` |
| `tool_call_failed` | The server reported `isError` |
| `mirrored_parameter_refused` | A tool's `x-mcp-header` annotations broke the rules, and it was named in the allowlist |
| `unsupported_protocol_version` | The server speaks a different MCP era |
| `unsupported_transport` | stdio, or an unknown transport |
| `protocol_failure` | Malformed JSON-RPC, or a JSON-RPC error |
| `transport_failure` | Unreachable, or a non-2xx status |
| `server_timed_out` | The bound was hit |

---

## Protocol

Speaks MCP revision **`2026-07-28`** — the current release — over Streamable
HTTP.

That revision is a rewrite rather than an increment: `initialize` was removed
outright and the protocol became **stateless**, with every request carrying its
own protocol version, client capabilities and client info in `_meta`. Sessions,
SSE resumability and the GET stream endpoint are gone. Revisions from
`2025-11-25` back are a different protocol wearing the same name.

So a server on an older era is **refused by name** — an error stating what it
speaks and what this client speaks — rather than silently downgraded. Legacy-era
support is [deferred](#deferred), not forgotten.

Implemented from the revision, including the parts a client MUST do:

- `server/discover` negotiation, with `-32022` turned into a refusal naming both
  sides
- Required `_meta` (`protocolVersion`, `clientCapabilities`, `clientInfo`) and
  required headers (`MCP-Protocol-Version`, `Mcp-Method`, `Mcp-Name`)
- **Mirrored parameters** — `x-mcp-header` copied into `Mcp-Param-*`, with the
  spec's full validation and the **exclusion** of any tool that breaks it. That
  exclusion is a security property: the annotation moves a *model-supplied* value
  into an HTTP header, where a CRLF would be header injection and every
  intermediary can read the result. Values that are not plain printable ASCII
  travel base64 in a sentinel.
- Cursor pagination, bounded against a server that paginates forever
- `ttlMs` / `cacheScope` honoured
- Both `application/json` and `text/event-stream` responses, taking the **last**
  JSON-RPC frame — the first is a progress notification, not an answer
- Response-id correlation, so a response for a different request is never
  returned as an answer

Protocol drift is watched: `prism-provider-watch` tracks the MCP spec and
`laravel/mcp` releases as an `mcp` target, so a revision arrives as a drift
report rather than a surprise.

---

## Deferred

Present in the protocol, deliberately absent here. Listed so an absence reads as
a plan rather than an oversight —
[decision 0003](https://github.com/Particle-Academy/prism-parity/blob/main/docs/decisions/0003-drift-and-existence.md).

| Not yet | Why |
|---|---|
| **stdio transport** | Spawns a local child process from a command string. A materially different risk class from fetching a URL, and it lands behind an explicit opt-in rather than as a peer of one. |
| **Legacy protocol eras** | `2025-11-25` and older use the removed `initialize` handshake — a second protocol, not a compatibility branch. |
| **OAuth (DCR, PKCE, CIMD)** | Bearer tokens and custom headers work today. Full authorization needs the confused-deputy mitigations done properly, not quickly. |
| **MRTR / elicitation** | Server-initiated input via `input_required` and a request retry. Needs a human-in-the-loop surface, which is `prism-harness`'s job. |
| **`subscriptions/listen`** | A long-lived stream does not fit a request that boots, serves and dies. |
| **Prompts and resources** | Reachable now through `->client()`; not yet wrapped in the trust layer. |
| **Server direction** | Exposing *your* tools over MCP. `laravel/mcp` does this well and Prism core already bridges to it via `Tool::make()`. |

---

## Relationship to other packages

**`laravel/mcp`** builds MCP **servers** — exposing your application's
capabilities. Prism core already bridges to it: `Tool::make()` accepts a
`Laravel\Mcp\Server\Tool`. As of `v1.0.0-beta.1` it also ships a client, and it
is a good one. This package is the other half of the problem: consuming a server
you do **not** own, which is where the trust boundary lives. Use both.

**`prism-php/relay`** was the previous answer to consuming MCP servers from
Prism. It is superseded — this package declares `replace` — and the reasons are
worth stating, because they are the design brief:

- It hardcodes protocol `2024-11-05` and has not been released since 2026-03-20,
  leaving it **two eras** behind, on a transport now formally deprecated.
- It guesses a tool's calling convention from its parameter names
  (`isUrlBasedTool`, `hasScriptParameter`), which silently mis-calls anything it
  guesses wrong about.
- It has no trust boundary of any kind.

Two things it got right are carried forward deliberately: caching the tool list,
and namespacing tool names per server.

**`prism` core** is untouched. It is a provider API shuttle and stays one — see
[the Prism boundary](https://github.com/Particle-Academy/prism-parity). Core's
`PrismServer` is an OpenAI-compatible endpoint: a different protocol solving a
different problem, and it stays where it is.

---

## Contributing

```bash
composer test    # Pest
composer types   # PHPStan level 6
composer format  # Pint
```

Every guard in this package has been **broken on purpose** and watched go red. A
green assertion nobody has seen fail is a hypothesis, not a test — if you add a
check, add the mutant that proves it works.

That includes the shipped configuration. A test that writes the config it reads
is a closed loop and proves nothing about what a consumer gets; `ShippedConfigTest`
reads the published file and proves the values in it reach behaviour.

## License

MIT.
