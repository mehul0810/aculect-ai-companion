# Quark MCP Connector

This directory contains the MCP server layer exposed at the Quark MCP endpoint.

## Ability IDs and Tool Names

Quark keeps internal ability IDs stable and descriptive, for example `content.list_items`. Public MCP tool names must be compatible with assistant clients that reject dots or other separators, so the public name is normalized to a safe form such as `content_list_items`.

When adding or changing tools:

- Keep the internal ability ID stable where possible.
- Expose only tool names matching `^[a-zA-Z0-9_-]{1,64}$`.
- Add legacy aliases in `AbilitiesRegistry::normalize_alias()` when renaming a tool.
- Update PHPUnit coverage for tool-name mapping and `tools/list` output.

This separation prevents client-specific validation rules from leaking into the plugin's internal ability model.
