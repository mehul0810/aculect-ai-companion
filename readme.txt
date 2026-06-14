=== Aculect AI Companion ===
Contributors: mehul0810
Tags: ai, mcp, chatgpt, claude, content
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.5.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use ChatGPT, Claude, Codex, and other MCP AI assistants to manage WordPress content, media, comments, and site workflows.

== Description ==

Aculect AI Companion is a secure MCP connector for WordPress. It lets approved AI assistants such as ChatGPT, Claude, Codex, and OpenAI-compatible tools work with your WordPress content, media, comments, and site information through permission-aware workflows.

Instead of switching between WordPress admin screens, you can ask your AI assistant in plain English to draft a post, update a page, review comments, upload media, inspect safe site details, or prepare long-form content using WordPress blocks.

WordPress remains the source of truth. Aculect AI Companion checks the connected WordPress user's permissions before every action, lets administrators choose which abilities are available, and keeps risky write actions behind configurable controls.

= What is MCP for WordPress? =

MCP stands for Model Context Protocol. In Aculect AI Companion, MCP gives supported AI assistants a structured, permission-controlled way to request WordPress actions. The assistant does not receive direct database access. It can only use the enabled tools exposed by WordPress and approved by an administrator.

= Why use Aculect AI Companion? =

* Connect WordPress to ChatGPT, Claude, Codex, and OpenAI-compatible MCP clients
* Create, update, and organize WordPress content through controlled AI workflows
* Work with posts, pages, custom post types, categories, tags, comments, and media
* Use block-aware content workflows instead of raw custom HTML
* Review AI activity with sanitized audit logs
* Control access globally, by connection, by role, and by WordPress user permissions
* Use diagnostics to verify OAuth, MCP, endpoint, and environment readiness

Setup is designed to be simple:

1. Copy your connection URL from AI Companion > Connect.
2. Open your AI tool and add a new connector.
3. Paste the URL when prompted.
4. Approve the connection on the screen that appears.

After approval, Aculect AI Companion checks the connected WordPress user's permissions before every action. You can also choose exactly what your AI assistant can do and disconnect assistants at any time.

= Features =

* Create, edit, and publish posts, pages, and supported custom post types
* Plan and draft long-form block content for WordPress
* Manage categories, tags, custom taxonomies, and content groups
* Moderate, reply to, and bulk-manage comments
* Upload, list, update, attach, detach, and safely rename media
* View safe site settings, active plugins, active themes, and diagnostics
* Connect, pause, review, and disconnect AI assistants
* Control which MCP abilities each assistant can use

= Supported AI Tools =

Aculect AI Companion includes setup guidance for popular AI assistants and MCP clients:

* ChatGPT app with Developer Mode connectors
* OpenAI API integrations that support remote connectors
* Claude app, Claude Desktop, Claude Cowork, and Claude mobile
* Claude Code
* Claude API integrations that support remote connectors

Your AI tool must be able to reach your WordPress site over HTTPS to complete OAuth approval and send MCP requests.

= Supported Abilities =

Admins can enable or disable these abilities from AI Companion > Abilities after the first assistant connection is active.

Long-form content workflows:

* Prepare a post outline, section plan, taxonomy recommendations, media recommendations, and SEO recommendations
* Create a draft from validated serialized WordPress block content
* Update an existing post with block-safe content workflows
* Update Rank Math SEO title, description, and focus keywords when Rank Math is active

Content:

* List readable content types, including custom post types
* List posts, pages, and custom content items with pagination
* Read one content item by ID
* Create a post, page, or custom content item with optional featured image, author, and taxonomy assignments
* Update title, content, excerpt, slug, status, featured image, author, or taxonomy assignments for an existing item
* Update SEO title, SEO description, and focus keywords for supported SEO plugins

Content groups:

* List available categories, tags, and custom content groups
* List terms for a supported taxonomy with pagination
* Create a category, tag, or custom content group
* Update a category, tag, or custom content group
* Assign or clear an image for supported taxonomy terms

Comments:

