=== Aculect AI Companion ===
Contributors: mehul0810
Tags: ai, content, claude, media, chatgpt
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress with AI. Aculect AI Companion helps you manage content, comments, media, and more with your AI assistant.

== Description ==

= Pre-production Notice =

Aculect AI Companion is an early release and is not intended for production websites yet. It can create, update, and manage WordPress content through connected AI assistants, so test it only on development or staging sites and enable it only with explicit approval from the site owner.

Aculect AI Companion lets site owners manage their WordPress site using an AI assistant. Instead of navigating WordPress menus, you can ask your AI assistant in plain English to create posts, update pages, moderate comments, upload media, and review safe site information.

Setup is designed to be simple:

1. Copy your connection URL from Settings > Aculect AI Companion.
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

Admins can enable or disable these abilities from Settings > Aculect AI Companion > Abilities after the first assistant connection is active.

Content:

* List readable content types, including custom post types
* List posts, pages, and custom content items with pagination
* Read one content item by ID
* Create a post, page, or custom content item
* Update title, content, excerpt, slug, or status for an existing item

Content groups:

* List available categories, tags, and custom content groups
* List terms for a supported taxonomy with pagination
* Create a category, tag, or custom content group
* Update a category, tag, or custom content group

Comments:

* List comments for review with pagination
* Read one comment by ID
* Reply to a comment as the connected WordPress user
* Moderate comment content or status

Media:

* List media library attachments with pagination
* Upload media from a public URL with server-side request checks

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
3. Open Settings > Aculect AI Companion.
4. Copy your connection URL.
5. Open your AI tool and add a new connector.
6. Paste the URL when prompted.
7. Approve the connection on the screen that appears.

== Frequently Asked Questions ==

= Does Aculect AI Companion send my data automatically? =

No. Aculect AI Companion does not send site data on activation or admin page load. Data is only available to an AI assistant after an administrator connects that assistant and approves access in WordPress.

= Can I disconnect access? =

Yes. Open Settings > Aculect AI Companion > Connections and disconnect one AI assistant or all active AI assistants.

= Can I control what my AI assistant can do? =

Yes. After a connection exists, open Settings > Aculect AI Companion > Abilities and enable or disable individual abilities. WordPress permissions are still checked every time your AI assistant asks Aculect AI Companion to do something.

= Does Aculect AI Companion require an account with a third-party service? =

Aculect AI Companion does not require a separate product account. To use it with an external AI assistant, you may need an account with that external service.

= Are custom post types and custom taxonomies supported? =

Yes. Aculect AI Companion can work with supported custom post types and custom taxonomies when they are visible through WordPress and the connected user has the required permissions.

== Development ==

The production package ships built assets and Composer dependencies. Development manifests such as `composer.json`, `composer.lock`, and `package.json` are intentionally excluded from release ZIP files.

For source code, build tooling, and exact dependency manifests, use the public GitHub repository:

https://github.com/mehul0810/aculect-ai-companion

Contributing guidelines:

https://github.com/mehul0810/aculect-ai-companion/blob/main/CONTRIBUTING.md

Security policy:

https://github.com/mehul0810/aculect-ai-companion/security/policy

From the repository checkout, rebuild assets with:

`npm install`
`npm run build`

Composer dependencies for production releases are installed with:

`composer install --no-dev --prefer-dist --optimize-autoloader`

== Changelog ==

= 0.1.0 =

* Connect a supported AI assistant by copying one connection URL from WordPress.
* Approve each AI assistant in WordPress before it can access your site.
* Choose which abilities your AI assistant can use after it is connected.
* See connected AI assistants and disconnect them whenever needed.
* Ask your AI assistant to help with content, comments, media, site information, plugins, and themes.
* Added clearer privacy notes and extra safety checks for testing.

== Upgrade Notice ==

= 0.1.0 =

Initial release.
