# Pattern picker widget v1

This is a presentation and selection slice for issue #372. The generated,
self-contained HTML is `assets/mcp-apps/pattern-picker/v1/pattern-picker.html`.
It is not registered as a production `ui://` resource yet.

The widget accepts a `tools/call` result with `structuredContent.items` from
the existing `intelligence.patterns.list_available` ability. It also accepts a
picker-specific `aculect.pattern-picker.v1` wrapper. Each item needs a stable
`id` or `name`; the card uses bounded title, categories, source, block, and
content-type metadata. It ignores `content` and other arbitrary markup.
Compatibility is `compatible`, `incompatible`, or `unknown`. Missing
compatibility becomes `unknown`, and only `compatible` items can be selected.
The existing ability currently omits this field, so backend integration must
add an authoritative compatibility projection before this can be a usable
production picker. Do not infer compatibility from visual metadata in the
browser.

Selection sends only
`{structuredContent:{schema:'aculect.pattern-selection.v1',pattern_id:'…'}}`
to `ui/update-model-context`. This is a host context update, not an insertion
or write request. Any later content workflow must revalidate the identifier,
actor capability, pattern availability, and confirmation policy server-side.
The widget has no `tools/call` allowlist or network access. Unknown, removed,
stale, and failed results have non-mutating recovery guidance.

At most 100 supplied records and 12 cards per page are rendered. Filtering is
local to that bounded result; the server remains responsible for permission
checks, discovery filtering, and pagination beyond that result. The preview is
semantic block text, not a screenshot or execution of pattern content.
Curated WebP previews require separately maintained, bundled assets and are
not fabricated by this slice. The widget-specific JavaScript budget is 18 KiB
and the single-file HTML budget remains 24 KiB.

Local checks with the repository-required Node version:

```sh
node scripts/build-widgets.mjs
node --test tests/js/mcp-apps-pattern-picker.test.mjs
node tests/Integration/Browser/mcp-apps-pattern-picker.mjs
```

The browser test uses a synthetic sandbox host. It does not prove a real MCP
resource read, ChatGPT/mobile host behavior, exact-ZIP packaging, or backend
discovery and capability enforcement. Those remain release gates for #372.
