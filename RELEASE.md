# Aculect AI Companion Release Governance

## Release Branch Rule
- Milestone release work targets `release/<version>`.
- Use the milestone version string as the branch base name, never the GitHub milestone number.
- Do not merge production branches, create tags, publish releases, or ship prereleases without explicit owner approval.

## Production Mainline Sync
- Beta and prerelease tags must never advance `main`.
- A production release remains open until its exact release tag is an ancestor of `origin/main` and the main plugin-header version matches the released version.
- Use a dedicated `release/<version>` to `main` sync PR only when the release branch head is the tagged commit. If the branch moved after tagging, create a narrow sync branch at the exact tag instead of merging untagged work.
- Require explicit owner approval for the `main` merge. Do not push directly to `main`.
- Before WordPress.org deployment and in the post-release check, fetch `origin/main`, prove `git merge-base --is-ancestor <release-tag-commit> origin/main`, and confirm the tag (minus an optional `v` prefix), tagged plugin header, and main plugin header use the same version. If it fails, keep the train open as `mainline sync missing` and reconcile the sync before the next prerelease.

## Current Pre-1.0 Train
- `0.8.0` is the current production release at tag and `main` commit `ddf11b49b8638c23793eb87a8079fa0e63b9f3fc`.
- `0.8.1` is the maintenance candidate on `release/0.8.1`, based on the exact 0.8.0 production commit.
- The candidate fixes PHP 8.5 MCP schema traversal, requires no-store/no-cache headers on every OAuth token response, accepts existing taxonomy terms by exact slug or ID without creating terms, and includes patched adm-zip and SVGO development dependencies.
- Preserve the reviewed 0.8.0 OAuth issuer/DCR boundary. These maintenance fixes do not authorize changes to discovery, registration, authorization, consent, PKCE, token issuance, refresh, revocation, scopes, or client storage.
- Keep Cloudflare Bot Fight Mode compatibility, MCP Apps embedded UI, and `ui://` product scope in later milestones; do not claim them in 0.8.1.
- The plugin header, runtime constant, package metadata, WordPress.org stable tag, changelog, and translation catalog must remain synchronized to `0.8.1`. Production remains `0.8.0` until the owner separately authorizes the exact tag and publication workflow.

## Deferred Development Data
- The deferred 0.8.0 custom Content Workflows builder/runner has no admin, MCP, native-ability, or installation surface. Earlier fixed content planning/draft tools remain supported.
- Existing development workflow tables and options are left untouched, including by the current uninstall path. No automatic migration or data cleanup is included; any later recovery or cleanup needs an explicit owner decision.

## Quality Gates
- Local preparation starts with `npm run check:release` from a clean committed checkout. Its receipt pins local checks and the production ZIP; it is preflight evidence, not a complete release decision or hosted-connector proof.
- Browser/admin UI proof is local: run `npm run smoke:release-ui` with safe disposable-site inputs when applicable and record its artifact. Hosted CI retains WPCS, the minimum PHP 8.2 quality gate, PHP 8.3/8.4/8.5 compatibility, WordPress 6.9/7.0/7.1, security, database and exact-package OAuth gates.
- Routine PR checks are consolidated and risk-selected; full validation remains required for release integration and publication. The full gate must build one canonical artifact and pass that exact artifact to packaged proofs and publication. No tag/release/deployment is performed by local check commands or manual CI validation.
- The 0.8.0 rejected-token cache-header exception is historical and does not apply to 0.8.1. Every successful and rejected token response must include `Cache-Control: no-store` and `Pragma: no-cache`; every functional OAuth assertion remains mandatory.
- The exact canonical ZIP must pass the packaged OAuth contract before attaching a beta asset or deploying a production package. Failed, cancelled, missing or skipped OAuth proof is not a pass. No automatic retries of the OAuth contract are permitted.
- OAuth failures require explicit owner authorization for any flow or test-contract change. Diagnose and preserve redacted stage evidence first; unrelated work may continue. Infrastructure fixes do not authorize OAuth behavior changes.
- Security/privacy
- Performance
- Modularity/architecture
- Maintainability
- Test coverage
- Docs/readme/changelog/release notes
- Dead-code/debug hygiene
- Compatibility
- Package/version metadata
- UI/browser proof when relevant

Release briefs must summarize each gate as passed, blocked, or not applicable with a reason.

## Proof Model
- Non-live fixture proof is acceptable for bounded release-gate coverage when secrets are unavailable.
- Live admin/browser proof remains required for visual and authenticated workflow validation when the train depends on it.
- Record exact missing inputs when live proof is blocked:
  - `ACULECT_SMOKE_BASE_URL`
  - `ACULECT_SMOKE_USERNAME`
  - `ACULECT_SMOKE_PASSWORD`
  - optional MCP smoke tokens when applicable

## Package and Metadata Expectations
- Plugin header version, runtime version constant, `readme.txt`, and package metadata must describe the same target train.
- Release-facing docs must not imply a production release before owner approval.
- Keep WordPress.org-facing copy and changelog entries aligned with the package under review.
- The production release workflow checks the tagged commit and plugin-header version against `main` before it deploys to WordPress.org.
