---
name: wordpress-site-audit
description: Audit a WordPress site's safe health and maintenance signals using Aculect's read-only MCP site audit workflow. Use when reviewing HTTPS, REST API, cron, permalink, theme, update, or connector readiness.
---

# WordPress Site Audit

## Purpose

Use the registered `site_workflow_audit` MCP tool for a bounded, read-only site readiness audit. It is the source of the audit findings and next actions; do not try to reconstruct its checks from unrelated settings or private data.

## Tools

Use `site_workflow_audit` for the audit. Use only the suggested read tools exposed to this connection for follow-up evidence. The static audit App displays the same findings and cannot call tools or apply changes.

## Evidence

Report the observed facts, their source tool, uncertainty, and a concrete next step. Configured URLs do not prove external reachability or successful OAuth. Missing or stale cached information does not establish healthy status. Distinguish a proposed change from a verified result.

## Confirmation

A request to review or diagnose authorizes relevant reads. Before a write, show the exact proposed change and its effect, obtain user direction when not already authorized, and follow the existing tool preview and confirmation flow. Guidance never grants capabilities or bypasses policy.

## Workflow

1. Use this workflow for the user’s requested site audit or readiness review.
2. Call `site_workflow_audit` once and use the returned status, summary, findings, and next actions.
3. Separate observed facts from recommendations. State when a signal is unavailable or inconclusive.
4. Explain any recommended change and its impact. Ask for the user's explicit direction before applying changes with other tools.

## Boundaries

- This skill is guidance only; it does not grant permissions or execute code.
- Use only MCP tools actually listed for the current connection. OAuth scopes, WordPress roles and capabilities, ability policy, and each tool's safety checks remain authoritative.
- Keep the audit read-only. Do not change settings, install or update extensions, edit files, or delete site data as part of an audit.
- Never request or reveal passwords, tokens, private option values, or other secrets. Do not infer that an unavailable check passed.
