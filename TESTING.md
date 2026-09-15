# Aculect AI Companion Validation Contract

## Baseline Expectations
- Every scoped change must name the exact validation commands that prove the touched behavior.
- Validation should scale with risk: narrow fixes get narrow checks; MCP, OAuth, release, and packaging changes need broader proof.
- If a required check cannot run, record the exact blocker and the remaining risk.

## Standard Checks
- Tools inspection: `vendor/bin/phpunit --filter 'SiteHealthInfoTest|PrivacyRequestStatusTest|NativeHealthSummaryTest|ToolsHandoffAbilitiesTest|ToolsOperationPolicyTest|SiteOperationsToolSurfaceTest'`. Validate capability-before-read, strict action/ID binding, native lifecycle changes, private-field exclusion, malformed data and unknown freshness. Native gateway proof must exercise a disposable WordPress runtime: unit adapters do not prove native admin dependencies are loaded. Never invoke real exports, send requester emails or erase real data during validation. Privacy lifecycle state is not exporter progress or proof that retained data is absent.
- Site-operation PHPStan lane: `composer analyse:mcp:site-operations`; focused tests: `vendor/bin/phpunit --filter 'NavigationItemWriteAbilitiesTest|RegisteredFieldAbilitiesTest|BoundedSiteOperationsTest|SiteOperationsToolSurfaceTest'`. These are WordPress-light tests, not live WordPress or hosted-connector proof.
- PHP syntax: `composer run lint:php`
- PHP unit and static analysis: `composer test` or the narrow `composer` scripts relevant to the touched surface
- JavaScript tests: `npm run test:js`
- JavaScript lint: `npm run lint:js`
- Diff hygiene: `git diff --check`
- Modularity budgets and dependency boundaries: `composer check:modularity`. For the pull-request ratchet (legacy files may not grow), run `php bin/check-modularity.php --config=.codex/modularity-rules.php --changed-from=origin/<base-branch>`.

## Extension Lifecycle Changes

- Static lane: `composer analyse:mcp:extensions`; module and gateway checks remain
  in their existing MCP lanes. No dependency or baseline increase is required.
- Focused regressions: `vendor/bin/phpunit --filter 'ExtensionDirectoryAbilitiesTest|ExtensionActivationGuardTest|ExtensionDeletionAbilitiesTest|ExtensionDeletionPolicyTest|ThemePackageAbilitiesTest|PluginLifecycleAbilitiesTest|ThemeLifecycleAbilitiesTest|AbilityExecutionGatewayTest|McpDiscoveryDeterminismTest'`.
- Prove directory limits, malformed remote fields, permission denial, closed
  schemas, metadata projection, and non-mutating native upload handoffs.
- Prove confirmed package identity, installed version postconditions, stale
  requests, replay, protected active/dependent extensions, single-site limits,
  direct-filesystem requirements, and disabled file modifications. Never run
  destructive tests against the working Studio site or real extension data.
- Round-trip deletion bindings through `ToolSafety` confirmation storage in
  regressions: it canonicalizes scalar values and key order. Comparing only
  service-local previews can miss a gateway integration failure.
- Check duplicate theme slugs in default and secondary registered roots: deletion
  must reject secondary-root targets without changing either directory. Core's
  theme deletion API resolves the default root, unlike theme inventory lookups.
- Use disposable WordPress and synthetic ZIPs for native upgrader/deletion proof.
  Test through the execution gateway and independently inspect native inventory.
  Locally substituted package downloads prove core integration, not WordPress.org
  availability, package trust, premium updates, or a full supported-version matrix.
- ZIP handoff success means only that the correct native upload screen was
  returned. Do not report an uploaded or installed ZIP until the native screen
  and subsequent inventory establish it. Do not automate private inputs.
- Run `npm run smoke:mcp-local` for changed discovery contracts. This uses fixture
  authentication and is not a hosted ChatGPT/Claude OAuth interoperability test.

## WordPress Abilities Integration

The PHPUnit suite deliberately uses WordPress-light stubs and cannot prove the
native Abilities API lifecycle. `.github/workflows/wordpress-abilities.yml`
runs `tests/Integration/WordPressAbilities/contract.php` in `wp-env` against
the supported WordPress matrix (6.8.1 compatibility, 6.9, and 7.1). The contract verifies native
registration, object schemas, public exposure metadata, permission callback
return types, and the expected Aculect read abilities. Older core versions
without `wp_get_abilities()` report an explicit skip.

## Pull Request PHP Gates
- Pull requests targeting `main`, `develop`, or `release/**` must pass `CI / PHP Quality`.
- `CI / PHP Quality` is the complete PHP quality gate: Composer validation, PHP lint, WPCS, `composer analyse:mcp`, `composer analyse:core`, and PHPUnit.
- Pull requests targeting `main`, `develop`, or `release/**` must also pass `PHP Security / Semgrep PHP Security`.
- The PHP security lane is expected to cover OAuth flows, REST and MCP permission paths, storage, uploads, and output-handling regressions through maintained Semgrep PHP and secrets rules, with SARIF uploaded into GitHub code scanning.
- If `phpstan-baseline.neon` changes, the PR body must include a non-empty `PHPStan baseline justification:` line that explains the reviewed reason for the baseline delta.

