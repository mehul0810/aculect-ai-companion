# CI consolidation and proof contract

## Scope

The automatic entry point is `.github/workflows/ci.yml`. It runs for every pull request and for explicit non-production manual validation. It does not run again for branch pushes, avoiding duplicate PR/push work. Release and prerelease workflows call it with the full matrix before publication; no release or deployment is directly reachable from the CI workflow.

The stable required-check name is **Required CI**. It runs even when upstream jobs fail and validates detector output, expected successes, cancellations, and unexpected skips. Job-level selection replaces workflow-level path filters so docs-only changes do not leave required checks pending.

## Preserved coverage, fewer repeated setups

| Proof | Owner after consolidation |
| --- | --- |
| Composer validation, PHP lint/WPCS/PHPStan/unit tests and modularity | Quality, build and package |
| JavaScript tests, asset build and runtime dependency audits | Quality, build and package |
| Production dependency/package-content verification | Quality, build and package; one canonical ZIP |
| PHP baseline and compatibility | PHP 8.2 quality gate plus PHP 8.3, 8.4 and 8.5 syntax + unit matrix |
| MySQL 8.0 + MariaDB 10.11 execution-worker concurrency | Shared Real Database Proofs, isolated claims database |
| MySQL 8.0 + MariaDB 10.11 OAuth migration/joins | Same services, separate OAuth database |
| Native WordPress Abilities | WordPress 6.9, 7.0 and 7.1 |
| Broad browser/admin UI proof | Local `npm run smoke:release-ui` with safe disposable-site inputs |
| Packaged settings and removed workflow routes | Release Package Proof, using the canonical ZIP |
| Memory batch/history, review rollback, migration CAS and sync replay | Same disposable release WordPress environment, guarded localhost:8882 only |
| PHP scanning | PHP Security on full/release validation and weekly schedule |
| Secrets scanning | Every Quality job, including documentation-only PRs |
| JavaScript security | CodeQL, plus existing weekly schedule |

A full applicable run builds assets and the package once. Exact artifact names and SHA-256 travel through job outputs so packaged OAuth and release Plugin Check consume the same immutable ZIP.

Database suites run against separate fresh claims and OAuth databases in each shared engine service. Test scripts have fixed table names and some intentionally drop tables; database isolation prevents one proof masking another's fresh-install behavior.

Database checks have one workflow owner. The former manual OAuth and execution-claims launchers have been removed; use the main CI workflow's manual full validation when a branch needs an explicit hosted run.

There are ten retained workflow files. The unused standalone packaging workflow was removed because Quality already owns packaging. The unused packaged WordPress workflow was removed after moving its memory and bounded admin-browser proofs into `plugin-check.yml`. Release and prerelease publication already depend on that job, so the retained proofs now have an actual release caller. Their scripts and safety guards remain unchanged.

## Selection rules

- Pull requests compare their event base/head merge-base.
- Manual and reusable release validation request full coverage. Unknown paths, CI changes and shared bootstrap/dependency changes also fail safe to full coverage.
- Renames are treated as delete/add, retaining both paths.
- Production PHP changes conservatively run all storage/authorization proof families. This intentionally favors safety until there is a tested dependency graph.
- Frontend-only changes run quality/package and OAuth checks without database matrices; browser proof is local.
- Documentation-only changes still run secrets scanning but skip builds/database environments.
- Pull requests enforce the changed-from modularity ratchet. Local validation enforces current modularity ceilings before push.

Tests in `tests/js/ci-changes.test.mjs` cover selection, event ranges, rename handling, fail-safe behavior, aggregate failures/skips, proof inventory and release-upload idempotency.

## Package and release contract

The Quality job is the single owner of package creation from the exact event commit. `bin/ci-package.sh` stages into a new temporary directory, verifies production contents, normalizes package timestamps/file ordering, and generates the single canonical `aculect-ai-companion.zip` plus SHA256 checksum.

Release/prerelease workflows call the full CI workflow and retain a separate exact-package release proof in `plugin-check.yml`. That proof verifies the artifact digest, runs Plugin Check, then memory rollback/migration/replay and bounded settings/removed-route browser checks on the installed ZIP. It installs browser dependencies with the asset Node version before switching to the proven Node 20 wp-env runtime, and keeps port 8882 required by the destructive fixture's guard. It never rebuilds the package. Only the final publishing job has repository write permission. Production retains exact tag/main ancestry and version checks and verifies that the tag resolves to the checked-out event commit.

