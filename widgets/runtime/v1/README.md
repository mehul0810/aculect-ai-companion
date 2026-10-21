# MCP Apps widget runtime v1

This is an isolated widget source boundary, not a WordPress admin app. The build
concatenates the small, ordered vanilla JavaScript modules and CSS into one
versioned HTML file at `assets/mcp-apps/runtime/v1/fixture.html`. The fixture
is packaged, but it is not registered as a production `ui://` resource by this
slice. A server-side resource owner must explicitly map an immutable URI to the
asset and keep the normal text/structured tool fallback.

Vanilla JavaScript was selected over React/Preact for this foundation: the
runtime needs a JSON-RPC message bridge, a small state reducer, and semantic
HTML, not a component tree. This avoids a framework and runtime dependency in
the sandbox. The build enforces 16 KiB of per-widget JavaScript and 24 KiB of
initial single-file HTML. The fixture currently measures under those ceilings;
each production widget should get its own budget and versioned URI.

`bridge.js` speaks MCP Apps protocol `2026-01-26` over `postMessage`. It accepts
messages only from `window.parent`, handles initialize/initialized, tool
input/result/cancellation, host context, and teardown, and exposes standard
`tools/call`, `ui/open-link`, `ui/update-model-context`, and display-mode
requests. Widget code must pass an explicit read-only tool allowlist; the MCP
server still owns OAuth, capabilities, scopes, and tool authorization. The
optional ChatGPT API adapter is feature-detected and unused by the baseline.
No widget state is persisted in browser storage or sent to a remote origin.

The fixture's CSP declares no network, frame, font, image, or remote script
origins. The host must also enforce the empty MCP Apps resource CSP domain lists;
an HTML meta policy alone is not a substitute for host sandbox enforcement.
All result fields are projected through `textContent` and fixed semantic nodes.

Local checks:

```sh
npm run build:widgets
node --test tests/js/mcp-apps-widget-runtime.test.mjs
npm run smoke:widget-runtime
```

The browser harness uses a synthetic sandbox host and data. It proves neither
a real `ui://` resource read nor a ChatGPT/Claude host render. Those require
server integration and exact-package host proof before release.
