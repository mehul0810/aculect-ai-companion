# Protected OAuth connection contract

The owner confirmed the 0.8.0-beta.13 connection works. Preserve its behavior; tests are intended to detect regressions, not authorize a redesign of authentication.

## Invariants

- Discovery advertises `/aculect-ai-companion/oauth/authorize`, not another provider's generic `/oauth/authorize` route. The generic compatibility alias may be shadowed by another plugin.
- Repeated valid public-client registrations succeed and remain usable across browser requests. An earlier pending registration must not become an unknown client merely because a retry occurred.
- Logged-out users sign in and return to admin consent. Already authenticated users reach consent directly, including through the REST authorization entry without a REST nonce.
- Consent is explicit and bound to the correct user/request. Invalid sessions, absent/invalid approval nonces, invalid redirects, bad PKCE, replayed codes, expired grants and unauthorized scopes remain rejected.
- An approved authorization can exchange its code and authenticate MCP requests. Refresh and revocation must preserve their existing security contracts.
- Another provider on the generic authorization route must not intercept the plugin-owned route. Responses involving authorization or credentials must not be publicly cached.
- Test output is stage-level only. No credentials, cookies, client IDs, authorization URLs, request bodies, callback codes or tokens in logs, screenshots, traces or uploaded artifacts.

## Failure and approval policy

1. Stop the affected release. Preserve the failing commit, package checksum and sanitized stage/status evidence.
2. Classify the failure as fixture/infrastructure, plugin/origin, discovery/cache, or external edge/provider. An absent WordPress log does not establish which edge product blocked the request.
3. Report the expected behavior, observed behavior, bounded reproduction and proposed change to the owner.
4. Obtain explicit owner approval before changing OAuth behavior or changing tests to accept different behavior. A general request to repair CI is not permission to alter authentication.
5. Rerun affected tests and nearby boundary probes after an approved correction. A passing rerun does not erase the initial failure; document why it failed and why the correction is sufficient.

No automatic retry, alternate auth fallback, security disabling, assertion weakening or silent acceptance of a flaky connection test is allowed. Infrastructure retries outside the contract must not hide a contract failure.

### Owner-approved 0.8.0 exception

On 19 September 2026 the owner deferred token-error cache-header hardening to issue #531 in 0.8.1 and authorized aligning the release gate with that decision. Only credential-free OAuth error responses (HTTP 400–599) may report missing cache headers as `DEFERRED #531` when the candidate version is exactly `0.8.0`. Successful token responses still require both `Cache-Control: no-store` and `Pragma: no-cache`. Error status/body, PKCE, replay, refresh, consent and authorization checks remain mandatory. The exception automatically fails closed for 0.8.1 and other versions; unit tests enforce that boundary. This is an accepted gap, not proof that deferred headers are present.

Taxonomy assignment remains term-ID-only in 0.8.0. Slug support is separately tracked in #532 for 0.8.1.

## Enforcement and scope

`oauth-contract.yml` tests the canonical ZIP in disposable WordPress using synthetic users and a local callback. CI's Required CI aggregate rejects missing/failed/cancelled/skipped OAuth proof whenever a package is required. Both beta asset attachment and production deployment depend directly on the same exact-package proof.

The fixture setup and browser runner require `ACULECT_OAUTH_FIXTURE_OPT_IN=1` and an HTTP loopback site. Do not point these tools at a live site. Setup installs test-only MU hooks, and the suite creates synthetic clients and cycles plugin activation. Use only disposable data. The workflow supplies these inputs explicitly; credentials must never be pasted into chat.

CODEOWNERS identifies the owner of authentication, its tests and enforcement. The file alone does not enforce approval: repository-level protection and a usable reviewer/approval mechanism must also be enabled. Workflows in an unmerged feature branch do not protect the release branch. Never report policy documentation as active remote enforcement.

Release-event checks prevent attaching/deploying an unverified ZIP; the GitHub release event itself has already happened at that point. Run candidate CI before creating a release. A failed release build leaves no verified downloadable asset and must be reported, not declared shipped.

The fixture proves the plugin contract, not ChatGPT/Claude behavior or Cloudflare routing. Hosted connector sign-in is a separate release check. No production credentials or live registrations are required by CI. Standard Bot Fight Mode support is tracked separately in issue #530 for 0.9.0; no Cloudflare changes are included here.

The browser suite exercises activation cycling, not a true cross-version upgrade. Public revocation is not exercised by this browser suite; existing unit coverage remains separate. A web-profile registration and WordPress login/consent probe does not contact the hosted ChatGPT callback. Full token and MCP probes use a disposable loopback callback server on port 49123, closed when the suite finishes. Approval and denial response headers are checked before following the callback.
