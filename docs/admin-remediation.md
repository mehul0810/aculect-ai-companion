# Administration remediation

Memory review retains namespace and the observed version through the database payload, both forms, and every save/delete command. Keys are immutable in this screen; stale revisions and malformed identities fail without silently writing to the site namespace. Authorization and nonce checks remain in SettingsPage; MemoryReviewAction owns validated command construction and delegates transactional writes to MemoryService.

Admin queries select the displayed columns explicitly. Status totals use a 60-second transient with invalidation after committed writes. Existing numbered page links remain compatible; keyset navigation is a separate public contract change, not silently substituted.

Blocked migration status and operator recovery guidance appear above memory records. The nonce-protected retry schedules bounded work rather than performing database migration during a browser request.

Complete memory-card and changelog components, shared action forms, empty states, labels, and presentation helpers are extracted under src/Admin. The capitalized directory is intentional: it matches the existing filesystem and Linux build paths. Feature SCSS uses Sass modules loaded at the original cascade locations. Optional DataViews chunk rejection retains the Activity table fallback and provides an actionable reload error for Connections and Abilities instead of an endless loading state.

Approval preserves existing privacy. The edit dialog explicitly offers private/site sharing (and preserves an existing connection visibility), while cards show namespace and sharing status. Imported private guidance is never implicitly made site-shared by approval.

Validation: focused MemoryReviewAction, MemoryAdminQuery and SettingsPage unit tests; source-owned memory UI identity contract and actual shared utility behavior tests; full Node suite, JS/CSS lint, build, and modularity. Browser proof and cross-request concurrent mutation proof remain separate release validation responsibilities.
