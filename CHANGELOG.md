# Changelog

## 0.3.0

- **Breaking:** an explicitly present `isError: null` now reports tool failure.
  Only an absent field or literal `false` reports success. Every other present
  value continues to fail closed. Error output still passes through all result
  guards before `RemoteTool` raises `ToolCallFailed`.
- Trust policy, result caps, omission markers, logging and diagnostics are
  unchanged. This aligns the presence-aware classification with the TypeScript
  and Python MCP ports.