* List comments for review with pagination
* Read one comment by ID
* Reply to a comment as the connected WordPress user
* Moderate comment content or status
* Bulk moderate multiple comments

Media:

* List media library attachments with pagination
* Read, update, trash, and safely rename media items
* Upload media from a public URL with server-side request checks
* Attach media to posts or detach media from posts

Site information:

* View safe, non-secret site settings
* View WordPress version, PHP version, active theme, and basic site metadata
* List installed plugins and active state for users who can manage plugins
* List installed themes and active state for users who can manage themes

WordPress abilities:

* Discover supported public WordPress abilities registered by WordPress and plugins
* Inspect one supported public WordPress ability
* Run a supported public WordPress ability using the connected user's permissions

== Third Party Services ==

Aculect AI Companion does not send site data to an external service on activation, page load, cron, or without a site administrator connecting an AI assistant.

When a site administrator copies the connection URL into an external AI assistant and approves the connection screen in WordPress, that assistant can request the enabled abilities. Depending on the enabled abilities and the connected WordPress user's permissions, requested data may include post titles, post content, excerpts, slugs, statuses, authors, dates, permalinks, category and tag names, media metadata, comments, and safe site settings such as site name, description, URLs, locale, timezone, date format, time format, permalink structure, and active theme name.

The external service that receives this data is the AI assistant selected and configured by the administrator. Aculect AI Companion's built-in setup UI includes links for:

* ChatGPT by OpenAI: https://chatgpt.com/, terms at https://openai.com/policies/terms-of-use/, and privacy policy at https://openai.com/policies/row-privacy-policy/
* Claude by Anthropic: https://claude.ai/, terms at https://www.anthropic.com/legal/consumer-terms, and privacy policy at https://www.anthropic.com/legal/privacy
* Gemini by Google: https://gemini.google.com/, terms at https://policies.google.com/terms, and privacy policy at https://policies.google.com/privacy

Administrators should review the terms and privacy policy for the AI assistant they connect. Aculect AI Companion controls the WordPress-side approval and permissions checks; it does not control how a connected external assistant processes data after the administrator authorizes access.

== Installation ==

1. Upload the `aculect-ai-companion` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP from WordPress.
2. Activate Aculect AI Companion from the Plugins screen.
3. For stronger production hardening, define `ACULECT_AI_COMPANION_ENCRYPTION_KEY` in `wp-config.php` with a unique random value of at least 32 characters. If the constant is not defined, Aculect AI Companion generates a database-managed encryption key automatically.
4. Open AI Companion > Connect.
5. Copy your connection URL.
6. Open your AI tool and add a new connector.
7. Paste the URL when prompted.
8. Approve the connection on the screen that appears.

== Frequently Asked Questions ==

= Does Aculect AI Companion send my data automatically? =

No. Aculect AI Companion does not send site data on activation or admin page load. Data is only available to an AI assistant after an administrator connects that assistant and approves access in WordPress.

= What is Aculect AI Companion used for? =

Aculect AI Companion is used to connect WordPress with AI assistants through MCP. Site owners can use it to draft and update content, manage comments, upload media, review safe site information, and run controlled WordPress workflows from tools such as ChatGPT, Claude, Codex, and OpenAI-compatible MCP clients.

= Does Aculect AI Companion give AI assistants direct database access? =

No. Aculect AI Companion exposes structured MCP tools through WordPress. Connected assistants can only request enabled abilities, and WordPress permissions are checked when each tool runs.

= Can ChatGPT or Claude create WordPress posts with Aculect AI Companion? =

Yes. After an administrator connects and approves the assistant, ChatGPT, Claude, Codex, or another supported MCP client can create WordPress drafts or update content when the required abilities and WordPress permissions are available.

= Can Aculect AI Companion help with long-form WordPress content? =

Yes. Aculect AI Companion includes guided content workflows for planning long-form posts, validating block content, creating drafts, updating existing content, and applying supported SEO metadata.

= Does Aculect AI Companion support Rank Math SEO? =

