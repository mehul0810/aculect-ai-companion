# Capability catalog and third-party management

Target: `release/0.8.0`. Owner-approved direction: WordPress and Aculect
capabilities are enabled by default; the Abilities screen manages third-party
WordPress Abilities API registrations. There is no Integrations tab or curated
integration prerequisite.

## Implementation contract

1. Keep existing Aculect module IDs, MCP tool names, aliases and execution gates.
   Ignore obsolete first-party global toggles for availability, retaining their
   stored data for rollback. Preserve role policies, scopes, capabilities,
   confirmation, dry-run, idempotency and audit checks.
2. Recognize native Core definitions by verified implementation origin, not a
   namespace alone. Enable public native Core abilities by default and omit
   them and Aculect mirrors from third-party controls.
3. Discover public third-party abilities automatically. New registrations start
   disabled; preserve explicit decisions, legacy allowlists, and decisions for
   temporarily absent plugins. Group controls by provider namespace without
   claiming verified plugin branding when provenance is unavailable.
4. Supply a paginated complete agent catalog using the same first-party
   availability policy as MCP tools/list plus enabled WordPress bridge entries.
   Deduplicate native Aculect mirrors and return preferred execution routes.
   Preserve existing capability-directory fields and WordPress subset tools.
5. Retain context retrieval as ordinary capabilities, with plain labels. Existing
   intelligence tool identifiers stay compatible. Diagnostic native-registration
   status must not imply that the generic bridge permits execution.
6. Restrict Abilities UI rows and bulk toggles to third-party entries. Saving that
   form must not erase unrelated first-party or confirmation settings.

## Acceptance and validation

- Fresh and existing installs expose first-party capabilities subject to the
  existing connection authorization; explicit role denials still work.
- New external read and write abilities start disabled. Saved choices survive
  imports, saves, provider disappearance and reactivation.
- A spoofed Core namespace does not gain default access. Core and Aculect rows
  are absent from third-party management.
- Catalog pages have stable ordering, complete totals and no mirror duplicates;
  entries do not invent callable tools or bypass scopes through the bridge.
- Existing direct tool calls and their aliases keep working; generic bridge
  execution still rejects first-party mirrors.
- Run focused policy/catalog/admin tests, full PHP and JS checks, build,
  modularity ratchet, and the no-secret MCP SDK smoke. Validate the admin UI
  save/reload, filters, pagination, keyboard and responsive behavior.
- Compare server catalog counts separately from client-imported tool counts.
  A client metadata refresh is an external proof step, not a reason to loosen
  server permissions.

## Rollout and rollback

This is elevated-risk work because it changes discovery and exposure defaults.
No new database schema or dependencies are required. Preserve legacy options;
rollback uses the previous plugin build and retained policy data. Newly
discovered third-party abilities never inherit enablement by namespace.
Commit and push after validation under repository policy. Publishing a new beta
and deployment are separate owner actions. Observe tool discovery, policy
denials and representative permitted calls after deployment.

## Implementation evidence (2026-09-09)

- PHP 8.4.8: full `composer test` passed, including WPCS, PHPStan and
  1,347 PHPUnit tests / 26,495 assertions. The two new catalog/source classes
  also passed their dedicated PHPStan check, now included in Composer scripts.
- Node 24.16.0: all 101 JS tests, JS/CSS lint and production asset build passed.
  Webpack still reports vendor/entrypoint size warnings; this is not a bundle
  performance certification.
- Both modularity checks passed, including the no-growth comparison with
  `origin/release/0.8.0`; legacy exception ceilings were not increased.
- No-secret local HTTP MCP smoke passed initialization, discovery of 135 direct
  tools, a permitted read call, and rejection of an invalid bearer with HTTP 401.
  This fixture does not prove external-client OAuth or Cloudflare behavior.
- Live Studio WordPress 7.1 / plugin 0.8.0: native registration contract passed;
  the administrator catalog returned 138 entries with a bounded 100-entry first
  page and all three native Core entries using the native-ability route.
- Independent source-blind browser validation was blocked at the reauthentication
  screen. Authenticated desktop/mobile UI, provider fixtures and save/reload
  persistence remain runtime proof gaps; automated policy/UI tests are not a
  substitute for those checks. No live settings were changed for this proof.
- No package, beta, tag, release, external-client refresh or deployment was
  performed. Other supported WordPress/PHP cells remain release-matrix work.
