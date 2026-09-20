# Aculect AI Companion Design Baseline

## Default UI Standard
- Use the WordPress Design System as the default admin and settings UI baseline.
- Prefer native WordPress patterns, spacing, typography, controls, and interaction models before inventing custom UI.
- Use `@wordpress/components` and related WordPress admin primitives wherever they fit the requirement.
- Treat the WordPress Design System Figma community file as the visual reference for default admin behavior and component usage.

## Aculect Layer
- Apply Aculect brand colors only as a restrained accent layer on top of the WordPress baseline.
- Do not restyle the admin into a product-marketing surface.
- Keep settings, diagnostics, and workflow screens dense, scannable, and operational.

## Admin Payload Boundaries
- The Abilities tab controls public third-party WordPress abilities, grouped by provider namespace. Core and Aculect capabilities are enabled by default and do not appear as global toggles. Newly discovered third-party abilities require explicit enablement; saved decisions survive provider disappearance. OAuth scopes, role policy, WordPress capabilities and runtime safety checks still authorize execution. Legacy first-party global selections are retained for rollback but no longer restrict availability. Role-managed classification must remain separate from global default enablement.
- The paginated capability directory combines authorized direct Aculect tools with enabled native Core and third-party abilities. Aculect native mirrors are deduplicated; each entry names its preferred execution route. Context retrieval uses ordinary capabilities with compatible existing tool IDs. WordPress subset discovery retains its existing contract. Native registration diagnostics distinguish direct execution from generic-bridge access.
- Core auto-enablement requires native WP_Ability objects with both callbacks originating in WordPress core ability implementation files; a namespace or metadata claim alone never confers trust. Unverifiable registrations remain administrator-managed. No curated integration registry or Integrations tab is required.
- `SettingsPage` owns settings-page orchestration, tab hydration, sample-data application, actions, routes, and permissions. Focused read-only payload builders may own one tab's query, filtering, and response projection without registering hooks or changing the public payload contract.
- `SettingsActivityPayloadBuilder` owns only the Activity tab's bounded filters, repository reads, empty shape, and pagination URLs. It must not query Activity storage for other tabs or own sample-data, action, nonce, REST, persistence, or asset behavior.
- `SettingsConnectionPayloadBuilder` owns only the effective-ability projection for active and revoked connection rows. It must not own session persistence, tab hydration, sample-data, actions, nonces, REST routes, or asset behavior.
- `SettingsDiagnosticsPayloadBuilder` owns only the diagnostics tab's bounded OAuth-capacity and log pagination reads, empty log shape, and navigation URLs. It receives the settings base URL and must not own sample-data, actions, nonces, REST routes, persistence, or asset behavior.

## Release Expectations
- Design-visible changes need screenshot proof or an explicit proof gap recorded in the issue or PR.
- Responsive admin behavior at constrained widths is part of done criteria for navigation, tabs, tables, and action controls.
- When a change affects onboarding, connection readiness, permissions, or confirmation flows, verify those states explicitly rather than only the happy path.

## Fixed MCP Workflow Module Boundary
- `Connectors\MCP\Modules\FixedWorkflowAbilityModules` owns the fixed workflow router, sessions, loops, guides, content workflow, content-media workflow, Rank Math workflow, and site-audit declarations and their exclusive input schemas. It preserves their established registry order and fails closed on duplicate internal IDs.
- `AbilityModuleFactory` and `ToolSafetySchema` are the shared internal construction boundary for callback modules and write-safety schema controls. `FirstPartyAbilityModules` composes domain providers; it must not duplicate their descriptors or schemas.

## WordPress Abilities Interoperability Boundary
- WordPress Core's Abilities API is the canonical native interoperability contract. Aculect keeps its MCP registry, OAuth scopes, global and role ability policy, capability checks, confirmation flow, and execution claims as the authorization boundary; profiles remain advisory navigation metadata, and native registration is an adapter rather than a second execution path.
- Only explicitly read-only intelligence is mirrored to native WordPress Abilities in this release. Operational writes remain MCP-gateway-only until each operation has an equivalent native confirmation, capability, rollback, and audit contract.
- External Ability registrations are untrusted inputs. Discovery skips malformed metadata, metadata reads fail closed, permission callbacks are exception-isolated, and execution returns a bounded error without propagating provider exception text.
- Native lifecycle coverage belongs in the real-WordPress `wp-env` contract lane. Unit tests may use stubs for deterministic edge cases but must not claim to prove Core registration behavior.

## Composite Content Write Boundary
- `Connectors\MCP\ContentWriteCoordinator` owns post writes that also touch taxonomies or featured media. It snapshots update state, applies dependent writes in a deterministic order, and compensates failures by restoring the snapshot; newly created posts are moved to the recoverable WordPress trash rather than permanently deleted.
- A failed or unverifiable compensation returns terminal `partial_write` metadata (`failed_step`, `rollback_status`, `recovery`) so execution claims are completed and automatic retries are never attempted. Workflows do not index or apply SEO side effects for that terminal result.
- Content updates may include `expected_modified_gmt` from a prior read. A stale token returns `conflict` before any write, giving clients an explicit optimistic-concurrency boundary.

## Atomic Write Execution Claims
- `Aculect\AICompanion\Connectors\MCP\ExecutionClaims` owns the per-site transactional authority for confirmation-token and identity-bound idempotency execution. Options, transients, and persistent object caches are compatibility inputs only and never decide callback ownership.
- One claim row may bind one nullable SHA-256 confirmation alias and one nullable SHA-256 idempotency alias. It stores only payload/tool/identity/owner hashes, a monotonically increasing fence, closed lifecycle state, bounded successful replay JSON/hash, and lifecycle timestamps—never raw tokens, keys, arguments, or OAuth identity fields.
- A 30-second lease exists only in the pre-callback `claimed` state. Entering `running`, completing, releasing a normal callback error, or marking an ambiguous callback `uncertain` requires the exact owner hash and fence. Only expired `claimed` rows may be reclaimed. `running` and `uncertain` rows are never automatically reclaimed or removed because the side effect may already have occurred.
- The authoritative completed result is committed before compatibility transients. Confirmation-only results retain for one hour and any idempotency-bound result for 24 hours; pruning is portable, eligibility-rechecking, and bounded to 500 rows. Successful replay JSON is capped at 1 MiB and hash-verified before use.
- Public behavior adds only bounded result-level `execution_in_progress` and `execution_uncertain` failures. Transport outcomes, dry-run/preview behavior, trusted-write policy, scopes, capabilities, role policy, advisory profile guidance, legacy/current MCP response shaping, and write callback schemas remain unchanged.
- Rollback across this boundary requires pausing AI access and draining or inspecting live claims first. Full uninstall removes the table only under the existing explicit remove-data policy.
