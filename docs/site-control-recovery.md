# Site-control expansion: recovery and safe settings

This expansion adds guarded content recovery, database-backed Site Editor
changes, and fixed private settings targets. It does not complete whole-site
control or authorize arbitrary WordPress mutations.

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

Ten further fixed targets add comment pagination/default page/order, automatic
comment closing/age, comment link threshold, avatar visibility, year/month folders
for new uploads only, and finite date/time format presets. These use the same
canonical integer/exact-enum validation; custom format strings and arbitrary
options remain outside the allowlist.

No value-bearing MCP arguments or result fields were added. Existing
administrator, HTTPS, nonce, expiry, site/actor binding and one-submission
requirements remain in force. This is a WordPress-hosted private form, not an
inline MCP Apps banner. It is not arbitrary option access.

## Trash recovery

`content.inspect_trashed` inspects one exact post/page without returning its
content. `content.restore_trashed` requires native read/edit/delete capabilities,
fresh keyed state, no edit lock, and explicit confirmation even on a trusted
connection. It uses the native untrash lifecycle and always restores to **draft**,
including previously published, private and scheduled items. Associated comments
follow native untrash behavior; trash metadata is cleared by core. Ambiguous
native outcomes are terminal, not an instruction to retry automatically.

## Database Site Editor changes

`site_editor.list_records` discovers existing database records, bounded to 50
per page. `site_editor.read_record` returns a fresh state token and bounded block
content or finite user-style overrides. Template, template-part and global-style
records must belong unambiguously to the active theme. Navigation records are
database `wp_navigation` posts. Read/discovery never creates theme overrides.

`site_editor.update_record` changes only title and/or registered block content.
It does not create records, change status, assign templates or navigation, or
edit theme/plugin files. Freeform content, Custom HTML, active markup and
oversized/deep block trees are rejected. Navigation content has an additional
native-child allowlist. Server-side validation is not a claim of visual or
Gutenberg JavaScript serialization validation.

`site_editor.set_style` changes one fixed color, typography, spacing or layout
override; null removes it. Colors are hex; dimensions are bounded to 3000px,
200rem/em or 100%; typography choices are finite. URLs, expressions, arbitrary
CSS and unknown paths are excluded. Unrelated native style data is preserved.

`site_editor.restore_record` uses a selected native content revision. Style
recovery rejects revisions differing in unrelated CSS/settings. It permits
supported scalar differences and bounded native color/font-size/spacing preset
references when recovering an old value. A setter refuses an existing target
value that cannot be restored under this policy; unchanged unrelated native values
are preserved. All editor writes require native
Site Editor/read/edit permission, exact state, explicit confirmation, no lock,
and native revisions retaining at least two versions. Before/after checks verify
a retained recovery revision and unchanged identity/status/ownership. Published
design records can affect the live site immediately; this is not a full-site backup.

## Classic navigation locations

`navigation.read_location_context` returns the active theme, registered classic
menu locations and complete assignment map (maximum 100 entries).
`navigation.assign_location` assigns an existing menu to one registered location,
or explicitly unassigns it with menu ID 0. Its confirmation preview identifies
the displaced menu. Fresh full-map/theme state is required; unrelated assignments
are preserved. Native filtered projections cannot replace the raw persisted map.
Success is verified; uncertain hooks/write outcomes are terminal. This does not
create/delete menus or menu items and is not a block-navigation assignment tool.

## Media trash safety

`media.delete_item` remains trash-only and requires explicit confirmation even
on trusted-write connections. If WordPress trash is disabled, it refuses
the operation rather than calling the native API that can permanently delete an
attachment in that configuration. Repeated trash on an already trashed item is
a no-op. Success requires a freshly verified attachment in trash; uncertain
native outcomes are terminal. Plugin hook side effects are not a rollback promise.

`media.get_item` now returns a keyed `expected_state` when the current attachment
state can be bounded and encoded safely. The existing update, trash and file-rename
tools accept this optional token and recheck it before mutation. Its input covers
attachment content/identity, alt text, attached-file reference and native image
metadata. Metadata values are not added to the response. File bytes are not
hashed, and this is not atomic locking. Omission retains legacy behavior; clients
should read and supply a fresh token rather than silently dropping a stale token.

## Remaining work and owner decisions

1. Broader navigation lifecycle and creation/assignment of new database editor
   records, with scoped state checks and native recovery proof.
2. User administration
   needs an explicit field/action policy; passwords, identity, roles and deletion
   are not implicitly authorized by a generic site-control request.
3. Backups require the owner to choose a provider and permitted backup/restore
   operations. Native Tools links are handoffs, not a backup implementation.
4. Typed third-party adapters require selected plugins and permitted data/actions;
   the generic WordPress Abilities bridge is not a typed plugin-data adapter.

Theme source-file editing, plugin source-file editing and site deletion remain
disallowed. Recovery is not authorization for these operations.

## Regression checks

Run `composer test:unit` for the full suite. The focused suites are
`ContentRevisionRecoveryTest`, `TrashedContentRecoveryTest`,
`EditorRecordAbilitiesTest` and `CorePrivateSettingTargetsTest`, alongside the
existing private-input tests. The existing MCP descriptor-security test protects
the supported OAuth-scope contract. The recovery class is included in
`composer analyse:mcp:site-operations`; the settings helper is covered by
`composer analyse:core`. Module declarations are covered by
`composer analyse:mcp:modules`; the new editor/trash helpers have the persistent
`composer analyse:mcp:editor-recovery` lane. Native recovery verification must use synthetic
posts in a disposable WordPress fixture, not production content. Do not automate
or capture private settings forms to obtain proof.
