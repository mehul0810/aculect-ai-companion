# MCP Apps exact-operation approval handoff: threat model and integration plan

Status: a bounded WordPress-authenticated human approval handoff is integrated
for negotiated MCP Apps calls to `content_workflow.update_post` when the
existing write policy requires confirmation. Non-App clients retain their
existing confirmation-token path. Unsupported negotiated App operations fail
closed without issuing an unusable legacy token. This implementation does not
meet every broader #371 operation-category acceptance criterion; see the 0.9
scope below. The approval queue, store, and tests require independent review
before production packaging.

## Decision and boundary

Use a direct WordPress-authenticated human handoff, with no Aculect relay. The
MCP App may explain that review is required and can offer a generic link to a
WordPress admin queue, but only after the server has created a pending request.
The queue itself must render the exact operation and receive the decision.
Neither the widget nor its host can approve, decline, mint a credential, or run
the operation. Approval records consent only; a later authenticated MCP call
must execute the operation through the existing checks.

The App's pending result links only to the generic WordPress queue. The queue is
the sole human decision surface. The model/widget cannot approve, decline,
mint a credential, or execute the operation. The existing MCP
confirmation-token path remains in place for non-App clients. A negotiated App
operation whose complete server-authored diff cannot be safely summarized
fails closed; the user may retry from a genuinely non-App client under the
existing confirmation flow. A configured
direct-write connection remains on that established policy path and does not
show a pending approval card; it grants no new ability through this handoff.

The store uses a keyed operation fingerprint, an encrypted bounded
summary, current-capability checks when listing/reviewing requests, a
per-actor outstanding-request limit, and best-effort WordPress-cron cleanup
registration. Its quota check and approved-operation comparison are not
atomic execution claims.

The 10-minute TTL is an authorization limit: every decision and operation
lookup rejects expired rows once `expires_at` is reached. It is not a guarantee
that ciphertext is physically deleted at that exact time. The store performs a
bounded lazy purge on relevant reads and writes and schedules best-effort hourly
WP-Cron cleanup; physical deletion therefore depends on site traffic and cron
execution. Do not describe this feature as providing a hard 10-minute
physical-retention window.

## 0.9 integration boundary

Only `content_workflow.update_post` is eligible, and only for a negotiated App
request with a complete server-authored dry-run diff, supported fields, a
current editable target, and a captured target-state digest that still matches
when the approval row is created. SEO metadata and workflow-session arguments
fail closed for negotiated App calls because their side effects are not fully
represented in that content diff; they do not receive a legacy token that the
App cannot use. Publication and destructive changes
are labeled with higher risk/category values in the review UI; they are not
separate general-purpose approval operations. Approval is bound to the current
WordPress user/site, OAuth client and token session, canonical exact tool and
arguments, target version/state digest, current policy fingerprint, and the
`edit_post` capability. The client must resubmit the exact request. An atomic
one-use consume occurs before the existing gateway executes it, which then
rechecks policy, capability, and expected target state. Approval lookup binds
the OAuth access-token session identifier; if token refresh rotates that
identifier during the TTL, the old approval is not transferable. A resubmission
under the new token safely requires a new approval; the old record remains
unusable until its short TTL expires.

The consume operation performs a second full target-state digest check after
its one-use database compare-and-set. WordPress core post writes do not provide
an atomic compare-and-set across post fields, taxonomies, and media
relationships. A very small concurrent-edit window remains between the last
check and the underlying WordPress write. The normal `expected_modified_gmt`
check remains active, but its timestamp has second-level precision. Do not
describe the write as transactionally isolated from all concurrent editors.

Not included in this narrow 0.9 path: model-callable approval tools, additional
read-only/reversible/publish/destructive operation families, or Cloudflare
relay behavior. Do not describe #371 as fully complete until those acceptance
clauses are either implemented safely or explicitly reconciled by the owner.

## Assets and trust boundaries

- MCP caller/model: untrusted for human intent; it can propose arguments and
  replay or alter requests.
- MCP Apps host/widget: untrusted presentation context; host messages, result
  notifications, UI state, and supplied URLs are not authority.
- WordPress admin session: human decision boundary. Require a logged-in user,
  same site/blog and same WordPress user as the originating OAuth request,
  nonce validation on POST, and a fresh capability/policy check.
- Plugin server/storage: sole authority for canonical arguments, operation
  fingerprint, expiration, decision state, replay protection, and execution.
- Site owner and WordPress roles: current capability and plugin policy may
  revoke an otherwise valid pending request at any time.

## Threats and required controls

