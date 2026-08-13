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
