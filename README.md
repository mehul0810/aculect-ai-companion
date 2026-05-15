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

Open `Settings > Aculect AI Companion` in WordPress and follow the setup flow:

1. Copy your connection URL.
2. Open your AI tool and add a new connector.
3. Paste the URL when prompted.
4. Approve the connection on the screen that appears.

## Features

- Create, edit, and publish posts and pages.
- Manage categories, tags, and content groups.
- Moderate and reply to comments.
- Upload and list media.
- View site settings, active plugins, and themes.
- Connect and disconnect AI assistants.

## Supported AI Tools

Aculect AI Companion currently includes setup guidance for:

- ChatGPT app with Developer Mode connectors.
- OpenAI API integrations that support remote connectors.
- Claude app, Claude Desktop, Claude Cowork, and Claude mobile.
- Claude Code.
- Claude API integrations that support remote connectors.

Your AI tool must be able to reach your WordPress site over HTTPS to connect.

## Supported Abilities

Admins can enable or disable these abilities from `Settings > Aculect AI Companion > Abilities` after the first assistant connection is active.

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

## Project Docs

- [Contributing guidelines](CONTRIBUTING.md)
- [Security policy](SECURITY.md)

## Developer Notes

Aculect AI Companion implements a remote MCP interface secured by OAuth-style authorization with automatic client setup for compatible assistants. Those protocol details are intentionally hidden from the primary WordPress admin experience so non-technical users only need the connection URL.

### Release Packaging

Production ZIP files include built assets and Composer dependencies. Development manifests such as `composer.json`, `composer.lock`, and `package.json` stay in the GitHub repository but are excluded from release artifacts with `.distignore`. The generated `build/index.asset.php` file is required by WordPress for script dependencies and is shipped.

### Public Interfaces

- MCP: `/wp-json/aculect-ai-companion/v1/mcp`
- OAuth registration: `/wp-json/aculect-ai-companion/v1/oauth/register`
- OAuth authorization: `/wp-json/aculect-ai-companion/v1/oauth/authorize`
- OAuth token: `/wp-json/aculect-ai-companion/v1/oauth/token`
- Protected resource metadata: `/.well-known/oauth-protected-resource`
- Authorization server metadata: `/.well-known/oauth-authorization-server`
