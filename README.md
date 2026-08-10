# Aculect AI Companion

[![CodeQL](https://github.com/mehul0810/aculect-ai-companion/actions/workflows/codeql.yml/badge.svg)](https://github.com/mehul0810/aculect-ai-companion/actions/workflows/codeql.yml)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)
![WordPress 6.5+](https://img.shields.io/badge/WordPress-6.5%2B-21759b.svg)
![License: GPL-2.0-or-later](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)

Connect WordPress with AI. Aculect AI Companion helps you manage content, comments, media, and more with your AI assistant.

## Tagline

Connect WordPress with AI.

## Pre-production Notice

Aculect AI Companion is an early release and is not intended for production websites yet. It can create, update, and manage WordPress content through connected AI assistants, so use it on development or staging sites until the integration has been tested for your workflow and approved by the site owner.

## Requirements

- WordPress 6.5 or later
- PHP 8.2 or later

## User Setup

Open `AI Companion > Connect` in WordPress:

1. Copy your connection URL.
2. Choose ChatGPT, Claude, Grok, or another AI app for the right setup guide.
3. Paste the URL when prompted by your AI app.
4. Approve the connection on the screen that appears.

## Features

- Create, edit, and publish posts and pages.
- Route multi-step MCP requests through guided content, SEO, site audit, and troubleshooting workflows.
- Manage categories, tags, and content groups.
- Moderate and reply to comments.
- Upload and list media.
- View site settings, active plugins, and themes.
- Inspect Site Editor and Admin Menu intelligence before planning admin-level theme or settings work.
- Keep internal-link discovery, audit, review, and approved apply flows in assistant-driven MCP workflows instead of a dedicated admin destination.
- Read compact MCP resources for capability, site, Site Editor, admin menu, content, brand, workflow, and memory context.
- Connect and disconnect AI assistants.

## Supported AI Tools

Aculect AI Companion keeps the primary setup surface focused on:

- ChatGPT custom apps. Developer Mode availability depends on your plan and workspace settings.
- Claude custom connectors through `Customize > Connectors`.
- Grok custom MCP connectors through [Grok Connectors](https://grok.com/connectors). Grok must be able to reach your WordPress MCP endpoint over public HTTPS.
- Cursor remote MCP servers.
- Standards-compatible remote MCP clients.

Your AI tool must be able to reach your WordPress site over HTTPS to connect.

## Supported Abilities

Admins can enable or disable optional abilities from `AI Companion > Abilities` after the first assistant connection is active. Baseline read/discovery abilities for core MCP guidance remain default-active and are not individually disableable, but WordPress capabilities, OAuth scopes, connection access, and execution-time policy checks still apply.

### Content

- List readable content types, including custom post types.
- List posts, pages, and custom content items with pagination.
- Read one content item by ID.
- Create a post, page, or custom content item.
- Update title, content, excerpt, slug, or status for an existing item.

### Content Groups

- List available categories, tags, and custom content groups.
- List terms for a supported taxonomy with pagination.
- Create a category, tag, or custom content group.
- Update a category, tag, or custom content group.

### Comments

- List comments for review with pagination.
- Read one comment by ID.
- Reply to a comment as the connected WordPress user.
- Moderate comment content or status.

### Media

- List media library attachments with pagination.
- Upload media from a public URL with server-side request checks.

### Site Information

- View safe, non-secret site settings.
- View WordPress version, PHP version, active theme, and basic site metadata.
- List installed plugins and active state for users who can manage plugins.
- List installed themes and active state for users who can manage themes.

### WordPress Abilities

- Discover supported public WordPress abilities registered by WordPress and plugins.
- Inspect one supported public WordPress ability.
- Run a supported public WordPress ability using the connected user's permissions.

## Activity Logging

`AI Companion > Activity` shows MCP actions requested by connected AI assistants, including the assistant, connected WordPress user, action, target, status, and sanitized metadata. Large content payloads are not stored in activity metadata.

## Project Docs

- [Contributing guidelines](CONTRIBUTING.md)
- [Release-candidate regression checklist](docs/release-candidate-regression-checklist.md)
- [Security policy](SECURITY.md)

## Developer Notes

Aculect AI Companion implements a remote MCP interface secured by OAuth-style authorization with automatic client setup for compatible assistants. Those protocol details are intentionally hidden from the primary WordPress admin experience so non-technical users only need the connection URL.

### Release Packaging

Production ZIP files include built assets and Composer dependencies. Development manifests such as `composer.json`, `composer.lock`, and `package.json` stay in the GitHub repository but are excluded from release artifacts with `.distignore`. Generated files under `build/` are not committed to the source repository; GitHub Actions runs `npm run build` before packaging so the release ZIP still ships `build/index.js`, `build/index.asset.php`, and stylesheets required by WordPress.

### MCP Ability Architecture

First-party MCP tools are registered as internal ability modules. Each module owns its metadata, JSON input schema, required OAuth scope, read-only flag, and execution callback. `AbilitiesRegistry` maps internal dotted IDs to client-safe public tool names and keeps legacy aliases working.

Safe baseline read/discovery modules can be classified as `core_default` in `AbilitiesRegistry`. Core-default abilities are always registered for connected assistants and omitted from user-facing enable/disable toggles. This policy is limited to read/discovery surfaces; write and admin operations still require normal global ability policy, role policy, OAuth scopes, confirmation or dry-run controls where applicable, WordPress capability checks, and audit logging.

`workflow_route_request` is the preferred first call for ambiguous or multi-step work. It classifies the user request, returns the next tool with arguments, points to a workflow guide, and reports operation blockers. `workflow_session_start`, `workflow_session_get`, and `workflow_session_update` provide compact server-side workflow state so clients do not need saved chat memory to resume long content or site-management work. `workflow_loop_create`, `workflow_loop_run_next`, and `workflow_loop_run_batch` add bounded item-aware progress for "do all" style collection workflows such as thin-page cleanup.

`content_media_apply_image` handles common image workflows end to end: use an existing media attachment, sideload a public image URL, import an externally generated image URL, accept base64/data URL image payloads, or search Openverse CC0 candidates before importing. The workflow can set the resolved image as featured media or insert safe core image, gallery, cover, or media/text blocks into existing content while preserving the existing media upload guardrails and content validation.

Internal-link intelligence is intentionally assistant-first. Use `content_internal_link_policy`, `content_audit_internal_links`, `content_find_internal_links`, and the reviewed suggestion flow to inspect policy, audit existing link health, find candidates, and only then dry-run or apply a reviewed update with the normal confirmation safeguards. This capability is not exposed as a dedicated admin tab.

Clients that support MCP resources can use `resources/list` and `resources/read` on the MCP endpoint for compact capability, site, Site Editor, admin menu, content, brand, workflow guide, and approved memory context.

This internal module registry is the foundation for the broader third-party action pack work tracked in #21. For now, third-party WordPress Abilities are bridged through the dedicated `wp_abilities.*` MCP tools and policy controls instead of letting external code inject arbitrary MCP tools directly.

### Public Interfaces

- MCP: `/wp-json/aculect-ai-companion/v1/mcp`
- OAuth registration: `/wp-json/aculect-ai-companion/v1/oauth/register`
- OAuth authorization: `/oauth/authorize`
- OAuth token: `/wp-json/aculect-ai-companion/v1/oauth/token`
- Protected resource metadata: `/.well-known/oauth-protected-resource`
- Authorization server metadata: `/.well-known/oauth-authorization-server`

The endpoint is stateless and POST-only. It supports MCP `2026-07-28` and
`2025-06-18`. Requests that omit `MCP-Protocol-Version` use the legacy
`2025-06-18` contract for compatibility with existing clients. Requests using
`2026-07-28` must send matching `MCP-Protocol-Version`, `Mcp-Method`, and (for
`tools/call`, `resources/read`, and `prompts/get`) `Mcp-Name` headers plus the
required per-request protocol and client-capability metadata. `server/discover`
advertises the supported versions.
Browser requests are accepted only from the exact public connector origin or
origins explicitly approved with the
`aculect-ai-companion/connectors/allowed_mcp_origins` filter; wildcards are not
accepted. JSON-RPC notifications require the same OAuth authentication as other
MCP requests. The server does not advertise list-change notifications and
returns HTTP 405 for GET/SSE probes because it does not implement a server event
stream.