Yes. Aculect AI Companion can update supported Rank Math SEO fields, including SEO title, meta description, and focus keywords, when Rank Math is active and the connected user has permission to edit the content.

= Can I disconnect access? =

Yes. Open AI Companion > Connections and disconnect one AI assistant or all active AI assistants.

= Can I control what my AI assistant can do? =

Yes. After a connection exists, open AI Companion > Abilities and enable or disable individual abilities. WordPress permissions are still checked every time your AI assistant asks Aculect AI Companion to do something.

= Why do diagnostics recommend ACULECT_AI_COMPANION_ENCRYPTION_KEY? =

Aculect AI Companion encrypts OAuth signing key material at rest. It does not use WordPress `AUTH_KEY` or other salts for this. If `ACULECT_AI_COMPANION_ENCRYPTION_KEY` is not defined, the plugin generates a random database-managed key so OAuth setup can continue securely. Define a unique `ACULECT_AI_COMPANION_ENCRYPTION_KEY` constant in `wp-config.php` for stronger protection because the key then lives outside the database. If the active key changes later, connected assistants must reconnect through the normal approval flow.

= Can I review what connected AI assistants changed? =

Yes. Open AI Companion > Activity to review MCP actions requested by connected AI assistants. The activity log stores the assistant, connected WordPress user, action, target, status, and sanitized metadata without storing large content payloads.

= Does Aculect AI Companion require an account with a third-party service? =

Aculect AI Companion does not require a separate product account. To use it with an external AI assistant, you may need an account with that external service.

= Can I try Aculect AI Companion in the WordPress.org preview? =

Yes. The WordPress.org preview opens a temporary WordPress Playground site with Aculect AI Companion active and takes you to the plugin settings screen. The preview is useful for reviewing the setup flow, supported abilities, diagnostics, and activity screens. For a full ChatGPT or Claude connection test, use your own HTTPS WordPress site because external AI assistants must be able to reach the WordPress site during OAuth and MCP requests.

= I use Cloudflare. Can Bot Fight Mode block the connection? =

Yes. Cloudflare Bot Fight Mode can block automated MCP requests from an AI assistant before they reach WordPress. Keep Bot Fight Mode disabled for the hostname used by your Aculect AI Companion connection URL; otherwise setup and later tool calls, such as creating or updating content, may fail even after the assistant was previously connected.

= I use Cloudflare. Can Flexible SSL prevent the connection from working? =

Yes. If your DNS record is proxied through Cloudflare and SSL/TLS mode is set to Flexible, the AI assistant connection may fail. Use an end-to-end HTTPS mode such as Full or Full (strict) with a valid origin certificate for the hostname used by your connection URL.

= Are custom post types and custom taxonomies supported? =

Yes. Aculect AI Companion can work with supported custom post types and custom taxonomies when they are visible through WordPress and the connected user has the required permissions.

== Screenshots ==

1. Overview tab showing the 0.5.3 AI Companion experience.
2. Connect tab with the MCP connection URL and guided setup for ChatGPT, Claude, Codex, and OpenAI.
3. Connections tab for reviewing connected AI assistants, access levels, pause controls, and disconnect actions.
4. Abilities tab for controlling global MCP abilities, role policies, and confirmation gates.
5. Activity tab showing sanitized MCP activity across writes, reads, workflows, blocked calls, and batch jobs.
6. Learning tab for reviewing assistant feedback and durable Aculect Intelligence suggestions.
7. Diagnostics tab for checking endpoint, OAuth, MCP, and environment readiness.
8. Changelog tab with the current 0.5.3 release notes.

== Development ==

The production package ships built assets and Composer dependencies. Development manifests such as `composer.json`, `composer.lock`, and `package.json` are intentionally excluded from release ZIP files. Generated files under `build/` are not committed to the source repository; release automation generates them before packaging.

For source code, build tooling, and exact dependency manifests, use the public GitHub repository:

https://github.com/mehul0810/aculect-ai-companion

Contributing guidelines:

https://github.com/mehul0810/aculect-ai-companion/blob/main/CONTRIBUTING.md

Security policy:

