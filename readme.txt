=== Quark ===
Contributors: mehul0810
Tags: mcp, ai, oauth, content, automation
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WordPress into an OAuth-protected MCP server for AI assistants such as ChatGPT and Claude.

== Description ==

Quark lets site administrators connect WordPress to AI assistants through the Model Context Protocol (MCP). After setup, supported assistants can request approved tools for reading content, drafting content, updating content, managing taxonomy terms, listing media, and reading safe site settings.

The primary setup is endpoint-only:

1. Open Settings > Quark in WordPress.
2. Copy the MCP endpoint.
3. Paste the endpoint into a supported MCP client such as ChatGPT or Claude.
4. Approve the WordPress OAuth consent screen.

Quark uses OAuth 2.1 style authorization with Dynamic Client Registration and PKCE. Connections are scoped, can be revoked from the Quark settings page, and still respect WordPress user capabilities for each action.

= Features =

* Endpoint-only setup for MCP clients.
* OAuth consent screen inside WordPress.
* Dynamic Client Registration for compatible clients.
* Read, create, and update abilities for posts, pages, custom post types, categories, tags, and custom taxonomies.
* Media listing ability.
* Safe site settings read ability.
* Per-ability enable/disable controls after connection.
* Active connection management and revocation.

== Third Party Services ==

Quark does not send site data to an external service on activation, page load, cron, or without a site administrator connecting an MCP client.

When a site administrator copies the MCP endpoint into an external MCP client and approves the WordPress OAuth consent screen, that client can request the enabled Quark abilities using the approved OAuth token. Depending on the enabled abilities and the authenticated WordPress user's capabilities, requested data may include post titles, post content, excerpts, slugs, statuses, authors, dates, permalinks, taxonomy names and descriptions, media metadata, and safe site settings such as site name, description, URLs, locale, timezone, date format, time format, permalink structure, and active theme name.

The external service that receives this data is the MCP client selected and configured by the administrator. Quark's built-in setup UI includes links for:

* ChatGPT by OpenAI: https://chatgpt.com/, terms at https://openai.com/policies/terms-of-use/, and privacy policy at https://openai.com/policies/row-privacy-policy/
* Claude by Anthropic: https://claude.ai/, terms at https://www.anthropic.com/legal/consumer-terms, and privacy policy at https://www.anthropic.com/legal/privacy

Administrators should review the terms and privacy policy for the MCP client they connect. Quark only provides the WordPress-side MCP and OAuth server; it does not control how a connected external assistant processes data after the administrator authorizes access.

== Installation ==

1. Upload the `quark` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP from WordPress.
2. Activate Quark from the Plugins screen.
3. Open Settings > Quark.
4. Copy the MCP endpoint into a supported MCP client.
5. Approve the OAuth consent screen when the client redirects to WordPress.

== Frequently Asked Questions ==

= Does Quark send my data automatically? =

No. Quark does not send site data on activation or admin page load. Data is only available to a connected MCP client after an administrator configures the client and approves the OAuth consent screen.

= Can I revoke access? =

Yes. Open Settings > Quark > Connections and revoke one session or all active sessions.

= Can I control which tools an AI assistant can use? =

Yes. After a connection exists, open Settings > Quark > Abilities and enable or disable individual abilities. WordPress capabilities are still checked on each tool call.

= Does Quark require an account with a third-party service? =

Quark itself does not require a Quark account. To use Quark with ChatGPT, Claude, or another MCP client, you may need an account with that external service.

= Are custom post types and custom taxonomies supported? =

Yes. Quark can expose supported custom post types and custom taxonomies when they are visible through WordPress and the authenticated user has the required capabilities.

== Development ==

The source files for the built admin assets are included in the plugin package under `src/`. To rebuild assets from source, use:

`npm install`
`npm run build`

Composer dependencies are installed for production releases with:

`composer install --no-dev --prefer-dist --optimize-autoloader`

== Changelog ==

= 0.1.0 =

* Initial MVP with endpoint-only MCP connector setup for ChatGPT and Claude.
* Added OAuth 2.1 style authorization, Dynamic Client Registration, PKCE, and WordPress consent.
* Added configurable MCP abilities for content, taxonomies, media, and safe site settings.
* Added active connection management and session revocation.
* Added WordPress.org plugin guideline disclosures and Plugin Check release gating.

== Upgrade Notice ==

= 0.1.0 =

Initial release.
