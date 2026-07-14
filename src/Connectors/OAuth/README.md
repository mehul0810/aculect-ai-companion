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

Expired authorization codes, access tokens, and refresh tokens are pruned
opportunistically during plugin boot with a throttled maintenance window. Rows
that are revoked before expiry are kept until their normal expiry time so
revocation checks remain immediate while the token or code could otherwise still
be presented.

Access tokens remain short-lived bearer credentials. Admin-visible connection
state is based on a non-revoked refresh token that can renew access for 30 days
from the last refresh; if an assistant does not use the connection during that
window, the refresh token expires and the connection is no longer active.
Expired access-token rows are retained only while they anchor an active refresh
token, keeping admin revocation available for the full connection window without
extending bearer-token validity.

## Rejected Refresh Activity

An `invalid_grant` refresh rejection happens before OAuth establishes a
WordPress identity. Activity therefore labels the identity as unavailable at
rejection time; it does not represent an authenticated unknown-user session.
Support metadata may classify the hashed stored refresh-token record as
`expired`, `revoked`, `not_found`, or `active_in_storage`, and may correlate the
event with an existing provider, hashed client identifier, and numeric
connection identifier. A revoked row does not preserve whether rotation,
disconnect, or another revocation path caused the state, so activity does not
claim that the token was replaced. Client-facing OAuth responses remain
unchanged, and the support action is to reconnect the assistant.

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