https://github.com/mehul0810/aculect-ai-companion/security/policy

From the repository checkout, use the Node.js version in `.nvmrc` and rebuild assets with:

`npm ci`
`npm run build`

Composer dependencies for production releases are installed with:

`composer install --no-dev --prefer-dist --optimize-autoloader`

== Changelog ==

= 0.5.3 =

* Improved MCP workflow discovery so content and SEO workflows are derived from the enabled atomic abilities instead of requiring separate workflow toggles.
* Improved read-only intelligence discovery so site, content, developer, brand, and capability context tools are available by default when OAuth read access allows them.
* Added an MCP capability help directory so assistants can discover available abilities, workflows, intelligence surfaces, blockers, prompts, and suggested next actions.
* Hardened durable intelligence memory writes so direct memory saves require review controls and normal learning suggestions use feedback submission.

= 0.5.2 =

* Fixed MCP content scheduling so future post status and date metadata persist correctly.
* Fixed missing or roleless MCP users so they resolve to a safe read-only ability policy.
* Fixed MCP tool availability so OAuth scope blockers are reported before exposing tools unavailable to the connected client.
* Added Cloudflare compatibility diagnostics for Bot Fight Mode and Flexible SSL/TLS connector issues.
* Updated GitHub Actions for the Node 24 runtime.
* Resolved an npm development dependency security alert.
* Added an OAuth connector smoke gate for dynamic client registration and authorization redirects.
* Bounded MCP PHPStan analysis into focused groups so release-readiness static analysis remains reliable.

= 0.5.1 =

* Improved OAuth secret storage so sites without `ACULECT_AI_COMPANION_ENCRYPTION_KEY` automatically use a generated database-managed encryption key instead of blocking assistant authorization.
* Updated diagnostics to warn, not fail, when OAuth secrets are encrypted with the database-managed fallback key.
* Kept `ACULECT_AI_COMPANION_ENCRYPTION_KEY` as the strongest production option for storing the encryption key outside the database.

= 0.5.0 =

* Refreshed the AI Companion admin experience with clearer navigation, connection guidance, diagnostics, activity, and changelog surfaces.
* Added AI Activity logging.
* Added role-specific MCP ability policy controls and per-user access pause controls.
* Added per-connection write permission controls for trusted assistants.
* Added Intelligence Layer.
* Added block and pattern knowledge so assistants can understand available WordPress blocks and patterns without relying on custom HTML blocks.
* Added settings import, export, and reset actions for safer configuration transfer and recovery.
* Added an authenticated MCP tool manifest export for diagnosing client-specific tool discovery differences.
* Added guided MCP content workflow tools for long-form post planning, draft creation, section-based post updates, and Rank Math SEO updates.
* Added reviewed learning suggestions so assistants can report improvement opportunities for admin review.
* Reduced unnecessary admin payload work for tab-specific data.
* Refined Connections and Abilities list views with assigned-abilities modals, access-level controls, and desktop-friendly DataViews layouts.
* Polished settings header spacing, tab navigation, Advanced layout, admin notices, loading states, and connector branding.
* Hardened OAuth storage maintenance so repeated dynamic client registration is bounded without blocking valid connector retries.
* Polished the connection access modal logo and form spacing.
* Updated workflow tool availability so workflows only appear when the required underlying global abilities, role policy, and OAuth scopes allow them.
* Added provider compatibility guards for MCP tool descriptors and schemas.
* Expanded cleanup coverage for full uninstall data removal.
* Expanded unit coverage for workflow tools, content intelligence indexing, activity logging, OAuth error handling, tool safety, and cleanup.

= 0.4.0 =

* Added featured image, author, taxonomy, and supported SEO metadata controls to content workflows.
* Added taxonomy term image assignment for supported content groups.
* Expanded media workflows with media read, update, trash, attachment relationship, and safe physical filename rename actions.
* Expanded comment workflows with richer filters and bulk moderation.
* Added safe site health summaries and broader site management visibility.
* Added dry-run previews and confirmation gates for higher-risk write actions.
* Added role-based MCP connection entry points for non-admin users.
* Added policy-controlled bridging for public WordPress Abilities registered by WordPress and other plugins.
* Added Codex connector setup guidance.
* Improved MCP list payloads with compact defaults and sparse field controls for lower-volume responses.
* Modularized MCP tool definitions, schemas, scopes, read-only hints, and handlers to reduce drift as more tools are added.
* Expanded test and CI coverage for MCP tools, permissions, safety controls, media workflows, and release packaging.

