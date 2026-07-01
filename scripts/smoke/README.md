# Release UI Smoke

Run the first browser release-readiness smoke against a disposable WordPress admin site with Aculect AI Companion active:

```bash
ACULECT_SMOKE_BASE_URL="https://example.test" \
ACULECT_SMOKE_USERNAME="admin" \
ACULECT_SMOKE_PASSWORD="password" \
npm run smoke:release-ui
```

Required environment variables:

-   `ACULECT_SMOKE_BASE_URL`: WordPress site URL.
-   `ACULECT_SMOKE_USERNAME`: WordPress admin username or email.
-   `ACULECT_SMOKE_PASSWORD`: WordPress admin password.

Optional environment variables:

-   `ACULECT_SMOKE_ADMIN_PATH`: Admin settings path. Defaults to `/wp-admin/options-general.php?page=aculect-ai-companion`.
-   `ACULECT_SMOKE_ARTIFACT_DIR`: Output directory. Defaults to `artifacts/smoke/release-ui`.
-   `ACULECT_SMOKE_HEADLESS`: Set to `false` to watch the browser. Defaults to `true`.
-   `ACULECT_SMOKE_MCP_BEARER_TOKEN`: OAuth access token for the optional authenticated MCP `tools/list` smoke.
-   `ACULECT_SMOKE_MCP_PATH`: MCP endpoint path. Defaults to `/wp-json/aculect-ai-companion/v1/mcp`.
-   `ACULECT_SMOKE_TIMEOUT_MS`: Per-action timeout. Defaults to `30000`.

The script writes deterministic artifacts to `artifacts/smoke/release-ui/latest/` and replaces that directory on each run. Screenshots cover desktop and constrained admin widths for the admin shell tabs. The Learning tab smoke also verifies the Learning Suggestions, Memory Records, and Incident Reports surfaces are exposed through clear tab states and that the active Learning surface is not separated from its navigation by a large blank vertical gap.

When `ACULECT_SMOKE_MCP_BEARER_TOKEN` is provided, the smoke also calls the MCP endpoint with `initialize` and repeated paginated `tools/list` requests. The summary records the tool count, page count, pagination status, duplicate/invalid tool-name counts, and a deterministic SHA-256 fingerprint of the tools payload. The bearer token and raw tool payload are not written to artifacts.

If `ACULECT_SMOKE_MCP_BEARER_TOKEN` is omitted, the UI smoke still runs and `summary.json` records authenticated MCP discovery as skipped. Connect approval and OAuth consent/revoke remain manual or future seeded-flow proof gaps.

## MCP Live-Client Discovery Smoke

Run the focused live-client discovery smoke after connecting an external MCP client and minting a safe test access token:

```bash
ACULECT_MCP_SMOKE_BASE_URL="https://example.test" \
ACULECT_MCP_SMOKE_BEARER_TOKEN="redacted-test-token" \
ACULECT_MCP_SMOKE_RECONNECT_PROOF_URL="artifacts/smoke/mcp-live-client/manual/client-reconnect.png" \
npm run smoke:mcp-live-client
```

The smoke calls `initialize` twice, follows every `tools/list` page twice, and records only counts, pagination status, and SHA-256 fingerprints. When `ACULECT_MCP_SMOKE_RECONNECT_PROOF_URL` is provided, it repeats `initialize` and full `tools/list` collection after the external client reconnect/cache-refresh proof step. Use `ACULECT_MCP_SMOKE_RECONNECT_WAIT_MS` when the tester needs a pause before that post-refresh check.

The summary never writes bearer tokens or raw tool payloads. If reconnect proof is omitted, the baseline deterministic discovery check still runs and `summary.json` marks the external reconnect/cache-refresh proof as deferred.
