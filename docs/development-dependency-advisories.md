# Development dependency advisory exceptions

This register covers advisories that remain in the repository-only Node.js toolchain after applying non-breaking lockfile updates. These packages are not included in the production plugin ZIP, and `npm audit --omit=dev` must remain at zero findings.

## WordPress Scripts archive and Markdown tooling

- Owner: Aculect engineering
- Review deadline: 2026-09-30
- Affected development paths: `@wordpress/scripts` through `adm-zip`, `markdownlint-cli`, `markdownlint`, `markdown-it`, `linkify-it`, and the nested `js-yaml`
- Current exposure: development-only. The production package excludes `node_modules`, `package.json`, and `package-lock.json`.
- Risk: crafted ZIP, Markdown, or YAML input could cause excessive memory or CPU consumption when the affected development commands process untrusted input.
- Temporary control: run the repository toolchain only against reviewed repository source and trusted build inputs. Do not use these development commands to inspect untrusted archives or documents.
- Upgrade constraint: npm currently proposes a breaking downgrade of `@wordpress/scripts` rather than a compatible remediation. Do not use `npm audit fix --force`.
- Exit condition: upgrade to a compatible `@wordpress/scripts` dependency tree that removes these advisories, then remove this exception after the full Node, package, and release-fixture gates pass.
