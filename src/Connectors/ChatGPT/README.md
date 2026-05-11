# ChatGPT Connector Notes

This document captures the working ChatGPT connection flow for Quark and the regressions we hit while getting OAuth + MCP working. Keep this updated whenever the ChatGPT connector flow changes.

## Working Flow

The working setup is endpoint-only:

1. WordPress admin opens `Settings > Quark > Connectors`.
2. Admin copies the Quark MCP endpoint URL only.
3. Admin opens ChatGPT connector settings and creates a custom MCP connector.
4. Admin pastes only the MCP endpoint URL.
5. ChatGPT discovers Quark OAuth metadata from the MCP endpoint/auth challenge.
6. ChatGPT dynamically registers an OAuth client through DCR.
7. ChatGPT redirects the user to WordPress OAuth authorize.
8. Quark redirects the browser to the wp-admin consent screen.
9. The WordPress user approves consent.
10. Quark issues an authorization code and ChatGPT exchanges it for tokens.
11. ChatGPT can call Quark MCP tools using the access token.

The admin should not copy or manually fill authorization, token, registration, client ID, or client secret fields in the primary UX.

## Public URLs ChatGPT Uses

These values are generated from `Quark\Connectors\Helpers`:

- MCP endpoint: `/wp-json/quark/v1/mcp`
- Protected resource metadata: `/.well-known/oauth-protected-resource` and resource-path variant
- Authorization server metadata: `/.well-known/oauth-authorization-server` and issuer-path variant
- Dynamic client registration: `/wp-json/quark/v1/oauth/register`
- Authorization endpoint: `/wp-json/quark/v1/oauth/authorize`
- Token endpoint: `/wp-json/quark/v1/oauth/token`

The MCP endpoint must be the only primary value shown to users. Metadata and OAuth URLs belong in advanced diagnostics only.

## Implementation Map

- ChatGPT provider label/setup copy: `src/Connectors/Providers/ChatGPT/Provider.php`
- Connector settings UI data: `src/Admin/SettingsPage.php`
- Shared URL/resource helpers: `src/Connectors/Helpers.php`
- MCP endpoint and OAuth challenges: `src/Connectors/MCP/McpController.php`
- OAuth metadata/discovery: `src/Connectors/OAuth/DiscoveryController.php`
- DCR endpoint: `src/Connectors/OAuth/ClientRegistrationController.php`
- OAuth authorize and consent handoff: `src/Connectors/OAuth/AuthorizationController.php`
- Token exchange: `src/Connectors/OAuth/TokenController.php`
- Token validation for MCP calls: `src/Connectors/OAuth/TokenValidator.php`

## Key Fixes That Made The Flow Work

### Endpoint-Only Setup

The primary setup had to be reduced to one field: the MCP endpoint. Showing client IDs, secrets, authorization URLs, token URLs, or registration URLs in the primary UX caused confusing and fragile manual setup paths.

### OAuth Discovery From MCP

Unauthenticated MCP requests must advertise OAuth through a bearer challenge and metadata. ChatGPT uses that to discover the authorization server and DCR endpoint.

The protected resource metadata needs to point to the canonical MCP resource and supported authorization servers. The authorization server metadata needs to advertise authorization, token, registration, supported scopes, PKCE `S256`, and resource indicators.

### Dynamic Client Registration Must Not 429 Valid Requests

The DCR endpoint previously returned plugin-level `429` responses after repeated setup attempts. ChatGPT may retry registration before authorization starts, so a local rate limiter blocked app creation before consent/redirection could happen.

Current rule: valid DCR requests must not return a plugin-level `429`. Invalid redirect URIs should still return `400`.

### Login Must Return To Consent, Not REST Authorize

The authorize endpoint previously sent logged-out users to `wp-login.php` with `redirect_to` pointing back to the REST authorize URL. That caused login loops or an admin login page stall.

Current rule:

- If the user is already logged in, `/oauth/authorize` redirects directly to the Quark wp-admin consent screen.
- If the user is logged out, `wp_login_url()` uses the Quark wp-admin consent screen as `redirect_to`.
- Consent approval/denial posts to `admin-post.php` with nonce validation.

### Root Authorize URL Compatibility

Some flows can hit `/oauth/authorize` outside the REST namespace. The rewrite rule redirects that root URL to the REST authorization endpoint while preserving query parameters.

## Common Errors We Hit

### `Dynamic client registration failed: registration endpoint returned 429`