Existing release assets are compared before production deployment. Identical files are left untouched; mismatches fail rather than using `--clobber`. Publishing retains WordPress.org deployment before attaching missing production assets. Old differently named assets are not removed automatically.

Release concurrency does not cancel publishing in progress. CI proof concurrency still cancels superseded non-production checks. Every job has a timeout; Composer downloads are cached, and build/package artifacts use short retention.

No release or deployment was triggered to validate this refactor. No secrets or repository settings were changed.

## Repository-settings decision still required

Workflow code cannot enforce branch protection by itself. After **Required CI** is green on the exact candidate, configure its status check on the desired branches. A direct push does not receive post-push CI from this workflow, so protected branches should require pull requests and **Required CI** where enforcement matters.

Suggested policy: require **Required CI** before normal PR merges, disallow force pushes/deletion, and require owner review of workflow/policy changes. If direct owner pushes remain permitted, document that bypass explicitly.

No ruleset or deployment-environment gate is created here. Creating an environment without configured reviewers would not add an approval boundary.

## Validation and limitations

The canonical pre-push command is `npm run check:local`; it includes WPCS, PHP/JS tests and static analysis, JS/CSS lint, build, dependency audits, modularity and diff hygiene. `npm run check:release` adds clean-revision fixture/package proof. Run `npm run smoke:release-ui` locally against a disposable WordPress site when UI/browser behavior changed or before release; its environment variables and safe artifact rules are documented in `scripts/smoke/README.md`.

Hosted MySQL/MariaDB, WordPress/PHP compatibility and packaged OAuth must pass on the exact PR head before claiming the pipeline is proven. Broad browser proof remains local and must be reported separately. The bounded packaged admin/memory proofs run before release publication; retaining or wiring their commands is not proof that a hosted run passed. Fixture-only checks are not hosted-client evidence.

This is resource/latency reduction, not a measured billing claim. Actual cost depends on repository visibility, runner billing and artifact consumption. Measure completed job time/artifact storage after the first hosted run.

Reference contracts: [reusable workflows](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows) and [sharing artifacts between jobs](https://docs.github.com/en/actions/tutorials/store-and-share-data).

## Next simplification approach

This cleanup removes unused definitions and manual wrappers, not running matrix coverage. The next phase should simplify ownership in small reviewed changes rather than remove quality gates to reach a file-count target.

| Proposed workflow | Responsibility |
| --- | --- |
| `ci.yml` | PR/manual entry, changed-file selection, Quality/build/package, required-result aggregation |
| `compatibility.yml` | Separate PHP and WordPress matrix jobs; inputs preserve current selective execution |
| `database-proof.yml` | Shared MySQL/MariaDB services with isolated claims and OAuth databases |
| `security.yml` | Separate Semgrep and CodeQL jobs, reusable/manual entry, and weekly schedules |
| `package-proof.yml` | OAuth proof for every required package; Plugin Check, memory and bounded admin checks for release validation |
| `prerelease.yml` | Beta validation and artifact attachment |
| `release.yml` | Stable ancestry/version checks, validation, WordPress.org deployment and artifact attachment |

That would reduce ten workflow files to seven while retaining independent jobs and failure reports. Keep beta and stable publishers separate because stable has additional ancestry, version and deployment effects. Keep OAuth and memory/admin environments isolated when grouping their workflow definitions; they have different mutable fixtures and failure contracts.

Recommended order:

1. Record actual hosted job durations and repeated setup from a complete run; no cost or speed improvement is established by file removal alone.
2. Centralize repeated package-digest verification and WordPress setup in small composite actions only where the inputs and runtimes are genuinely shared. Retain exact ZIP identity, locked browser dependencies, Node runtime separation, explicit disposable guards and unconditional cleanup.
3. Group PHP/WordPress compatibility and PHP/JavaScript security definitions without reducing matrices or scanner coverage. Preserve the existing weekly frequency and manual entry points.
4. Group packaged proofs and pass a release-validation input through the CI caller. Test that OAuth is mandatory whenever a package is required and that all release proofs complete before either publisher can run.
5. Improve job selection only from a tested dependency map. Preserve fail-closed handling of unknown paths, dependency/bootstrap changes, main-target PRs and full/manual validation, plus the stable `Required CI` check name.

This is a future plan. The current change does not merge those seven responsibilities, change repository protection, publish a release, or claim hosted acceptance of the newly connected release proof.
