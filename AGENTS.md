# AGENTS.md — particle-academy/prism-mcp

Tools from a server you do not own, offered to your model as ordinary Prism
tools — across a trust boundary you can see.

> **Read [the shared guide](https://github.com/Particle-Academy/prism-parity/blob/main/docs/AGENTS.md)
> first** — the boundary, the gates, the binding decisions, the review skills.
> This file is only what is true of *this* repository.

## The guard is the product

Everything else in this package is plumbing. The README's *trust boundary*
section is the design; the two decisions that bind it are
[0014, consuming untrusted MCP tools](https://github.com/Particle-Academy/prism-parity/blob/main/docs/decisions/0014-consuming-untrusted-mcp-tools.md)
and
[0015, the error path is a channel](https://github.com/Particle-Academy/prism-parity/blob/main/docs/decisions/0015-the-error-path-is-a-channel.md).

Read both before changing anything under `src/Trust/`, `src/Gates/` or
`src/Tools/`. A change here that contradicts one of them changes the decision
first, in prism-parity, with the reason.

## Invariants that a plausible-looking refactor will break

**Nothing reaches the model until an application says so, and the refusal
happens before any request is sent.** A misconfigured application must not even
tell the server it has an audience. Moving the trust check downstream of
discovery looks like a tidy-up and removes that property.

**There is no exploratory escape hatch.** `PrismMcp::client($url)->tools()` —
the form people reach for when trying a server out — refuses exactly as firmly
as the configured path. Exempting the exploratory path puts the hole precisely
where the exploration happens.

**An empty allowlist is a different, louder refusal than a missing one.** An
empty one *looks* configured and hands the model zero tools, and a run with no
tools reads as the model declining to use any. Collapsing the two error paths
into one message loses that distinction.

**The error path gets every guard the success path gets.** Bounding, framing and
filtering all apply to `isError: true` results. Prism turns a thrown tool
exception into a `ToolError` the model reads, so from an attacker's side these
are one channel with a boolean between them. This was a real hole in the first
draft; the test that pins it is
`it('does not let an error result bypass the guard')`. Never make that branch
conditional.

**An oversized result is refused, not truncated.** A silently cut result reads
to a model as a complete one. This is also a cost property: an unbounded result
lets a remote party choose how many tokens you pay for and evict your system
prompt.

**The framing nonce is per call.** A fixed delimiter is one a server can learn,
close early, and have the rest of its output read as trusted text.

**Pins cover what the model reads** — name, title, description, input schema —
and deliberately exclude annotations, which the spec already tells clients to
distrust. Keys are sorted recursively so a server reordering its JSON does not
read as a rewritten tool. Adding annotations to the digest would make pins fire
on noise, and pins that cry wolf get switched off.

## The one carve-out from "unsupported means throw"

A tool with spec-violating `x-mcp-header` annotations is **excluded** from the
list rather than raising, because the specification makes that a client MUST and
one malformed tool should not make a healthy server unusable.

It is bounded at both ends and both ends matter:

- under `trustingEveryTool()` it is dropped, and readable from
  `->client()->excluded()` — not only from a log, because nobody reads a log
  mid-run;
- under an **explicit allowlist naming that tool**, it **throws**. You asked for
  it by name; succeeding without it is the Perplexity `withTools()` failure
  exactly.

## Protocol

MCP revision `2026-07-28` over Streamable HTTP. That revision has **no
`initialize` handshake and no session**: `server/discover`, `tools/list`,
`tools/call`. Code or documentation that reaches for a session id is written
against an older revision.

Deferred surface is listed in the README under *Deferred* — as a plan, not an
oversight. Add to that list rather than quietly implementing around it.

## Gates

```sh
composer test && composer types && composer format
```

CI runs `tests`, `phpstan`, `formatting`, `require-checker`. The Laravel Gate
and Fancy FMS adapters are **optional** — `require-checker` is what stops one
from becoming mandatory by accident, so a failure there is a design signal, not
a config annoyance.
