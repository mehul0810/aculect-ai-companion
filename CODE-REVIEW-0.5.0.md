# Aculect AI Companion — Code Review (release/0.5.0)

Reviewed: 2026-06-10 · Scope: modularity, performance, security, scalability, intelligence/abilities/MCP layer.
Codebase: 80 PHP files, ~24.4k LOC in `src/`.

**Verdict:** Architecture is solid — clean module boundaries, consistent governance (global → role → scope → confirmation), good SQL hygiene (`$wpdb->prepare` everywhere, FULLTEXT with LIKE fallback). All 7 reported issues are confirmed real. The blockers for a WP.org-quality 0.6.0 are P0-1 through P0-3 below.

---

## P0 — Fix before next release

### P0-1. `permission_callback => '__return_true'` on MCP routes
**Evidence:** `src/Connectors/MCP/McpController.php:30,40`. Auth happens *inside* `describe()`/`handle_rpc()` via `TokenValidator`, not in the permission callback.
**Impact:** WP plugin review team hard-blocks this. It also means `rest_pre_dispatch`/permission-aware middleware from other plugins sees these routes as open.
**Fix (smallest diff):** Move `TokenValidator` into the permission callback. Cache the auth context on the request so the callback doesn't re-validate.

```php
'permission_callback' => array( $this, 'check_mcp_permission' ),

public function check_mcp_permission( WP_REST_Request $request ): bool|WP_Error {
    // JSON-RPC notifications are auth-exempt per MCP spec (202, no id).
    $body = $request->get_json_params();
    if ( is_array( $body ) && ! array_key_exists( 'id', $body )
        && str_starts_with( (string) ( $body['method'] ?? '' ), 'notifications/' ) ) {
        return true;
    }

    $auth = ( new TokenValidator() )->authenticate( $request );
    if ( array() === $auth ) {
        return new WP_Error( 'rest_unauthorized', 'Authorization required.', array( 'status' => 401 ) );
    }

    $request->set_attributes( array_merge( $request->get_attributes(), array( 'aculect_auth' => $auth ) ) );
    return true;
}
```

Keep the MCP-shaped 401 + `WWW-Authenticate` header by adding a `rest_post_dispatch` filter that rewrites `rest_unauthorized` errors on `/mcp` into the existing `auth_challenge_response()` shape. In `handle_rpc()`, read `$request->get_attributes()['aculect_auth']` instead of re-authenticating.

The OAuth routes (`/oauth/register`, `/oauth/token`, `/oauth/authorize`, discovery) are *legitimately* public per RFC — but replace `'__return_true'` with named methods like `array( $this, 'check_public_oauth_endpoint' )` returning `true` with a docblock explaining why. The review team accepts intentional, documented public callbacks; they reject bare `__return_true`.

**Effort:** ~half day incl. tests.

### P0-2. OAuth private key + Defuse encryption key plaintext in `wp_options`
**Evidence:** `src/Connectors/OAuth/Server/KeyManager.php:30,98-99` — both keys stored via `update_option()` unencrypted.
**Impact:** Any DB read (SQLi in another plugin, leaked backup, hosting panel) yields the JWT signing key → attacker can mint valid access tokens for any user/scope.

**On the AUTH_KEY concern — the review team is right, and here's the layered answer:**

The risk with `AUTH_KEY` isn't that it's a bad key — it's that (a) hosts rotate salts, and (b) some hosts ship empty/duplicate salts. Both are survivable for *this* use case because OAuth keys are re-issuable: if decryption fails, regenerate the keypair and force clients to re-authorize. That's degraded UX, not data loss. So:

1. **Tier 1 (preferred): dedicated constant.** Support `ACULECT_AI_COMPANION_ENCRYPTION_KEY` in `wp-config.php`. Show a dismissible admin notice with a generated value to paste. This fully answers the review team — the key lives outside the DB and outside the salts.
2. **Tier 2 (fallback): salt-derived key.** `hash_hkdf( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, 32, 'aculect-oauth-key-v1' )`, then `sodium_crypto_secretbox`. Refuse this tier (fall to Tier 3 warning) if salts are the WP placeholder string or shorter than 32 chars.
3. **Rotation safety:** store `hash( 'sha256', $derived_key )` as a key-check value in a separate option. On decrypt failure or key-check mismatch → wipe keypair, regenerate, log a `Logger()->warning`, and let sessions re-auth via the normal OAuth challenge. Never fatal.

```php
private static function master_key(): string {
    if ( defined( 'ACULECT_AI_COMPANION_ENCRYPTION_KEY' ) && strlen( ACULECT_AI_COMPANION_ENCRYPTION_KEY ) >= 32 ) {
        return hash_hkdf( 'sha256', ACULECT_AI_COMPANION_ENCRYPTION_KEY, 32, 'aculect-oauth-v1' );
    }
    return hash_hkdf( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, 32, 'aculect-oauth-v1' );
}
```

