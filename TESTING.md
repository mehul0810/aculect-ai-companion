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

## Pull Request PHP Gates
- Pull requests targeting `main`, `develop`, or `release/**` must pass `CI / PHP Quality`.
- `CI / PHP Quality` is the complete PHP quality gate: Composer validation, PHP lint, WPCS, `composer analyse:mcp`, `composer analyse:core`, and PHPUnit.
- Pull requests targeting `main`, `develop`, or `release/**` must also pass `PHP Security / Semgrep PHP Security`.
- The PHP security lane is expected to cover OAuth flows, REST and MCP permission paths, storage, uploads, and output-handling regressions through maintained Semgrep PHP and secrets rules, with SARIF uploaded into GitHub code scanning.
- If `phpstan-baseline.neon` changes, the PR body must include a non-empty `PHPStan baseline justification:` line that explains the reviewed reason for the baseline delta.

## Release and Proof Checks
- Non-live release proof may use fixture-backed smoke coverage when secrets are unavailable.
- Live admin/browser proof still requires the owner-provided smoke inputs documented in `scripts/smoke/README.md`.
- MCP discovery changes must prove deterministic `initialize` and paginated `tools/list` behavior.
- High-risk write-path changes must include confirmation, capability, rollback, and audit-log validation.

## PR Reporting
- PRs must list the commands actually run.
- Negative-path checks should be called out when they are part of the acceptance criteria.
- CI failures should be owned by the surface they block: PHP lint, WPCS, PHPStan, and PHPUnit failures stay with the author; Semgrep findings need either a code fix or an explicit owner-reviewed triage note before merge.
- If screenshots, manual OAuth proof, or external client reconnect proof are deferred, say that explicitly in the PR or release brief.