| Threat | Required control |
| --- | --- |
| Widget or model forges approval | Widget has no write-tool bridge; only an authenticated WordPress POST records a decision. |
| Link leaks a bearer token, nonce, or pending-item secret | Link goes to a generic admin queue. Put no operation id usable as authority, nonce, token, args, or secret in the URL. Resolve pending rows from the logged-in user's server-side queue. |
| Another user/site approves a request | Bind pending row to blog/site id, WP user id and OAuth client/connection; show it only to that user on that site. Fail closed on multisite or identity ambiguity. |
| Operation changes between preview and execution | Compute a fingerprint from canonical normalized tool name/arguments, but retain only the fingerprint. Require the MCP client to resubmit the exact arguments after approval and compare again before execution. |
| Stale or unauthorized request executes | On approval and again immediately before execution, check expiry, user, site, OAuth connection, ability availability, scopes, role policy, capabilities, object state and operation-specific validators. |
| Duplicate approval/replay causes repeated side effect | State machine plus atomic transition/claim; integrate with `ExecutionClaims` and existing idempotency/confirmation finalization. Consume approval once. |
| Decline is overridden by client replay | Persist terminal `declined` state for the pending fingerprint; reject replay without creating a new implicit request. A new preview is a distinct human decision. |
| CSRF or clickjacking records a decision | POST-only approve/decline; WordPress nonce and capability checks; standard admin origin/frame protections; no GET side effects. |
| Stored XSS or misleading summary | Render escaped server-authored values; bound lengths and fields; never trust widget-provided summary; show canonical tool, target, proposed values/diff, destructive effects and expiry. |
| Storage outage or race | Fail closed; no direct execution fallback; use atomic compare-and-set/unique claim and test concurrent requests. |
| Private data retained indefinitely | Minimize stored argument fields where possible, encrypt/sanitize as needed, set bounded TTL and cleanup for pending, declined, expired and consumed records, and avoid sensitive logging. |

## Exact server lifecycle to implement

1. In `AbilityExecutionGateway`, preserve today's order: validate input/schema,
   OAuth scopes, role/global ability policy, current WordPress capabilities,
   operation-specific guards and preview/dry-run. Only a write that already
   requires confirmation can create a handoff candidate. Read-only or
   already-authorized operations do not gain a new approval path.
2. Add a dedicated pending-approval store/state machine. Compute the canonical
   exact-call fingerprint transiently, then persist only that hash,
   target/site/user/client binding, a bounded encrypted review summary, expiry
   and status (`pending`, `approved`, `declined`, `consumed`, `expired`). Do not
   retain full tool arguments; require exact argument resubmission and a hash
   comparison before later execution. Never persist or return a reusable raw
   secret. Use a random internal row id only as a lookup key, not as
   authorization; do not put it in the generic handoff URL or widget data.
3. Return a bounded MCP result with a server-authored summary, expiration and
   generic WordPress admin queue URL. Do not include `confirmation_token`, WP
   nonce, secret, raw arguments, or a mutation-capable endpoint in the widget
   payload or URL. A resource read must not create or change pending state.
4. Add an authenticated admin queue page. It lists only records bound to the
   current WP user/site and performs a fresh authorization check. Require
   explicit POST actions with nonce and capability validation. Show the exact
   operation and its material effects before either Approve or Decline.
5. Approval changes only `pending -> approved`; decline changes only
   `pending -> declined`. Both are atomic and auditable. Repeated decisions,
   stale rows and invalid nonces are harmless errors. The human decision does
   not itself invoke the ability.
6. On the next MCP `tools/call`, require the same OAuth client/connection and
   actor binding, exact canonical operation fingerprint, approved unexpired
   state, and current policy/capabilities. Re-run object/version checks, claim
   atomically, and route through the existing ability execution and idempotency
   machinery. Any mismatch or unavailable store returns a blocked result with
   no side effect. A changed call requires a new preview and separate decision.
7. Finalize the approval as consumed only with execution-claim semantics that
   prevent duplicate writes on retry; return deterministic replay results as
   current confirmation tokens do. Emit privacy-safe audit events for pending,
   approve, decline, expiry and execution outcomes.

## Integration surface and proof

Expected code touchpoints: `McpAppsNegotiation`, `McpResourceRegistry`,
`AbilityExecutionGateway`, `ToolSafety`/confirmation schema, MCP result shape,
`ExecutionClaims`, a new store and admin controller/page, package verifier and
widget build/tests. Keep WordPress admin authentication local; no callback,
token exchange or operation data should pass through Aculect infrastructure.

Tests must cover wrong user/site/client, capability/policy revocation, exact
argument and target mismatch, expiry, nonce/CSRF, decline replay, concurrent
approve/execute, database/storage failure, execution retries, no widget tool
calls, no token/nonce/secret in resource payload or URL, and bounded cleanup.
Then validate the packaged artifact in a disposable non-production WordPress
runtime and verify the human queue, login redirect, approve/decline, and MCP
replay behavior. Unit tests or synthetic widget rendering alone do not prove
the authenticated lifecycle.
