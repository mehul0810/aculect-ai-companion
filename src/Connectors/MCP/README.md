# Aculect AI Companion MCP Connector

This directory contains the MCP server layer exposed at the Aculect AI Companion MCP endpoint.

## Ability IDs and Tool Names

Aculect AI Companion keeps internal ability IDs stable and descriptive, for example `content.list_items`. Public MCP tool names must be compatible with assistant clients that reject dots or other separators, so the public name is normalized to a safe form such as `content_list_items`.

When adding or changing tools:

- Keep the internal ability ID stable where possible.
- Expose only tool names matching `^[a-zA-Z0-9_-]{1,64}$`.
- Add legacy aliases in `AbilitiesRegistry::normalize_alias()` when renaming a tool.
- Update PHPUnit coverage for tool-name mapping and `tools/list` output.

This separation prevents client-specific validation rules from leaking into the plugin's internal ability model.

## WordPress Abilities Bridge

Aculect AI Companion exposes the WordPress Abilities API through three controlled MCP tools:

- `wp_abilities_discover`: Lists public abilities registered by WordPress core and plugins.
- `wp_abilities_get_info`: Returns schema and metadata for a single public ability.
- `wp_abilities_run`: Executes a public ability as the connected WordPress user.

The bridge intentionally does not expose a generic REST proxy. Execution still
flows through WordPress ability permissions, Aculect AI Companion ability toggles, and OAuth
scopes. Keep `wp_abilities_run` treated as write-capable because third-party
abilities may modify data even when their names are not obvious.

## Content Surface

Aculect AI Companion's built-in MCP tools cover posts/pages/custom post types, taxonomies,
comments, media library listing/upload, safe site settings, site information,
and plugin/theme inventory. New tool groups should stay deterministic,
paginated where applicable, and capability-checked at execution time.
