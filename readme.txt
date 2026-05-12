=== Quark ===
Contributors: mehul0810
Tags: ai, content, claude, media, chatgpt
Requires at least: 6.5
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manage your WordPress site with AI. Talk to Claude or ChatGPT to create posts, update pages, moderate comments, and more -- no coding required.

== Description ==

= Pre-production Notice =

Quark is an early release and is not intended for production websites yet. It can create, update, and manage WordPress content through connected AI assistants, so test it only on development or staging sites and enable it only with explicit approval from the site owner.

Quark lets site owners manage their WordPress site using AI assistants like Claude and ChatGPT. Instead of navigating WordPress menus, you can ask your AI assistant in plain English to create posts, update pages, moderate comments, upload media, and review safe site information.

Setup is designed to be simple:

1. Copy your connection URL from Settings > Quark.
2. Open Claude or ChatGPT and add a new connector.
3. Paste the URL when prompted.
4. Approve the connection on the screen that appears.

After approval, Quark checks the connected WordPress user's permissions before every action. You can also choose exactly what your AI can do and disconnect assistants at any time.

= Features =

* Create, edit, and publish posts and pages
* Manage categories, tags, and content groups
* Moderate and reply to comments
* Upload and list media
* View site settings, active plugins, and themes
* Connect and disconnect AI assistants

== Third Party Services ==

Quark does not send site data to an external service on activation, page load, cron, or without a site administrator connecting an AI assistant.

When a site administrator copies the connection URL into an external AI assistant and approves the connection screen in WordPress, that assistant can request the enabled actions. Depending on the enabled actions and the connected WordPress user's permissions, requested data may include post titles, post content, excerpts, slugs, statuses, authors, dates, permalinks, category and tag names, media metadata, comments, and safe site settings such as site name, description, URLs, locale, timezone, date format, time format, permalink structure, and active theme name.

The external service that receives this data is the AI assistant selected and configured by the administrator. Quark's built-in setup UI includes links for:

* ChatGPT by OpenAI: https://chatgpt.com/, terms at https://openai.com/policies/terms-of-use/, and privacy policy at https://openai.com/policies/row-privacy-policy/
* Claude by Anthropic: https://claude.ai/, terms at https://www.anthropic.com/legal/consumer-terms, and privacy policy at https://www.anthropic.com/legal/privacy

Administrators should review the terms and privacy policy for the AI assistant they connect. Quark controls the WordPress-side approval and permissions checks; it does not control how a connected external assistant processes data after the administrator authorizes access.

== Installation ==

1. Upload the `quark` folder to the `/wp-content/plugins/` directory, or install the plugin ZIP from WordPress.
2. Activate Quark from the Plugins screen.
3. Open Settings > Quark.
4. Copy your connection URL.
5. Open Claude or ChatGPT and add a new connector.
6. Paste the URL when prompted.
7. Approve the connection on the screen that appears.

== Frequently Asked Questions ==

= Does Quark send my data automatically? =

No. Quark does not send site data on activation or admin page load. Data is only available to an AI assistant after an administrator connects that assistant and approves access in WordPress.

= Can I disconnect access? =

Yes. Open Settings > Quark > Connections and disconnect one AI assistant or all active AI assistants.

= Can I control what my AI assistant can do? =

Yes. After a connection exists, open Settings > Quark > Actions and enable or disable individual actions. WordPress permissions are still checked every time your AI assistant asks Quark to do something.

= Does Quark require an account with a third-party service? =

Quark itself does not require a Quark account. To use Quark with ChatGPT, Claude, or another AI assistant, you may need an account with that external service.

= Are custom post types and custom taxonomies supported? =

Yes. Quark can work with supported custom post types and custom taxonomies when they are visible through WordPress and the connected user has the required permissions.

== Development ==

The production package ships built assets and Composer dependencies. Development manifests such as `composer.json`, `composer.lock`, and `package.json` are intentionally excluded from release ZIP files.

For source code, build tooling, and exact dependency manifests, use the public GitHub repository:

https://github.com/mehul0810/quark

From the repository checkout, rebuild assets with:

`npm install`
`npm run build`

Composer dependencies for production releases are installed with:

`composer install --no-dev --prefer-dist --optimize-autoloader`

== Changelog ==

= 0.1.0 =

* Initial MVP for connecting Claude and ChatGPT to WordPress with one connection URL.
* Added WordPress approval flow for AI assistant connections.
* Added configurable actions for content, categories, tags, comments, media, and safe site information.
* Added active connection management and disconnect controls.
* Added WordPress.org plugin guideline disclosures and Plugin Check release gating.

== Upgrade Notice ==

= 0.1.0 =

Initial release.
