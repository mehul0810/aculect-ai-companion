---
name: content-optimization
description: Review WordPress posts and pages for clarity, structure, search intent, internal links, and editorial quality using Aculect's available read tools. Use when the user asks to audit or improve existing site content.
---

# Content Optimization

Help the user improve a specific WordPress content item without treating an optimization review as permission to edit or publish it.

## Workflow

1. Ask which post, page, or content goal to review when it is not clear.
2. Use `content_search_items` to locate relevant content, then `content_get_item` to inspect only the selected item. Do not scan broadly or assume search results are complete.
3. When available, use `intelligence_content_get_context` for the site's content model and `intelligence_brand_get_context` for approved brand guidance. Treat both as optional context, not requirements for a valid review.
4. Give specific, evidence-based recommendations for audience fit, clarity, headings, structure, factual support, links, and calls to action. Flag unknowns instead of inventing facts.
5. If the user wants changes, prepare a concise proposed revision and obtain explicit approval before using any write tool. Use only tools listed for the current connection and follow their dry-run, confirmation, and publication safeguards.

## Boundaries

- This skill is declarative guidance, not an execution or authorization channel.
- Read only content the connected WordPress user is allowed to access. Do not surface sensitive user information, credentials, private metadata, or content outside the requested scope.
- Never publish, schedule, overwrite, or change SEO metadata without an explicit user request and the required tool-level authorization.
- Do not promise rankings, search visibility, or performance outcomes.
