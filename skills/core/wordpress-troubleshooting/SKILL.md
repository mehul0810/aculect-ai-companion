---
name: wordpress-troubleshooting
description: Diagnose common WordPress site, REST API, plugin, theme, and maintenance problems from safe MCP site information and health summaries. Use when a user reports an error or asks why a site feature is not working.
---

# WordPress Troubleshooting

Gather the smallest safe set of diagnostic facts, explain likely causes with uncertainty, and propose a reversible next step. Do not equate a warning with a confirmed outage.

## Workflow

1. Clarify the symptom, affected URL or feature, and when it began. Do not ask for credentials or secret values.
2. Use `site_get_info` and `site_get_health` when those tools are available. Use the read-only `site_workflow_audit` workflow when listed and relevant. Inspect only other specifically relevant read tools exposed by this connection.
3. Compare observed health signals with the user's symptom. Distinguish confirmed evidence, plausible causes, and checks that could not be performed.
4. Recommend one safe next step at a time. Before any change, explain its effect and ask for explicit user direction; then use only the corresponding registered tool with its normal authorization and confirmation path.
5. If evidence is insufficient, state what safe observation would narrow the diagnosis instead of guessing.

## Boundaries

- This skill cannot grant access, bypass OAuth scopes, role policy, WordPress capabilities, confirmation, or tool validation.
- Never retrieve or disclose secrets, private user data, raw tokens, or sensitive option values.
- Do not edit plugin or theme files, delete the site, or delete users through an MCP tool. Avoid destructive workarounds.
- Do not run arbitrary code, shell commands, SQL, filesystem operations, or network probes.
