# Product Design Contract

This document defines the intended Aculect AI Companion product experience for the WordPress admin, connector setup, MCP-facing workflows, and maintainer documentation. It complements `PRODUCT.md`, `ROADMAP.md`, and `RELEASE.md`: product direction explains what the plugin is trying to become, while this contract explains how the experience should feel and behave when work changes admin UI, setup copy, diagnostics, workflows, or release-facing docs.

## Experience Principles

- Keep WordPress in control. Users should understand that the connected assistant acts through their WordPress user, their site permissions, and the abilities the site owner has approved.
- Optimize for safe completion, not technical display. The primary path should help a site owner connect an assistant, approve access, manage abilities, and inspect activity without reading protocol details.
- Make risk visible before action. Permission changes, assistant connections, workflow blockers, diagnostics exports, and destructive content actions need plain consequence copy before the user commits.
- Keep expert surfaces efficient. Developers and maintainers need dense, scannable diagnostics, MCP resource references, setup docs, and release notes without hiding important protocol details behind marketing copy.
- Preserve trust through recoverability. Empty, loading, denied, disconnected, expired, failed, and partially completed states should explain what happened, what is safe to retry, and what needs owner or developer intervention.

## Users And Jobs

### Site Owners

Site owners need to decide whether connecting an AI assistant is appropriate for a site, approve or revoke connections, set safe defaults, and review what assistants have done. The UI should foreground ownership, consent, ability controls, activity visibility, and pre-production risk until the product is production-approved.

### Administrators

Administrators need a fast, WordPress-native operating surface for setup, permissions, diagnostics, and troubleshooting. They should be able to move from `AI Companion > Connect` to abilities, activity, diagnostics, and advanced settings without learning MCP or OAuth internals.

### Editors And Content Operators

Editors need confidence that assistant-driven content work respects their role, site workflow, and content safety expectations. Copy and activity records should show the connected user, target content, action status, and useful next steps without exposing tokens, private payloads, or internal stack traces.

### Developer Maintainers

Developer maintainers need explicit contracts for MCP tools, resources, OAuth/DCR behavior, diagnostics, release packaging, and setup docs. Maintainer-facing docs can use protocol names and endpoint references, but should still separate public behavior, internal implementation notes, and release authorization rules.

## Core Surfaces

### Connection Setup

- The default setup path is endpoint-first: show the connection URL, direct users to paste it into a supported assistant, and return them to WordPress for consent.
- Avoid manual OAuth fields, client secrets, protocol diagrams, or internal route names in the primary setup path.
- Show compatibility and reachability requirements where they affect success, especially HTTPS, public accessibility, logged-in WordPress access, and supported assistant modes.
- Provide clear states for not started, ready to copy, waiting for assistant, consent required, connected, disconnected, expired, and unsupported client.

### Consent

- Consent screens must identify the assistant or client, the WordPress user granting access, the scope of requested access, and the consequence of approving.
- The approve action should be prominent but not coercive. The deny or cancel path should be visible and safe.
- If login is required, the user should land back on the Aculect consent screen after authentication, not on a raw REST endpoint.
- Consent copy must avoid implying that Aculect, WordPress, or the AI assistant can bypass WordPress capabilities or owner-approved abilities.

### Abilities And Role Controls

- Ability management should be dense but scannable, grouped by user job such as content, media, comments, site information, workflow, diagnostics, and WordPress abilities.
- Each ability needs a readable label, concise consequence copy, and a visible enabled, disabled, unavailable, or restricted state.
- Role and connection controls should make least privilege the default. Dangerous or broad abilities should explain the added risk and the permission required to change them.
- Public MCP tool names and internal ability IDs should stay out of primary user labels unless the surface is explicitly for developers.

### Activity And Audit Logs

- Activity logs should answer who requested the action, which connected assistant was used, what target was affected, when it happened, whether it succeeded, and what safe metadata is available.
- Logs should support scanning by status, assistant, user, target, and action type when the data set grows.
- Empty states should explain that future assistant actions will appear here and that large content payloads or sensitive request bodies are not stored.
- Failed and blocked rows should include a safe reason and next step without exposing secrets, PII, raw provider payloads, internal paths, or stack traces.

### Diagnostics

- Diagnostics are an expert support surface. They may be denser than setup screens, but must still group information by connection health, OAuth/DCR, MCP tools, resource availability, workflow readiness, logs, and environment checks.
- Diagnostic results should use explicit pass, warning, fail, unavailable, and unknown states with short remediation guidance.
- Export, copy, or report actions must state what data is included and exclude tokens, secrets, private salts, PII, and sensitive payloads by default.
- Do not let diagnostic copy blame the user. Name the failing condition and the next useful check.

### Advanced Settings

- Advanced settings should use progressive disclosure. Keep rare protocol, storage, logging, cleanup, and recovery controls out of the main setup path.
- Each advanced control needs owner-facing consequence copy, default value context, and a clear save or reset state.
- High-risk settings should require confirmation that names the setting, the consequence, whether the change is reversible, and which permission is required.
- Avoid exposing arbitrary `wp_options`, raw token material, or implementation-only toggles as user-facing settings.

### Workflow Guidance

- Workflow guidance should help assistants and users choose safe next steps for ambiguous, multi-step, or "do all" requests.
- Blockers should be specific and recoverable: missing permission, disabled ability, unsupported content type, missing data, unsafe request, external failure, or incomplete setup.
- Guidance should separate what the assistant can do now, what requires owner approval, and what should be performed manually in WordPress.
- Long-running or collection workflows should show bounded progress, partial completion, retry safety, and the item currently being processed.