= 0.3.0 =

* Added an AI Activity tab for reviewing write actions requested by connected AI assistants.
* Added a Connections control to temporarily pause or resume AI access without disconnecting assistants.
* Added connection diagnostics that check endpoint, OAuth, and MCP readiness from the settings screen.
* Refreshed the settings screen header with Aculect branding and clearer AI agent connection messaging.
* Added WordPress.org plugin icon, banner, branded screenshots, and a Playground preview blueprint for the plugin listing.
* Improved action permission controls so AI tools only expose and run enabled, authorized abilities.
* Added OAuth storage maintenance to clean up expired connection data.
* Hardened media uploads from public URLs with stricter sideload safeguards.
* Expanded connector flow, activity log, access control, media, OAuth, and diagnostics test coverage.
* Fixed the Active Connections screen so the active access notice does not appear when no assistants are connected.

= 0.2.1 =

* Fixed diagnostics logging when the logs table is missing but the saved diagnostics database version is current.
* Added automatic repair for the diagnostics logs table during plugin boot.
* Added Cloudflare troubleshooting guidance for Bot Fight Mode and Flexible SSL/TLS connection issues.
* Clarified that Cloudflare Bot Fight Mode should remain disabled for the hostname used by the MCP connection URL because it can block setup and later tool calls.
* Added unit coverage for diagnostics log table repair.

= 0.2.0 =

* Added opt-in diagnostic logging for AI assistant connection flows.
* Added an Advanced setting to enable diagnostics and a Logs tab that appears only when logging is enabled.
* Added sanitized lifecycle and error logs for dynamic client registration, OAuth discovery, authorization, token exchange, and MCP authorization checks.
* Added 30-day log retention, automatic pruning, pagination, and clear-log controls.
* Hardened OAuth consent and authorization request handling with allowlisted, context-aware sanitization.
* Added stricter validation for response type, resource, PKCE, scopes, redirect URI, and consent decisions.
* Added unit coverage for diagnostic logging, redaction, repository behavior, and OAuth authorization parameter handling.

= 0.1.0 =

* Connect a supported AI assistant by copying one connection URL from WordPress.
* Approve each AI assistant in WordPress before it can access your site.
* Choose which abilities your AI assistant can use after it is connected.
* See connected AI assistants and disconnect them whenever needed.
* Ask your AI assistant to help with content, comments, media, site information, plugins, and themes.
* Added clearer privacy notes and extra safety checks for testing.

== Upgrade Notice ==

= 0.5.3 =

Improves MCP discovery with derived workflows, default read-only intelligence tools, a capability help directory, and reviewed durable memory writes.

= 0.5.2 =

Improves MCP scheduling, OAuth-scope-aware availability, connector diagnostics, and release-readiness checks.

= 0.5.1 =

Improves OAuth authorization reliability by adding an encrypted database-managed fallback key when the wp-config encryption constant is not defined.

= 0.5.0 =

Refreshes the AI Companion admin experience, adds AI activity logging, role ability controls, Intelligence Layer workflows, and stronger MCP diagnostics.

= 0.4.0 =

Expands content, media, taxonomy, comment, site health, and WordPress Abilities workflows with stronger safety controls and a more modular MCP tool layer.

= 0.3.0 =

Adds AI activity visibility, access pause controls, connection diagnostics, stronger safeguards, and refreshed onboarding screens.

= 0.2.1 =

Fixes diagnostics log table repair and adds Cloudflare MCP connection guidance.

= 0.2.0 =

Adds opt-in connection diagnostics and hardens OAuth consent request handling.

= 0.1.0 =

Initial release.
