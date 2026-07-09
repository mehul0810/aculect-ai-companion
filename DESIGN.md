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
