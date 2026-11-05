# Media and content reference pickers

These opt-in 0.9.0 MCP Apps extend the existing pattern-picker visual language.
They help a user identify an existing WordPress object. Selecting an object is
not approval to insert media, edit content, publish, or change metadata.

## Interface and tool bindings

| Interface | Existing read tools | Display |
| --- | --- | --- |
| Media picker | `media.list_items`, `media.get_item` | Thumbnail cards, title, media family, available preview dimensions, alt text, and item reference |
| Content picker | `content.list_items`, `content.get_item`, `content_search.items` | Compact rows with title, content type, status, available excerpt or summary, and URL |

The versioned resources are `ui://aculect/media-picker/v1.html` and
`ui://aculect/content-picker/v1.html`. They are only available after the existing
site opt-in and MCP Apps negotiation. Tool visibility remains `model`; the
widgets do not introduce an app-callable tool or change any ability permission.
Non-App clients retain the existing text and structured responses.

Search and type filtering operate on the loaded response, not the entire site.
Local pages contain up to nine media cards or twelve content rows. Each picker
projects at most 100 returned objects. When server pagination indicates more
results, the interface tells the user to ask the assistant for another server
page or query. It does not silently fetch more data or imply complete-library
search. Missing metadata is shown as unavailable, not inferred.

## Selection and safety

The action sends a bounded ID and object type through `ui/update-model-context`.
The selected state appears only after host acknowledgement. No write tool,
approval token, markup, original file bytes, or content body is sent by selection.
The assistant must read the current object and follow the existing permission,
preview, and approval requirements before a separately authorized write.

New input, replacement results, cancellation, and teardown invalidate pending
selection callbacks. Host rejection leaves an actionable error without claiming
success. Indexed rows marked stale are not selectable until refreshed.

Text is rendered as text, never interpreted as HTML. Content URLs are displayed
for identification and are not automatically opened. A thumbnail is available
only when WordPress returns an image preview on the configured site's exact
origin. The displayed dimensions describe that preview, not the original file.
Cross-origin/CDN media and unavailable thumbnails remain selectable as text
cards. Preview loading uses the connected site's origin, with no Aculect relay,
remote script, additional provider, or arbitrary-origin allowance.

## Ownership and verification

- PHP resource and media-metadata helpers own negotiation-aware projection and
  the exact image-origin boundary. Existing ability permissions still run before
  metadata is added.
- `widgets/reference-picker/v1/` owns the shared bounded projection, lifecycle,
  selection behavior, and styles. Separate templates identify each picker.
- The widget build produces self-contained HTML under `assets/mcp-apps/` and
  enforces the existing 24 KiB per-widget budget. The build-only minifier does
  not add a runtime dependency to the plugin package.

Focused commands are `vendor/bin/phpunit --filter
'McpAppsControllerTest|McpAppsNegotiationTest|McpAppsPickerResourceTest'`, `node --test
tests/js/mcp-apps-reference-pickers.test.mjs`, `composer analyse:mcp:0.9`, and
`npm run build:widgets`. The complete pre-push gate is `npm run check:local`.

Rendered proof must cover desktop and 320px frames, light/dark themes, keyboard
selection, filters, pagination, empty/error/cancelled states, rejected and late
host replies, and no write invocation. Record the exact generated asset hashes
and distinguish simulated-host checks from actual ChatGPT/other-host checks.
VoiceOver and narration verification are excluded by owner direction, not
passed; this does not establish screen-reader compatibility or WCAG conformance.

### Current proof boundary

The pickers are implemented for the release candidate. Exact-revision local
validation and independent-review results are recorded in PR #557; they are not
merge, tag, release, deployment, or real-host verification. The disposable WordPress SQLite fixture
returned degraded live-search results after its indexed full-text SQL failed at
`MATCH ... AGAINST`; native indexed-search compatibility is not established by
the picker checks. That query implementation is outside this picker change.
