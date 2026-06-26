# Release UI Smoke

Run the first browser release-readiness smoke against a disposable WordPress admin site with Aculect AI Companion active:

```bash
ACULECT_SMOKE_BASE_URL="https://example.test" \
ACULECT_SMOKE_USERNAME="admin" \
ACULECT_SMOKE_PASSWORD="password" \
npm run smoke:release-ui
```

Required environment variables:

- `ACULECT_SMOKE_BASE_URL`: WordPress site URL.
- `ACULECT_SMOKE_USERNAME`: WordPress admin username or email.
- `ACULECT_SMOKE_PASSWORD`: WordPress admin password.

Optional environment variables:

- `ACULECT_SMOKE_ADMIN_PATH`: Admin settings path. Defaults to `/wp-admin/options-general.php?page=aculect-ai-companion`.
- `ACULECT_SMOKE_ARTIFACT_DIR`: Output directory. Defaults to `artifacts/smoke/release-ui`.
- `ACULECT_SMOKE_HEADLESS`: Set to `false` to watch the browser. Defaults to `true`.
- `ACULECT_SMOKE_TIMEOUT_MS`: Per-action timeout. Defaults to `30000`.

The script writes deterministic artifacts to `artifacts/smoke/release-ui/latest/` and replaces that directory on each run. Screenshots cover desktop and constrained admin widths for the admin shell tabs. The Learning tab smoke also verifies the Learning Suggestions, Memory Records, and Incident Reports surfaces are exposed through clear tab states and that the active Learning surface is not separated from its navigation by a large blank vertical gap.

This first harness intentionally does not automate Connect approval, OAuth consent/revoke, or authenticated MCP `tools/list` discovery. Those flows need seeded clients/tokens and should be added as focused follow-up smoke slices instead of being faked here.
