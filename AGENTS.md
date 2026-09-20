# Aculect AI Companion Engineering Standards

## Baseline
- Use strict types and PSR-4 classes under `Aculect\AICompanion\\`.
- Keep classes focused, testable, and small.
- Prefer WordPress core APIs over custom SQL or duplicated helpers.
- Follow least privilege and avoid exposing secrets or private options.
- Minimum PHP version is 8.2.
- Use locally installed Codex skills for WordPress and GitHub work, especially `$wp-expert` and `$github`; do not depend on repo-local skill copies.
- Repo-local governance docs live in `AGENTS.md`, `DESIGN.md`, `TESTING.md`, and `RELEASE.md`; keep product-specific workflow rules there instead of only in chat or memory.

## Modularity and Dependency Budgets
- New production PHP, JavaScript, and SCSS files should stay at or below 500 lines for review and 1,200 lines as a hard ceiling; new test files should stay at or below 800 lines for review and 1,800 lines as a hard ceiling.
- Keep individual methods below 80 lines in production and 100 lines in tests for review; the hard ceilings are 120 and 180 lines respectively. Extract a focused collaborator before adding a new responsibility to a legacy exception.
- Run `composer check:modularity` for every scoped change. It reports legacy hotspots with owner/issue/target metadata, enforces exception ceilings, and checks forbidden namespace dependencies. Pull-request CI additionally runs `bin/check-modularity.php --changed-from=origin/<base>` so touched legacy exceptions cannot grow relative to the base branch.
- Legacy exceptions in `.codex/modularity-rules.php` are temporary ratchets, not waivers. A PR that touches an exception must either reduce its ceiling or explain the bounded change and keep the recorded ceiling fixed afterward.
- When a target branch has never contained the modularity configuration, its first adoption enforces current ceilings and dependency rules without comparing historical growth. An invalid base, shallow history, or previously removed configuration fails closed. Once adopted, the normal no-growth comparison applies.
- Domain ownership is directional: Intelligence and Activity cannot import MCP services directly. Add a neutral port or adapter when a boundary needs to cross layers.

## Project Subagents
- Project profiles live in `.codex/agents`. `.codex/config.toml` allows two child agents plus the parent, with depth one. Children must not delegate. Do not restore `agents.max_threads`.
- The minimum model is `gpt-5.6-luna` for the parent and all ad hoc, built-in, reviewer, and behavior-validation agents. Keep a compliant stronger parent model; never use an older or lower-capability model, even for trivial work or as a fallback.
- All repository profiles explicitly select Luna. Mapping and log analysis use `medium`, bounded implementation and behavior proof use `high`, and security/release review use `max`. Reasoning effort is separate from model capability.
- For a justified escalation or unavailable Luna, use an available `gpt-5.6-terra`, `gpt-5.6-sol`, or `gpt-6-astra` with supported reasoning effort; record the reason. Do not infer eligibility from a model-name prefix. Unknown models need an owner decision; never silently downgrade.
- Before spawning, check the actual model and effort selected by the runtime. When custom profiles are unavailable, pass their instructions and explicit model/effort to the supported spawn tool. Full-history inheritance must not bypass the model floor.
- Configuration defaults are not a runtime prohibition on explicit overrides. The parent must enforce the model floor on every spawn and stop/reassign any noncompliant worker before relying on its work.

## When To Spawn Project Subagents
- Work directly on small, understood fixes, documentation, configuration, and short CI failures. Delegate only an independent question or implementation slice that saves useful time, or a required independent review. Do not launch the full roster by default.
- Choose one mapper for the unresolved boundary: `aculect-plugin-mapper` for PHP/hooks/REST/MCP/OAuth/storage, or `aculect-admin-ui-mapper` for React/state/styles/editor/admin flows. Use both only when their assignments do not overlap. Mapping does not constitute review or rendered proof.
- Use `aculect-ci-log-summarizer` for long or multi-job failures; give it immutable run/job IDs and let the parent continue independent work. Preserve the first failure and distinguish infrastructure from product failures.
- Use `aculect-narrow-fixer` only after specifying exact files or an isolated checkout, expected behavior, constraints, and validation. At most one writer may own a file at a time. Shared-checkout edits require disjoint file ownership.
- Require a fresh `aculect-mcp-oauth-reviewer` before integrating high-risk authentication, permissions, privacy, destructive-operation, or MCP contract changes. It must not be the implementer; include a reviewed revision and revisit affected findings after changes or conflict resolution.
- Use a fresh `aculect-behavior-validator` for changed critical user flows and release golden workflows. Supply the observable contract, exact runtime/ZIP and checksum, synthetic fixtures, permitted mutations, and evidence destination. Do not fork implementation history or provide source/diff explanations. Source inspection contaminates source-blind proof and must be reported.
- Require `aculect-release-reviewer` before production integration, beta/stable publication, or release synchronization. Routine package builds and small release-document edits do not each need a new reviewer. Reuse evidence only when its tested revision or unchanged scope is demonstrated.
- Every assignment states objective, acceptance criteria, repo/branch/SHA, owned files, non-goals, environment/mutation limits, validation, and stop condition. Use a fresh concise handoff for independent review; reuse workers only within the same bounded assignment.
- Workers return findings or changes, exact revision/artifact, commands and results, untested boundaries, and blockers. They must not commit, push, create/edit GitHub entities, merge, release, change settings, or subdelegate. The parent owns those actions under existing authorization.
- Keep checkpoints to meaningful changes, avoid duplicate testing and unchanged polling, and stop workers that overlap or drift. Agent completion is not acceptance: the parent inspects the actual diff and evidence before integration.

