# Quark

Quark helps site owners manage WordPress with AI. Connect Claude or ChatGPT, approve access in WordPress, then use plain English to create posts, update pages, moderate comments, upload media, and check site information.

## Tagline

Manage your WordPress site with AI.

## Requirements

- WordPress 6.5 or later
- PHP 8.2 or later

## User Setup

Open `Settings > Quark` in WordPress and follow the setup flow:

1. Copy your connection URL.
2. Open Claude or ChatGPT and add a new connector.
3. Paste the URL when prompted.
4. Approve the connection on the screen that appears.

## Features

- Create, edit, and publish posts and pages.
- Manage categories, tags, and content groups.
- Moderate and reply to comments.
- Upload and list media.
- View site settings, active plugins, and themes.
- Connect and disconnect AI assistants.

## Developer Notes

Quark implements a remote MCP interface secured by OAuth-style authorization with automatic client setup for compatible assistants. Those protocol details are intentionally hidden from the primary WordPress admin experience so non-technical users only need the connection URL.

### Public Interfaces

- MCP: `/wp-json/quark/v1/mcp`
- OAuth registration: `/wp-json/quark/v1/oauth/register`
- OAuth authorization: `/wp-json/quark/v1/oauth/authorize`
- OAuth token: `/wp-json/quark/v1/oauth/token`
- Protected resource metadata: `/.well-known/oauth-protected-resource`
- Authorization server metadata: `/.well-known/oauth-authorization-server`
