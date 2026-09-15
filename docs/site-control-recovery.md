# Site-control expansion: recovery and safe settings

This batch adds two content recovery tools and extends the existing private
settings form. It does not complete whole-site control.

## Recovery contract

`revisions.compare_content` and `revisions.restore_content` operate on one
existing post/page and one saved revision belonging to it. Autosaves, trash and
custom post types are excluded. Comparison returns three field-level change
flags and byte lengths, not historical text or revisioned metadata values.
The envelope also identifies the post/revision, current status and fixed safety
warnings; these are permitted output fields, not historical content. Each field
is bounded to one MiB. The keyed state token includes the site, parent identity,
status, modification time, content fields and selected revision state.

Restore uses the existing `content:draft` OAuth write scope, native read/edit
capabilities, and native publishing capability for published, private or
scheduled targets. There is no separate `content:publish` scope in the current
connector contract. Explicit confirmation is mandatory, including connections
otherwise allowed direct writes. The confirmation binds the selected revision
and expected state. Native other-editor locks and stale state reject execution.

Before a content write, a matching native recovery revision must be verified.
The state and edit lock are checked again after saving that recovery point. A
successful result verifies the restored title/content/excerpt, unchanged status,
and the continued existence of the matching recovery revision. Hook failures or
unverified outcomes after a native operation are terminal; do not auto-retry.

The write deliberately does not call the full `wp_restore_post_revision`
workflow: WordPress attaches restoration of all revision-enabled metadata to
that workflow, even when its requested fields are narrowed. This tool instead
updates only the three named fields through the native post-update API. Native
save hooks still run; arbitrary plugin side effects are not a rollback promise.
Revision retention can prune history. Optimistic state and lock checks do not
make writes atomic against native editors or unrelated plugins.

## Private settings contract

The fixed private form gains 16 target IDs: start of week; RSS count/excerpt
mode; default comment/ping status; moderation; threaded comments/depth;
comments per page; thumbnail crop; and the six thumbnail/medium/large dimensions.
Canonical integer and enum validation runs before the existing generic text
path. Bounds and allowed choices are in the form labels. Image-size changes do
not regenerate existing images.

No value-bearing MCP arguments or result fields were added. Existing
administrator, HTTPS, nonce, expiry, site/actor binding and one-submission
requirements remain in force. This is a WordPress-hosted private form, not an
inline MCP Apps banner. It is not arbitrary option access.

## Remaining work, in order

1. Trashed-content recovery with native comment/trash-metadata behavior and
   explicit handling of restored publication status.
2. Site Editor/global styles/templates and broader navigation operations, each
   with scoped state checks, preview and recovery proof.
3. Remaining safe settings and user/media operations.
4. Maintenance/backups and typed plugin-specific adapters.

Theme source-file editing, plugin source-file editing and site deletion remain
disallowed. Recovery is not authorization for these operations.

## Regression checks

Run `composer test:unit` for the full suite. The focused suites are
`ContentRevisionRecoveryTest` and `CorePrivateSettingTargetsTest`, alongside the
existing private-input tests. The existing MCP descriptor-security test protects
the supported OAuth-scope contract. The recovery class is included in
`composer analyse:mcp:site-operations`; the settings helper is covered by
`composer analyse:core`. Module declarations are covered by
`composer analyse:mcp:modules`. Native recovery verification must use synthetic
posts in a disposable WordPress fixture, not production content. Do not automate
or capture private settings forms to obtain proof.
