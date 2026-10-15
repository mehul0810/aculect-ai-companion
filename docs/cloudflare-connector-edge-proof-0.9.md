# Cloudflare connector edge proof for 0.9.0

Status: operator checklist, not completed edge proof. No Cloudflare setting is changed by the plugin.

## Product limit

Basic Cloudflare Bot Fight Mode can challenge non-browser OAuth/MCP traffic and cannot be skipped by a WAF custom rule or Page Rule. A plugin cannot make a request reach WordPress after Cloudflare has intercepted it. If basic Bot Fight Mode challenges the connector, the site operator must disable it for the connector hostname or use a Cloudflare product with scoped exceptions. Super Bot Fight Mode is different: Cloudflare supports a narrow WAF custom-rule Skip action for its phase. Do not exempt all WordPress REST traffic or weaken unrelated protection.

Cloudflare primary sources: [Bot Fight Mode false positives](https://developers.cloudflare.com/bots/troubleshooting/false-positives/), [Bot Fight Mode setup and limits](https://developers.cloudflare.com/bots/get-started/bot-fight-mode/), and [Super Bot Fight Mode exceptions](https://developers.cloudflare.com/bots/get-started/super-bot-fight-mode/).

## Exact external proof

Run on an approved non-production hostname with its actual Cloudflare mode recorded. Save only status, content type, the presence of `cf-mitigated`, and a redacted request identifier; never save registration secrets, OAuth codes, tokens, cookies, or full response bodies.

1. From an external client, fetch the OAuth authorization-server and protected-resource metadata. Both must be JSON, not an HTML challenge.
2. Send an unauthenticated MCP request. It must reach the plugin and return the expected Bearer challenge, not a Cloudflare 403/challenge.
3. Register a new dynamic client, then use that exact returned client ID through WordPress login, consent, code exchange with PKCE, token refresh, and one read-only MCP call. Test the first attempt and a fresh second client; a retry-only success is a failure signal.
4. Repeat while basic Bot Fight Mode is enabled, then under the operator-approved remediation. Repeat separately under Super Bot Fight Mode with only the required connector paths skipped, if that product is available. Compare Cloudflare Security Events with origin diagnostics to identify which layer answered.
5. Verify the remaining site still receives the intended Cloudflare protection. Revert the test rule after proof if it is not the approved production policy.

The route set is `/wp-json/aculect-ai-companion/v1/`, `/.well-known/oauth-`, `/oauth/authorize`, and `/aculect-ai-companion/oauth/authorize`. Origin responses and edge events must agree. Local/synthetic tests cannot mark this gate passed.
