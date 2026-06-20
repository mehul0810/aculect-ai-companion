# Roadmap

This roadmap is the repo-local planning guide for near-term Aculect AI Companion milestones. GitHub issues, milestones, releases, and tags remain the source of truth for current state.

## Release Train Snapshot

Before making release decisions, re-check GitHub releases and tags for the latest production release and latest prerelease. As of the last planning snapshot for issue `#204`, production was `v0.5.3`, the latest prerelease was `0.6.0-beta-4`, and the active milestone dates were:

| Milestone | Intended role | Snapshot due date |
| --- | --- | --- |
| `0.6.0` | First broad production release train for the current connector, OAuth/DCR, MCP abilities, workflow routing, diagnostics, and package-readiness work. | 2026-06-29 |
| `0.6.1` | Post-`0.6.0` stabilization release for narrow follow-up fixes and release polish. | 2026-07-07 |
| `0.7.0` | Next feature train after `0.6.x` stabilization. | 2026-07-15 |

Treat those versions and dates as a snapshot, not immutable truth. Verify live milestone due dates, open issue scope, labels, and release/tag state before retargeting issues, cutting prereleases, or approving stable releases.

## Milestone Discipline

- Every active milestone needs a due date before release planning depends on it.
- If a milestone has no due date, ask the current owner to set one before treating the milestone as scheduled.
- If dates conflict across issues, milestone pages, release notes, or maintainer comments, use the GitHub milestone due date after confirming the intended date with the current owner.
- If a due date is ambiguous or stale, document the ambiguity in the planning thread and avoid release or retargeting actions until the owner resolves it.

## Train Ordering

Next milestone prereleases are blocked until the previous milestone has a production release. For example, do not create a `0.6.1` prerelease until `0.6.0` has shipped as a production release, and do not create a `0.7.0` prerelease until the required `0.6.x` production train is complete or the owner explicitly changes the plan.