Cause: plugin-level DCR rate limiting.

Fix: remove hard local rate limiting for valid DCR registration. Validate redirect URIs and create the client instead.

Regression check: run repeated valid DCR requests and confirm every request returns `201`, not `429`.

### Redirects To Login Even When Already Logged In

Cause: authorize flow did not cleanly hand off to wp-admin consent and relied on REST route state.

Fix: validate the OAuth request first, then send logged-in users directly to `options-general.php?page=quark&view=oauth-consent`.

Regression check: with an authenticated browser session, OAuth authorize should show the consent screen without landing on `wp-login.php`.

### Login Gets Stuck Instead Of Showing Consent

Cause: `wp_login_url()` used the REST authorize URL as `redirect_to`.

Fix: `redirect_to` must be the Quark admin consent URL, including the OAuth request parameters.

Regression check: in a logged-out browser, authorize should redirect to login with `redirect_to` containing `options-general.php?page=quark&view=oauth-consent`.

### ChatGPT Says The MCP Server Does Not Implement OAuth

Possible causes:

- MCP unauthenticated response did not include the expected OAuth bearer challenge.
- Protected resource metadata was missing or not reachable.
- Authorization server metadata did not include the registration endpoint.
- WordPress canonical redirects or HTML responses intercepted `.well-known` metadata.

Regression check: verify `.well-known` metadata returns JSON early, with `Access-Control-Allow-Origin: *` and no HTML/admin redirect.

### Authorization Screen Shows But Approval Fails

Possible causes:

- Redirect URI was not the same value used during DCR.
- Resource was not echoed consistently through authorize/token/MCP.
- PKCE challenge method was not `S256`.
- Consent posted back to the wrong endpoint.

Regression check: approve consent and confirm ChatGPT reaches token exchange without a WordPress login loop or invalid authorization request.

## Required Smoke Tests Before ChatGPT OAuth Betas

Run these before creating a beta release that touches OAuth, MCP discovery, settings setup, rewrites, or connector metadata.

### DCR Should Not 429

```bash
base='http://localhost:8895'
for i in 1 2 3; do
  curl -sS -o "/tmp/quark-dcr-${i}.json" -w "dcr_${i}_status=%{http_code}\n" \
    -X POST "$base/wp-json/quark/v1/oauth/register" \
    -H 'Content-Type: application/json' \
    --data "{\"client_name\":\"Quark DCR Smoke ${i}\",\"redirect_uris\":[\"https://chatgpt.com/connector/oauth/smoke-${i}\"]}"
done
```

Expected: every response is `201`.

### Logged-Out Authorize Should Return To Consent After Login

```bash
base='http://localhost:8895'
client_id='CLIENT_FROM_DCR_RESPONSE'
curl -sSI "$base/wp-json/quark/v1/oauth/authorize?response_type=code&client_id=$client_id&redirect_uri=https%3A%2F%2Fchatgpt.com%2Fconnector%2Foauth%2Fsmoke-1&scope=content%3Aread+content%3Adraft&code_challenge=abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKL&code_challenge_method=S256&resource=$base%2Fwp-json%2Fquark%2Fv1%2Fmcp&state=oauth_smoke_state" \
  | tr -d '\r' \
  | grep -Ei '^(HTTP/|location:)'
```

Expected: `302` to `wp-login.php` with `redirect_to` containing `options-general.php?page=quark&view=oauth-consent`.

### Logged-In Authorize Should Go Directly To Consent

Use a logged-in browser session and start the ChatGPT connector flow. Expected: WordPress shows the Quark consent screen directly, not the login page.

### Metadata Should Be JSON

```bash
base='http://localhost:8895'
curl -sSI "$base/.well-known/oauth-protected-resource" | grep -Ei '^(HTTP/|content-type:)'
curl -sSI "$base/.well-known/oauth-authorization-server" | grep -Ei '^(HTTP/|content-type:)'
```

Expected: `200` and JSON content type. No WordPress HTML page, no canonical redirect, and no login redirect.

## Release Checklist

Before a ChatGPT OAuth beta release:

- Run `composer test`.
- Run `npm run lint:css` if CSS changed.
- Run `npm run lint:js` and `npm run build` if settings JS changed.
- Run the DCR and authorize smoke tests above.
- Confirm the settings UI still shows only the MCP endpoint as the primary ChatGPT setup field.
- Confirm the release notes mention any OAuth behavior changes.
