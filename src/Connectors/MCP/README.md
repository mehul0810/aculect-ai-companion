# Aculect AI Companion MCP Connector

This directory contains the MCP server layer exposed at the Aculect AI Companion MCP endpoint.

## Ability IDs and Tool Names

Aculect AI Companion keeps internal ability IDs stable and descriptive, for example `content.list_items`. Public MCP tool names must be compatible with assistant clients that reject dots or other separators, so the public name is normalized to a safe form such as `content_list_items`.

When adding or changing tools:

- Keep the internal ability ID stable where possible.
- Expose only tool names matching `^[a-zA-Z0-9_-]{1,64}$`.
- Add legacy aliases in `AbilitiesRegistry::normalize_alias()` when renaming a tool.
- Add legacy aliases in `IntelligenceRegistry::normalize_alias()` when moving a context tool out of user-managed abilities.
- Update PHPUnit coverage for tool-name mapping and `tools/list` output.

This separation prevents client-specific validation rules from leaking into the plugin's internal ability model.

## Aculect Intelligence

Aculect Intelligence tools are always-on read-only MCP context tools. They are
not user-managed abilities, do not appear in the admin Abilities list, and are
not controlled by global or role-based ability toggles. They still require an
authenticated connection, the `content:read` OAuth scope, and active AI access.

The intelligence layer is divided into four context domains:

- Site Intelligence: site identity, WordPress runtime, active theme, and connector context.
- Content Intelligence: content types, taxonomies, registered block and pattern summaries, and generation constraints.
- Developer Intelligence: safe implementation context for understanding the WordPress runtime and extension surfaces.
- Brand Intelligence: saved and detected brand guidance for content, design, and media decisions.

Block and pattern inspection helpers also live in this layer so assistant
clients can understand the site's editable content surface without administrators
having to enable a separate user-managed ability. All intelligence guidance must
continue to state that assistants should never use the Custom HTML block
(`core/html`) for generated content.

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

Aculect AI Companion's built-in user-managed MCP abilities cover posts/pages/custom post types, taxonomies,
comments, media library listing/upload, safe site settings, site information,
site health summaries, and plugin/theme inventory. New user-managed ability groups
should stay deterministic, paginated where applicable, and capability-checked at
execution time.

`content_create_item` and `content_update_item` accept an `author` user ID when
the connected WordPress user can assign authors for the target post type. The
target user must exist and be able to own that post type. Omitting `author`
preserves WordPress' default author behavior.

`content_create_item` and `content_update_item` accept a `taxonomies` object
that maps taxonomy slugs to existing term IDs or term slugs, for example
`{ "category": [ 12, "release-notes" ], "post_tag": [ "mcp" ] }`. The
implementation validates that each taxonomy is exposed by WordPress, assigned to
the target post type, and assignable by the connected user. It only assigns
existing terms; term creation remains handled by `taxonomy_create_term`.

Content create and update tools can assign an existing image attachment as the
featured image through `featured_media`. Use media upload/list tools first when
the image is not already in the media library. Clearing a featured image requires
the explicit `clear_featured_media` flag on content update.

Content create and update tools accept `date` as `YYYY-MM-DDTHH:MM:SS` in the
site timezone, `YYYY-MM-DD HH:MM:SS`, or an ISO 8601 value with a timezone
offset such as `2026-06-01T09:00:00+00:00`. Invalid or empty date values return
a structured validation error instead of being silently converted by WordPress.
Tool output includes both the stored local `date` and `date_gmt`.

Media tools include `media_get_item` and `media_update_item` for reading and
updating attachment title, alt text, caption, description, slug, and attachment
parent. Updating `post_id` changes the attachment parent relationship only after
the connected user can edit both the attachment and the target parent post.

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
