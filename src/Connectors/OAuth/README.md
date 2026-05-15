# Aculect AI Companion OAuth Connector Layer

This directory implements the OAuth 2.1 style authorization server used by MCP
clients such as ChatGPT and Claude.

## Storage Model

Aculect AI Companion uses plugin-owned custom tables for OAuth protocol state:

- `aculect_ai_companion_oauth_clients`
- `aculect_ai_companion_oauth_auth_codes`
- `aculect_ai_companion_oauth_access_tokens`
- `aculect_ai_companion_oauth_refresh_tokens`

These records are not stored in `wp_options`, user meta, or transients because
OAuth state needs indexed lookup by client, hashed token/code identifier, user,
resource, expiry, and revocation state. Token revocation must be visible
immediately, so the repository classes intentionally avoid object-cache reads
for token validation.

## Plugin Check DB Warnings

The repository classes contain scoped PHPCS suppressions for:

- `WordPress.DB.DirectDatabaseQuery.DirectQuery`
- `WordPress.DB.DirectDatabaseQuery.NoCaching`
- `WordPress.DB.DirectDatabaseQuery.SchemaChange` where schema changes are
  expected.

Those suppressions are intentionally narrow and include inline rationale. Do not
replace them with broad project-level ignores. Normal WordPress content,
taxonomy, media, comment, and settings reads should continue to use WordPress
core APIs instead of custom SQL.

## Security Notes

- Raw access tokens, refresh tokens, and authorization codes are never stored.
- Token and code identifiers are stored as SHA-256 hashes.
- Client secrets are stored with WordPress password hashing.
- Access-token and refresh-token checks query the current database state so
  revocation works immediately.
