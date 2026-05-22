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
site health summaries, and plugin/theme inventory. New tool groups should stay
deterministic, paginated where applicable, and capability-checked at execution
time.

## Safety Controls

Write-capable tools accept `dry_run: true` to validate the request and return a
deterministic preview without changing WordPress data. Previews include the
target object, proposed changes, warnings, risk level, and whether confirmation
is required.

High-risk actions such as publishing, trashing, spam changes, and running
generic WordPress abilities require a short-lived `confirmation_token` before
execution. Tokens are bound to the connected user, OAuth client, provider, tool,
and exact argument payload, and are consumed after one successful use.

Comment workflows support review filters for moderation status, post, author,
author email, author user ID, search, and date ranges. Replies are created with
`comments_create_item` by passing `parent_id`, and `comments_bulk_update`
requires confirmation for every bulk moderation run.

Admins can configure additional ability groups that require confirmation for
every write action. High-risk actions still require confirmation even when no
group is selected.

Delete-style behavior should prefer reversible WordPress states. Built-in
content trashing uses the WordPress trash instead of permanent deletion, and
comment trash responses include recovery guidance.

Assistant-triggered media sideloads are bounded before WordPress imports the
file. Aculect AI Companion checks public URL headers when available, caps the
streamed download size, and validates the downloaded file type against the
site's allowed upload MIME types. The default size limit is 10 MB and can be
changed with the `aculect_ai_companion_media_upload_max_bytes` filter. Allowed
MIME types can be narrowed or expanded with
`aculect_ai_companion_media_upload_allowed_mime_types`.
