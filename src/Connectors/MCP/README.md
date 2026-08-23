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

## Workflow Routing And Sessions

`workflow_route_request` is the first-party entry point for ambiguous or
multi-step work. It classifies the user request, chooses a workflow guide when
one applies, returns the recommended next tool with arguments, and includes
operation availability so the assistant can explain blockers before retrying a
tool.

`workflow_session_start`, `workflow_session_get`, and
`workflow_session_update` store bounded transient workflow progress. Use these
for long-form content, SEO, site audit, and troubleshooting flows that may span
multiple MCP calls. Session data must remain compact: keep brief, intent,
content mode, target metadata, state, and short event messages; never store full
article bodies, secrets, OAuth tokens, or raw request payloads.

Workflow and session tools are first-party Aculect surfaces. They do not appear
in the admin ability list and are not controlled by global or role ability
toggles. They still require authenticated MCP access, OAuth scope checks, and
the normal execution-time WordPress capability checks in the underlying write
tools.

## Workflow Loops

`workflow_loop_create`, `workflow_loop_get`, `workflow_loop_run_next`,
`workflow_loop_run_batch`, `workflow_loop_pause`, and `workflow_loop_cancel`
store bounded item-aware progress for collection workflows. Use them when a
user asks for "do all" behavior after discovery, such as finding thin pages and
applying the same guidance item by item.

Loops store compact item metadata, per-item status, recent events, filters, and
the user's reusable guidance. They do not generate or write content themselves.
Run calls return the next `content_workflow_prepare_post` arguments so the
assistant still uses the normal content workflow, block validation, dry-run,
OAuth scope, role policy, capability checks, trusted-write policy, and activity
logging before any WordPress data changes.

Keep loop responses bounded. Do not store full article bodies, raw HTML, OAuth
tokens, private option values, or unbounded prior chat context in loop state.

## Content Media Workflows

`content_media_search_cc0_images` searches Openverse for bounded CC0 image
candidates. It is a review/discovery tool; the import path still validates the
selected media URL with the normal upload guard before WordPress sideloading.

`content_media_apply_image` resolves one image source and applies it to an
existing content item. Supported sources are existing attachment IDs, public
image URLs, externally generated image URLs, base64/data URL image payloads, and
Openverse CC0 search results. Supported targets are featured image assignment
and insertion of core image, gallery, cover, or media/text blocks. The workflow
uses existing media upload, featured-media validation, block validation,
dry-run, OAuth scope, trusted-write policy, capability checks, and activity
logging; it must not bypass the lower-level content or media safeguards.

## MCP Resources

The MCP endpoint supports `resources/list` and `resources/read` for compact
context that some clients can load more reliably than large tool calls. Current
resources cover capability directory, site summary, Site Editor context, admin
menu context, content model, brand profile, workflow guide summaries, and
approved Aculect memory.

Keep resource payloads bounded, JSON encoded, and free of secrets. Resources are
context surfaces, not write paths; changes to WordPress data must still go
through tools.

## Aculect Intelligence

Aculect Intelligence is a categorized context surface, not an entitlement layer
that hides operational abilities. Intelligence entries appear in the admin
catalog but are not directly controlled by global or role-based ability toggles.
Most tools are read-only context; reviewed feedback and incident-report tools
can write bounded local records. Every tool still requires an authenticated
connection, its declared OAuth scopes, profile visibility, WordPress capability
checks, and active AI access.

The intelligence layer is divided into these context domains:

- Site Intelligence: site identity, WordPress runtime, active theme, and connector context.
- Site Editor Intelligence: active theme, Appearance > Editor availability, global settings/styles, templates, template parts, navigation, blocks, and patterns without theme-file writes.
- Admin Menu Intelligence: visible admin menu pages, navigation targets, and registered setting metadata without raw option values.
- Navigation Intelligence: active navigation mode plus read-only classic menu, classic location, and `wp_navigation` inventory with bounded Navigation block parsing and no serialized markup writes.
- Content Intelligence: content types, taxonomies, registered block and pattern summaries, and generation constraints.
- Developer Intelligence: safe implementation context for understanding the WordPress runtime and extension surfaces.
- Brand Intelligence: saved and detected brand guidance for content, design, and media decisions.

