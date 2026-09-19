# 0.8.0 Beta 3 readiness

Status: **READY FOR OWNER RELEASE AUTHORITY**. The candidate has passed the
documented engineering and smoke gates below. Tagging, prerelease publication,
deployment, repository settings changes, and Studio lifecycle changes remain
owner-gated.

## Candidate identity and accepted scope

- Production: `0.7.2`, tag and main commit `16d8e7776eca4a6cff1d569f595c973046be7620`; ancestry rechecked on 2026-09-08.
- Previous tester release: `0.8.0-beta.2`, published 2026-09-02.
- Validated runtime candidate: `release/0.8.0` at `294627f15c673830b6bce9af0aa9796f4de0b2a4`.
- Validated runtime package: `aculect-ai-companion-package-294627f15c673830b6bce9af0aa9796f4de0b2a4-1`, ZIP SHA-256 `ddb1ee65ce2124304dc25acd4861324e65b043b5379eb003c67b0eb2c79c6235`. This final brief is a documentation-only reconciliation; the post-brief package must be verified before publication.
- No open release-branch PRs at reconciliation; Dependabot #520/#521 target main and are not being repurposed.
- Scope: completed ability/taxonomy, memory, WebMCP, modularity and CI remediation; fix remaining approved readiness blockers, then freeze and prove the resulting package.
- Visual progress, secure input/URL elicitation, and embedded MCP Apps remain proposed follow-up features, not Beta 3 claims.

## Acceptance checklist

| Acceptance point | Status and next evidence |
| --- | --- |
| Current production and release identity | Verified: production remains `0.7.2` at `16d8e7776eca4a6cff1d569f595c973046be7620`; candidate is identified above |
| Remaining development dependency advisories | Passed: scoped lockfile remediation reduced `npm audit` from five advisories to zero; clean locked install, JS tests, and build passed |
| Learning queue concurrency | Passed: #527's compare-and-swap queue storage has two-writer, rollback/retry, cap, and real database proof; parent full CI is recorded below |
| Release notes and metadata | Passed: package carries the `0.8.0` header/readme metadata and checksum listed above; release notes distinguish Beta 3 scope from the stable release |
| Milestone reconciliation | Recorded below: open issues are not silently closed or claimed as complete beyond their accepted evidence |
| Changed Memory/Learning UI | Passed: exact-package constrained-width state, keyboard, selected-state, and component-level axe proof passed; host-admin findings are explicitly out of scope |
| Beta 2 upgrade | Passed: disposable Beta 2-to-parent-package upgrade preserved pending Learning data, approved memory, options, migration replay safety, and reactivation; the final delta is UI-only |
| OAuth/Connect regression | Passed: disposable exact-package smoke ran two DCR POSTs (201), logged-out authorize-to-login/consent redirect, and logged-in direct-consent redirect without recording credentials or tokens |
| Final quality matrix | Passed: exact candidate CI run `34197169210` succeeded. Current UI-only checks plus the immediate parent full matrix are summarized below; independent release review completed |
| Canonical ZIP and SHA256 | Passed: the exact package was downloaded from successful CI, its provided checksum matched, and `unzip -t` reported no errors |
| Owner release authority | Still required: no tag, GitHub prerelease, deployment, or Studio action is authorized by this brief |

## Candidate evidence

