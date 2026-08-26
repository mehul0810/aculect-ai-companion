<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\Modules\FixedWorkflowAbilityModules;
use Closure;
use RuntimeException;

/**
 * Builds first-party MCP ability modules.
 */
final class FirstPartyAbilityModules {

	private const MAX_SERIALIZED_CONTENT_BYTES = 300000;

	private readonly AbilityModuleFactory $module_factory;

	public function __construct( ?AbilityModuleFactory $module_factory = null ) {
		$this->module_factory = $module_factory ?? new AbilityModuleFactory();
	}

	/**
	 * Return first-party modules keyed by internal ability ID.
	 *
	 * @return array<string, AbilityModuleInterface>
	 * @throws RuntimeException When two providers declare the same internal ID.
	 */
	public function all(): array {
		$fixed_workflows = ( new FixedWorkflowAbilityModules( $this->module_factory ) )->all();
		$modules         = array(
			$this->module(
				'search',
				'Search WordPress Content',
				'Use this when ChatGPT, Claude, Codex, or another MCP client needs citation-friendly search results from WordPress content before fetching a full item. This canonical read-only search tool returns stable result IDs, titles, and canonical URLs.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->canonical_search_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->canonical_search( $args )
			),
			$this->module(
				'fetch',
				'Fetch WordPress Content',
				'Use this after the canonical search tool returns a result ID, or when the user gives a known WordPress post ID, and a client needs full citation-friendly text for one readable content item.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->canonical_fetch_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->canonical_fetch( $args )
			),
			$fixed_workflows['workflow.route_request'],
			$this->module(
				'core_schema.discover',
				'Discover WordPress Core Schema',
				'Use this before planning WordPress core management work. It returns bounded read-only REST route, post type, taxonomy, status, revision, autosave, Site Editor, and capability hints without exposing callbacks, nonces, option values, or private internals.',
				'Core Schema Discovery',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => ( new CoreSchemaDiscovery() )->manifest()
			),
			$fixed_workflows['workflow_session.start'],
			...array_values( array_slice( $fixed_workflows, 2 ) ),
			$this->module(
				'site_editor.get_context',
				'Read Site Editor Intelligence',
				'Use this before planning Appearance > Editor work. It reads the active theme, Site Editor availability, merged global settings/styles, templates, template parts, navigation, blocks, and patterns without editing theme files.',
				'Site Editor Intelligence',
				'content:read',
				true,
				$this->context_only_schema(),
				static fn ( array $args ): array => ( new SiteEditorAbilities() )->get_context( $args )
			),
			$this->module(
				'site_editor.refresh_context',
				'Refresh Site Editor Intelligence',
				'Refresh the plugin-owned Site Editor intelligence snapshot after theme, template, template part, global style, navigation, block, or pattern changes. This never writes theme files.',
				'Site Editor Intelligence',
				'content:draft',
				false,
				$this->context_only_schema(),
				static fn ( array $args ): array => ( new SiteEditorAbilities() )->refresh_context( $args )
			),
			$this->module(
				'site_editor.list_templates',
				'List Site Editor Templates',
				'List block templates available through Appearance > Editor for the active theme, including source, origin, customization state, and admin-safe metadata.',
				'Site Editor Intelligence',
				'content:read',
				true,
				$this->template_collection_schema(),
				static fn ( array $args ): array => ( new SiteEditorAbilities() )->list_templates( $args )
			),
			$this->module(
				'site_editor.get_template',
				'Read Site Editor Template',
				'Read one block template by ID or slug, including bounded serialized block markup for admin-level planning. This does not read or write files directly.',
				'Site Editor Intelligence',
				'content:read',
				true,
				$this->template_get_schema(),
				static fn ( array $args ): array => ( new SiteEditorAbilities() )->get_template( $args )
			),
			$this->module(
				'site_editor.list_template_parts',
				'List Site Editor Template Parts',
				'List block template parts available through Appearance > Editor, including header, footer, sidebar, and other theme-defined areas.',
				'Site Editor Intelligence',
				'content:read',
				true,
				$this->template_collection_schema(),
				static fn ( array $args ): array => ( new SiteEditorAbilities() )->list_template_parts( $args )
			),
			$this->module(
				'site_editor.get_template_part',
				'Read Site Editor Template Part',
				'Read one block template part by ID or slug, including bounded serialized block markup for admin-level planning. This does not read or write files directly.',
				'Site Editor Intelligence',
				'content:read',
				true,
				$this->template_get_schema(),
				static fn ( array $args ): array => ( new SiteEditorAbilities() )->get_template_part( $args )
			),
			$this->module(
				'site_structure.list_reusable_blocks',
				'List Reusable Blocks and Synced Patterns',
				'List reusable blocks/synced patterns with bounded metadata, status, modified date, local usage hints, and safe edit/view links. Full content bodies are not returned by default.',
				'Site Structure Discovery',
				'content:read',
				true,
				$this->reusable_blocks_schema(),
				static fn ( array $args ): array => ( new SiteStructureDiscoveryAbilities() )->list_reusable_blocks( $args )
			),
			$this->module(
				'site_structure.list_block_areas',
				'List Widget and Block Areas',
				'List registered sidebars/widget areas and block-theme template-part areas with active state and bounded counts. This never writes widgets, templates, or theme files.',
				'Site Structure Discovery',
				'content:read',
				true,
				$this->empty_schema(),
				static fn ( array $args ): array => ( new SiteStructureDiscoveryAbilities() )->list_block_areas( $args )
			),
			$this->module(
				'users.current_access',
				'Read Current User Access Summary',
				'Read the connected WordPress user ID, sanitized role slugs, curated site-level capability booleans relevant to Aculect operations, and blocked/unavailable reasons. This never returns email, raw caps, sessions, tokens, user meta, or password data.',
				'User Access Discovery',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => ( new UserRoleCapabilitiesDiscovery() )->current_access_summary()
			),
			$this->module(
				'users.roles_summary',
				'Read Role Capability Summary',
				'Read a privileged, bounded summary of WordPress roles with translated labels, user counts, and curated capability category booleans. This requires promote_users and never returns raw capability maps.',
				'User Access Discovery',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => ( new UserRoleCapabilitiesDiscovery() )->roles_summary()
			),
			$this->module(
				'users.list_safe',
				'List Users Safely',
				'List users only when the connected WordPress user has list_users. Results are capped at 50 per page and include only user ID, display name, and sanitized role slugs; private fields and raw caps are never returned.',
				'User Access',
				'content:read',
				true,
				$this->safe_users_list_schema(),
				static fn ( array $args ): array => ( new UserRoleCapabilitiesDiscovery() )->list_safe_users( $args )
			),
			$this->module(
				'admin_menu.get_context',
				'Read Admin Menu Intelligence',
				'Use this before planning WordPress core, plugin, or theme admin settings work. It returns visible admin menus, navigation targets, and registered settings metadata without exposing option values.',
				'Admin Menu Intelligence',
				'content:read',
				true,
				$this->context_only_schema(),
				static fn ( array $args ): array => ( new AdminMenuAbilities() )->get_context( $args )
			),
			$this->module(
				'admin_menu.refresh_context',
				'Refresh Admin Menu Intelligence',
				'Refresh the plugin-owned admin menu intelligence snapshot after plugin/theme changes that add, remove, or move admin screens. This does not update settings or options.',
				'Admin Menu Intelligence',
				'content:draft',
				false,
				$this->context_only_schema(),
				static fn ( array $args ): array => ( new AdminMenuAbilities() )->refresh_context( $args )
			),
			$this->module(
				'admin_menu.list_pages',
				'List Admin Menu Pages',
				'List visible WordPress admin menu pages and subpages for the connected administrator, including URLs, capability requirements, and high-level sections.',
				'Admin Menu Intelligence',
				'content:read',
				true,
				$this->admin_menu_list_schema(),
				static fn ( array $args ): array => ( new AdminMenuAbilities() )->list_pages( $args )
			),
			$this->module(
				'admin_menu.get_navigation_target',
				'Find Admin Navigation Target',
				'Find the most relevant WordPress admin page for a settings, plugin, theme, Site Editor, content, media, user, tool, or maintenance task.',
				'Admin Menu Intelligence',
				'content:read',
				true,
				$this->admin_navigation_schema(),
				static fn ( array $args ): array => ( new AdminMenuAbilities() )->get_navigation_target( $args )
			),
			$this->module(
				'admin_menu.list_settings',
				'List Registered Admin Settings',
				'List registered WordPress setting metadata by group or search term without exposing raw option values or secrets.',
				'Admin Menu Intelligence',
				'content:read',
				true,
				$this->admin_settings_schema(),
				static fn ( array $args ): array => ( new AdminMenuAbilities() )->list_settings( $args )
			),
			$this->module(
				'navigation.get_context',
				'Read Navigation Intelligence',
				'Use this before planning navigation or menu work. It detects block theme, hybrid, classic-menu, and unsupported navigation context and states clearly that writes are not implemented in this slice.',
				'Navigation Intelligence',
				'content:read',
				true,
				$this->context_only_schema(),
				static fn ( array $args ): array => ( new NavigationMenuDiscoveryAbilities() )->get_context( $args )
			),
			$this->module(
				'navigation.list_menus',
				'List Navigation Menus',
				'List readable classic menus and wp_navigation entities with clear source markers, bounded counts, and no write capability.',
				'Navigation Intelligence',
				'content:read',
				true,
				$this->navigation_menus_schema(),
				static fn ( array $args ): array => ( new NavigationMenuDiscoveryAbilities() )->list_menus( $args )
			),
			$this->module(
				'navigation.list_locations',
				'List Navigation Locations',
				'List registered classic menu locations and assignments for classic or hybrid themes. Future location reassignment must remain explicit-only and is not implemented here.',
				'Navigation Intelligence',
				'content:read',
				true,
				$this->navigation_locations_schema(),
				static fn ( array $args ): array => ( new NavigationMenuDiscoveryAbilities() )->list_locations( $args )
			),
			$this->module(
				'navigation.list_items',
				'List Navigation Items',
				'List readable classic menu items or bounded block-navigation items for one menu, location, or wp_navigation entity. This inventory is read-only and never rewrites serialized navigation markup.',
				'Navigation Intelligence',
				'content:read',
				true,
				$this->navigation_items_schema(),
				static fn ( array $args ): array => ( new NavigationMenuDiscoveryAbilities() )->list_items( $args )
			),
			$this->module(
				'content_index.refresh_batch',
				'Refresh Content Intelligence Index',
				'Refresh a bounded local Aculect Intelligence index batch so MCP clients can search content, sections, and link candidates quickly without reading full posts repeatedly.',
				'Content Intelligence Index',
				'content:draft',
				false,
				$this->index_refresh_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->refresh_batch( $args )
			),
			$this->module(
				'content_search.items',
				'Search Indexed Content',
				'Search the local Aculect Intelligence content index for posts, pages, and custom content items before choosing read or write tools.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->index_search_items_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->search_items( $args )
			),
			$this->module(
				'content_search.chunks',
				'Search Indexed Content Sections',
				'Search section-level long-form content chunks. Use context=full only when exact serialized block markup is needed for an update.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->index_search_chunks_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->search_chunks( $args )
			),
			$this->module(
				'content_find.related',
				'Find Related Content',
				'Find related indexed content for a source post or topic so assistants can plan updates with existing site context.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->related_content_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->find_related( $args )
			),
			$this->module(
				'content_internal_link.policy',
				'Get Internal Link Policy',
				'Read the active internal-linking exclusions, limits, and placement guardrails before auditing existing links, proposing candidates, or applying a reviewed update.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => ( new IntelligenceIndexAbilities() )->internal_link_policy_context()
			),
			$this->module(
				'content_find.internal_links',
				'Find Internal Link Opportunities',
				'Find internal link candidates and anchor suggestions from the local content index after reviewing policy and existing-link health, while avoiding links already present in the source item.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->internal_links_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->find_internal_links( $args )
			),
			$this->module(
				'content_audit.internal_links',
				'Audit Internal Link Health',
				'List orphan, underlinked, thin, stale, or link-heavy indexed content using the local link graph before planning new internal-link suggestions or requesting any write.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->internal_link_audit_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->audit_internal_links( $args )
			),
			$this->module(
				'content_internal_link.suggestions_create',
				'Create Internal Link Suggestions',
				'Create bounded reviewable internal-link suggestion records from approved audit or discovery results. This stores source post, target post, proposed anchor, reason, score, confidence, status, and last checked time without full post content.',
				'Content Intelligence Index',
				'content:draft',
				false,
				$this->internal_link_suggestions_create_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->create_internal_link_suggestions( $args )
			),
			$this->module(
				'content_internal_link.suggestions_list',
				'List Internal Link Suggestions',
				'List bounded internal-link suggestion records for review by source, target, or status. This does not mutate content.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->internal_link_suggestions_list_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->list_internal_link_suggestions( $args )
			),
			$this->module(
				'content_internal_link.suggestion_review',
				'Review Internal Link Suggestion',
				'Approve, reject, skip, or mark one internal-link suggestion stale before any apply planning. This does not edit post content.',
				'Content Intelligence Index',
				'content:draft',
				false,
				$this->internal_link_suggestion_review_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->review_internal_link_suggestion( $args )
			),
			$this->module(
				'content_internal_link.suggestion_apply',
				'Apply Internal Link Suggestion',
				'Dry-run or apply one approved internal-link suggestion through the targeted content.update_block path. Applies only one reviewed suggestion per call, never performs automatic site-wide mutation, and may require confirmation before writes.',
				'Content Intelligence Index',
				'content:draft',
				false,
				$this->internal_link_suggestion_apply_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->apply_internal_link_suggestion( $args )
			),
			$this->module(
				'memory.list',
				'List Aculect Memory',
				'List durable Aculect Intelligence memory items. These are local WordPress memories and do not depend on ChatGPT or Claude saved memory.',
				'Aculect Memory',
				'content:read',
				true,
				$this->memory_list_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->list_memories( $args )
			),
			$this->module(
				'memory.save',
				'Save Aculect Memory',
				'Queue or save durable local Aculect Intelligence memory. Empty calls bootstrap an initial memory set from site, brand, content, workflow, and approved learning signals.',
				'Aculect Memory',
				'content:draft',
				false,
				$this->memory_save_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->save_memory( $args )
			),
			$this->module(
				'memory.bootstrap',
				'Bootstrap Aculect Memory',
				'Prepare or store an initial durable Aculect Intelligence memory set from site, brand, content, workflow, and approved learning signals.',
				'Aculect Memory',
				'content:draft',
				false,
				$this->memory_bootstrap_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->bootstrap_memory( $args )
			),
			$this->module(
				'content_batch.status',
				'Get Content Batch Status',
				'Read the status and result for a content intelligence batch job.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->batch_status_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->batch_status( $args )
			),
			$this->module(
				'mcp_learning.inspect_activity',
				'Inspect MCP Learning Signals',
				'Review recent sanitized MCP activity failures and return bounded learning suggestions that can be submitted for admin review when assistants repeatedly hit disabled tools, missing scopes, block validation issues, or wrong workflow paths.',
				'Aculect Memory',
				'content:read',
				true,
				$this->mcp_learning_inspect_activity_schema(),
				static fn ( array $args ): array => ( new ActivityLearningInsights() )->inspect( $args )
			),
			$this->module(
				'site.list_post_types',
				'List Content Types',
				'List readable WordPress content types, including custom ones.',
				'Content',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => array( 'items' => ( new ContentAbilities() )->list_post_types() )
			),
			$this->module(
				'content.list_items',
				'List Posts and Pages',
				'List content for any enabled content type with pagination.',
				'Content',
				'content:read',
				true,
				$this->object_schema(
					array(
						'post_type' => array( 'type' => 'string' ),
						'status'    => $this->status_filter_schema( 'Post statuses to include. Defaults to publish, future, draft, pending, and private.' ),
						'page'      => $this->page_schema(),
						'per_page'  => $this->per_page_schema( 100, 'Items per page. Defaults to 20.' ),
						'context'   => $this->context_schema( 'Use compact for list browsing or full to include full content bodies. Defaults to compact.' ),
					)
				),
				static fn ( array $args ): array => ( new ContentAbilities() )->list_items( $args )
			),
			$this->module(
				'content.get_item',
				'Read a Post or Page',
				'Read one content item by ID from any enabled content type.',
				'Content',
				'content:read',
				true,
				$this->object_schema(
					array( 'id' => array( 'type' => 'integer' ) ),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new ContentAbilities() )->get_item( (int) ( $args['id'] ?? 0 ) )
			),
			$this->module(
				'content_revisions.list',
				'List Content Revisions',
				'List safe, bounded revision metadata for an editable WordPress post, page, or supported custom post type. This read-only discovery tool does not restore, delete, or return full post bodies by default.',
				'Content',
				'content:read',
				true,
				$this->revision_discovery_schema( true ),
				static fn ( array $args ): array => ( new RevisionsAutosavesAbilities() )->list_revisions( $args )
			),
			$this->module(
				'content_autosaves.inspect',
				'Inspect Content Autosave',
				'Inspect whether WordPress has a current-user autosave for an editable post, page, or supported custom post type. This read-only discovery tool returns bounded metadata only unless a capped preview is explicitly requested.',
				'Content',
				'content:read',
				true,
				$this->revision_discovery_schema( false ),
				static fn ( array $args ): array => ( new RevisionsAutosavesAbilities() )->inspect_autosaves( $args )
			),
			$this->module(
				'content.get_seo',
				'Read SEO Metadata',
				'Read saved SEO title, meta description, and focus keywords for supported active SEO plugins.',
				'Content',
				'content:read',
				true,
				$this->object_schema(
					array(
						'id'     => array( 'type' => 'integer' ),
						'plugin' => array(
							'type'        => 'string',
							'enum'        => array( 'auto', 'yoast', 'rank_math' ),
							'description' => 'Supported SEO metadata source. Defaults to auto-detect.',
						),
						'source' => array(
							'type'        => 'string',
							'enum'        => array( 'auto', 'yoast', 'rank_math' ),
							'description' => 'Alias for plugin when clients prefer source terminology.',
						),
					),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new SeoAbilities() )->get_seo( $args )
			),
			$this->module(
				'content.create_item',
				'Create a Post or Page',
				'Create a post, page, or custom content item.',
				'Content',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'post_type'      => array( 'type' => 'string' ),
						'title'          => array( 'type' => 'string' ),
						'content'        => array(
							'type'        => 'string',
							'maxLength'   => self::MAX_SERIALIZED_CONTENT_BYTES,
							'description' => 'Serialized WordPress block content. Use registered blocks and patterns, and never use the Custom HTML block (core/html).',
						),
						'excerpt'        => array( 'type' => 'string' ),
						'slug'           => array( 'type' => 'string' ),
						'status'         => $this->content_status_schema(),
						'date'           => $this->content_date_schema(),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => 'Existing image attachment ID to assign as the featured image.',
						),
						'author'         => array(
							'type'        => 'integer',
							'description' => 'Existing WordPress user ID to assign as author.',
						),
						'taxonomies'     => $this->taxonomy_assignment_schema( 'Map taxonomy slugs to existing term IDs or term slugs.' ),
					)
				),
				static fn ( array $args ): array => ( new ContentAbilities() )->create_item( $args )
			),
			$this->module(
				'content.update_item',
				'Update a Post or Page',
				'Update title, content, excerpt, slug, or status for an existing item.',
				'Content',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'id'                    => array( 'type' => 'integer' ),
						'title'                 => array( 'type' => 'string' ),
						'content'               => array(
							'type'        => 'string',
							'maxLength'   => self::MAX_SERIALIZED_CONTENT_BYTES,
							'description' => 'Serialized WordPress block content. Use registered blocks and patterns, and never use the Custom HTML block (core/html).',
						),
						'excerpt'               => array( 'type' => 'string' ),
						'slug'                  => array( 'type' => 'string' ),
						'status'                => $this->content_status_schema(),
						'date'                  => $this->content_date_schema(),
						'featured_media'        => array(
							'type'        => 'integer',
							'description' => 'Existing image attachment ID to assign as the featured image.',
						),
						'clear_featured_media'  => array(
							'type'        => 'boolean',
							'description' => 'Set true to intentionally remove the current featured image.',
						),
						'author'                => array(
							'type'        => 'integer',
							'description' => 'Existing WordPress user ID to assign as author.',
						),
						'taxonomies'            => $this->taxonomy_assignment_schema( 'Map taxonomy slugs to existing term IDs or term slugs. Use an empty array to clear a taxonomy.' ),
						'expected_modified_gmt' => ContentWriteSchemas::expected_modified_gmt(),
					),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new ContentAbilities() )->update_item( $args )
			),
			$this->module(
				'content.update_block',
				'Update One Content Block',
				'Update one supported block inside an existing post by deterministic block path from a prior content read. Supports core paragraph and heading text replacement plus one allowlisted same-site internal-link insertion. Attribute writes are intentionally deferred unless a later allowlist is added.',
				'Content',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'id'                    => array( 'type' => 'integer' ),
						'locator'               => array(
							'type'        => 'object',
							'description' => 'Server-side block locator from content_get_item block_locators. Path is a zero-based nested block index path.',
							'properties'  => array(
								'path' => array(
									'type'        => 'array',
									'description' => 'Zero-based nested block path, such as [0] or [1,0].',
									'items'       => array(
										'type'    => 'integer',
										'minimum' => 0,
									),
									'minItems'    => 1,
									'maxItems'    => 12,
								),
							),
							'required'    => array( 'path' ),
						),
						'text'                  => array(
							'type'        => 'string',
							'maxLength'   => 20000,
							'description' => 'Replacement plain text for core/paragraph or core/heading. The tool serializes safe block markup; do not pass HTML.',
						),
						'internal_link'         => array(
							'type'        => 'object',
							'description' => 'Allowlisted same-site internal-link insertion for one existing anchor text occurrence in the targeted core paragraph or heading block.',
							'properties'  => array(
								'anchor_text' => array(
									'type'        => 'string',
									'maxLength'   => 120,
									'description' => 'Existing visible text in the targeted block to link.',
								),
								'url'         => array(
									'type'        => 'string',
									'description' => 'Same-site target URL resolved from the target post permalink.',
								),
							),
							'required'    => array( 'anchor_text', 'url' ),
						),
						'attrs'                 => array(
							'type'                 => 'object',
							'description'          => 'Reserved for future narrow allowlisted registered block attributes. This beta slice rejects attribute writes to avoid unsafe third-party settings edits.',
							'additionalProperties' => true,
						),
						'dry_run'               => array(
							'type'        => 'boolean',
							'description' => 'When true, validate and return a field-level diff without saving.',
						),
						'expected_modified_gmt' => ContentWriteSchemas::expected_modified_gmt(),
					),
					array( 'id', 'locator' )
				),
				static fn ( array $args ): array => ( new ContentAbilities() )->update_block( $args )
			),
			$this->module(
				'content.update_seo',
				'Update SEO Metadata',
				'Update SEO title, description, and focus keywords for supported SEO plugins.',
				'Content',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'id'               => array( 'type' => 'integer' ),
						'plugin'           => array(
							'type'        => 'string',
							'enum'        => array( 'auto', 'yoast', 'rank_math' ),
							'description' => 'Supported SEO plugin adapter. Defaults to auto-detect.',
						),
						'meta_title'       => array( 'type' => 'string' ),
						'meta_description' => array( 'type' => 'string' ),
						'focus_keywords'   => $this->string_list_schema( 'Focus keywords to store for the SEO plugin.', 10 ),
					),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new SeoAbilities() )->update_seo( $args )
			),
			$this->module(
				'redirects.list',
				'List Redirect Rules',
				'List bounded Rank Math redirect rules when Rank Math Redirections support is available for the connected user.',
				'Redirect Workflows',
				'content:read',
				true,
				$this->redirects_list_schema(),
				static fn ( array $args ): array => ( new RankMathRedirectAbilities() )->list_redirects( $args )
			),
			$this->module(
				'redirects.validate',
				'Validate Redirect Proposal',
				'Validate a proposed Rank Math redirect source, destination, code, and conflict state before any redirect is created.',
				'Redirect Workflows',
				'content:read',
				true,
				$this->redirects_validate_schema(),
				static fn ( array $args ): array => ( new RankMathRedirectAbilities() )->validate_redirect( $args )
			),
			$this->module(
				'redirects.create',
				'Create Redirect Rule',
				'Create controlled Rank Math redirects through Rank Math Redirection objects after validation and conflict checks.',
				'Redirect Workflows',
				'content:draft',
				false,
				$this->redirects_create_schema(),
				static fn ( array $args ): array => ( new RankMathRedirectAbilities() )->create_redirect( $args )
			),
			$this->module(
				'not_found.list_recent',
				'List Recent 404 Records',
				'List bounded and query-redacted Rank Math 404 Monitor records when available for the connected user.',
				'Redirect Workflows',
				'content:read',
				true,
				$this->not_found_list_schema(),
				static fn ( array $args ): array => ( new RankMathRedirectAbilities() )->list_recent_404s( $args )
			),
			...TaxonomyAbilityModules::all( $this->module_factory ),
			$this->module(
				'media.list_items',
				'List Media',
				'List media library attachments with pagination.',
				'Media',
				'content:read',
				true,
				$this->object_schema(
					array(
						'page'        => $this->page_schema(),
						'per_page'    => $this->per_page_schema( 100, 'Media items per page. Defaults to 20.' ),
						'search'      => array( 'type' => 'string' ),
						'type'        => array(
							'type'        => 'string',
							'description' => 'Attachment family such as image, audio, video, or application.',
						),
						'mime_type'   => array( 'type' => 'string' ),
						'post_id'     => array(
							'type'        => 'integer',
							'description' => 'Filter by attachment parent post ID. Use 0 for unattached media.',
						),
						'parent_id'   => array( 'type' => 'integer' ),
						'author'      => array( 'type' => 'integer' ),
						'date_after'  => $this->date_boundary_schema( 'Inclusive lower date boundary accepted by WordPress date queries.' ),
						'date_before' => $this->date_boundary_schema( 'Inclusive upper date boundary accepted by WordPress date queries.' ),
						'context'     => $this->context_schema( 'Use compact for list browsing or full to include full attachment body fields. Defaults to compact.' ),
					)
				),
				static fn ( array $args ): array => ( new MediaAbilities() )->list_media( $args )
			),
			$this->module(
				'media.get_item',
				'Read Media Item',
				'Read one media library attachment by ID.',
				'Media',
				'content:read',
				true,
				$this->object_schema(
					array( 'id' => array( 'type' => 'integer' ) ),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new MediaAbilities() )->get_media( (int) ( $args['id'] ?? 0 ) )
			),
			$this->module(
				'media.audit_usage',
				'Audit Media Usage',
				'Return bounded read-only media usage intelligence for unused discovery, missing alt text, attached/unattached images, and likely content usage.',
				'Media',
				'content:read',
				true,
				$this->object_schema(
					array(
						'page'               => $this->page_schema(),
						'per_page'           => $this->per_page_schema( 100, 'Media audit items per page. Defaults to 20.' ),
						'type'               => array(
							'type'        => 'string',
							'description' => 'Attachment family such as image, audio, video, or application.',
						),
						'mime_type'          => array( 'type' => 'string' ),
						'parent_id'          => array(
							'type'        => 'integer',
							'description' => 'Filter by attachment parent post ID. Use 0 for unattached media.',
						),
						'status_filter'      => array(
							'type'        => 'string',
							'enum'        => array( 'all', 'unused', 'missing_alt', 'attached', 'unattached', 'used' ),
							'description' => 'Return all audited media or only a focused subset.',
						),
						'content_scan_limit' => array(
							'type'        => 'integer',
							'description' => 'Maximum readable content posts to scan for likely usage. Defaults to 100 and is capped at 250.',
						),
					)
				),
				static fn ( array $args ): array => ( new MediaAbilities() )->audit_usage( $args )
			),
			$this->module(
				'media.update_item',
				'Update Media Item',
				'Update media title, alt text, caption, description, slug, or attachment parent.',
				'Media',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'id'          => array( 'type' => 'integer' ),
						'title'       => array( 'type' => 'string' ),
						'alt_text'    => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'post_id'     => array(
							'type'        => 'integer',
							'description' => 'Post, page, or custom post ID to set as the attachment parent. Use 0 to detach.',
						),
					),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new MediaAbilities() )->update_media( $args )
			),
			$this->module(
				'media.delete_item',
				'Trash Media Item',
				'Move a media library attachment to the trash when permitted.',
				'Media',
				'content:draft',
				false,
				$this->object_schema(
					array( 'id' => array( 'type' => 'integer' ) ),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new MediaAbilities() )->delete_media( $args )
			),
			$this->module(
				'media.rename_file',
				'Rename Media File',
				'Safely rename the uploaded file on disk while preserving its extension and attachment metadata.',
				'Media',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'id'       => array( 'type' => 'integer' ),
						'filename' => array(
							'type'        => 'string',
							'description' => 'New filename for the physical uploaded file. The original extension must be preserved.',
						),
					),
					array( 'id', 'filename' )
				),
				static fn ( array $args ): array => ( new MediaAbilities() )->rename_media_file( $args )
			),
			$this->module(
				'site.get_settings',
				'View Site Settings',
				'Read safe, non-secret site settings.',
				'Site Information',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => ( new SiteAbilities() )->get_settings()
			),
			$this->module(
				'site.get_info',
				'View Site Information',
				'Read WordPress version, PHP version, active theme, and basic site metadata.',
				'Site Information',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => ( new SiteAbilities() )->get_site_info()
			),
			$this->module(
				'site.get_health',
				'View Site Health Summary',
				'Read a safe site health summary for users who can manage site options.',
				'Site Information',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => ( new SiteAbilities() )->get_site_health()
			),
			$this->module(
				'site.maintenance_report',
				'View Site Maintenance Report',
				'Read a compact, paginated, read-only maintenance report with severity, bounded evidence, and next steps. Reports never run arbitrary PHP, dump raw database data, scan files, write files, or expose option values.',
				'Site Information',
				'content:read',
				true,
				$this->site_maintenance_report_schema(),
				static fn ( array $args ): array => ( new SiteMaintenanceReports() )->report( $args )
			),
			$this->module(
				'site.list_plugins',
				'List Plugins',
				'List installed WordPress plugins and active state for users who can manage plugins.',
				'Site Information',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => ( new SiteAbilities() )->list_plugins()
			),
			$this->module(
				'plugin_lifecycle.list_plugins',
				'List Plugin Lifecycle Status',
				'List installed WordPress plugins with lifecycle-oriented status, active/network-active state, cached update availability, recovery pause state, multisite context, and capability blockers. This tool is read-only and never installs, updates, activates, deactivates, deletes, edits, or executes plugins.',
				'Plugin Lifecycle',
				'content:read',
				true,
				$this->plugin_lifecycle_list_schema(),
				static fn ( array $args ): array => ( new PluginLifecycleAbilities() )->list_plugins( $args )
			),
			$this->module(
				'plugin_lifecycle.get_plugin',
				'Get Plugin Lifecycle Status',
				'Read one installed WordPress plugin lifecycle status record with safe update and recovery metadata. This tool is read-only and never installs, updates, activates, deactivates, deletes, edits, or executes plugins.',
				'Plugin Lifecycle',
				'content:read',
				true,
				$this->plugin_lifecycle_get_schema(),
				static fn ( array $args ): array => ( new PluginLifecycleAbilities() )->get_plugin( $args )
			),
			$this->module(
				'plugin_lifecycle.activate_plugin',
				'Activate an Installed Plugin',
				'Activate one already-installed WordPress plugin on the current site with dry-run preview, confirmation-token gating, capability checks, and structured results. This first beta slice does not install plugins, update plugins, delete plugins, or perform network-wide activation.',
				'Plugin Lifecycle',
				'content:draft',
				false,
				$this->plugin_lifecycle_mutation_schema(),
				static fn ( array $args ): array => ( new PluginLifecycleAbilities() )->activate_plugin( $args )
			),
			$this->module(
				'plugin_lifecycle.deactivate_plugin',
				'Deactivate an Installed Plugin',
				'Deactivate one already-installed WordPress plugin on the current site with dry-run preview, confirmation-token gating, capability checks, and structured results. This first beta slice does not delete plugins or perform network-wide deactivation.',
				'Plugin Lifecycle',
				'content:draft',
				false,
				$this->plugin_lifecycle_mutation_schema(),
				static fn ( array $args ): array => ( new PluginLifecycleAbilities() )->deactivate_plugin( $args )
			),
			$this->module(
				'theme_lifecycle.list_themes',
				'List Theme Lifecycle Status',
				'List installed WordPress themes with active state, parent and child relationships, cached update availability, block or classic or hybrid signals, multisite context, and capability blockers. This tool is read-only and never installs, updates, switches, deletes, edits, or deactivates themes.',
				'Theme Lifecycle',
				'content:read',
				true,
				$this->theme_lifecycle_list_schema(),
				static fn ( array $args ): array => ( new ThemeLifecycleAbilities() )->list_themes( $args )
			),
			$this->module(
				'theme_lifecycle.get_theme',
				'Get Theme Lifecycle Status',
				'Read one installed WordPress theme lifecycle status record with safe update metadata and presentation signals. This tool is read-only and never installs, updates, switches, deletes, edits, or deactivates themes.',
				'Theme Lifecycle',
				'content:read',
				true,
				$this->theme_lifecycle_get_schema(),
				static fn ( array $args ): array => ( new ThemeLifecycleAbilities() )->get_theme( $args )
			),
			$this->module(
				'theme_lifecycle.switch_theme',
				'Switch to an Installed Theme',
				'Switch the current site to one already-installed WordPress theme with dry-run preview, confirmation-token gating, capability checks, and structured rollback metadata. This first beta slice does not install themes, update themes, delete themes, deactivate themes, or perform network-wide theme management.',
				'Theme Lifecycle',
				'content:draft',
				false,
				$this->theme_lifecycle_switch_schema(),
				static fn ( array $args ): array => ( new ThemeLifecycleAbilities() )->switch_theme( $args )
			),
			$this->module(
				'site.list_themes',
				'List Themes',
				'List installed WordPress themes and active state for users who can manage themes.',
				'Site Information',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => ( new SiteAbilities() )->list_themes()
			),
			$this->module(
				'comments.list_items',
				'List Comments for Review',
				'List WordPress comments with pagination and moderation-safe fields.',
				'Comments',
				'content:read',
				true,
				$this->object_schema(
					array(
						'status'         => array(
							'type'        => 'string',
							'description' => 'Comment status: all, pending, hold, approved, approve, spam, or trash.',
						),
						'post_id'        => array( 'type' => 'integer' ),
						'author'         => array(
							'type'        => 'string',
							'description' => 'Search by comment author name, email, URL, or IP.',
						),
						'author_user_id' => array(
							'type'        => 'integer',
							'description' => 'Filter by the WordPress user ID that authored the comment.',
						),
						'author_email'   => array(
							'type'        => 'string',
							'description' => 'Filter by exact comment author email address.',
						),
						'date_after'     => array(
							'type'        => 'string',
							'description' => 'Inclusive lower date boundary accepted by WordPress date queries.',
						),
						'date_before'    => array(
							'type'        => 'string',
							'description' => 'Inclusive upper date boundary accepted by WordPress date queries.',
						),
						'search'         => array( 'type' => 'string' ),
						'page'           => $this->page_schema(),
						'per_page'       => $this->per_page_schema( 100, 'Comments per page. Defaults to 50.' ),
						'context'        => $this->context_schema( 'Use compact for moderation queues or full to include comment bodies. Defaults to compact.' ),
					)
				),
				static fn ( array $args ): array => ( new CommentAbilities() )->list_comments( $args )
			),
			$this->module(
				'comments.get_item',
				'Read a Comment',
				'Read a single WordPress comment by ID.',
				'Comments',
				'content:read',
				true,
				$this->object_schema(
					array( 'id' => array( 'type' => 'integer' ) ),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new CommentAbilities() )->get_comment( $args )
			),
			$this->module(
				'comments.create_item',
				'Reply to a Comment',
				'Create a WordPress comment as the connected user.',
				'Comments',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'post_id'   => array( 'type' => 'integer' ),
						'content'   => array( 'type' => 'string' ),
						'parent_id' => array(
							'type'        => 'integer',
							'description' => 'Optional parent comment ID for structured replies.',
						),
						'status'    => array(
							'type'        => 'string',
							'description' => 'Optional status for moderators: hold or approve.',
						),
					),
					array( 'post_id', 'content' )
				),
				static fn ( array $args ): array => ( new CommentAbilities() )->create_comment( $args )
			),
			$this->module(
				'comments.update_item',
				'Moderate a Comment',
				'Update comment content or moderation status.',
				'Comments',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'id'      => array( 'type' => 'integer' ),
						'content' => array( 'type' => 'string' ),
						'status'  => array(
							'type'        => 'string',
							'description' => 'Comment status: pending, hold, approved, approve, spam, or trash.',
						),
					),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new CommentAbilities() )->update_comment( $args )
			),
			$this->module(
				'comments.bulk_update',
				'Bulk Moderate Comments',
				'Apply one moderation status to multiple WordPress comments.',
				'Comments',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'ids'    => array(
							'type'        => 'array',
							'description' => 'Comment IDs to moderate. Maximum 100 per call.',
							'items'       => array( 'type' => 'integer' ),
						),
						'status' => array(
							'type'        => 'string',
							'description' => 'Comment status: pending, hold, approved, approve, spam, or trash.',
						),
					),
					array( 'ids', 'status' )
				),
				static fn ( array $args ): array => ( new CommentAbilities() )->bulk_update_comments( $args )
			),
			$this->module(
				'media.upload_item',
				'Upload Media From a URL',
				'Upload media to the WordPress media library from a public URL with SSRF checks.',
				'Media',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'url'         => array(
							'type'        => 'string',
							'format'      => 'uri',
							'description' => 'Public HTTP or HTTPS media URL to upload.',
						),
						'title'       => array( 'type' => 'string' ),
						'alt_text'    => array( 'type' => 'string' ),
						'caption'     => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'post_id'     => array( 'type' => 'integer' ),
					),
					array( 'url' )
				),
				static fn ( array $args ): array => ( new MediaAbilities() )->upload_media( $args )
			),
			$this->module(
				'media.upload_image_data',
				'Upload Image Data',
				'Upload a base64 image payload or image data URL to the WordPress media library with MIME and size checks.',
				'Media',
				'content:draft',
				false,
				$this->media_image_data_upload_schema(),
				static fn ( array $args ): array => ( new MediaAbilities() )->upload_image_data( $args )
			),
			$this->module(
				'wp_abilities.discover',
				'Discover WordPress Actions',
				'Discover supported actions registered by WordPress and plugins.',
				'WordPress Actions',
				'content:read',
				true,
				$this->object_schema(
					array(
						'search'   => array( 'type' => 'string' ),
						'category' => array( 'type' => 'string' ),
						'page'     => $this->page_schema(),
						'per_page' => $this->per_page_schema( 100, 'Actions per page.' ),
					)
				),
				static fn ( array $args ): array => ( new WordPressAbilitiesBridge() )->discover( $args )
			),
			$this->module(
				'wp_abilities.get_info',
				'Inspect a WordPress Action',
				'Review details for a supported action registered by WordPress or a plugin.',
				'WordPress Actions',
				'content:read',
				true,
				$this->object_schema(
					array( 'id' => array( 'type' => 'string' ) ),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new WordPressAbilitiesBridge() )->get_info( $args )
			),
			$this->module(
				'wp_abilities.run',
				'Run a WordPress Action',
				'Run a supported public WordPress action using the connected user permissions.',
				'WordPress Actions',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'id'        => array( 'type' => 'string' ),
						'arguments' => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
					),
					array( 'id' )
				),
				fn ( array $args ): array => $this->run_wp_ability( $args )
			),
		);

		$keyed = array();
		foreach ( $modules as $module ) {
			if ( isset( $keyed[ $module->id() ] ) ) {
				throw new RuntimeException( 'Duplicate first-party ability ID.' );
			}
			$keyed[ $module->id() ] = $module;
		}

		return $keyed;
	}

	/**
	 * Build a first-party module.
	 *
	 * @param string               $id          Internal ability ID.
	 * @param string               $title       Admin-facing title.
	 * @param string               $description Assistant-facing description.
	 * @param string               $group       Admin grouping label.
	 * @param string               $scope       Required OAuth scope.
	 * @param bool                 $read_only   Whether the ability is read-only.
	 * @param array<string, mixed> $schema Input schema.
	 * @param Closure              $handler     Execution callback.
	 */
	private function module( string $id, string $title, string $description, string $group, string $scope, bool $read_only, array $schema, Closure $handler ): AbilityModuleInterface {
		return $this->module_factory->create( $id, $title, $description, $group, $scope, $read_only, $schema, $handler );
	}

	/**
	 * Build an object schema.
	 *
	 * @param array<string, mixed> $properties Schema properties.
	 * @param array                $required   Required property names.
	 * @phpstan-param list<string> $required
	 * @return array<string, mixed>
	 */
	private function object_schema( array $properties, array $required = array() ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);

		if ( array() !== $required ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Build an empty object schema.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => new \stdClass(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Build the site maintenance report schema.
	 *
	 * @return array<string, mixed>
	 */
	private function site_maintenance_report_schema(): array {
		return $this->object_schema(
			array(
				'report_type' => array(
					'type'        => 'string',
					'enum'        => SiteMaintenanceReports::report_types(),
					'description' => 'Report to return. Defaults to content_review.',
				),
				'page'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'One-based result page. Defaults to 1.',
				),
				'per_page'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 20,
					'description' => 'Maximum findings to return. Defaults to 10 and caps at 20.',
				),
			)
		);
	}

	/**
	 * Build the plugin lifecycle list schema.
	 *
	 * @return array<string, mixed>
	 */
	private function plugin_lifecycle_list_schema(): array {
		return $this->object_schema(
			array(
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'active', 'inactive', 'network_active', 'update_available', 'paused' ),
					'description' => 'Optional lifecycle status filter. Defaults to all.',
				),
				'page'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'One-based result page. Defaults to 1.',
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => 'Maximum plugins to return. Defaults to 50 and caps at 100.',
				),
			)
		);
	}

	/**
	 * Build the plugin lifecycle get schema.
	 *
	 * @return array<string, mixed>
	 */
	private function plugin_lifecycle_get_schema(): array {
		return $this->object_schema(
			array(
				'plugin' => array(
					'type'        => 'string',
					'description' => 'Installed plugin basename, for example example-plugin/example-plugin.php.',
				),
			),
			array( 'plugin' )
		);
	}

	/**
	 * Build the plugin lifecycle mutation schema.
	 *
	 * @return array<string, mixed>
	 */
	private function plugin_lifecycle_mutation_schema(): array {
		return $this->object_schema(
			array(
				'plugin' => array(
					'type'        => 'string',
					'description' => 'Installed plugin basename, for example example-plugin/example-plugin.php.',
				),
			),
			array( 'plugin' )
		);
	}

	/**
	 * Build the theme lifecycle list schema.
	 *
	 * @return array<string, mixed>
	 */
	private function theme_lifecycle_list_schema(): array {
		return $this->object_schema(
			array(
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'active', 'inactive', 'child', 'parent', 'update_available', 'block', 'classic', 'hybrid' ),
					'description' => 'Optional lifecycle status filter. Defaults to all.',
				),
				'page'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'One-based result page. Defaults to 1.',
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => 'Maximum themes to return. Defaults to 50 and caps at 100.',
				),
			)
		);
	}

	/**
	 * Build the theme lifecycle get schema.
	 *
	 * @return array<string, mixed>
	 */
	private function theme_lifecycle_get_schema(): array {
		return $this->object_schema(
			array(
				'stylesheet' => array(
					'type'        => 'string',
					'description' => 'Installed theme stylesheet slug, for example twentytwentysix.',
				),
			),
			array( 'stylesheet' )
		);
	}

	/**
	 * Build the theme lifecycle switch schema.
	 *
	 * @return array<string, mixed>
	 */
	private function theme_lifecycle_switch_schema(): array {
		return $this->object_schema(
			array(
				'stylesheet' => array(
					'type'        => 'string',
					'description' => 'Installed theme stylesheet slug to activate, for example twentytwentysix.',
				),
			),
			array( 'stylesheet' )
		);
	}

	/**
	 * Build the canonical MCP search schema expected by ChatGPT company knowledge.
	 *
	 * @return array<string, mixed>
	 */
	private function canonical_search_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query' => array(
					'type'        => 'string',
					'description' => 'Natural-language query string for WordPress content search.',
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Build the canonical MCP fetch schema expected by ChatGPT company knowledge.
	 *
	 * @return array<string, mixed>
	 */
	private function canonical_fetch_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Search result ID returned by search, such as wp-post:123, or a readable WordPress post ID.',
				),
			),
			'required'             => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Build a canonical list-of-strings schema.
	 *
	 * @param string $description Schema description.
	 * @param int    $max_items   Maximum item count.
	 * @return array<string, mixed>
	 */
	private function string_list_schema( string $description, int $max_items = 20 ): array {
		return array(
			'type'        => 'array',
			'description' => $description,
			'items'       => array( 'type' => 'string' ),
			'maxItems'    => $max_items,
		);
	}

	/**
	 * Build a bounded page-number schema.
	 *
	 * @return array<string, mixed>
	 */
	private function page_schema(): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'One-based page number. Defaults to 1.',
		);
	}

	/**
	 * Build a bounded per-page schema.
	 *
	 * @param int    $maximum     Maximum value accepted by the handler.
	 * @param string $description Schema description.
	 * @return array<string, mixed>
	 */
	private function per_page_schema( int $maximum, string $description ): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'maximum'     => $maximum,
			'description' => $description,
		);
	}

	/**
	 * Build a compact/full response context schema.
	 *
	 * @param string $description Schema description.
	 * @return array<string, mixed>
	 */
	private function context_schema( string $description ): array {
		return array(
			'type'        => 'string',
			'enum'        => array( 'compact', 'full' ),
			'description' => $description,
		);
	}

	/**
	 * Build revision/autosave discovery input schema.
	 *
	 * @param bool $include_pagination Whether this ability lists a collection.
	 * @return array<string, mixed>
	 */
	private function revision_discovery_schema( bool $include_pagination ): array {
		$properties = array(
			'post_id'         => array(
				'type'        => 'integer',
				'description' => 'Parent post, page, or supported custom post type ID.',
			),
			'id'              => array(
				'type'        => 'integer',
				'description' => 'Alias for post_id.',
			),
			'context'         => $this->context_schema( 'Use compact for metadata only or full to add bounded text summaries. Defaults to compact.' ),
			'include_preview' => array(
				'type'        => 'boolean',
				'description' => 'Explicitly request a bounded plain-text content preview. Defaults to false.',
			),
			'preview_chars'   => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 500,
				'description' => 'Maximum plain-text preview characters when include_preview is true. Defaults to 200.',
			),
		);

		if ( $include_pagination ) {
			$properties['page']     = $this->page_schema();
			$properties['per_page'] = $this->per_page_schema( 50, 'Revisions per page. Defaults to 20.' );
		}

		return $this->object_schema( $properties );
	}

	/**
	 * Build the capped safe user-list schema.
	 *
	 * @return array<string, mixed>
	 */
	private function safe_users_list_schema(): array {
		return $this->object_schema(
			array(
				'page'     => $this->page_schema(),
				'per_page' => $this->per_page_schema( 50, 'Users per page. Defaults to 20 and is capped at 50.' ),
				'role'     => array(
					'type'        => 'string',
					'description' => 'Optional role slug filter.',
				),
			)
		);
	}

	/**
	 * Build a date boundary schema for WordPress query arguments.
	 *
	 * @param string $description Schema description.
	 * @return array<string, mixed>
	 */
	private function date_boundary_schema( string $description ): array {
		return array(
			'type'        => 'string',
			'description' => $description,
		);
	}

	/**
	 * Build a canonical list-of-post-statuses schema.
	 *
	 * @param string $description Schema description.
	 * @return array<string, mixed>
	 */
	private function status_filter_schema( string $description ): array {
		return array(
			'type'        => 'array',
			'description' => $description,
			'items'       => array( 'type' => 'string' ),
			'maxItems'    => 10,
		);
	}

	/**
	 * Build the activity-learning inspection schema.
	 *
	 * @return array<string, mixed>
	 */
	private function mcp_learning_inspect_activity_schema(): array {
		return $this->object_schema(
			array(
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'error', 'success', 'all' ),
					'description' => 'Activity status to inspect. Defaults to error.',
				),
				'range'    => array(
					'type'        => 'string',
					'enum'        => array( '24h', '7d', '30d' ),
					'description' => 'Activity time range when supported by the activity store.',
				),
				'per_page' => $this->per_page_schema( 50, 'Activity rows to inspect. Defaults to 25.' ),
			)
		);
	}

	/**
	 * Build the content index refresh schema.
	 *
	 * @return array<string, mixed>
	 */
	private function index_refresh_schema(): array {
		return $this->object_schema(
			array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type to refresh. Defaults to post.',
				),
				'status'    => $this->status_filter_schema( 'Post statuses to refresh. Defaults to publish, future, draft, pending, and private.' ),
				'ids'       => array(
					'type'        => 'array',
					'description' => 'Optional explicit content IDs to refresh. Maximum 100 per batch.',
					'items'       => array( 'type' => 'integer' ),
					'maxItems'    => 100,
				),
				'limit'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => 'Maximum number of recent items to refresh when ids are not supplied. Maximum 100.',
				),
				'mode'      => array(
					'type'        => 'string',
					'enum'        => array( 'sync', 'queued' ),
					'description' => 'Defaults to queued. Use sync only for one explicitly requested item when an immediate result is required.',
				),
				'queued'    => array(
					'type'        => 'boolean',
					'description' => 'When true, create a queued WordPress cron job and return a job_key immediately. Legacy false requests synchronous mode and therefore requires exactly one explicit ID.',
				),
			)
		);
	}

	/**
	 * Build the indexed content search schema.
	 *
	 * @return array<string, mixed>
	 */
	private function index_search_items_schema(): array {
		return $this->object_schema(
			array(
				'query'          => array(
					'type'        => 'string',
					'description' => 'Search text for title, summary, terms, and indexed content.',
				),
				'post_type'      => array( 'type' => 'string' ),
				'status'         => array( 'type' => 'string' ),
				'stale'          => array(
					'type'        => 'boolean',
					'description' => 'Filter to stale or fresh index rows.',
				),
				'max_word_count' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 5000,
					'description' => 'Optional upper word-count threshold for thin-page discovery.',
				),
				'page'           => $this->page_schema(),
				'per_page'       => $this->per_page_schema( 50, 'Search results per page. Defaults to 10.' ),
				'context'        => $this->context_schema( 'Use compact for normal retrieval. Full is reserved for future expanded item fields.' ),
			)
		);
	}

	/**
	 * Build the indexed content chunk search schema.
	 *
	 * @return array<string, mixed>
	 */
	private function index_search_chunks_schema(): array {
		return $this->object_schema(
			array(
				'query'     => array(
					'type'        => 'string',
					'description' => 'Search text for headings, section text, or parent title.',
				),
				'post_id'   => array(
					'type'        => 'integer',
					'description' => 'Optional source content ID to fetch chunks for one item.',
				),
				'post_type' => array( 'type' => 'string' ),
				'status'    => array( 'type' => 'string' ),
				'page'      => $this->page_schema(),
				'per_page'  => $this->per_page_schema( 50, 'Content chunks per page. Defaults to 10.' ),
				'context'   => $this->context_schema( 'Use full only when exact serialized block markup is needed.' ),
			)
		);
	}

	/**
	 * Build the related content schema.
	 *
	 * @return array<string, mixed>
	 */
	private function related_content_schema(): array {
		return $this->object_schema(
			array(
				'post_id'   => array(
					'type'        => 'integer',
					'description' => 'Indexed source content ID.',
				),
				'query'     => array(
					'type'        => 'string',
					'description' => 'Topic or query to use when post_id is not available or needs refinement.',
				),
				'post_type' => array( 'type' => 'string' ),
				'status'    => array( 'type' => 'string' ),
				'limit'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 20,
					'description' => 'Maximum related items to return. Defaults to 10.',
				),
			)
		);
	}

	/**
	 * Build the internal link discovery schema.
	 *
	 * @return array<string, mixed>
	 */
	private function internal_links_schema(): array {
		return $this->object_schema(
			array(
				'source_id' => array(
					'type'        => 'integer',
					'description' => 'Indexed source content ID. Existing outbound indexed links from this source are avoided.',
				),
				'topic'     => array(
					'type'        => 'string',
					'description' => 'Topic, section heading, or target concept to find link candidates for.',
				),
				'query'     => array(
					'type'        => 'string',
					'description' => 'Alias for topic for clients that already use query fields.',
				),
				'post_type' => array( 'type' => 'string' ),
				'status'    => array( 'type' => 'string' ),
				'limit'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 20,
					'description' => 'Maximum link opportunities to return. Defaults to 10.',
				),
			)
		);
	}

	/**
	 * Build the internal-link audit schema.
	 *
	 * @return array<string, mixed>
	 */
	private function internal_link_audit_schema(): array {
		return $this->object_schema(
			array(
				'state'              => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'needs_review', 'orphan', 'underlinked', 'thin', 'stale', 'link_heavy', 'broken', 'missing_target', 'unreadable_target', 'unpublished_target', 'stale_permalink', 'redirected' ),
					'description' => 'Audit state to list. Defaults to needs_review. Use broken or a target-state value to audit stale and broken indexed internal-link targets.',
				),
				'post_type'          => array( 'type' => 'string' ),
				'status'             => array( 'type' => 'string' ),
				'min_inbound_links'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'description' => 'Minimum inbound internal links before a non-orphan item stops being flagged as underlinked. Defaults to 2.',
				),
				'thin_word_count'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 5000,
					'description' => 'Word-count threshold for thin content. Defaults to 300.',
				),
				'max_outbound_links' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 500,
					'description' => 'Outbound internal-link count above which an item is flagged link-heavy. Defaults to 25.',
				),
				'page'               => $this->page_schema(),
				'per_page'           => $this->per_page_schema( 50, 'Audit rows per page. Defaults to 10.' ),
				'queue_limit'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'description' => 'Maximum prioritized action queue items to return. Defaults to per_page or 10.',
				),
			)
		);
	}

	/**
	 * Build the internal-link suggestion create schema.
	 *
	 * @return array<string, mixed>
	 */
	private function internal_link_suggestions_create_schema(): array {
		$item_schema = $this->object_schema(
			array(
				'target_id'            => array(
					'type'        => 'integer',
					'description' => 'Target post ID to link to.',
				),
				'post_id'              => array(
					'type'        => 'integer',
					'description' => 'Alias for target_id when using content_find_internal_links rows.',
				),
				'anchor_text'          => array( 'type' => 'string' ),
				'proposed_anchor_text' => array(
					'type'        => 'string',
					'description' => 'Alias for anchor_text.',
				),
				'reason'               => array( 'type' => 'string' ),
				'score'                => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
				'quality_score'        => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
				'confidence'           => array(
					'type' => 'string',
					'enum' => array( 'low', 'medium', 'high' ),
				),
				'warnings'             => $this->string_list_schema( 'Bounded internal-link quality warnings.', 10 ),
				'quality_signals'      => array( 'type' => 'object' ),
				'signals'              => array( 'type' => 'object' ),
				'target_title'         => array( 'type' => 'string' ),
				'target_permalink'     => array( 'type' => 'string' ),
				'target_post_type'     => array( 'type' => 'string' ),
				'target_status'        => array( 'type' => 'string' ),
			),
			array( 'target_id', 'anchor_text', 'reason' )
		);

		return $this->object_schema(
			array(
				'source_id'            => array(
					'type'        => 'integer',
					'description' => 'Source post ID that may receive the internal link.',
				),
				'post_id'              => array(
					'type'        => 'integer',
					'description' => 'Alias for source_id.',
				),
				'target_id'            => array(
					'type'        => 'integer',
					'description' => 'Target post ID for single-record creation.',
				),
				'anchor_text'          => array(
					'type'        => 'string',
					'description' => 'Proposed anchor text for single-record creation.',
				),
				'proposed_anchor_text' => array(
					'type'        => 'string',
					'description' => 'Alias for anchor_text.',
				),
				'reason'               => array(
					'type'        => 'string',
					'description' => 'Reviewable reason for single-record creation.',
				),
				'score'                => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
				'confidence'           => array(
					'type' => 'string',
					'enum' => array( 'low', 'medium', 'high' ),
				),
				'items'                => array(
					'type'        => 'array',
					'description' => 'Suggestion items from content_find_internal_links or an approved audit workflow.',
					'maxItems'    => 20,
					'items'       => $item_schema,
				),
			),
			array( 'source_id' )
		);
	}

	/**
	 * Build the internal-link suggestion list schema.
	 *
	 * @return array<string, mixed>
	 */
	private function internal_link_suggestions_list_schema(): array {
		return $this->object_schema(
			array(
				'status'    => $this->internal_link_suggestion_status_schema(),
				'source_id' => array( 'type' => 'integer' ),
				'post_id'   => array(
					'type'        => 'integer',
					'description' => 'Alias for source_id.',
				),
				'target_id' => array( 'type' => 'integer' ),
				'page'      => $this->page_schema(),
				'per_page'  => $this->per_page_schema( 50, 'Suggestion records per page. Defaults to 20.' ),
			)
		);
	}

	/**
	 * Build the internal-link suggestion review schema.
	 *
	 * @return array<string, mixed>
	 */
	private function internal_link_suggestion_review_schema(): array {
		return $this->object_schema(
			array(
				'id'            => array( 'type' => 'string' ),
				'suggestion_id' => array(
					'type'        => 'string',
					'description' => 'Alias for id.',
				),
				'action'        => array(
					'type'        => 'string',
					'enum'        => array( 'approve', 'reject', 'skip', 'stale' ),
					'description' => 'Review action.',
				),
				'status'        => $this->internal_link_suggestion_status_schema(),
				'note'          => array(
					'type'        => 'string',
					'description' => 'Short review note. Do not include full post content.',
				),
				'review_note'   => array(
					'type'        => 'string',
					'description' => 'Alias for note.',
				),
			),
			array( 'id', 'action' )
		);
	}

	/**
	 * Build the internal-link suggestion apply-plan schema.
	 *
	 * @return array<string, mixed>
	 */
	private function internal_link_suggestion_apply_schema(): array {
		$schema = $this->object_schema(
			array(
				'id'            => array( 'type' => 'string' ),
				'suggestion_id' => array(
					'type'        => 'string',
					'description' => 'Alias for id.',
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'description' => 'When true, validate and return a targeted block diff without saving. Defaults to false for approved suggestions.',
				),
			),
			array( 'id' )
		);

		return ( new ToolSafetySchema() )->augment( $schema );
	}

	/**
	 * Build the suggestion status schema.
	 *
	 * @return array<string, mixed>
	 */
	private function internal_link_suggestion_status_schema(): array {
		return array(
			'type'        => 'string',
			'enum'        => array( 'suggested', 'approved', 'rejected', 'applied', 'skipped', 'stale' ),
			'description' => 'Internal-link suggestion review status.',
		);
	}

	/**
	 * Build the Aculect memory list schema.
	 *
	 * @return array<string, mixed>
	 */
	private function memory_list_schema(): array {
		return $this->object_schema(
			array(
				'domain'   => array(
					'type'        => 'string',
					'enum'        => array( 'brand', 'site', 'content', 'developer', 'seo', 'workflow' ),
					'description' => 'Memory domain to filter.',
				),
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'approved', 'pending', 'dismissed' ),
					'description' => 'Memory review status. Defaults to approved.',
				),
				'query'    => array( 'type' => 'string' ),
				'page'     => $this->page_schema(),
				'per_page' => $this->per_page_schema( 50, 'Memory rows per page. Defaults to 10.' ),
			)
		);
	}

	/**
	 * Build the Aculect memory save schema.
	 *
	 * @return array<string, mixed>
	 */
	private function memory_save_schema(): array {
		return $this->object_schema(
			array(
				'mode'       => array(
					'type'        => 'string',
					'enum'        => array( 'save', 'bootstrap' ),
					'description' => 'Use bootstrap, or omit key/value, to prepare initial Aculect memory from available intelligence signals.',
				),
				'key'        => array(
					'type'        => 'string',
					'description' => 'Stable key such as brand.voice.primary or content.internal_links.rule.',
				),
				'domain'     => array(
					'type'        => 'string',
					'enum'        => array( 'brand', 'site', 'content', 'developer', 'seo', 'workflow' ),
					'description' => 'Memory domain.',
				),
				'value'      => array(
					'type'        => 'string',
					'description' => 'Durable memory value to use in future workflows.',
				),
				'evidence'   => array(
					'type'        => 'string',
					'description' => 'Short non-sensitive evidence for why this memory should exist.',
				),
				'confidence' => array(
					'type' => 'string',
					'enum' => array( 'low', 'medium', 'high' ),
				),
				'status'     => array(
					'type'        => 'string',
					'enum'        => array( 'approved', 'pending', 'dismissed' ),
					'description' => 'Review status. Defaults to pending; approved memories affect future workflows.',
				),
				'force'      => array(
					'type'        => 'boolean',
					'description' => 'When bootstrapping, update existing memory keys instead of only creating missing ones.',
				),
			)
		);
	}

	/**
	 * Build the Aculect memory bootstrap schema.
	 *
	 * @return array<string, mixed>
	 */
	private function memory_bootstrap_schema(): array {
		return $this->object_schema(
			array(
				'status' => array(
					'type'        => 'string',
					'enum'        => array( 'approved', 'pending' ),
					'description' => 'Review status for created memory rows. Defaults to pending.',
				),
				'force'  => array(
					'type'        => 'boolean',
					'description' => 'Update existing bootstrap keys instead of only creating missing ones.',
				),
			)
		);
	}

	/**
	 * Build the redirects list schema.
	 *
	 * @return array<string, mixed>
	 */
	private function redirects_list_schema(): array {
		return $this->object_schema(
			array(
				'status'   => array(
					'type'        => 'string',
					'enum'        => array( 'any', 'active', 'inactive' ),
					'description' => 'Redirect status to list. Defaults to any active or inactive non-trashed redirects.',
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Optional source or destination search string.',
				),
				'orderby'  => array(
					'type'        => 'string',
					'enum'        => array( 'id', 'url_to', 'header_code', 'hits', 'created', 'last_accessed' ),
					'description' => 'Rank Math redirect field to order by. Defaults to id.',
				),
				'order'    => array(
					'type'        => 'string',
					'enum'        => array( 'ASC', 'DESC' ),
					'description' => 'Sort direction. Defaults to DESC.',
				),
				'page'     => $this->page_schema(),
				'per_page' => $this->per_page_schema( 50, 'Redirect rows per page. Defaults to 10.' ),
			)
		);
	}

	/**
	 * Build the redirect validation schema.
	 *
	 * @return array<string, mixed>
	 */
	private function redirects_validate_schema(): array {
		return $this->object_schema(
			$this->redirect_proposal_fields(),
			array( 'source' )
		);
	}

	/**
	 * Build the redirect creation schema.
	 *
	 * @return array<string, mixed>
	 */
	private function redirects_create_schema(): array {
		$fields          = $this->redirect_proposal_fields();
		$fields['items'] = array(
			'type'        => 'array',
			'description' => 'Optional batch of redirect proposals. Each item uses the same fields as a single redirect proposal. Maximum 25.',
			'maxItems'    => 25,
			'items'       => $this->object_schema( $this->redirect_proposal_fields(), array( 'source' ) ),
		);

		return $this->object_schema( $fields );
	}

	/**
	 * Return shared redirect proposal input fields.
	 *
	 * @return array<string, mixed>
	 */
	private function redirect_proposal_fields(): array {
		return array(
			'source'        => array(
				'type'        => 'string',
				'description' => 'Local source path or same-site source URL to validate.',
			),
			'destination'   => array(
				'type'        => 'string',
				'description' => 'Relative path or http/https destination URL. Not required for 410 or 451.',
			),
			'redirect_code' => array(
				'type'        => 'integer',
				'enum'        => array( 301, 302, 307, 410, 451 ),
				'description' => 'Supported redirect or maintenance response code. Defaults to 301.',
			),
			'match_type'    => array(
				'type'        => 'string',
				'enum'        => array( 'exact', 'start', 'contains', 'end' ),
				'description' => 'Supported source match type. Defaults to exact. Regex is intentionally not exposed.',
			),
			'ignore_case'   => array(
				'type'        => 'boolean',
				'description' => 'Whether source matching should ignore case.',
			),
		);
	}

	/**
	 * Build the recent 404 list schema.
	 *
	 * @return array<string, mixed>
	 */
	private function not_found_list_schema(): array {
		return $this->object_schema(
			array(
				'search'   => array(
					'type'        => 'string',
					'description' => 'Optional URI search string.',
				),
				'uri'      => array(
					'type'        => 'string',
					'description' => 'Optional exact URI filter.',
				),
				'orderby'  => array(
					'type'        => 'string',
					'enum'        => array( 'id', 'uri', 'accessed', 'times_accessed' ),
					'description' => 'Rank Math 404 monitor field to order by. Defaults to accessed.',
				),
				'order'    => array(
					'type'        => 'string',
					'enum'        => array( 'ASC', 'DESC' ),
					'description' => 'Sort direction. Defaults to DESC.',
				),
				'page'     => $this->page_schema(),
				'per_page' => $this->per_page_schema( 50, '404 rows per page. Defaults to 10.' ),
			)
		);
	}

	/**
	 * Build the content batch status schema.
	 *
	 * @return array<string, mixed>
	 */
	private function batch_status_schema(): array {
		return $this->object_schema(
			array(
				'job_key' => array(
					'type'        => 'string',
					'description' => 'Job key returned by content_index_refresh_batch.',
				),
			),
			array( 'job_key' )
		);
	}

	/**
	 * Build direct image data upload schema.
	 *
	 * @return array<string, mixed>
	 */
	private function media_image_data_upload_schema(): array {
		return $this->object_schema(
			array(
				'data_url'     => array(
					'type'        => 'string',
					'maxLength'   => 15000000,
					'description' => 'Base64 image data URL.',
				),
				'data_base64'  => array(
					'type'        => 'string',
					'maxLength'   => 15000000,
					'description' => 'Raw base64 image data. Requires mime_type.',
				),
				'image_base64' => array(
					'type'        => 'string',
					'maxLength'   => 15000000,
					'description' => 'Alias for data_base64.',
				),
				'mime_type'    => array(
					'type'        => 'string',
					'description' => 'Image MIME type required when raw base64 data is provided.',
				),
				'filename'     => array(
					'type'        => 'string',
					'description' => 'Preferred image filename.',
				),
				'title'        => array( 'type' => 'string' ),
				'alt_text'     => array( 'type' => 'string' ),
				'caption'      => array( 'type' => 'string' ),
				'description'  => array( 'type' => 'string' ),
				'post_id'      => array( 'type' => 'integer' ),
			)
		);
	}

	/**
	 * Build a compact/full context-only schema.
	 *
	 * @return array<string, mixed>
	 */
	private function context_only_schema(): array {
		return $this->object_schema(
			array(
				'context' => $this->context_schema( 'Use compact for first-pass discovery or full to include a larger bounded preview. Defaults to compact.' ),
			)
		);
	}

	/**
	 * Build Site Editor template collection schema.
	 *
	 * @return array<string, mixed>
	 */
	private function template_collection_schema(): array {
		return $this->object_schema(
			array(
				'context' => $this->context_schema( 'Use compact for inventory or full for more metadata. Defaults to compact.' ),
			)
		);
	}

	/**
	 * Build Site Editor template get schema.
	 *
	 * @return array<string, mixed>
	 */
	private function template_get_schema(): array {
		return $this->object_schema(
			array(
				'id'   => array(
					'type'        => 'string',
					'description' => 'Template ID such as theme//slug when known.',
				),
				'slug' => array(
					'type'        => 'string',
					'description' => 'Template slug when ID is not known.',
				),
			)
		);
	}

	/**
	 * Build reusable block/synced pattern collection schema.
	 *
	 * @return array<string, mixed>
	 */
	private function reusable_blocks_schema(): array {
		return $this->object_schema(
			array(
				'status'          => $this->status_filter_schema( 'Reusable block statuses to include. Defaults to publish, draft, pending, and private.' ),
				'page'            => $this->page_schema(),
				'per_page'        => $this->per_page_schema( 100, 'Items per page. Defaults to 20.' ),
				'include_preview' => array(
					'type'        => 'boolean',
					'description' => 'Include a bounded plain-text preview up to 600 bytes. Full reusable-block content bodies are never returned by this list tool.',
				),
			)
		);
	}

	/**
	 * Build Admin Menu page listing schema.
	 *
	 * @return array<string, mixed>
	 */
	private function admin_menu_list_schema(): array {
		return $this->object_schema(
			array(
				'search'  => array(
					'type'        => 'string',
					'description' => 'Optional search term for menu title, page title, slug, or parent slug.',
				),
				'section' => array(
					'type'        => 'string',
					'enum'        => array( 'dashboard', 'content', 'media', 'comments', 'appearance', 'plugins', 'users', 'tools', 'settings', 'plugin' ),
					'description' => 'Optional high-level admin section filter.',
				),
				'context' => $this->context_schema( 'Use compact for navigation or full for a larger bounded preview. Defaults to compact.' ),
			)
		);
	}

	/**
	 * Build Admin Menu navigation target schema.
	 *
	 * @return array<string, mixed>
	 */
	private function admin_navigation_schema(): array {
		return $this->object_schema(
			array(
				'query' => array(
					'type'        => 'string',
					'description' => 'Natural-language admin task or destination, such as edit header, permalink settings, plugin settings, or privacy settings.',
				),
				'task'  => array(
					'type'        => 'string',
					'description' => 'Alias for query.',
				),
			)
		);
	}

	/**
	 * Build registered settings listing schema.
	 *
	 * @return array<string, mixed>
	 */
	private function admin_settings_schema(): array {
		return $this->object_schema(
			array(
				'group'  => array(
					'type'        => 'string',
					'description' => 'Optional registered settings group.',
				),
				'search' => array(
					'type'        => 'string',
					'description' => 'Optional search term for registered setting name, group, or description.',
				),
			)
		);
	}

	/**
	 * Build navigation menu listing schema.
	 *
	 * @return array<string, mixed>
	 */
	private function navigation_menus_schema(): array {
		return $this->object_schema(
			array(
				'source_type' => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'classic_menu', 'wp_navigation' ),
					'description' => 'Optional navigation source filter. Defaults to all.',
				),
				'search'      => array(
					'type'        => 'string',
					'description' => 'Optional search term for menu name, title, slug, or source marker.',
				),
				'page'        => $this->page_schema(),
				'per_page'    => $this->per_page_schema( 100, 'Menus per page. Defaults to 20.' ),
				'context'     => $this->context_schema( 'Use compact for inventory or full for bounded structure summaries. Defaults to compact.' ),
			)
		);
	}

	/**
	 * Build navigation location listing schema.
	 *
	 * @return array<string, mixed>
	 */
	private function navigation_locations_schema(): array {
		return $this->object_schema(
			array(
				'search'        => array(
					'type'        => 'string',
					'description' => 'Optional search term for location slug, label, or assigned menu name.',
				),
				'assigned_only' => array(
					'type'        => 'boolean',
					'description' => 'Return only assigned classic locations.',
				),
				'page'          => $this->page_schema(),
				'per_page'      => $this->per_page_schema( 100, 'Locations per page. Defaults to 20.' ),
			)
		);
	}

	/**
	 * Build navigation item listing schema.
	 *
	 * @return array<string, mixed>
	 */
	private function navigation_items_schema(): array {
		return $this->object_schema(
			array(
				'source_type'   => array(
					'type'        => 'string',
					'enum'        => array( 'classic_menu', 'classic_location', 'wp_navigation' ),
					'description' => 'Optional explicit source type for the requested target.',
				),
				'menu_id'       => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Classic menu term ID.',
				),
				'navigation_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'wp_navigation post ID.',
				),
				'location'      => array(
					'type'        => 'string',
					'description' => 'Registered classic menu location slug.',
				),
				'page'          => $this->page_schema(),
				'per_page'      => $this->per_page_schema( 100, 'Navigation items per page. Defaults to 20.' ),
				'context'       => $this->context_schema( 'Use compact for item inventory or full for bounded block/link attrs and structure notes. Defaults to compact.' ),
			)
		);
	}

	/**
	 * Build the content publication date schema.
	 *
	 * @return array<string, string>
	 */
	private function content_date_schema(): array {
		return array(
			'type'        => 'string',
			'description' => 'Publication date as YYYY-MM-DDTHH:MM:SS in the site timezone, YYYY-MM-DD HH:MM:SS, or ISO 8601 with a timezone offset. Pass date, not date_gmt. Use status future with a future date to schedule.',
		);
	}

	/**
	 * Build the writable content status schema.
	 *
	 * @return array<string, mixed>
	 */
	private function content_status_schema(): array {
		return array(
			'type'        => 'string',
			'enum'        => array( 'draft', 'future', 'pending', 'private', 'publish', 'trash' ),
			'description' => 'Writable WordPress status. Use future only with a date that resolves to a future time in the WordPress site timezone. Invalid statuses are rejected instead of being converted to draft.',
		);
	}

	/**
	 * Build the content taxonomy assignment schema.
	 *
	 * @param string $description Schema description.
	 * @return array<string, mixed>
	 */
	private function taxonomy_assignment_schema( string $description ): array {
		return array(
			'type'                 => 'object',
			'description'          => $description . ' Each taxonomy value should be an array of existing term IDs.',
			'additionalProperties' => array(
				'type'        => 'array',
				'description' => 'Existing term IDs for one taxonomy.',
				'items'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'maxItems'    => 100,
			),
		);
	}

	/**
	 * Run a WordPress Ability API callback with dry-run preview support.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	private function run_wp_ability( array $data ): array {
		$bridge = new WordPressAbilitiesBridge();
		if ( ( new ToolSafety() )->is_dry_run( $data ) ) {
			$info = $bridge->get_info( $data );
			if ( isset( $info['error'] ) ) {
				return $info;
			}

			return array(
				'dry_run'               => true,
				'status'                => 'preview',
				'action'                => 'wp_abilities.run',
				'risk_level'            => 'system',
				'target'                => array(
					'type' => 'wp_ability',
					'id'   => sanitize_text_field( (string) ( $data['id'] ?? $data['name'] ?? '' ) ),
				),
				'changes'               => array(),
				'warnings'              => array( 'This WordPress ability is provided by WordPress or another plugin. Aculect can validate the ability metadata but cannot preview the callback result before execution.' ),
				'confirmation_required' => true,
			);
		}

		return $bridge->run( $data );
	}
}
