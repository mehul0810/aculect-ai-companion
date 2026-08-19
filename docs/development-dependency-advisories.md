# Development dependency advisory exceptions

This register covers advisories that remain in the repository-only Node.js toolchain after applying non-breaking lockfile updates. These packages are not included in the production plugin ZIP, and `npm audit --omit=dev` must remain at zero findings.

## Current inventory

- Last verified: 2026-08-19 with Node 24.16 and npm 11.
- Full development tree: 12 advisory packages (10 high, 2 moderate).
- Production tree: zero advisories from `npm audit --omit=dev`.
- Direct owner: `@wordpress/scripts` 32.2.x. npm's automated force fix proposes installing 19.2.4, which is a breaking downgrade and is not an acceptable remediation.

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
