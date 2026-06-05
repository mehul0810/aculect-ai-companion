=== Aculect AI Companion ===
Contributors: mehul0810
Tags: ai, content, claude, codex, chatgpt
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to ChatGPT, Claude, Codex, and other AI assistants through secure MCP workflows.

== Description ==

Aculect AI Companion lets site owners manage their WordPress site using an AI assistant. Instead of navigating WordPress menus, you can ask your AI assistant in plain English to create posts, update pages, moderate comments, upload media, and review safe site information.

Setup is designed to be simple:

1. Copy your connection URL from AI Companion > Connect.
2. Open your AI tool and add a new connector.
3. Paste the URL when prompted.
4. Approve the connection on the screen that appears.

After approval, Aculect AI Companion checks the connected WordPress user's permissions before every action. You can also choose exactly what your AI can do and disconnect assistants at any time.

= Features =

* Create, edit, and publish posts and pages
* Manage categories, tags, and content groups
* Moderate and reply to comments
* Upload and list media
* View site settings, active plugins, and themes
* Connect and disconnect AI assistants

= Supported AI Tools =

Aculect AI Companion currently includes setup guidance for:

* ChatGPT app with Developer Mode connectors
* OpenAI API integrations that support remote connectors
* Claude app, Claude Desktop, Claude Cowork, and Claude mobile
* Claude Code
* Claude API integrations that support remote connectors

Your AI tool must be able to reach your WordPress site over HTTPS to connect.

= Supported Abilities =

Admins can enable or disable these abilities from AI Companion > Abilities after the first assistant connection is active.

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

Administrators should review the terms and privacy policy for the AI assistant they connect. Aculect AI Companion controls the WordPress-side approval and permissions checks; it does not control how a connected external assistant processes data after the administrator authorizes access.

== Installation ==

1. Upload the `aculect-ai-companion` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP from WordPress.
2. Activate Aculect AI Companion from the Plugins screen.
3. Open AI Companion > Connect.
4. Copy your connection URL.
5. Open your AI tool and add a new connector.
6. Paste the URL when prompted.
7. Approve the connection on the screen that appears.

== Frequently Asked Questions ==

= Does Aculect AI Companion send my data automatically? =

No. Aculect AI Companion does not send site data on activation or admin page load. Data is only available to an AI assistant after an administrator connects that assistant and approves access in WordPress.

= Can I disconnect access? =

Yes. Open AI Companion > Connections and disconnect one AI assistant or all active AI assistants.

= Can I control what my AI assistant can do? =

Yes. After a connection exists, open AI Companion > Abilities and enable or disable individual abilities. WordPress permissions are still checked every time your AI assistant asks Aculect AI Companion to do something.

= Can I review what connected AI assistants changed? =

Yes. Open AI Companion > Activity to review write actions requested by connected AI assistants. The activity log stores the assistant, connected WordPress user, action, target, status, and sanitized metadata. Read-only actions are not logged in this version.

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

1. About tab with a plain-language overview of Aculect AI Companion.
2. Connect tab with the MCP connection URL and guided setup for AI tools.
3. Diagnostics tab for checking endpoint, OAuth, and MCP readiness.
4. Connections tab for reviewing connected AI assistants and access state.
5. Activity tab showing write actions requested by connected AI assistants.
6. Advanced tab with diagnostic logging and retention controls.
7. Logs tab showing sanitized connection lifecycle and error events.
8. Changelog tab with recent release notes.

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

= 0.5.0 =

* Refreshed the AI Companion admin experience with clearer navigation, connection guidance, diagnostics, activity, and changelog surfaces.
* Added brand profile guidance for connected assistants.
* Added role-specific MCP ability policy controls and per-user access pause controls.
* Added per-connection write permission controls for trusted assistants.
* Added the Aculect Intelligence Layer with site, content, developer, and brand intelligence context.
* Added block and pattern knowledge so assistants can understand available WordPress blocks and patterns without relying on custom HTML blocks.
* Added settings import, export, and reset actions for safer configuration transfer and recovery.
* Added an authenticated MCP tool manifest export for diagnosing client-specific tool discovery differences.
* Reduced unnecessary admin payload work for tab-specific data.
* Refined Connections and Abilities list views with assigned-abilities modals, access-level controls, and desktop-friendly DataViews layouts.
* Polished settings header spacing, tab navigation, Advanced layout, admin notices, loading states, and connector branding.
* Hardened OAuth storage maintenance so repeated dynamic client registration is bounded without blocking valid connector retries.
* Fixed Claude MCP tool invocation while keeping ChatGPT and Codex scope metadata aligned.
* Improved MCP tool discovery compatibility for Claude, ChatGPT, OpenAI, and Codex by prioritizing operational tools and simplifying input schemas.
* Aligned Intelligence Layer operation availability with the same global ability, role policy, and OAuth scope checks used by the MCP tools list.
* Added MCP initialize guidance, intelligence output schemas, and OpenAI invocation labels so assistants can discover and use Aculect Intelligence context more reliably.
* Normalized lazy settings payload requests to the current admin origin to avoid 403 errors when WordPress REST URLs use a different host or scheme.
* Polished the connection access modal logo and form spacing.
* Added provider compatibility guards for MCP tool descriptors and schemas.
* Included per-tool availability, policy blockers, required scopes, and read-only hints in intelligence context so assistants can decide which enabled abilities to use.
* Expanded cleanup coverage for full uninstall data removal.

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

= 0.5.0 =

Refreshes the AI Companion admin redesign, adds brand and role controls, and hardens OAuth/client cleanup for larger sites.

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
