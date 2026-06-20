# Product Direction

Aculect AI Companion connects WordPress sites to AI assistants through a secure, owner-approved connector. The product should stay focused on practical site-management workflows for administrators, editors, and developers without exposing protocol details in the primary WordPress admin experience.

## Product Principles

- Keep setup simple: users should copy one connection URL, approve access in WordPress, and manage abilities from the plugin UI.
- Protect site owners: OAuth consent, least-privilege abilities, activity visibility, and safe defaults matter more than broad automation.
- Keep assistant actions WordPress-native: use core content, media, taxonomy, comments, Site Editor, and capability APIs where possible.
- Keep technical surfaces explicit: MCP, OAuth, diagnostics, and release-readiness details belong in developer and maintainer docs, not primary user copy.

## Current Product Tracks

- `0.6.0`: stabilize the first broad AI Companion release train for connector setup, OAuth/DCR, MCP abilities, workflow routing, diagnostics, packaging, and user-facing release readiness.
- `0.6.1`: follow-up stabilization for issues found after the `0.6.0` production release, especially connector reliability, release polish, and narrow regressions.
- `0.7.0`: next feature train after `0.6.x` stabilization, including larger workflow and product-surface improvements that should not block the `0.6.0` production release.

Do not infer issue ownership or issue assignment from branch names alone. Confirm milestone, labels, issue discussion, PR base, and current maintainer direction before moving work between these tracks.