### MCP Resources

- MCP resources are developer and assistant-facing context, not primary setup copy.
- Resource descriptions should be compact, deterministic, and explicit about freshness, limits, and any omitted sensitive data.
- User-facing docs can mention that resources provide capability, site, Site Editor, admin menu, content, brand, workflow, and approved memory context. Detailed protocol shape belongs in maintainer docs.
- Do not use MCP resources to expose secrets, private options, unbounded scans, or data beyond the connected user's permissions.

### Release And Setup Docs

- Setup docs should match the primary admin flow: copy the connection URL, configure the assistant, approve in WordPress, then manage abilities and activity.
- Release-facing docs should call out user-visible setup changes, consent changes, ability-policy changes, diagnostics changes, public MCP tool/resource changes, and packaging requirements.
- Release notes should not overpromise production readiness while the pre-production notice applies.
- Maintainer docs should preserve the distinction between GitHub source files and production ZIP contents.

## UI Patterns

- Use WordPress-native admin structure and `@wordpress/components` for plugin-owned screens unless there is an explicit reason for a custom surface.
- Favor dense but scannable layouts: clear section headings, compact tables, aligned labels, filters where lists grow, and stable primary actions.
- Keep one clear primary action per setup step. Secondary actions such as copy, disconnect, retry, view activity, or open diagnostics should not compete with the primary path.
- Use progressive disclosure for advanced, protocol, debug, and recovery details. Default screens should speak in user jobs, not implementation modules.
- Status and error states must be visible, specific, and accessible. Color alone is not enough; use text, icons where helpful, and proper focus handling.
- Responsive behavior should preserve the same task path on narrow screens. Tables and controls must remain readable, keyboard accessible, and usable with translated strings and long site or assistant names.
- Avoid nested card-heavy layouts, hero-style marketing panels, decorative gradients, and oversized illustration-driven UI in wp-admin. The product surface should feel like a capable admin tool, not a landing page.

## Copy Rules

### High-Risk Actions

- Name the object, the action, the consequence, reversibility, and required permission.
- Use confirmation copy for disconnecting assistants, enabling broad abilities, exporting diagnostics, changing advanced protocol settings, clearing logs, or retrying potentially mutating workflows.
- Do not hide risk in tooltips only. The main dialog or settings row must carry the consequence.

### OAuth And Connector Setup

- Use "connection URL", "connect", "approve", "disconnect", and "assistant" for primary user copy.
- Use "OAuth", "DCR", "MCP endpoint", and route names only in developer or maintainer surfaces.
- Explain recoverable setup failures with the condition and next action, such as HTTPS required, site unreachable, login required, unsupported assistant mode, expired authorization, or permission denied.

### Diagnostics

- State what was checked, the result, and the next useful remediation.
- Do not reveal secrets, tokens, private request bodies, stack traces, local filesystem paths, or raw provider payloads.
- When data is intentionally omitted, say that sensitive data is excluded rather than leaving the result ambiguous.

### Workflow Blockers

- A blocker should tell the user or assistant what cannot proceed, why, and what would unblock it.
- Distinguish permission blockers from disabled abilities, unsupported operations, missing content, invalid input, and external service failures.
- Avoid vague copy such as "something went wrong" when a safe specific reason is available.

### Release-Facing Docs

- Keep release notes concrete and user-visible. Name setup flow changes, consent changes, ability or role-policy changes, diagnostics behavior, MCP resource/tool changes, and package validation.
- Do not imply owner authorization, milestone retargeting, release creation, or production readiness unless those facts have been verified in the current release process.
- Keep public wording competitor-neutral and avoid presenting temporary implementation details as permanent product commitments.

## Non-Goals And Guardrails

- Do not redesign the plugin into a marketing site, dashboard splash page, chat product, or branded SaaS shell inside WordPress admin.
- Do not replace WordPress-native settings, list, notice, modal, tab, or form patterns without a concrete accessibility or workflow reason.
- Do not expose privacy or security-sensitive internals in UI, logs, diagnostics, MCP resources, release notes, screenshots, or setup docs.
- Do not change public MCP tool names, resource behavior, OAuth routes, consent semantics, or capability enforcement as a design-only task.
- Do not use UI copy to promise actions that WordPress capabilities, site owner controls, or ability policy do not actually allow.
- Do not treat advanced diagnostics or maintainer docs as a substitute for safe defaults in the primary setup and consent flows.
- Do not broaden the product surface beyond the active milestone without confirming issue scope, labels, milestone, and owner direction.

## Acceptance Checklist For Future Work

Use this checklist when a change touches admin UI, setup copy, consent, abilities, logs, diagnostics, workflows, MCP resources, or release/setup docs:

- The target user and primary job are clear.
- The happy path, empty state, loading state, permission-denied state, external-failure state, and recovery path are represented where relevant.
- High-risk actions include consequence copy and confirmation behavior.
- Advanced or protocol details are progressively disclosed.
- WordPress capabilities, role policy, and enabled abilities remain the source of truth.
- Copy avoids secrets, PII, internal paths, raw provider payloads, and unsupported production-readiness claims.
- Public MCP, OAuth, and REST behavior is unchanged unless the issue explicitly authorizes a contract change.
- Documentation links remain aligned with `PRODUCT.md`, `ROADMAP.md`, and `RELEASE.md`.