Include a one-time migration: on first run, read existing plaintext options, encrypt, rewrite, delete plaintext. Version the stored format (`v1:` prefix) so future rotation is possible.

**Effort:** 1–1.5 days incl. migration + rotation tests.

### P0-3. No rate limiting on unauthenticated OAuth endpoints
**Evidence:** `src/Connectors/OAuth/ClientRegistrationController.php` (`/oauth/register`) and `TokenController.php` (`/oauth/token`) — zero throttling. Every DCR request also writes 1–2 rows to the diagnostics log table.
**Impact:** Unauthenticated client-row spam (fingerprint dedupe only catches *identical* payloads — vary one redirect URI and it's a new row), diagnostics-table bloat, and token brute-forcing.
**Fix:** Transient-based fixed-window limiter keyed on hashed IP: e.g. 5 registrations/hour/IP, 30 token requests/min/IP, return 429 + `Retry-After`. Add a global cap on unconsumed dynamically-registered clients (e.g. 100) with oldest-unused eviction in `StorageMaintenance::prune()`.
**Effort:** ~half day.

---

## P1 — High priority

### P1-1. Synchronous indexing on every `save_post` (no import/cron bypass)
**Evidence:** `src/Plugin.php:98,361-369` → `ContentIndexer::index_post()` (801-line class) runs inline. Only revisions/autosaves are skipped.
**Compounding problem found during review:** `links_from_content()` (`ContentIndexer.php:382-406`) calls `url_to_postid()` per anchor, **up to 300 times per save**. `url_to_postid()` is one of the most expensive single calls in WP core (full rewrite-rule resolution + queries). A link-heavy post makes *every editor save* slow, not just imports.
**Fix:**
1. In `handle_content_index_save()`, defer when bulk context is detected:

```php
if ( ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) || wp_doing_cron()
    || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST && $this->is_bulk_rest_request() ) ) {
    ( new ContentIndexer() )->mark_post_stale( $post_id );
    $this->schedule_stale_sweep(); // single debounced wp_schedule_single_event, +60s
    return;
}
```

The sweep job reuses the existing `queue_refresh_batch()` plumbing with `stale => true` — the queue/job/claim infrastructure is already built (`ContentIndexer.php:136-193`), this is wiring, not new architecture.
2. Cache `url_to_postid()` results per request (static array keyed by URL) and cap resolution at ~50 internal-looking URLs (skip external hosts before calling it — string-compare against `home_url()` host first).

**Effort:** 1 day.

### P1-2. No degraded fallback when the index is empty/stale
**Evidence:** `src/Connectors/MCP/IntelligenceIndexAbilities.php:26-36` — `search_items` returns whatever the index has plus a prose `freshness` hint. Empty index → empty `items`, no recourse the client can act on programmatically.
**Fix:** In `search_items()` (and `search_chunks` at item level), when the index returns zero rows for a non-empty query, run a bounded live `WP_Query` (`s => $query`, `posts_per_page => $per_page`, `no_found_rows => true`, statuses filtered by `current_user_can`) and return:

```php
$result['degraded']     = true;
$result['degraded_reason'] = 'index_empty'; // or 'index_stale'
$result['next_actions'][]  = 'Call content_index_refresh_batch with mode=queued, then retry for indexed results.';
```

Add `degraded` to the collection output schema in `McpController::collection_output_schema()` so clients can branch on it. Map live results to the same item shape (id, title, permalink, summary from `wp_trim_words`).
**Effort:** ~1 day incl. shape parity tests.

### P1-3. No idempotency keys on workflow writes
**Evidence:** `src/Connectors/MCP/ToolSafety.php` — confirmation tokens are consumed-then-execute (`consume_confirmation_token` deletes the transient at line 229 *before* the write runs). A timeout after execution + client retry = duplicate draft. `ContentWorkflowAbilities::create_draft()` has no dedupe.
**Fix:** Extend ToolSafety with replay storage — the plumbing (payload hashing, transient keys, auth binding) already exists:

1. Accept optional `idempotency_key` arg on write tools (add to `CONTROL_KEYS` so it's stripped before execution/hashing).
2. Before executing: `get_transient( 'aculect_idem_' . hash( 'sha256', $key . '|' . payload_hash( $tool, $args, $auth ) ) )` → if hit, return stored result with `'replayed' => true`.
3. After successful execution: store the result for 24h.
4. Hash must include the payload — same key + different args should return `409 idempotency_key_reuse` error, per Stripe semantics.
5. Also change confirmation-token flow to mark-consumed-after-success rather than delete-before-execute, storing the result against the token hash for the TTL window. That makes confirmed writes replay-safe even without an explicit key.
6. Advertise in tool descriptions + `inputSchema` so AI clients actually send it.

**Effort:** 1 day.

### P1-4. `mark_post_stale` fires on every meta change of every post
**Evidence:** `src/Plugin.php:102-104` — `added_post_meta`/`updated_post_meta`/`deleted_post_meta` each trigger a DB `UPDATE` via `mark_post_stale()`, with no post-type filter, no debounce, and no check that the post is even in the index. A single editor save fires these dozens of times (edit locks, Elementor/RankMath/ACF meta); a WooCommerce stock sync fires them constantly.
**Fix:** (a) skip non-indexable post types early (cheap `get_post_type()` check against the same rules as `is_indexable_post()`), (b) skip internal meta keys (`str_starts_with( $key, '_' )` covers edit locks/ACF internals — decide which underscore keys you actually index), (c) debounce per request: static `$marked = []` set so each post is marked stale once per request.
**Effort:** 2–3 hours.

---

## P2 — Medium priority

### P2-1. IntelligenceContext payload size
**Evidence:** `McpToolAvailability::operations_manifest_for_user()` (lines 123-223) emits ~37 operation entries × 7 keys each (`ability_id`, `tool`, `available`, `blocked_by`, `blocked_dependency_ids`, `required_scopes`, `read_only`), duplicated *inside every* `intelligence_*_get_context` response, plus full `blocked_by_global_ids`/`blocked_by_role_ids` arrays in the `policy` block, plus repeated `guidance` and `learning_protocol` blobs across all four context tools.
**Fix:**
- For **available** operations: emit only `tool` (name). `read_only`/`required_scopes` are already in `tools/list` — duplicating them burns client context tokens.
- For **blocked** operations: emit `tool` + `blocked_by` code only; drop `ability_id`, `required_scopes`, `blocked_dependency_ids` (fold deps into the `blocked_by` code, e.g. `dependency_blocked:content.update_item`).
- Drop `global_enabled_tool_names`/`global_enabled_ids` duplication in `ability_policy_for_user()` consumers — counts + blocked lists are sufficient.
- Hoist `guidance`/`learning_protocol` to short strings with a pointer: clients that read the server instructions already have the long form.
Target: context payloads under ~4 KB each. Measure before/after with `strlen( wp_json_encode( $payload ) )` in a unit test and assert a budget.
**Effort:** ~half day.

### P2-2. PHPStan level 6 → raise to 8, then `max`
**Evidence:** `phpstan.neon.dist` — `level: 6`, plus `missingType.iterableValue` globally ignored.
(Note: I read "(change to str)" as "make stricter" — if you meant something else, tell me.)
**Plan:** Don't jump straight to `max` — generate a baseline so new code is held to the higher bar immediately while existing debt burns down:

```neon
parameters:
  level: 8
includes:
  - phpstan-baseline.neon
```

`vendor/bin/phpstan analyse --generate-baseline`, commit it, then drive the baseline to zero over 2–3 releases. Level 8 adds nullability checks — the highest-value tier for this codebase given the heavy `?? ''` / `(string)` coercion style. Remove the global `missingType.iterableValue` ignore at the same time; you already write good docblock generics, so the diff is mostly mechanical. Also bump `parallel.maximumNumberOfProcesses` (currently 1 — CI is slower than it needs to be for no reason unless you're memory-capped).
**Effort:** half day setup; debt burn-down ongoing.

### P2-3. `AbilitiesRegistry` rebuilt ~20× per request
**Evidence:** 20 `new AbilitiesRegistry()` and 9 `new RoleAbilitiesPolicy()` call sites in `src/`. The module cache (`AbilitiesRegistry.php:24`) is per-instance, so all 45 module objects + definitions are reconstructed at each instantiation — several times within a single `tools/call`.
**Fix (no DI container needed):** make the module map a `private static ?array $shared_modules = null`. One-line semantics change, kills the rebuild cost everywhere. Longer-term (0.7.0): a tiny service locator (`Services::abilities_registry()`) so tests can swap instances — see P3-1.
**Effort:** 1 hour for static cache.

### P2-4. Capability-filter N+1 in index search
**Evidence:** `IntelligenceIndexAbilities::can_read_post()` calls `current_user_can( 'read_post', $id )` per row; the overfetch loop (`fill_readable_results`, up to 4 extra pages × 50 rows) can issue ~250 `get_post()` lookups per search for low-permission users.
**Fix:** Prime the cache before filtering: `_prime_post_caches( $ids, false, false )` (one query for all uncached posts), then run the filter. Keep the overfetch loop, it's correctly bounded.
**Effort:** 1–2 hours.

### P2-5. Confirmation/idempotency transients on non-persistent object cache
**Evidence:** `ToolSafety` uses `set_transient`. On hosts with a non-persistent object cache misconfiguration (or multi-server without shared cache), confirmation tokens vanish between the issue call and the confirm call → users can never confirm writes.
**Fix:** Add a connection diagnostic (you already have `ConnectionHealth`) that round-trips a transient and surfaces "confirmation tokens may not persist" in the diagnostics screen. Optionally fall back to a custom table row (you already have a cache table — `Installer::cache_table()` — use it instead of transients for tokens).
**Effort:** half day.

---

## P3 — Hygiene / architectural

### P3-1. `Plugin.php` is a 25-method proxy God-object
Every admin action is a one-line proxy to `SettingsPage`. Replace with a loop-registered map (`foreach ( self::ADMIN_ACTIONS as $action => $method ) add_action( "admin_post_$action", ... )`) or register hooks from `SettingsPage::register_hooks()` directly. Pure noise reduction; do it opportunistically.

### P3-2. `new X()` everywhere blocks unit testing
Constructors are invoked inline throughout (`McpController` alone news up `TokenValidator`, `Logger`, `AbilitiesRegistry`, `IntelligenceRegistry`, `ToolSafety`, `ActivityLogger` per request). `ContentIndexer` already takes an optional repository — extend that pattern (optional constructor args defaulting to `null` → lazy default) to `McpController` and ability services. No container needed.

### P3-3. SSE handler bypasses the REST lifecycle
`McpController::send_event_stream()` (line 343-351) echoes and `exit`s inside a REST callback. Works, but skips `rest_post_dispatch` (which P0-1 will rely on for headers). Set the auth challenge headers before the echo path, and document the early exit.

### P3-4. Block parsing via regex
`ContentIndexer::serialized_blocks()` regex-parses block comments instead of `parse_blocks()`. The regex misses nested inner blocks (children of `core/group`/`core/columns` are swallowed into the parent match) — chunking quality degrades on layout-heavy pages. Switch to `parse_blocks()` + recursive flatten when available; keep regex as the non-WP fallback. Worth doing before the index becomes the primary retrieval path for site builders.

### P3-5. `tools/list` has no pagination cursor
MCP spec supports `cursor`/`nextCursor` on `tools/list`. With ~45+ tools and full input/output schemas the payload is large; ChatGPT truncates big manifests. Low urgency now, but add cursor support before the tool count grows past ~60.

---

## What's already good (don't touch)

- SQL: consistent `$wpdb->prepare` with `%i` identifiers, FULLTEXT + LIKE fallback, bounded query text (200 chars).
- Governance layering: global ability toggle → role policy → OAuth scope → dry-run/confirmation → WP capability inside tools. Defense in depth done right.
- Capability-filtered index responses (`filtered_result_metadata`) prevent draft/private leakage to low-permission connections.
- Confirmation tokens are payload-hash-bound, user/client/provider-bound, constant-time-compared, hashed at rest.
- `wp_abilities.run` gates: public-ability check + policy allowlist before execute.
- Uninstall is opt-in and complete; OAuth storage pruning has lock + batching.

---

## Implementation plan

**Wave 1 — Security release 0.5.1 (1 week)**
1. P0-1 permission_callback refactor (0.5d)
2. P0-2 key encryption + migration + rotation handling (1.5d)
3. P0-3 OAuth endpoint rate limiting + client cap (0.5d)
4. P2-3 static registry cache — trivial, ride along (1h)
5. Regression pass: full OAuth flow against Claude + ChatGPT + Codex connectors (1d)

**Wave 2 — Reliability release 0.6.0 (1–1.5 weeks)**
6. P1-1 bulk-save bypass + stale sweep + `url_to_postid` cache/cap (1d)
7. P1-4 meta-hook filtering + debounce (0.5d)
8. P1-2 degraded `WP_Query` fallback + `degraded` schema flag (1d)
9. P1-3 idempotency keys + confirm-after-success (1d)
10. P2-1 context payload trim + size budget test (0.5d)
11. P2-4 `_prime_post_caches` (2h), P2-5 transient diagnostic (0.5d)

**Wave 3 — Ongoing**
12. P2-2 PHPStan 8 + baseline; burn down per release
13. P3-1/2/3/4/5 opportunistically with touched files

Sequencing rationale: Wave 1 is everything the WP review team and a pen tester would flag — smallest diffs, highest stakes. Wave 2 is everything an AI client or a bulk import would trip over. Nothing in Wave 2 depends on Wave 1 except P1-3's reuse of the ToolSafety changes, so the waves can overlap if you have the bandwidth.
