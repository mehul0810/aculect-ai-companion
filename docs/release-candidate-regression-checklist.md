# Release-Candidate Regression Checklist

Use this checklist before an Aculect AI Companion beta, prerelease, or
production readiness brief. It is a release-candidate evidence gate, not a
substitute for automated CI or package validation.

The checklist must be completed against the release branch build or packaged
ZIP that is being proposed for release. Do not claim beta or production
readiness if critical UI proof is missing, or if a known previously working
workflow is untested, unless the release brief records explicit risk
acceptance.

## Release Candidate Proof

- Package or build tested:
- Git branch, tag, or commit tested:
- WordPress version:
- PHP version:
- Browser and version:
- Test environment:
- Package/build validation status:
- Functional smoke status:
- Visual proof links:
- Known proof gaps:
- Explicit risk acceptance for skipped critical proof:

## Screenshot And Evidence Rules

- Attach screenshots or artifact links to the pull request or release-readiness brief.
- Do not include secrets, tokens, private site data, database dumps, customer
  data, private MCP connection details, or sensitive request bodies in
  screenshots, logs, tests, commits, or issue comments.
- Use a seeded local or staging site with scrubbed data when screenshots would
  otherwise expose private content.
- Changed admin UI, consent, connection, diagnostics, activity, learning,
  settings, or workflow surfaces require screenshot proof from the release
  branch build or packaged ZIP before beta or production readiness is claimed.
- Desktop screenshots are required for changed admin UI surfaces.
- Constrained or mobile-ish admin width screenshots are required when a
  surface is responsive, width-sensitive, or known to shift layout at narrower
  widths.
- Visual proof must show the relevant active tab, sub-navigation state,
  empty/loading/error state when changed, and the final loaded state where
  applicable.

## Golden Workflow Matrix

Mark each workflow as `Pass`, `Fail`, `Skipped`, or `Not affected`. Record
proof links and gaps for every `Skipped`, `Fail`, or `Not affected` item.

For each workflow, fill in:

- Status:
- Proof links:
- Gaps or notes:

### Plugin Activation And Update Path

Activate the release candidate on a clean test site and update from the
previous supported package where practical. Record any migration, option,
database, or admin notice behavior.

### Admin Shell And Tab Navigation

Open `AI Companion` and verify primary tabs load, active states are clear, and
tab navigation does not leave stale panels or blank layout gaps.

### Connect Flow

Start from `AI Companion > Connect`, copy or use the connection URL, and verify
the expected assistant setup path or local smoke equivalent.

### Connection Approval And Consent Screen

Complete the approval screen as a logged-in WordPress user and verify scopes,
site identity, connected user, and approve/deny paths.

### Connections List And Revoke Path

Verify connected assistants appear in the list with safe metadata and that
revoke or disconnect removes access without exposing token material.

### Abilities Tab, Ability List, And Filtering

Verify ability groups, enabled/disabled states, list rendering, filtering, and
any pagination or search behavior.

### Activity And Diagnostics Views

Verify recent MCP activity, sanitized metadata, diagnostics summaries,
connection health, and error/empty states.

### Learning Tab Separation

Verify Learning Suggestions, Memory Records, and Incident Reports are distinct
review surfaces or have clear sub-navigation and active states, with no large
blank vertical layout gap.

### Advanced Settings Save

Change a safe setting, save it, reload the page, and verify the stored value
and success/error notice behavior.

### MCP Discovery And Tools-List Smoke

Verify deterministic discovery and `tools/list` behavior through the normal
connection/auth path. Record pagination, filtered ability proof, and any
client-specific variance.

### Changelog, Readme, And Release Metadata Visibility

Verify plugin header, readme stable tag, changelog entry, release metadata
tests, and WordPress admin update/details visibility where relevant.

## Automated And Manual Checks

- Run the cheapest relevant validation for the changed release candidate surface.
- For docs-only checklist updates, review Markdown links, headings, and formatting.
- For package readiness, run the repo's package/build validation and record the
  exact command and result.
- For OAuth and MCP surfaces, include the existing connector smoke or a
  documented manual equivalent.
- Browser automation is preferred for repeatable UI proof, but manual
  screenshots are acceptable when a Playwright or browser harness is not yet
  practical.
- If browser automation is skipped for a changed surface, record the manual
  screenshot evidence and the reason automation was not used.

## Release Brief Gate

A release-ready brief must include:

- The package/build tested and exact environment.
- Links to required visual proof.
- Functional smoke status for every golden workflow.
- Known proof gaps and their risk level.
- Explicit risk acceptance for any skipped critical UI proof or untested known workflow.
- Confirmation that screenshots and logs were captured without secrets or
  private data.

If the brief lacks critical proof, state that the candidate is not beta-ready
or production-ready yet. Do not soften missing proof as "ready with caveats"
unless the owner has explicitly accepted the risk in the brief.
