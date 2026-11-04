# Development dependency advisories

This register covers advisories in the Node.js dependency tree used for development and frontend asset builds. The production plugin ZIP excludes `node_modules`; built-asset exposure requires separate review. `npm audit --omit=dev` must remain at zero findings.

## Beta 2 preparation — 2026-11-04

A fresh Node 24/npm 11 install of the release candidate with Playwright 1.64.1 passes `npm ls --all`. The full `npm audit --package-lock-only` exits 1: **71 affected package records (10 high, 48 moderate, 13 low), representing 26 distinct advisories**. `npm audit --omit=dev` reports zero findings. No finding names the three updated Playwright packages; all implicated package lock records are unchanged from `f1276ea681268b00d648700bdc031a21d46126d1`.

This is an unresolved readiness issue, not an accepted exception or a passing full audit. The production ZIP excludes `node_modules`, but that alone does not establish whether an affected frontend dependency is bundled into built assets. Exposure and compatible remediations need review before Beta 2 recommendation. npm proposes breaking WordPress package downgrades for some aggregate findings; those suggestions have not been applied. The MCP SDK remains 1.31.0 in this scoped Playwright reconciliation; its newly reported advisories are included below.

| Package | Severity | Advisory |
| --- | --- | --- |
| `@hono/node-server` | moderate | [GHSA-rmxm-3fg6-px4f](https://github.com/advisories/GHSA-rmxm-3fg6-px4f) |
| `@modelcontextprotocol/sdk` | high | [GHSA-22jm-h49p-29qw](https://github.com/advisories/GHSA-22jm-h49p-29qw) |
| `@modelcontextprotocol/sdk` | high | [GHSA-46jr-4wx8-v453](https://github.com/advisories/GHSA-46jr-4wx8-v453) |
| `@modelcontextprotocol/sdk` | moderate | [GHSA-6prh-2h8m-c8cw](https://github.com/advisories/GHSA-6prh-2h8m-c8cw) |
| `@modelcontextprotocol/sdk` | moderate | [GHSA-rvq5-wwqv-78pq](https://github.com/advisories/GHSA-rvq5-wwqv-78pq) |
| `body-parser` | high | [GHSA-27r3-jxmh-p828](https://github.com/advisories/GHSA-27r3-jxmh-p828) |
| `deepmerge` | low | [GHSA-gcqx-w48m-w2h4](https://github.com/advisories/GHSA-gcqx-w48m-w2h4) |
| `fast-uri` | low | [GHSA-333x-w4r6-6crp](https://github.com/advisories/GHSA-333x-w4r6-6crp) |
| `fast-uri` | moderate | [GHSA-w74x-v6f9-j3xg](https://github.com/advisories/GHSA-w74x-v6f9-j3xg) |
| `fast-uri` | moderate | [GHSA-wjr9-j795-jxcr](https://github.com/advisories/GHSA-wjr9-j795-jxcr) |
| `fast-uri` | low | [GHSA-xhq7-xmqj-769h](https://github.com/advisories/GHSA-xhq7-xmqj-769h) |
| `follow-redirects` | high | [GHSA-8r9p-f939-6h3c](https://github.com/advisories/GHSA-8r9p-f939-6h3c) |
| `follow-redirects` | high | [GHSA-mrwp-f3q8-3g9p](https://github.com/advisories/GHSA-mrwp-f3q8-3g9p) |
| `follow-redirects` | high | [GHSA-q3jh-wrc6-jgq8](https://github.com/advisories/GHSA-q3jh-wrc6-jgq8) |
| `hono` | moderate | [GHSA-5r4p-p66f-jhc7](https://github.com/advisories/GHSA-5r4p-p66f-jhc7) |
| `ip-address` | moderate | [GHSA-rxp7-6hxx-x8q5](https://github.com/advisories/GHSA-rxp7-6hxx-x8q5) |
| `joi` | high | [GHSA-4528-x5fx-h6hg](https://github.com/advisories/GHSA-4528-x5fx-h6hg) |
| `launch-editor` | high | [GHSA-587h-7wmj-h5pm](https://github.com/advisories/GHSA-587h-7wmj-h5pm) |
| `launch-editor` | moderate | [GHSA-698v-4r3x-3vcv](https://github.com/advisories/GHSA-698v-4r3x-3vcv) |
| `launch-editor` | moderate | [GHSA-r825-w8mm-5pxw](https://github.com/advisories/GHSA-r825-w8mm-5pxw) |
| `markdown-it` | high | [GHSA-gfr2-rv44-r23c](https://github.com/advisories/GHSA-gfr2-rv44-r23c) |
| `markdown-it` | low | [GHSA-vfgh-jcmw-g37q](https://github.com/advisories/GHSA-vfgh-jcmw-g37q) |
| `minimatch` | moderate | [GHSA-g3hv-75wg-m249](https://github.com/advisories/GHSA-g3hv-75wg-m249) |
| `qs` | moderate | [GHSA-6pq8-jhm2-2vcw](https://github.com/advisories/GHSA-6pq8-jhm2-2vcw) |
| `serialize-javascript` | low | [GHSA-m5mm-p8h7-m29v](https://github.com/advisories/GHSA-m5mm-p8h7-m29v) |
| `zod` | high | [GHSA-mrhx-c7xc-m3w5](https://github.com/advisories/GHSA-mrhx-c7xc-m3w5) |

The older inventory below is retained as historical evidence and does not waive the current findings. Raw current audit output is retained with the candidate validation artifacts.

## Historical inventory — 2026-08-19

- Last verified: 2026-08-19 with Node 24.16 and npm 11.
- Full development tree: 12 advisory packages (10 high, 2 moderate).
- Production tree: zero advisories from `npm audit --omit=dev`.
- Direct owner: `@wordpress/scripts` is declared as `^32.2.0` and currently resolves to 32.6.0. npm's automated force fix proposes installing 19.2.4, which is a breaking downgrade and is not an acceptable remediation.

## WordPress Scripts archive, browser, and Markdown tooling

- Owner: Aculect engineering
- Review deadline: 2026-09-30
- Affected development paths:
  - Archive/browser chain: `@wordpress/scripts` -> `adm-zip`; and `@wordpress/scripts` -> `@wordpress/e2e-test-utils-playwright` -> `lighthouse` -> `puppeteer-core` -> `@puppeteer/browsers` -> `extract-zip`.
  - Markdown/YAML chain: `@wordpress/scripts` -> `markdownlint-cli` -> `markdownlint` -> `markdown-it` -> `linkify-it`; plus the nested `js-yaml` used by `markdownlint-cli`.

| Package | Severity | Owning path / current fix status |
| --- | --- | --- |
| `@wordpress/scripts` | High | Direct development dependency; aggregate npm fix is the rejected 19.2.4 downgrade. |
| `adm-zip` | High | Directly below WordPress Scripts; fixed 0.6.0 requires separate compatibility proof. |
| `@wordpress/e2e-test-utils-playwright` | High | WordPress Scripts browser-test path; no compatible owning update is currently selected. |
| `lighthouse` | High | Browser-test path; updating independently can alter audit behavior and requires its own proof. |
| `puppeteer-core` | High | WordPress Scripts and Lighthouse browser path; fixed versions are outside the locked owning ranges. |
| `@puppeteer/browsers` | High | Puppeteer path; fixed versions are outside the locked owning ranges. |
| `extract-zip` | High | Puppeteer archive path; no fixed published 2.x release in this path. |
| `js-yaml` | High | Nested Markdown CLI parser; an in-major fix exists but the non-force aggregate update expanded the vulnerable graph, so isolate before adopting. |
| `linkify-it` | High | Markdown parser path; fixed 5.0.2 requires a separately proven owning Markdown update. |
| `markdown-it` | High | Markdown parser path; fixed 14.2.0 requires a separately proven owning Markdown update. |
| `markdownlint` | Moderate | Markdown CLI path; current owning range remains affected. |
| `markdownlint-cli` | Moderate | WordPress Scripts lint path; current owning range remains affected. |
- Current exposure: development-only. The production package excludes `node_modules`, `package.json`, and `package-lock.json`.
- Risk: crafted archives can trigger path traversal or excessive memory allocation; crafted Markdown, YAML, or browser-audit input can trigger excessive CPU or memory use. These parsers are not reachable from the shipped WordPress runtime.
- Temporary control: run the repository toolchain only against reviewed repository source and trusted build inputs. Do not use these development commands to inspect untrusted archives or documents.
- Fix availability:
  - Safe in-range refreshes must be evaluated independently; a non-force `npm audit fix` trial increased the installed advisory graph and is not suitable evidence for a lockfile update.
  - `extract-zip` has no fixed published 2.x release in the current dependency path.
  - npm's aggregate fix recommendation is the breaking `@wordpress/scripts` downgrade noted above. Do not use `npm audit fix --force` or add unreviewed transitive overrides.
- Exit condition: upgrade to a compatible `@wordpress/scripts` dependency tree, or separately prove compatible owning-package updates that remove every advisory. Then remove this exception only after a clean install, full Node gates, package verification, no-secret fixture, and a zero-production-advisory check pass.
