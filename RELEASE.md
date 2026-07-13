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
- `0.7.0-beta.3` is the active release-candidate target on `release/0.7.0`.
- Beta-readiness work should stay focused on proof, metadata, docs, package validation, hardening, and small reliability fixes.
- Do not pull `0.8.0` custom workflow planning scope into the `0.7.0-beta.3` train.

## Quality Gates
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