Internal-link intelligence is part of the MCP content workflow, not a dedicated
WordPress admin destination. Assistants should read
`content_internal_link_policy`, inspect existing health with
`content_audit_internal_links` when needed, find candidates with
`content_find_internal_links`, and only then move through the reviewed
suggestion flow toward an apply call that still respects dry-run and
confirmation safeguards.

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

`plugin_lifecycle.list_plugins` and `plugin_lifecycle.get_plugin` provide
read-only installed-plugin lifecycle status: active/network-active state, cached
update availability, recovery pause state, multisite context, and capability
blockers. They must not install, update, activate, deactivate, delete,
uninstall, edit, execute, or expose raw plugin code.

`theme_lifecycle.list_themes` and `theme_lifecycle.get_theme` provide read-only
installed-theme lifecycle status: active state, parent and child relationships,
cached update availability, and block or classic or hybrid signals derived from
safe WordPress core helpers. They must not install, update, switch, delete,
edit, or expose filesystem paths, and they explicitly do not implement a
standalone theme-deactivation action because WordPress switches themes instead.

`navigation_get_context`, `navigation_list_menus`, `navigation_list_locations`,
and `navigation_list_items` provide read-only navigation context and inventory.
They cover classic menus and locations plus `wp_navigation` entities and bounded
Navigation block inspection. This slice intentionally does not implement menu
writes, location reassignment, `wp_navigation` mutation, theme-template edits,
or raw serialized navigation string edits. Future writes must preserve
unknown/custom blocks and attrs, validate parsed block structure before save,
and fail closed with recovery guidance.

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

`content_update_block` provides a conservative targeted block-edit path for
small text changes. Call `content_get_item` first and reuse a returned
`block_locators[].path` value as the locator. The beta-1 slice supports
plain-text replacement for registered `core/paragraph` and `core/heading`
blocks, validates the reserialized block document, supports `dry_run`, and
returns field-level diff metadata. Registered block attribute writes are
deferred until a narrower allowlist can be tested against third-party block
schemas.

Media tools include `media_get_item` and `media_update_item` for reading and
updating attachment title, alt text, caption, description, slug, and attachment
parent. Updating `post_id` changes the attachment parent relationship only after
the connected user can edit both the attachment and the target parent post.

`content_get_seo` reads saved SEO title, meta description, and focus keywords
for a connected user's readable content item through supported active SEO
plugin adapters. It does not expose arbitrary post meta, and unsupported,
inactive, inaccessible, missing, and adapter-failure states return distinct
public-safe errors.

## Safety Controls

Write-capable tools accept `dry_run: true` to validate the request and return a
deterministic preview without changing WordPress data. Previews include the
target object, proposed changes, warnings, risk level, whether confirmation is
required, and a reusable `diff` object for field-level review.

Dry-run `diff` responses use this shape:

```json
{
  "version": "1.0",
  "type": "field",
  "fields": [
    {
      "field": "title",
      "before": { "available": true, "value": "Old title" },
      "after": { "available": true, "value": "New title" },
      "changed": true
    }
  ],
  "unsupported": []
}
```

`fields[].field` is the public tool field name, not a storage key. `before`
values must be omitted with `{ "available": false, "reason": "not_readable" }`
when the connected user can validate a write but cannot safely read the
previous value. Long body fields are summarized in `diff` instead of returning
unbounded serialized content. Keep the legacy `changes` array for compatibility,
but prefer `diff` for new clients and future navigation, menu, and redirect
write previews.

High-risk actions such as publishing, trashing, spam changes, and running
generic WordPress abilities require a short-lived `confirmation_token` before
execution only when the connection is not trusted for direct writes. Connections
set to Write access in the admin Connections tab skip the token
prompt after OAuth scopes, role policy, global pause state, and WordPress
capability checks pass. Tokens are bound to the connected user, OAuth client,
provider, tool, and exact argument payload, and are consumed after one
successful use.

`plugin_incident_list` and `plugin_incident_report` are administrator-only and
require `manage_options` for discovery and execution. Listing remains read-only
and never requires write approval. Reporting stores a local report, so after
the capability check it follows the same approval contract as other writes: a
connection with Write access can submit directly, while a read-only connection
receives a short-lived `confirmation_token` for the exact report payload.
WordPress capability, selected tool-profile policy, OAuth scope, and
confirmation failures are evaluated separately so clients can surface the
actual blocker.

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
