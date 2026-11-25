# Changelog

## Unreleased

- `TransportFailure::exchange()` no longer chains the HTTP client's exception
  and takes only the server label. That exception's message can carry the
  configured URL with its password and query string, or a rejected header value
  such as a bearer token ending in a newline.
- The application's `allow_redirects` setting is read the way the HTTP client
  reads it and never widened: any value that is not `true` or an array, an
  empty array, and a `max` that is not a positive whole number all mean no
  redirect is followed. A `max` below five is kept, and an application
  `on_redirect` hook still runs for hops that stay on the configured origin.
- README: credentials belong in headers, not the query string, because
  Laravel's `ConnectionFailed` event carries the URL.
## 0.3.0

- **Breaking:** an explicitly present `isError: null` now reports tool failure.
  Only an absent field or literal `false` reports success. Every other present
  value continues to fail closed. Error output still passes through all result
  guards before `RemoteTool` raises `ToolCallFailed`.
- Trust policy, result caps, omission markers, logging and diagnostics are
  unchanged. This aligns the presence-aware classification with the TypeScript
  and Python MCP ports.
