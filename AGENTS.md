# Quark Engineering Standards

## Baseline
- Use strict types and PSR-4 classes under `Quark\\`.
- Keep classes focused, testable, and small.
- Prefer WordPress core APIs over custom SQL or duplicated helpers.
- Follow least privilege and avoid exposing secrets or private options.
- Minimum PHP version is 8.2.

## PHP and WordPress Coding
- Follow WPCS (`WordPress-Core`, `WordPress-Docs`, `WordPress-Extra`).
- Run PHPStan before merge; new code must not increase baseline issues.
- Validate all input and sanitize per context.
- Escape all output in rendered/admin HTML.
- Use capability checks in all write paths.
- Use REST `permission_callback` for every route.

## Security and OAuth
- Use OAuth 2.1 style flows with PKCE for user-authorized access.
- Primary connector UX must be endpoint-only: paste the MCP endpoint into ChatGPT or Claude, then complete WordPress OAuth consent.
- Support Dynamic Client Registration (DCR) for plug-and-play clients; do not expose manual OAuth fields in the primary UX.
- Store token material hashed at rest.
- Use short-lived access tokens and rotating refresh tokens.
- Do not log tokens, secrets, personal data, or sensitive request bodies.
- Scope bearer tokens and enforce scope/capability before every tool action.

## MCP and Abilities API
- Keep tool outputs structured and deterministic.
- Add pagination and max limits for list endpoints.
- Disallow unbounded scans and expensive queries by default.
- Add only safe settings fields; never expose arbitrary `wp_options`.

## Assets and JS Tooling
- Use `@wordpress/scripts` for build/lint/format.
- Use WordPress Design System components (`@wordpress/components`) for admin UI.
- Keep bundle size small, avoid unused dependencies, and split code by feature if needed.

## Performance and Scalability
- Query only required fields and respect pagination.
- Avoid N+1 lookups and repeated expensive option reads.
- Cache immutable capability/descriptor data when useful.
- Prefer asynchronous operations for long-running external calls.

## Maintenance Workflow
- Run: `composer test` and JS lint/build before releases.
- Keep README and route/tool schema docs updated with behavior changes.
- Maintain backward compatibility for public tool names and response shapes where practical.
- Commit and push completed changes frequently, including small fixes, after validation passes.
