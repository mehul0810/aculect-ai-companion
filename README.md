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

## Public Endpoints

- MCP: `/wp-json/quark/v1/mcp`
- OAuth registration: `/wp-json/quark/v1/oauth/register`
- OAuth authorization: `/wp-json/quark/v1/oauth/authorize`
- OAuth token: `/wp-json/quark/v1/oauth/token`
- Protected resource metadata: `/.well-known/oauth-protected-resource`
- Authorization server metadata: `/.well-known/oauth-authorization-server`
