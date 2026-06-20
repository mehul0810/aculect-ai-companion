# Release Operations

This document records Aculect AI Companion release-train rules for maintainers and Codex workers. It does not replace GitHub releases, tags, milestones, or owner authorization.

## Release State Checks

Before any release decision:

1. Check GitHub releases for the latest production release and latest prerelease.
2. Check Git tags for matching version tags and unexpected newer tags.
3. Check the active milestone due date, issue list, labels, and open release-blocking PRs.
4. Confirm the intended PR base and target train with the current owner.

Do not rely on local branch names, stale changelog entries, package metadata, or prior planning snapshots as the source of truth for release state.

## Authorization

Creating a prerelease, stable release, tag, release asset, or publish action requires explicit current owner authorization. Prior approval, old automation instructions, or an issue assignment are not enough.

If authorization is missing or ambiguous, stop at a report with the checked state and the owner decision needed. Do not draft, create, tag, publish, retarget milestones, merge, or close issues as a substitute for owner approval.

## Release Train Policy

- `0.6.0` is the current broad stabilization train for the connector/OAuth/MCP/workflow/package-readiness release.
- `0.6.1` is the follow-up stabilization train after `0.6.0` has a production release.
- `0.7.0` is the next feature train after the `0.6.x` stabilization sequence.
- Next milestone prereleases are blocked until the previous milestone has shipped a production release, unless the current owner explicitly changes the train.
- Issue assignment must come from GitHub issue/milestone state and owner direction, not branch names alone.

## Snapshot Handling

Planning snapshots may mention versions such as production `v0.5.3`, prerelease `0.6.0-beta-4`, and milestone due dates for `0.6.0`, `0.6.1`, or `0.7.0`. Use those only as examples for what to verify. Re-check live GitHub releases, tags, and milestone pages before acting.
