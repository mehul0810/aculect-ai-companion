# Public WebMCP contract

Release scope: 0.9.0, public read-only page context. Authenticated WordPress
management, forms, publishing, private content, OAuth and remote MCP Apps are
separate features and are not exposed through this browser bridge.

## Browser contract

`aculect_get_page_context` uses `document.modelContext.registerTool()` with a
registration `AbortSignal`. It returns the existing structured page-context
shape: title, description, language, visible headings/content and optional links.
JSON output is limited to 1,500 characters, traversal to 400 nodes per walk,
headings to six and same-origin links to four. Links require `includeLinks: true`;
query strings, fragments and URL credentials are removed. Hidden, form and
editable content is excluded. Tool results are marked read-only and untrusted.

The PHP enqueue policy excludes logged-in, admin, JSON, feed, preview,
password-protected and non-published singular requests. The default-enabled
`aculect_ai_companion_webmcp_enabled` filter can disable eligible requests but
cannot override these exclusions. Page caches must preserve WordPress's
logged-in, preview and password protections; the plugin cannot repair a cache
that serves private HTML as public. Themes must call the normal WordPress
enqueue/footer hooks. No browser tool fetches backend or external data.

Duplicate asset execution reuses one bridge. Registration stops on page exit or
visibility loss and resumes on return, including back/forward restoration.
Initial load, DOM readiness, page return and visibility restoration each have
two finite readiness checks at 100 and 500 ms; there is no perpetual polling.
Unsupported browsers retain normal page behavior. A site integration that makes
the API available later can explicitly call `window.aculectWebMcp.register()`.
Cancelled and stale executions fail before exposing page context.

`window.aculectWebMcp.getStatus()` reports only state, registration attempts and
cumulative failures. It contains no content or errors and is neither transmitted
nor persisted. A successful retry does not erase the failure count.

## Diagnostics and recovery

Agent Readiness reuses its bounded, anonymous homepage response. It separates
filter configuration, local asset existence and an observed executable script
tag. Disabled is not applicable, a missing local asset fails, and a blocked
homepage is externally blocked. Script delivery alone remains a warning because
PHP cannot prove native browser execution. The HTML observation is limited to
64 KiB; long pages, bundled/CDN-renamed scripts and missing parser support can
produce an inconclusive observation. No page bodies, URLs or response headers
are saved in the diagnostic result.

For missing discovery, inspect the eligible anonymous page, its script request,
the browser's native API availability, then the local status. Check stale page
cache and optimization settings without disabling site security. If needed,
disable the bridge with the existing filter and purge only affected public-page
caches. No migration or persistent browser state needs rollback.

## Reproducible validation

- `node --test tests/js/webmcp.test.mjs` covers delayed readiness, failures,
  cancellation, stale registrations and bounded/private-content handling.
- `vendor/bin/phpunit --filter 'WebMcp|AgentReadinessDiagnostics'` covers the
  request exclusions and diagnostic classifications.
- `ACULECT_WEBMCP_BROWSER_CHANNEL=chrome npm run smoke:webmcp` exercises native
  registration, discovery and execution in a fresh browser profile against
  synthetic public HTML, including minified/deferred delivery, duplicate loading,
  navigation return, cached reload and unsupported-browser fallback. Omit the
  channel to use Playwright's installed Chromium. It fails if the required native
  API is unavailable; it never substitutes a mock API.
- Point `ACULECT_WEBMCP_ASSET` at the asset extracted from the candidate ZIP and
  set `ACULECT_WEBMCP_SHA256` to its digest to bind the browser proof to that file.
- On a fresh, local disposable WordPress install with the candidate activated,
  run `ACULECT_WEBMCP_FIXTURE=1 ACULECT_WEBMCP_WP_ROOT=/absolute/disposable/site
  php tests/Integration/WebMCP/public-contract.php`. It creates and removes only
  synthetic pages, suppresses mail, checks native request exclusions and native
  HTML parsing, and refuses non-local sites.
- For an approved public site, set `ACULECT_WEBMCP_PUBLIC_URL=https://example.com/`
  and run `npm run smoke:webmcp`. This uses a fresh anonymous context, restricts
  requests to same-origin GETs, injects no asset and calls only this read-only
  tool. Its receipt contains status/count/hash evidence rather than page data.
  With `ACULECT_WEBMCP_SHA256`, it also requires an anonymous re-fetch of the
  observed standalone asset URL to match (five-second/256 KiB limit, no
  redirects). This hash check is separate from browser execution, not a browser
  cache attestation. HTTP is permitted only for loopback fixtures with explicit
  `ACULECT_WEBMCP_FIXTURE=1`. Never supply authenticated URLs or browser profiles.

The browser receipt identifies its version and experimental flags. Native
Chromium proof is not proof of every agent, mobile browser, origin trial,
Cloudflare scanner or deployed site. Keep those results separate. The existing
OAuth checks are unchanged and are not invoked by these WebMCP commands.

API references checked on 2026-10-21:
[Chrome imperative API](https://developer.chrome.com/docs/ai/webmcp/imperative-api)
and [WebMCP draft](https://webmachinelearning.github.io/webmcp/).
This remains an experimental browser integration, not a claim of a finalized
standard or universal browser support.
