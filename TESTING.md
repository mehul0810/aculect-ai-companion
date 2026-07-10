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

## Release and Proof Checks
- Non-live release proof may use fixture-backed smoke coverage when secrets are unavailable.
- Live admin/browser proof still requires the owner-provided smoke inputs documented in `scripts/smoke/README.md`.
- MCP discovery changes must prove deterministic `initialize` and paginated `tools/list` behavior.
- High-risk write-path changes must include confirmation, capability, rollback, and audit-log validation.

## PR Reporting
- PRs must list the commands actually run.
- Negative-path checks should be called out when they are part of the acceptance criteria.
- If screenshots, manual OAuth proof, or external client reconnect proof are deferred, say that explicitly in the PR or release brief.
