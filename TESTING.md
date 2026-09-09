# Aculect AI Companion Validation Contract

## Baseline Expectations
- Every scoped change must name the exact validation commands that prove the touched behavior.
- Validation should scale with risk: narrow fixes get narrow checks; MCP, OAuth, release, and packaging changes need broader proof.
- If a required check cannot run, record the exact blocker and the remaining risk.

## Standard Checks
- PHP syntax: `composer run lint:php`
- PHP unit and static analysis: `composer test` or the narrow `composer` scripts relevant to the touched surface
- JavaScript tests: `npm run test:js`
- JavaScript lint: `npm run lint:js`
- Diff hygiene: `git diff --check`
- Modularity budgets and dependency boundaries: `composer check:modularity`. For the pull-request ratchet (legacy files may not grow), run `php bin/check-modularity.php --config=.codex/modularity-rules.php --changed-from=origin/<base-branch>`.

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
- OAuth retry/persistence regressions: `vendor/bin/phpunit --filter 'OAuthDcrSqlitePersistenceTest|OAuthRepositoryTest'`. Repeated registrations must preserve pending clients, including at capacity; duplicate cleanup must retain live credentials and only remove registrations older than the existing stale-client threshold (24 hours by default). Failed access-token insertion must stop issuance before superseded-session revocation. These repository tests do not prove an end-to-end hosted OAuth exchange or transactional refresh rollback.
- Non-live release proof may use fixture-backed smoke coverage when secrets are unavailable.
- Before any live MCP credential is used, run `npm run smoke:mcp-local`. This starts a local PHP fixture server around the actual `McpController`, drives it with the pinned MCP SDK, and proves the no-secret `initialize` → initialized notification → paginated `tools/list` → `site_get_info` flow plus invalid-bearer rejection. Its summary is written to `artifacts/smoke/mcp-local/latest/summary.json`.
- Live admin/browser proof still requires the owner-provided smoke inputs documented in `scripts/smoke/README.md`.
- MCP discovery changes must prove deterministic `initialize` and paginated `tools/list` behavior.
- MCP transport changes also require `npm run smoke:mcp-sdk` against the target using locally configured OAuth smoke credentials. The SDK performs initialization, the initialized notification, paginated discovery, and a read-only `site_get_info` call. The result identifies the failed stage and HTTP status without printing bearer tokens or tool payloads. Unit tests, synthetic HTTP fixtures, and a successful GET response do not prove a hosted connector works.
- Distinguish Streamable HTTP from legacy HTTP+SSE when diagnosing fallback. An optional GET 405 is valid for Streamable HTTP; a legacy SSE client expects an `endpoint` event. Capture the failing exchange before changing transport behavior or adding protocol versions.
- High-risk write-path changes must include confirmation, capability, rollback, and audit-log validation.

## PR Reporting
- PRs must list the commands actually run.
- Negative-path checks should be called out when they are part of the acceptance criteria.
- CI failures should be owned by the surface they block: PHP lint, WPCS, PHPStan, and PHPUnit failures stay with the author; Semgrep findings need either a code fix or an explicit owner-reviewed triage note before merge.
- If screenshots, manual OAuth proof, or external client reconnect proof are deferred, say that explicitly in the PR or release brief.