## Release and Proof Checks
- OAuth login/consent routing: `composer smoke:oauth` with disposable-site `ACULECT_SMOKE_BASE_URL` and locally supplied `ACULECT_SMOKE_COOKIE_HEADER`. The smoke checks repeated DCR and logged-in/logged-out redirects on both `/oauth/authorize` and the REST authorization entry, without a REST nonce. Never paste cookies into chat. A valid session must reach admin consent directly; missing or invalid sessions must log in first with an admin-consent return target. Cookie inspection for redirect selection must never authenticate the REST request or bypass consent checks.
- OAuth retry/persistence regressions: `vendor/bin/phpunit --filter 'OAuthDcrSqlitePersistenceTest|OAuthRepositoryTest'`. Repeated registrations must preserve pending clients, including at capacity; duplicate cleanup must retain live credentials and only remove registrations older than the existing stale-client threshold (24 hours by default). Failed access-token insertion must stop issuance before superseded-session revocation. These repository tests do not prove an end-to-end hosted OAuth exchange or transactional refresh rollback.
- Non-live release proof may use fixture-backed smoke coverage when secrets are unavailable.
- Before any live MCP credential is used, run `npm run smoke:mcp-local`. This starts a local PHP fixture server around the actual `McpController`, drives it with the pinned MCP SDK, and proves the no-secret `initialize` → initialized notification → paginated `tools/list` → `site_get_info` flow plus invalid-bearer rejection. Its summary is written to `artifacts/smoke/mcp-local/latest/summary.json`.
- Live admin/browser proof still requires the owner-provided smoke inputs documented in `scripts/smoke/README.md`.
- MCP discovery changes must prove deterministic `initialize` and paginated `tools/list` behavior.
- MCP transport changes also require `npm run smoke:mcp-sdk` against the target using locally configured OAuth smoke credentials. The SDK performs initialization, the initialized notification, paginated discovery, and a read-only `site_get_info` call. The result identifies the failed stage and HTTP status without printing bearer tokens or tool payloads. Unit tests, synthetic HTTP fixtures, and a successful GET response do not prove a hosted connector works.
- Distinguish Streamable HTTP from legacy HTTP+SSE when diagnosing fallback. An optional GET 405 is valid for Streamable HTTP; a legacy SSE client expects an `endpoint` event. Capture the failing exchange before changing transport behavior or adding protocol versions.
- High-risk write-path changes must include confirmation, capability, rollback, and audit-log validation.

## PR Reporting

### Private settings handoff

- Run `vendor/bin/phpunit --filter PrivateSettingRequestsTest` for allowlists,
  role/HTTPS/site-binding gates, expiration/replacement, replay, stale writes, owner-checked
  locks, provider rejection, and value-free schemas/results/metadata. These
  tests use isolated SDK and metadata doubles, not a live provider or database.
- Before release, use a disposable HTTPS WordPress instance and synthetic input
  only: request a form through MCP, manually save a distinct site title, reload,
  and verify native persistence plus status-only MCP output. Repeat with an
  unauthorized account, missing/invalid nonce, expired request, replay, and a
  changed underlying setting. Inspect redirects and activity for value leakage.
- Verify desktop/mobile keyboard flow and response security headers. Connector
  coverage requires installed supported provider plugins; fixture acceptance
  must not be described as live credential verification. Never paste keys into
  chat or capture populated private fields in screenshots or automation traces.
- Development proof on 2026-09-15 used a disposable WordPress 7.1/PHP 8.4.8
  SQLite site behind a loopback HTTPS proxy. A fresh source-blind browser run
  verified save/persistence, value-free result/redirect/audit, replay, invalid
  nonce, anonymous/subscriber denial, replacement/expiry, stale state, desktop
  and 320px layout, labels/descriptions, and keyboard traversal. The fixture
  explicitly marked its trusted local proxy as HTTPS; this does not prove
  production TLS/proxy configuration, live provider credentials, external MCP
  OAuth clients, MySQL concurrency, or live multisite behavior.
- A bounded independent source review found and verified a multisite site-ID
  binding fix; cross-site and legacy-unbound requests have regression tests.
  The separate formal security scans remained incomplete on earlier snapshots
  and must not be described as passing. Native storage is not encrypted by this
  feature; opt-in uninstall cleanup of value-free request/lock metadata remains
  a low-priority limitation.

- PRs must list the commands actually run.
- Negative-path checks should be called out when they are part of the acceptance criteria.
- CI failures should be owned by the surface they block: PHP lint, WPCS, PHPStan, and PHPUnit failures stay with the author; Semgrep findings need either a code fix or an explicit owner-reviewed triage note before merge.
- If screenshots, manual OAuth proof, or external client reconnect proof are deferred, say that explicitly in the PR or release brief.
