# 0.8.0 Beta 3 readiness

Status: **NOT READY**. Preparation is authorized; tagging, publication, deployment, repository settings changes and Studio lifecycle changes are not.

## Candidate identity and accepted scope

- Production: `0.7.2`, tag and main commit `16d8e7776eca4a6cff1d569f595c973046be7620`; ancestry rechecked on 2026-09-08.
- Previous tester release: `0.8.0-beta.2`, published 2026-09-02.
- Preparation base: `release/0.8.0` at `25db8910fbcd44f875f062a29c9a9266713cdc90`.
- No open release-branch PRs at reconciliation; Dependabot #520/#521 target main and are not being repurposed.
- Scope: completed ability/taxonomy, memory, WebMCP, modularity and CI remediation; fix remaining approved readiness blockers, then freeze and prove the resulting package.
- Visual progress, secure input/URL elicitation, and embedded MCP Apps remain proposed follow-up features, not Beta 3 claims.

## Acceptance checklist

| Acceptance point | Status and next evidence |
| --- | --- |
| Current production and release identity | Verified at preparation base; recheck before final recommendation |
| Remaining development dependency advisories | Scoped fix prepared: npm audit five to zero, clean locked install passed; independent review/integration pending |
| Learning queue concurrency | Open, tracked in #527: whole-option read/modify/write can lose another writer's changes; failure atomicity alone does not fix this |
| Release notes and metadata | Candidate narrative updated from beta2-to-base diff; runtime/header/package/readme remain 0.8.0; final candidate-channel metadata and package parity still require verification |
| Milestone reconciliation | Five pre-existing open items classified below, plus the scoped #527 blocker; no issue closure or scope deferral assumed |
| Changed Memory/Learning UI | Candidate-specific desktop/constrained-width, state coverage and accessibility proof pending |
| Beta 2 upgrade | Disposable package-to-package upgrade smoke pending; do not mutate Studio to obtain it |
| OAuth/Connect regression | DCR and authorize redirect candidate smoke required by AGENTS.md; reuse only evidence applicable to unchanged code and exact package |
| Final quality matrix | Independent release review pending; no green-CI-only readiness inference |
| Canonical ZIP and SHA256 | Preserved baseline below is not the final candidate; rebuild after accepted changes and verify package/runtime contents |
| Owner release authority | Not granted by the readiness schedule |

## Reusable baseline proof

Full [CI run 34063927627](https://github.com/mehul0810/aculect-ai-companion/actions/runs/34063927627) passed all 14 jobs at `1ca0632a24e91caefcf63aa2c7d2b733691e4ead`. The subsequent base commit changes evidence documentation only. This includes PHP quality, MySQL/MariaDB/SQLite, WordPress 6.8.1/6.9/7.1 contracts, canonical packaging, Plugin Check, real memory rollback/CAS/sync proof, and workflow-admin browser proof. It does not prove the changed Memory/Learning UI or a Beta 2 upgrade.

Preserved canonical baseline ZIP SHA256: `ed63954d619a299732b1559ef811492480860aadc84b17a8d79608ec7cba1788`, verified against its packaged checksum. Original Actions artifact: `aculect-ai-companion-package-1ca0632a24e91caefcf63aa2c7d2b733691e4ead-1`. Its artifact-container digest is not the plugin ZIP hash. Locally retained at `/private/tmp/aculect-beta3-evidence.fTQdrn/aculect-ai-companion.zip`; temporary storage is not a durable release asset.

## Open milestone disposition

- #522/#523: duplicate taxonomy-assignment requests; implementation and regression tests are present via #524. Final acceptance reconciliation remains pending, not assumed closed.
- #469: WordPress 7.1 automated/package evidence exists and Tested up to is 7.1; candidate-specific editor/admin acceptance mapping still needs closure evidence.
- #354: partial modularity delivery, with tightened ceilings and extracted components. Remaining large files are not represented as fully decomposed.
- #329: custom-workflow epic has implemented runner/admin/connector work and existing proof. Reconcile child acceptance criteria rather than treating the open epic as proof of missing implementation or full completion.
- #527: newly recorded, duplicate-screened learning queue concurrency blocker; bounded acceptance includes two-writer preservation, safe retry, atomic memory/history, cache behavior and disposable real-database proof.

## Dependency remediation evidence

The bounded update changes fast-uri 3.1.5 to 3.1.7, qs 6.15.2 to 6.16.0, selector-parser 6.1.2 to 6.1.4 and 7.1.1 to 7.1.6, plus qs's side-channel dependency patch. Only qs needs a manifest override because its parent range excludes the patched minor; all other patches use existing parent ranges. Full npm audit reports zero advisories after a clean `npm ci --ignore-scripts` on Node 24.18.0/npm 11.16.0. JavaScript/style lint, all 95 JavaScript tests and build passed for the patched dependency tree, with existing bundle-size warnings. This is a dependency advisory result, not a blanket security certification or a final release approval.

## Execution and stop boundaries

One bounded dependency worker owns package manifests; this product task owns preparation/docs and reconciliation. No duplicate full audit, CI rerun, release task, Studio provisioning, or cleanup is required for the unchanged baseline. Run focused checks first, then one coherent final candidate proof after fixes and independent review. Keep sensitivity/permission boundaries unchanged. If proof exposes a defect, return that exact failure to a scoped implementation lane before readiness.
