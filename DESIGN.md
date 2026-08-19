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
- `SettingsPage` owns settings-page orchestration, tab hydration, sample-data application, actions, routes, and permissions. Focused read-only payload builders may own one tab's query, filtering, and response projection without registering hooks or changing the public payload contract.
- `SettingsActivityPayloadBuilder` owns only the Activity tab's bounded filters, repository reads, empty shape, and pagination URLs. It must not query Activity storage for other tabs or own sample-data, action, nonce, REST, persistence, or asset behavior.

## Release Expectations
- Design-visible changes need screenshot proof or an explicit proof gap recorded in the issue or PR.
- Responsive admin behavior at constrained widths is part of done criteria for navigation, tabs, tables, and action controls.
- When a change affects onboarding, connection readiness, permissions, or confirmation flows, verify those states explicitly rather than only the happy path.

## Custom Workflow Definition Boundary
- `Aculect\AICompanion\Workflows\Definitions` owns the internal, immutable custom-workflow definition contract. It is independent from MCP connectors, storage, execution, adapters, admin UI, and public APIs.
- Version 1 uses exactly these top-level fields: `definition_schema_version`, `workflow_id`, `workflow_version`, `name`, `description`, `content_target`, `input_schema`, `steps`, `allowed_abilities`, `write_policy`, `approval_gates`, `output_contract`, `validation_rules`, `status`, `created_by`, `updated_by`, and `compatibility`.
- Definitions fail closed on missing or unknown fields, invalid shapes or enums, unavailable ability references, forward dependencies, unsafe write/gate combinations, non-JSON values, schema references, or resource-bound violations. Validation never sanitizes or silently repairs input.
- The v1 write-policy modes are `proposal_only`, `draft_only`, and `approved_update`. Proposal-only definitions cannot contain write steps; every write step in the other modes must have an approval gate, and non-write steps cannot be gated.
- Both input and output contracts are bounded JSON Schema objects with `type: object`. V1 deliberately supports only `type`, `description`, scalar `enum`/`const`, object `properties`/`required`/`additionalProperties`/property counts, array `items`/item counts/`uniqueItems`, string length/`pattern`, and numeric range keywords. Every nested schema declares one exact type; keyword shapes, values, counts, ranges, required-property references, and patterns are validated. Unknown keywords, vocabulary declarations, `$ref`, and `$dynamicRef` fail closed, so validation never requires network access or an external metaschema.
- Maps are recursively key-sorted for normalization while list order, including step order, remains significant. PHP arrays follow `array_is_list()` semantics; callers use `stdClass` or the object-preserving `WorkflowDefinition::from_json()` boundary when generic argument data needs explicit objects, including numeric member names. The authoritative canonical JSON projection encodes required maps, including empty step arguments, as objects and hashes that exact string with SHA-256; equivalent map ordering has one checksum and material object/list or value changes produce another. Returned values are detached deep copies, so neither original nor returned objects can mutate stored definition identity.
- V1 contains no storage, migration, runner, adapter execution, workflow admin, or public REST/MCP surface. Those layers must consume this boundary rather than redefining or weakening it.
- `WorkflowDefinitionCompatibilityMetadata` derives detached deterministic compatibility identity from a validated definition: schema/workflow versions, checksum, input/output contract versions, sorted abilities, and adapter requirements grouped by adapter with sorted unique versions. It does not persist, migrate, or execute definitions.
- `WorkflowDefinitionSchemaSupport` truthfully supports the current schema and, only after a later schema exists, current-minus-one. Product v1 therefore supports exactly `[1]` with no invented v0; a future v2 policy would report `[2, 1]`. Repository-owned JSON compatibility fixtures and their exact manifest remain test-only under `tests/fixtures/workflows/definitions` and are excluded from production packages.

## Pure Workflow Planning Boundary
- `Aculect\AICompanion\Workflows\Planning` owns deterministic, immutable workflow plans, dry-run summaries, transition states, and evidence binding. It consumes validated workflow definitions but does not persist runs, resolve adapters or abilities, execute callbacks, or call WordPress APIs.
- Plan identity pins the workflow and definition versions/checksum, contract versions, sorted adapter and ability requirements, ordered steps/gates/rules, and normalized input hash. Map order is canonical, list order is significant, and every plan and evidence object binds to the exact SHA-256 plan hash.
- The closed run lifecycle is `created`, `waiting_for_input`, `prepared`, `dry_run_ready`, `waiting_for_approval`, `running`, then terminal `completed`, `failed`, or `cancelled`. Gated plans cannot enter `running` without first reaching `waiting_for_approval`; running cancellation requires externally supplied exact-plan safe-boundary evidence.
- Dry-run summaries are declarative and public-safe: they expose bounded plan identity and step requirements, never raw workflow inputs or step arguments, and always state that execution has not started.
- This layer intentionally has no database, options, transient, cache, clock, randomness, retry, lease, audit, MCP, REST, admin, registry, gateway, or bootstrap integration. Later persistence and execution layers must consume this contract without bypassing its evidence and transition checks.

## Fixed MCP Workflow Module Boundary
- `Connectors\MCP\Modules\FixedWorkflowAbilityModules` owns the fixed workflow router, sessions, loops, guides, content workflow, content-media workflow, Rank Math workflow, and site-audit declarations and their exclusive input schemas. It preserves their established registry order and fails closed on duplicate internal IDs.
- `AbilityModuleFactory` and `ToolSafetySchema` are the shared internal construction boundary for callback modules and write-safety schema controls. `FirstPartyAbilityModules` composes domain providers; it must not duplicate their descriptors or schemas.
