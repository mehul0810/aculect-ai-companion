# Post-update result widget v1

This self-contained, versioned frontend asset presents the server-authored
post-update result. The "Review prior revision" link is an authenticated
WordPress revision-screen handoff, not an Undo operation. The widget performs
no write tool calls and cannot initiate restoration. Installed-host proof
remains separate from fixture tests.

The associated tool result must supply `structuredContent` with this bounded
shape. Only the server may set the fields; the widget never derives URLs from
post or revision IDs:

```json
{
  "schema": "aculect.post-update.v1",
  "outcome": "success",
  "content": {
    "title": "Example draft",
    "type": "Page",
    "status": "draft",
    "completed_at": "2026-09-25T10:30:00Z",
    "revision": "Revision 42"
  },
  "links": {
    "view": "https://site.example/example-draft/",
    "edit": "https://site.example/wp-admin/post.php?post=123&action=edit",
    "compare": "https://site.example/wp-admin/revision.php?revision=42"
  },
  "undo": { "available": false, "safe_snapshot": false }
}
```

`outcome` is one of `success`, `partial`, `stale`, `unavailable`, `error`.
The timestamp is an RFC 3339 instant with an explicit UTC or numeric offset.
Links are optional, HTTPS-only, server-canonical, authorized for the actor,
and must not include credentials or secrets. The widget rejects unsupported
schemata and unsafe links, downgrades an incomplete `success` to `partial`, and
renders all text with `textContent`. `undo.available` and
`undo.safe_snapshot` must both be explicitly true, and the exact positive
post/revision IDs and `wordpress_revision_review` reason must be present, to
show recovery guidance. The server selects the pre-change revision before
writing and rechecks it after writing. WordPress authenticates and authorizes
the human who follows the link; the human must compare the then-current post
before acting. Opening the link itself never restores anything. Native Restore
may affect revisioned metadata and does not undo status, taxonomy, or
featured-image changes. The separate Aculect content-only recovery flow
requires a fresh comparison and confirmation. Publishing is never inferred
from an update result.

The HTML/CSS/JS asset is built by `npm run build:widgets`; tests use only a
synthetic sandbox host. Passing those tests does not prove ChatGPT/Claude
rendering, server authorization, revision recovery, or exact-package behavior.
