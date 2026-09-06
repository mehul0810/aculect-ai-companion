# CI consolidation and proof contract

## Scope

The automatic entry point is `.github/workflows/ci.yml`. It runs for every pull-request base, pushes to main/develop/release branches, and an explicit non-production manual validation. No release/deployment is reachable from that workflow.

The stable required-check name is **Required CI**. It runs even when upstream jobs fail and validates detector output, expected successes, cancellations, and unexpected skips. Job-level selection replaces workflow-level path filters so docs-only changes do not leave required checks pending.

## Preserved coverage, fewer repeated setups

| Proof | Owner after consolidation |
| --- | --- |
| Composer validation, PHP lint/WPCS/PHPStan/unit tests and modularity | CI PHP Quality, once |
| JavaScript/style lint, JS tests and asset build | CI Assets, once |
| Production dependency/package-content verification | Canonical Plugin Package |
| MySQL 8.0 + MariaDB 10.11 installer and runner | Shared Real Database Proofs |
| MySQL 8.0 + MariaDB 10.11 execution-worker concurrency | Same services, separate claims database |
| MySQL 8.0 + MariaDB 10.11 OAuth migration/joins | Same services, separate OAuth database |
| WordPress SQLite installer + runner | Workflow SQLite Proof, one environment |
| Native WordPress Abilities | All existing versions: 6.8.1, 6.9, 7.1 |
| Packaged Plugin Check, WordPress Options retry lease, memory rollback/CAS/sync proof, workflow-admin browser proof | Packaged WordPress Proof |
| PHP/secrets scanning | PHP Security |
| JavaScript security | CodeQL, plus existing weekly schedule |

A full applicable run builds assets once rather than separately for assets, package and packaged-browser jobs. The package consumes this run's SHA/attempt-named asset artifact. Exact artifact names travel through upstream job outputs so full reruns avoid name collisions and failed-job reruns can reuse successful upstream artifacts. The browser consumes the same verified ZIP, checking its checksum against the independent package job output before extraction.

Database suites run against four fresh databases in each shared engine service. Test scripts have fixed table names and some intentionally drop tables; database isolation prevents one proof masking another's fresh-install behavior. SQLite proof scripts run in separate WP-CLI processes in the same disposable WordPress environment.

The former OAuth and execution-claims workflows remain manual focused entry points. They call the shared database proof rather than maintaining another copy.

## Selection rules

- Pull requests compare their event base/head merge-base.
- Direct pushes compare event before/after. They are never assumed redundant with a PR.
- Missing history, new-branch zero SHAs, unknown paths, CI changes and shared bootstrap/dependency changes request full coverage.
- Renames are treated as delete/add, retaining both paths.
- Production PHP changes conservatively run all storage/authorization proof families. This intentionally favors safety until there is a tested dependency graph.
- Frontend-only changes run asset, package/browser and security checks without database matrices.
- Documentation-only changes still run secrets scanning but skip builds/database environments.
- Both PRs and direct pushes enforce the changed-from modularity ratchet.

Tests in `tests/js/ci-changes.test.mjs` cover selection, event ranges, rename handling, fail-safe behavior, aggregate failures/skips, proof inventory and release-upload idempotency.

## Package and release contract

`package.yml` is read-only and builds from the exact event commit. `bin/ci-package.sh` stages into a new temporary directory, verifies production contents, normalizes package timestamps/file ordering, and generates the single canonical `aculect-ai-companion.zip` plus SHA256 checksum.

Release/prerelease workflows reuse that builder and a shared Plugin Check workflow. Only the final publishing job has repository write permission. Production retains exact tag/main ancestry and version checks, now also verifies that the tag still resolves to the checked-out event commit.

Existing release assets are compared before production deployment. Identical files are left untouched; mismatches fail rather than using `--clobber`. Publishing retains WordPress.org deployment before attaching missing production assets. Old differently named assets are not removed automatically.

Release concurrency does not cancel publishing in progress. CI proof concurrency still cancels superseded non-production checks. Every job has a timeout; Composer downloads are cached, and build/package artifacts use short retention.

No release or deployment was triggered to validate this refactor. No secrets or repository settings were changed.

## Repository-settings decision still required

Workflow code cannot enforce branch protection by itself. After **Required CI** is green on the exact candidate, configure its status check on the desired branches. Decide explicitly whether the owner may bypass required checks for direct pushes; the current work request specifically permits owner direct pushes to release/0.8.0.

Suggested policy for approval: require **Required CI** before normal PR merges, disallow force pushes/deletion, and require owner review of workflow/policy changes. If direct owner pushes remain permitted, document the narrow bypass rather than silently pretending every push can be pre-gated. Post-push CI can detect regressions but cannot retroactively prevent that push.

No ruleset or deployment-environment gate is created here. Creating an environment without configured reviewers would not add an approval boundary.

## Validation and limitations

Local validation: actionlint 1.7.12, scoped ESLint, 13 passing CI Node regression tests, Bash syntax checks and repository modularity check.

Hosted MySQL/MariaDB/SQLite/WordPress/browser execution must pass on the pushed exact head before claiming the consolidated pipeline is proven. The local machine has no Docker runtime; the package script's deterministic timestamp normalization targets the Ubuntu CI runner.

This is resource/latency reduction, not a measured billing claim. Actual cost depends on repository visibility, runner billing and artifact consumption. Measure completed job time/artifact storage after the first hosted run.

Reference contracts: [reusable workflows](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows) and [sharing artifacts between jobs](https://docs.github.com/en/actions/tutorials/store-and-share-data).