Exact candidate [CI run 34197169210](https://github.com/mehul0810/aculect-ai-companion/actions/runs/34197169210) passed its applicable Assets, Semgrep, CodeQL, canonical package, packaged WordPress/Plugin Check, workflow retry, real memory review/CAS/sync, and browser checks. The downloaded canonical package checksum is `ddb1ee65ce2124304dc25acd4861324e65b043b5379eb003c67b0eb2c79c6235`, matching its packaged checksum file; archive integrity passed with `unzip -t`.

The immediate parent [CI run 34194600683](https://github.com/mehul0810/aculect-ai-companion/actions/runs/34194600683) passed the full PHP, MySQL/MariaDB/SQLite, WordPress 6.8.1/6.9/7.1 contract, package, Plugin Check, memory, workflow, and browser matrix at `9bf689e928502ece110d8b9e55ddddc8530e0588`. The final delta removes an invalid ARIA prop and adds its JavaScript regression assertion only, so it does not alter that PHP, database, migration, or upgrade surface.

The isolated Learning proof used the exact candidate package in a fresh disposable WordPress 7.1 runtime at `782x1000`. Suggestions, Memory, and Incidents states; ArrowRight, End, Home, and Space behavior; selected visual state; and Learning-component axe violations all passed. Host WordPress admin had five unrelated axe violations and two incomplete checks; this is not a whole-admin accessibility certification.

The OAuth smoke used the same disposable candidate runtime. It passed two DCR registrations, the logged-out login/consent redirect, and the logged-in direct-consent redirect. The harness held the disposable admin cookie in memory only and did not persist or print credentials, tokens, authorization codes, client secrets, or raw response bodies.

## Reusable baseline proof

Full [CI run 34063927627](https://github.com/mehul0810/aculect-ai-companion/actions/runs/34063927627) passed all 14 jobs at `1ca0632a24e91caefcf63aa2c7d2b733691e4ead`. The subsequent base commit changes evidence documentation only. This includes PHP quality, MySQL/MariaDB/SQLite, WordPress 6.8.1/6.9/7.1 contracts, canonical packaging, Plugin Check, real memory rollback/CAS/sync proof, and workflow-admin browser proof. It does not prove the changed Memory/Learning UI or a Beta 2 upgrade.

Preserved canonical baseline ZIP SHA256: `ed63954d619a299732b1559ef811492480860aadc84b17a8d79608ec7cba1788`, verified against its packaged checksum. Original Actions artifact: `aculect-ai-companion-package-1ca0632a24e91caefcf63aa2c7d2b733691e4ead-1`. Its artifact-container digest is not the plugin ZIP hash. Locally retained at `/private/tmp/aculect-beta3-evidence.fTQdrn/aculect-ai-companion.zip`; temporary storage is not a durable release asset.

## Open milestone disposition

- #522/#523: duplicate taxonomy-assignment requests; implementation and regression tests are present via #524. They remain open for product-owner disposition, not as undisclosed Beta 3 blockers.
- #469: WordPress 7.1 automated/package evidence exists and Tested up to is 7.1. It remains open for product-owner acceptance tracking, not as an untested candidate claim.
- #354: modularity ceilings and extracted components are delivered; the issue remains open because legacy decomposition is not represented as fully complete.
- #329: custom-workflow epic remains open for child-acceptance tracking; it is not treated as either an undisclosed missing Beta 3 capability or a claim of full epic completion.
- #527: the bounded queue-concurrency acceptance passed: two-writer preservation, retry, atomic memory/history, cache behavior, and disposable real-database proof. The issue remains open until the product owner closes it.

## Dependency remediation evidence

The bounded update changes fast-uri 3.1.5 to 3.1.7, qs 6.15.2 to 6.16.0, selector-parser 6.1.2 to 6.1.4 and 7.1.1 to 7.1.6, plus qs's side-channel dependency patch. Only qs needs a manifest override because its parent range excludes the patched minor; all other patches use existing parent ranges. Full npm audit reports zero advisories after a clean `npm ci --ignore-scripts` on Node 24.18.0/npm 11.16.0. JavaScript/style lint, all 95 JavaScript tests and build passed for the patched dependency tree, with existing bundle-size warnings. This is a dependency advisory result, not a blanket security certification or a final release approval.

## Execution and stop boundaries

One bounded dependency worker owns package manifests; this product task owns preparation/docs and reconciliation. No duplicate full audit, CI rerun, release task, Studio provisioning, or cleanup is required for the unchanged baseline. Run focused checks first, then one coherent final candidate proof after fixes and independent review. Keep sensitivity/permission boundaries unchanged. If proof exposes a defect, return that exact failure to a scoped implementation lane before readiness.
