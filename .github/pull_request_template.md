## Linked Issue

- Related:

## Summary

-

## Scope

- Changes:
- Non-goals:

## Risk

- Risk level: Low / Medium / High
- Security/privacy notes:
  - [ ] No secrets, tokens, private site data, personal data, or sensitive request bodies are included in code, logs, screenshots, or fixtures.
  - [ ] MCP/OAuth/ability changes preserve least privilege, capability/scope checks, safe tool outputs, and existing public tool/response contracts.

## Validation

- Commands/checks run:
- Modularity impact:
  - [ ] `composer check:modularity` passes.
  - [ ] If a legacy exception is touched, the changed-file ratchet passes against the PR base.
  - [ ] No new legacy exception was added; if an exception is required, it has an owner, issue, target, and removal plan in `.codex/modularity-rules.php`.
  - [ ] New responsibilities stay within the documented namespace dependency boundaries.
- PHPStan baseline justification:
- UI or workflow proof:
  - [ ] Screenshot, screen recording, CLI output, or reason not applicable is included for UI/workflow-visible changes.

## Release Train

- Base branch:
- Release/milestone:
- [ ] Base branch and release train are intentional for this issue.
- [ ] README, readme.txt, docs, and changelog were updated or confirmed not applicable.
- [ ] Package/build/release metadata impact was reviewed or confirmed not applicable.
