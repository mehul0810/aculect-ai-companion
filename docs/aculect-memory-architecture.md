# Aculect Memory Architecture

## Product contract

Aculect Memory is the site-owned source of truth for durable AI context. Intelligence groups help an assistant navigate and interpret abilities; they never grant, hide, or revoke an ability. OAuth scopes, WordPress capabilities, role policy, record visibility, review state, and runtime safety remain independent authorization controls.

Connected AI clients may read approved, relevant memory and propose changes. They do not overwrite approved site memory directly. Provider-native memory synchronization is optional, adapter-based, and enabled only when an official provider API and explicit administrator consent are available.

## Ownership boundaries

- `Intelligence\Memory` owns records, versions, review state, retrieval, history, tombstones, and the neutral synchronization contract.
- `Connectors\MCP\FirstPartyAbilityModules` currently owns the compatibility MCP declarations; these declarations will move into a focused memory module provider as the public surface expands.
- `Connectors\MCP\MemoryListService` maps approved-memory reads to the domain without owning persistence rules.
- Provider-specific adapters belong to `Connectors` and depend inward on the neutral memory synchronization contract.
- Admin UI consumes the same domain service as MCP. It must not implement separate save, rename, delete, or conflict rules.

## Data model

Each memory has a stable UUID and site-global legacy key, namespace, optional user owner, domain, value and evidence, visibility and sensitivity, review status, confidence, source, monotonically increasing version, content hash, validity window, and soft-deletion timestamp. The legacy key remains globally unique for compatibility; namespaces classify routing and visibility but do not create duplicate identities for the same key.

Every accepted mutation also creates an append-only event. Events provide history, cache invalidation, and a cursor-based outbound change feed. Connector sync state stores only mappings, checkpoints, leases, bounded errors, and retry timing; provider tokens remain in the existing protected OAuth/connector storage.

## Write and conflict rules

1. New AI-originated content is a pending proposal by default.
2. Updates require the caller's expected version. A stale version returns a conflict with the current safe record projection.
3. Approved site state wins conflicts. Incoming provider changes never use last-write-wins.
4. Forget operations create tombstones so connected clients can converge without resurrecting deleted memory.
5. Sensitive or user-private memory is excluded from synchronization by default.

## Retrieval path and budgets

The default tier uses indexed namespace, owner, status, visibility, expiry, and update filters before bounded text ranking. Semantic retrieval is an optional adapter; remote embeddings are opt-in and never run synchronously during normal MCP, REST, admin, or frontend requests.

- Maximum returned memories: 50; recommended recall default: 10.
- Text search is applied in the database and supports bounded page traversal; callers never receive more than 50 rows per request.
- Values and evidence remain bounded by the storage contract.
- List/search queries select explicit columns and may skip exact totals.
- Retrieval caching will key namespace, actor visibility, normalized query/filter, and latest event cursor when introduced.
- Provider synchronization will run asynchronously in leased batches with retry backoff and idempotency keys when provider adapters are introduced.

## Delivery phases

### Phase 1: canonical, versioned foundation

- Add versioned memory records, event history, tombstones, and connector checkpoint storage.
- Expose bounded `memory_list`, `memory_save`, and `memory_bootstrap` compatibility operations.
- Keep get/update/forget/history/change-feed behavior in the domain layer until their public MCP contracts and authorization scopes are completed.
- Keep existing `content:read` and `content:draft` authorization working during the transition.

### Phase 2: fine-grained consent and admin UX

- Introduce `memory:read`, `memory:propose`, `memory:write`, `memory:sync`, and `memory:admin` with an explicit legacy-token compatibility policy.
- Move the 9,000-line admin bundle's memory UI into focused components.
- Add conflict review, history, visibility, expiry, sensitivity, and sync controls.

### Phase 3: retrieval and adapters

- Add a capability-detected local full-text or embedding adapter behind the neutral retrieval interface.
- Add provider adapters only for official APIs with explicit consent, per-connection allowlists, rate limiting, and deletion propagation.
- Measure recall quality, p95 query time, queue delay, retry rates, and storage growth before changing defaults.

## Acceptance and rollback

Phase 1 passes when legacy rows remain readable, stale writes fail deterministically, tombstones appear in the change feed, non-admin callers cannot read unapproved/private memory, all queries are bounded, installer retries remain recoverable, and existing public memory tools retain compatible response fields.

The migration is expand-only. Schema expansion is marked independently, while legacy UUID/hash identity and transactional-engine conversion continue through bounded background batches tracked by a separate migration version. Rolling back code leaves additive columns and tables intact; older code continues using `memory_key`, value, evidence, status, and timestamps. No rollback deletes memory or history.
