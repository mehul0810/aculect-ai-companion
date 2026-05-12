# Quark

Quark turns WordPress into an MCP server for AI assistants.

## Requirements

- WordPress 6.5 or later
- PHP 8.2 or later

## Connector Setup

Quark uses an endpoint-only OAuth setup. Open `Settings > Quark`, copy the MCP endpoint URL, and add that URL to Claude or ChatGPT.

- Claude: run the command shown in `Settings > Quark > Connectors`.
- ChatGPT: open connector settings, create a custom MCP connector, and paste only the MCP endpoint URL.

When the assistant starts authentication, WordPress shows a Quark OAuth consent screen. Approving the request completes the connection.

## Current Abilities

- Read, create, and update posts, pages, and supported custom post types.
- Read, create, and update terms in supported built-in and custom taxonomies.
- List and moderate comments, including creating and updating comments as the connected user.
- List media library items and upload media from public URLs.
- Read safe site settings, site information, plugin inventory, and theme inventory.
- Discover, inspect, and run public WordPress Abilities API actions registered by WordPress core or plugins.

## Public Endpoints

- MCP: `/wp-json/quark/v1/mcp`
- OAuth registration: `/wp-json/quark/v1/oauth/register`
- OAuth authorization: `/wp-json/quark/v1/oauth/authorize`
- OAuth token: `/wp-json/quark/v1/oauth/token`
- Protected resource metadata: `/.well-known/oauth-protected-resource`
- Authorization server metadata: `/.well-known/oauth-authorization-server`