## PHP and WordPress Coding
- Follow WPCS (`WordPress-Core`, `WordPress-Docs`, `WordPress-Extra`).
- Run PHPStan before merge; new code must not increase baseline issues.
- Validate all input and sanitize per context.
- Escape all output in rendered/admin HTML.
- Use capability checks in all write paths.
- Use REST `permission_callback` for every route.

## Security and OAuth
- Owner-approved 0.8.0-only exception: rejected credential-free token response cache headers are deferred to #531 in 0.8.1. Report the gap explicitly; preserve all functional/security assertions and strict successful-token cache checks. The version-limited exception must not apply to 0.8.1 or later.
- Treat the working beta13 OAuth flow as a protected behavioral contract. A failing OAuth test blocks release and requires a redacted diagnosis and explicit owner approval before changing discovery, routing, registration, login/consent, client storage, scopes, PKCE, token issuance, refresh, or revocation behavior.
- Do not weaken, skip, delete, or rewrite OAuth assertions to make a failure pass without explicit owner approval. Tests and CI safeguards are part of the protected contract. Approval to add tests is not approval to change the OAuth flow.
- Preserve first-attempt failure evidence. A passing retry does not erase a regression or establish its cause. Classify fixture/infrastructure, origin/plugin, metadata/cache, and edge/provider failures separately; never automatically disable security or change authentication to recover.
- Use OAuth 2.1 style flows with PKCE for user-authorized access.
- Primary connector UX must be endpoint-only: paste the MCP endpoint into ChatGPT or Claude, then complete WordPress OAuth consent.
- Support Dynamic Client Registration (DCR) for plug-and-play clients; do not expose manual OAuth fields in the primary UX.
- Do not return plugin-level `429` responses for valid DCR registration attempts; ChatGPT and Claude may retry registration before authorization starts.
- OAuth authorize must send already logged-in users directly to the Aculect AI Companion consent screen, never back through `wp-login.php`.
- If login is required, `redirect_to` must point to the Aculect AI Companion admin consent screen, not the REST authorize endpoint.
- Before beta releases, smoke-test DCR `POST /oauth/register` and authorize redirects to avoid repeating setup blockers.
- Store token material hashed at rest.
- Use short-lived access tokens and rotating refresh tokens.
- Do not log tokens, secrets, personal data, or sensitive request bodies.
- Scope bearer tokens and enforce scope/capability before every tool action.

## MCP and Abilities API
- MCP `tools/list` names must match Claude's tool-name constraint: `^[a-zA-Z0-9_-]{1,64}$`.
- Keep internal ability IDs separate from public MCP tool names; accept legacy aliases in `tools/call` where practical.
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
- Use `npm run check:local` as the canonical pre-push gate. WPCS, essential tests/static analysis, secrets/dependency checks and packaged OAuth remain hosted; JS/CSS style lint runs locally. Do not equate a local receipt with a hosted OAuth pass.
- Use `npm run check:release` for clean-revision local package preflight. Hosted WordPress/PHP compatibility, security and exact-package OAuth remain mandatory. Run `npm run smoke:release-ui` locally with safe disposable-site inputs when browser proof applies; missing proof is a reported gap, not an authorized skip.
- Run: `composer test` and JS lint/build before releases.
- Keep README and route/tool schema docs updated with behavior changes.
- Maintain backward compatibility for public tool names and response shapes where practical.
- Commit even minor completed changes and push once the whole task is done, after validation passes.
- Never create a GitHub release or prerelease unless the user explicitly asks for one.

## Worktree Hygiene
- If the primary checkout is dirty or on the wrong branch for the requested work, do not edit in place.
- Prefer a fresh scoped worktree from the correct base branch for release-train or issue-specific implementation.
- Treat unrelated local changes as user-owned; do not revert or overwrite them.
- When a scoped issue is already fixed on the target branch, report it as reconciled instead of forcing a no-op patch.
