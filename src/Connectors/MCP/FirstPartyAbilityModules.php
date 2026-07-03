<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Closure;

/**
 * Builds first-party MCP ability modules.
 */
final class FirstPartyAbilityModules {

	private const MIN_LONG_FORM_WORDS          = 3000;
	private const MAX_LONG_FORM_WORDS          = 5000;
	private const MAX_SERIALIZED_CONTENT_BYTES = 300000;
	private const CONTENT_MODES                = array( 'article', 'page', 'landing_page', 'visual_layout', 'service_page', 'product_page', 'case_study' );
	private const BLOCK_FAMILIES               = array( 'text', 'media', 'layout', 'navigation', 'data', 'embed', 'design', 'widget' );

	/**
	 * Return first-party modules keyed by internal ability ID.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	public function all(): array {
		$modules = array(
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
			$this->module(
				'workflow.route_request',
				'Route MCP Workflow Request',
				'Use this first for ambiguous or multi-step WordPress MCP work. It classifies the request, selects the best workflow guide, lists blocked operations, and returns the next tool with arguments.',
				'Workflow Router',
				'content:read',
				true,
				$this->workflow_route_schema(),
				static fn ( array $args ): array => ( new WorkflowRouter() )->route( $args )
			),
			$this->module(
				'workflow_session.start',
				'Start MCP Workflow Session',
				'Start a bounded Aculect workflow session so ChatGPT, Claude, Codex, or another MCP client can resume multi-tool content, SEO, or site-management work without relying on chat memory.',
				'Workflow Sessions',
				'content:draft',
				false,
				$this->workflow_session_start_schema(),
				static fn ( array $args ): array => ( new WorkflowSessionStore() )->start( $args )
			),
			$this->module(
				'workflow_session.get',
				'Get MCP Workflow Session',
				'Read bounded Aculect workflow progress for a previous multi-tool MCP workflow.',
				'Workflow Sessions',
				'content:read',
				true,
				$this->workflow_session_get_schema(),
				static fn ( array $args ): array => ( new WorkflowSessionStore() )->get( $args )
			),
			$this->module(
				'workflow_session.update',
				'Update MCP Workflow Session',
				'Advance bounded Aculect workflow progress after a planning, validation, draft, update, SEO, or review step.',
				'Workflow Sessions',
				'content:draft',
				false,
				$this->workflow_session_update_schema(),
				static fn ( array $args ): array => ( new WorkflowSessionStore() )->update( $args )
			),
			$this->module(
				'workflow_loop.create',
				'Create MCP Workflow Loop',
				'Create a bounded item-aware workflow loop from thin-page candidates or explicit content items so assistants can store guidance once and resume item-by-item.',
				'Workflow Loops',
				'content:draft',
				false,
				$this->workflow_loop_create_schema(),
				static fn ( array $args ): array => ( new WorkflowLoopStore() )->create( $args )
			),
			$this->module(
				'workflow_loop.get',
				'Get MCP Workflow Loop',
				'Read compact workflow loop progress, item statuses, recent events, current item, and next actions.',
				'Workflow Loops',
				'content:read',
				true,
				$this->workflow_loop_get_schema(),
				static fn ( array $args ): array => ( new WorkflowLoopStore() )->get( $args )
			),
			$this->module(
				'workflow_loop.run_next',
				'Run Next Workflow Loop Item',
				'Mark optional prior item completion and return the next bounded item with the workflow tool arguments the assistant should use.',
				'Workflow Loops',
				'content:draft',
				false,
				$this->workflow_loop_run_next_schema(),
				static fn ( array $args ): array => ( new WorkflowLoopStore() )->run_next( $args )
			),
			$this->module(
				'workflow_loop.run_batch',
				'Run Workflow Loop Batch',
				'Mark optional completed item results and return a bounded batch of pending loop items without repeating already completed items.',
				'Workflow Loops',
				'content:draft',
				false,
				$this->workflow_loop_run_batch_schema(),
				static fn ( array $args ): array => ( new WorkflowLoopStore() )->run_batch( $args )
			),
			$this->module(
				'workflow_loop.pause',
				'Pause MCP Workflow Loop',
				'Pause a workflow loop without discarding stored item progress.',
				'Workflow Loops',
				'content:draft',
				false,
				$this->workflow_loop_pause_schema(),
				static fn ( array $args ): array => ( new WorkflowLoopStore() )->pause( $args )
			),
			$this->module(
				'workflow_loop.cancel',
				'Cancel MCP Workflow Loop',
				'Cancel a workflow loop and prevent future run calls from starting pending items.',
				'Workflow Loops',
				'content:draft',
				false,
				$this->workflow_loop_pause_schema(),
				static fn ( array $args ): array => ( new WorkflowLoopStore() )->cancel( $args )
			),
			$this->module(
				'workflow_guides.list',
				'List MCP Workflow Guides',
				'List compact, policy-aware workflow guides so assistants can choose the right multi-tool path without loading large instructions upfront.',
				'Workflow Guides',
				'content:read',
				true,
				$this->workflow_guides_list_schema(),
				static fn ( array $args ): array => ( new WorkflowGuideRegistry() )->list_guides( $args )
			),
			$this->module(
				'workflow_guides.get',
				'Get MCP Workflow Guide',
				'Read one compact workflow guide with required operations, optional operations, missing blockers, and safe step order.',
				'Workflow Guides',
				'content:read',
				true,
				$this->workflow_guides_get_schema(),
				static fn ( array $args ): array => ( new WorkflowGuideRegistry() )->get_guide( $args )
			),
			$this->module(
				'content_workflow.prepare_post',
				'Prepare Long-Form Content Workflow',
				'Use this when a user asks to create, rewrite, or plan WordPress long-form content. It returns a block-safe outline, section plan, SEO recommendations, and available workflow operations before any write.',
				'Content Workflows',
				'content:read',
				true,
				$this->workflow_prepare_post_schema(),
				static fn ( array $args ): array => ( new ContentWorkflowAbilities() )->prepare_post( $args )
			),
			$this->module(
				'content_workflow.create_draft',
				'Create Draft From Block Workflow',
				'Use this when a user wants to create a WordPress draft from validated serialized block content, including long-form posts of 3000 to 5000 words. Do not use raw HTML or core/html.',
				'Content Workflows',
				'content:draft',
				false,
				$this->workflow_create_draft_schema(),
				static fn ( array $args ): array => ( new ContentWorkflowAbilities() )->create_draft( $args )
			),
			$this->module(
				'content_workflow.update_post',
				'Update Post From Block Workflow',
				'Use this when a user wants to update an existing WordPress post from validated serialized block content or a section map. Prefer this for long-form content updates instead of low-level content_update_item.',
				'Content Workflows',
				'content:draft',
				false,
				$this->workflow_update_post_schema(),
				static fn ( array $args ): array => ( new ContentWorkflowAbilities() )->update_post( $args )
			),
			$this->module(
				'content_media.search_cc0_images',
				'Search CC0 Image Candidates',
				'Search Openverse for CC0 image candidates that can be reviewed before import into the WordPress media library.',
				'Content Media Workflows',
				'content:read',
				true,
				$this->content_media_search_schema(),
				static fn ( array $args ): array => ( new ContentMediaWorkflowAbilities() )->search_cc0_images( $args )
			),
			$this->module(
				'content_media.apply_image',
				'Apply Image to Content',
				'Resolve an image from an existing attachment, URL, generated image URL, base64/data URL, or CC0 search result, then set it as featured media or insert a safe core media block into an existing post.',
				'Content Media Workflows',
				'content:draft',
				false,
				$this->content_media_apply_image_schema(),
				static fn ( array $args ): array => ( new ContentMediaWorkflowAbilities() )->apply_image( $args )
			),
			$this->module(
				'seo_workflow.update_rankmath',
				'Update Rank Math SEO Workflow',
				'Use this when a user specifically wants to update Rank Math SEO title, meta description, or focus keywords for a WordPress content item.',
				'SEO Workflows',
				'content:draft',
				false,
				$this->workflow_rankmath_schema(),
				static fn ( array $args ): array => ( new ContentWorkflowAbilities() )->update_rankmath_seo( $args )
			),
			$this->module(
				'site_workflow.audit',
				'Audit Site Management Readiness',
				'Use this read-only workflow when a user asks to audit site health, maintenance posture, connector readiness, update signals, permalinks, HTTPS, REST API, cron, or active theme state before planning site management work.',
				'Site Workflows',
				'content:read',
				true,
				$this->empty_schema(),
				static fn ( array $args ): array => ( new SiteWorkflowAbilities() )->audit( $args )
			),
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
				'content_index.refresh_batch',
				'Refresh Content Intelligence Index',
				'Refresh a bounded local Aculect Intelligence index batch so MCP clients can search content, sections, and link candidates quickly without reading full posts repeatedly.',
				'Content Intelligence Index',
				'content:read',
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
				'Read the active internal-linking exclusions, limits, and placement guardrails before proposing or applying links.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => ( new IntelligenceIndexAbilities() )->internal_link_policy_context()
			),
			$this->module(
				'content_find.internal_links',
				'Find Internal Link Opportunities',
				'Find internal link candidates and anchor suggestions from the local content index while avoiding links already present in the source item.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->internal_links_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->find_internal_links( $args )
			),
			$this->module(
				'content_audit.internal_links',
				'Audit Internal Link Health',
				'List orphan, underlinked, thin, stale, or link-heavy indexed content using the local link graph without reading full post bodies or applying changes.',
				'Content Intelligence Index',
				'content:read',
				true,
				$this->internal_link_audit_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->audit_internal_links( $args )
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
						'id'                   => array( 'type' => 'integer' ),
						'title'                => array( 'type' => 'string' ),
						'content'              => array(
							'type'        => 'string',
							'maxLength'   => self::MAX_SERIALIZED_CONTENT_BYTES,
							'description' => 'Serialized WordPress block content. Use registered blocks and patterns, and never use the Custom HTML block (core/html).',
						),
						'excerpt'              => array( 'type' => 'string' ),
						'slug'                 => array( 'type' => 'string' ),
						'status'               => $this->content_status_schema(),
						'date'                 => $this->content_date_schema(),
						'featured_media'       => array(
							'type'        => 'integer',
							'description' => 'Existing image attachment ID to assign as the featured image.',
						),
						'clear_featured_media' => array(
							'type'        => 'boolean',
							'description' => 'Set true to intentionally remove the current featured image.',
						),
						'author'               => array(
							'type'        => 'integer',
							'description' => 'Existing WordPress user ID to assign as author.',
						),
						'taxonomies'           => $this->taxonomy_assignment_schema( 'Map taxonomy slugs to existing term IDs or term slugs. Use an empty array to clear a taxonomy.' ),
					),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new ContentAbilities() )->update_item( $args )
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
			$this->module(
				'taxonomy.list_taxonomies',
				'List Content Groups',
				'List available categories, tags, and custom content groups.',
				'Content Groups',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => array( 'items' => ( new TaxonomyAbilities() )->list_taxonomies() )
			),
			$this->module(
				'taxonomy.list_terms',
				'List Categories and Tags',
				'List categories, tags, or custom content groups with pagination.',
				'Content Groups',
				'content:read',
				true,
				$this->object_schema(
					array(
						'taxonomy'   => array( 'type' => 'string' ),
						'page'       => $this->page_schema(),
						'per_page'   => $this->per_page_schema( 100, 'Terms per page. Defaults to 50.' ),
						'search'     => array( 'type' => 'string' ),
						'hide_empty' => array( 'type' => 'boolean' ),
					),
					array( 'taxonomy' )
				),
				static fn ( array $args ): array => ( new TaxonomyAbilities() )->list_terms( $args )
			),
			$this->module(
				'taxonomy.create_term',
				'Create a Category or Tag',
				'Create a category, tag, or custom content group.',
				'Content Groups',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'taxonomy'    => array( 'type' => 'string' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
					),
					array( 'taxonomy', 'name' )
				),
				static fn ( array $args ): array => ( new TaxonomyAbilities() )->create_term( $args )
			),
			$this->module(
				'taxonomy.update_term',
				'Update a Category or Tag',
				'Update a category, tag, or custom content group.',
				'Content Groups',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'taxonomy'    => array( 'type' => 'string' ),
						'term_id'     => array( 'type' => 'integer' ),
						'name'        => array( 'type' => 'string' ),
						'slug'        => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'parent'      => array( 'type' => 'integer' ),
					),
					array( 'taxonomy', 'term_id' )
				),
				static fn ( array $args ): array => ( new TaxonomyAbilities() )->update_term( $args )
			),
			$this->module(
				'taxonomy.set_term_image',
				'Set Category or Tag Image',
				'Assign or clear an image attachment for an allowlisted taxonomy term image meta key.',
				'Content Groups',
				'content:draft',
				false,
				$this->object_schema(
					array(
						'taxonomy'    => array( 'type' => 'string' ),
						'term_id'     => array( 'type' => 'integer' ),
						'image_id'    => array(
							'type'        => 'integer',
							'description' => 'Existing image attachment ID to assign as the term image.',
						),
						'clear_image' => array(
							'type'        => 'boolean',
							'description' => 'Set true to intentionally clear the term image.',
						),
						'meta_key'    => array(
							'type'        => 'string',
							'description' => 'Allowlisted term meta key. Defaults to aculect_ai_companion_term_image_id.',
						),
					),
					array( 'taxonomy', 'term_id' )
				),
				static fn ( array $args ): array => ( new TaxonomyAbilities() )->set_term_image( $args )
			),
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
		return new CallbackAbilityModule(
			$id,
			$title,
			$description,
			$group,
			array( $scope ),
			$read_only,
			$read_only ? $schema : $this->schema_with_safety_controls( $schema ),
			$handler
		);
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
	 * Build the workflow guide list schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_guides_list_schema(): array {
		return $this->object_schema(
			array(
				'category'       => array(
					'type'        => 'string',
					'enum'        => array( 'content', 'seo', 'site' ),
					'description' => 'Optional guide category filter.',
				),
				'available_only' => array(
					'type'        => 'boolean',
					'description' => 'When true, return only guides whose required operations are currently available.',
				),
				'detail'         => array(
					'type'        => 'string',
					'enum'        => array( 'summary', 'full' ),
					'description' => 'Use summary for discovery and full for guide steps.',
				),
			)
		);
	}

	/**
	 * Build the workflow router schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_route_schema(): array {
		return $this->object_schema(
			array(
				'request'                  => array(
					'type'        => 'string',
					'description' => 'User request to classify and route.',
				),
				'brief'                    => array(
					'type'        => 'string',
					'description' => 'Alias for request when the client already has a content brief.',
				),
				'user_goal'                => array(
					'type'        => 'string',
					'description' => 'Alias for request when the client has a goal statement.',
				),
				'prompt'                   => array(
					'type'        => 'string',
					'description' => 'Alias for request for clients that pass prompt-shaped arguments.',
				),
				'intent'                   => array(
					'type'        => 'string',
					'enum'        => array( 'capability_discovery', 'site_audit', 'site_editor', 'admin_menu', 'seo_update', 'internal_links', 'content_update', 'content_create' ),
					'description' => 'Optional explicit intent override.',
				),
				'post_type'                => array(
					'type'        => 'string',
					'description' => 'Target WordPress post type when known.',
				),
				'content_mode'             => $this->content_mode_schema(),
				'layout_intent'            => array(
					'type'        => 'string',
					'description' => 'Layout direction such as hero, columns, cards, grid, comparison, or CTA sections.',
				),
				'visual_reference_summary' => array(
					'type'        => 'string',
					'description' => 'Concise summary of any image/screenshot/design reference the assistant inspected.',
				),
				'existing_post_id'         => array(
					'type'        => 'integer',
					'description' => 'Existing post ID when the request is an update.',
				),
				'post_id'                  => array(
					'type'        => 'integer',
					'description' => 'Alias for existing_post_id.',
				),
			)
		);
	}

	/**
	 * Build the workflow session start schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_session_start_schema(): array {
		return $this->object_schema(
			array(
				'workflow'     => array(
					'type'        => 'string',
					'description' => 'Workflow or workflow guide ID.',
				),
				'workflow_id'  => array(
					'type'        => 'string',
					'description' => 'Alias for workflow.',
				),
				'state'        => $this->workflow_session_state_schema(),
				'brief'        => array( 'type' => 'string' ),
				'request'      => array( 'type' => 'string' ),
				'provider'     => array( 'type' => 'string' ),
				'intent'       => array( 'type' => 'string' ),
				'content_mode' => $this->content_mode_schema(),
				'post_type'    => array( 'type' => 'string' ),
				'target_type'  => array( 'type' => 'string' ),
				'target_id'    => array( 'type' => 'integer' ),
				'post_id'      => array( 'type' => 'integer' ),
				'title'        => array( 'type' => 'string' ),
				'operation'    => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Build the workflow session get schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_session_get_schema(): array {
		return $this->object_schema(
			array(
				'workflow_session_id' => array(
					'type'        => 'string',
					'description' => 'Workflow session ID returned by workflow_session_start or a workflow response.',
				),
				'id'                  => array(
					'type'        => 'string',
					'description' => 'Alias for workflow_session_id.',
				),
			)
		);
	}

	/**
	 * Build the workflow session update schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_session_update_schema(): array {
		return $this->object_schema(
			array(
				'workflow_session_id' => array(
					'type'        => 'string',
					'description' => 'Workflow session ID returned by workflow_session_start or a workflow response.',
				),
				'id'                  => array(
					'type'        => 'string',
					'description' => 'Alias for workflow_session_id.',
				),
				'state'               => $this->workflow_session_state_schema(),
				'message'             => array(
					'type'        => 'string',
					'description' => 'Short progress note. Do not include secrets or long content bodies.',
				),
				'tool'                => array(
					'type'        => 'string',
					'description' => 'Tool that just completed.',
				),
				'post_id'             => array( 'type' => 'integer' ),
			)
		);
	}

	/**
	 * Build the workflow loop create schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_loop_create_schema(): array {
		return $this->object_schema(
			array(
				'source'              => array(
					'type'        => 'string',
					'enum'        => array( 'thin_pages', 'provided_items' ),
					'description' => 'Collection source. Defaults to thin_pages.',
				),
				'workflow'            => array(
					'type'        => 'string',
					'description' => 'Workflow or workflow guide ID. Defaults to thin_page_cleanup.',
				),
				'workflow_id'         => array(
					'type'        => 'string',
					'description' => 'Alias for workflow.',
				),
				'workflow_session_id' => $this->workflow_session_id_schema(),
				'objective'           => array(
					'type'        => 'string',
					'description' => 'Short loop objective.',
				),
				'brief'               => array(
					'type'        => 'string',
					'description' => 'Alias for objective.',
				),
				'guidance'            => array(
					'type'        => 'string',
					'maxLength'   => 1200,
					'description' => 'User guidance to apply to every loop item.',
				),
				'query'               => array(
					'type'        => 'string',
					'description' => 'Optional search term for thin-page discovery.',
				),
				'post_type'           => array(
					'type'        => 'string',
					'description' => 'Post type for thin-page discovery. Defaults to page.',
				),
				'status'              => array(
					'type'        => 'string',
					'description' => 'Post status for thin-page discovery. Defaults to publish.',
				),
				'max_word_count'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 5000,
					'description' => 'Maximum indexed word count for thin-page candidates. Defaults to 300.',
				),
				'limit'               => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'description' => 'Maximum items to store in the loop.',
				),
				'batch_size'          => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 10,
					'description' => 'Default batch size for workflow_loop_run_batch.',
				),
				'items'               => array(
					'type'        => 'array',
					'description' => 'Explicit content items when source=provided_items.',
					'maxItems'    => 50,
					'items'       => $this->workflow_loop_item_schema(),
				),
			)
		);
	}

	/**
	 * Build the workflow loop get schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_loop_get_schema(): array {
		return $this->object_schema(
			array(
				'workflow_loop_id' => $this->workflow_loop_id_schema(),
				'loop_id'          => $this->workflow_loop_id_schema(),
				'id'               => $this->workflow_loop_id_schema(),
			)
		);
	}

	/**
	 * Build the workflow loop run-next schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_loop_run_next_schema(): array {
		return $this->object_schema(
			array(
				'workflow_loop_id'  => $this->workflow_loop_id_schema(),
				'loop_id'           => $this->workflow_loop_id_schema(),
				'id'                => $this->workflow_loop_id_schema(),
				'completed_item_id' => array(
					'type'        => 'integer',
					'description' => 'Optional item ID that the assistant just completed.',
				),
				'completed_status'  => $this->workflow_loop_completion_status_schema(),
				'completed_message' => array(
					'type'        => 'string',
					'description' => 'Short completion note. Do not include long content bodies.',
				),
				'resume'            => array(
					'type'        => 'boolean',
					'description' => 'Set true to resume a paused loop before selecting the next item.',
				),
			)
		);
	}

	/**
	 * Build the workflow loop batch schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_loop_run_batch_schema(): array {
		return $this->object_schema(
			array(
				'workflow_loop_id' => $this->workflow_loop_id_schema(),
				'loop_id'          => $this->workflow_loop_id_schema(),
				'id'               => $this->workflow_loop_id_schema(),
				'limit'            => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 10,
					'description' => 'Maximum pending items to start in this call.',
				),
				'completed_items'  => array(
					'type'        => 'array',
					'description' => 'Optional completed item results to store before starting more items.',
					'maxItems'    => 10,
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'      => array( 'type' => 'integer' ),
							'item_id' => array( 'type' => 'integer' ),
							'status'  => $this->workflow_loop_completion_status_schema(),
							'message' => array( 'type' => 'string' ),
						),
						'additionalProperties' => false,
					),
				),
				'resume'           => array(
					'type'        => 'boolean',
					'description' => 'Set true to resume a paused loop before selecting the next batch.',
				),
			)
		);
	}

	/**
	 * Build the workflow loop pause/cancel schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_loop_pause_schema(): array {
		return $this->object_schema(
			array(
				'workflow_loop_id' => $this->workflow_loop_id_schema(),
				'loop_id'          => $this->workflow_loop_id_schema(),
				'id'               => $this->workflow_loop_id_schema(),
				'message'          => array(
					'type'        => 'string',
					'description' => 'Short reason for the pause or cancellation.',
				),
			)
		);
	}

	/**
	 * Build an explicit loop item input schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_loop_item_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'          => array( 'type' => 'integer' ),
				'post_id'     => array( 'type' => 'integer' ),
				'type'        => array( 'type' => 'string' ),
				'post_type'   => array( 'type' => 'string' ),
				'status'      => array( 'type' => 'string' ),
				'post_status' => array( 'type' => 'string' ),
				'title'       => array( 'type' => 'string' ),
				'post_title'  => array( 'type' => 'string' ),
				'permalink'   => array( 'type' => 'string' ),
				'url'         => array( 'type' => 'string' ),
				'word_count'  => array( 'type' => 'integer' ),
				'stale'       => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Build workflow loop ID schema.
	 *
	 * @return array<string, string>
	 */
	private function workflow_loop_id_schema(): array {
		return array(
			'type'        => 'string',
			'description' => 'Workflow loop ID returned by workflow_loop_create.',
		);
	}

	/**
	 * Build workflow loop completion status schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_loop_completion_status_schema(): array {
		return array(
			'type'        => 'string',
			'enum'        => array( 'succeeded', 'failed', 'skipped', 'blocked', 'cancelled' ),
			'description' => 'Final status for an item that has already been processed outside the loop store.',
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
	 * Build the workflow guide lookup schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_guides_get_schema(): array {
		return $this->object_schema(
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Workflow guide ID returned by workflow_guides_list.',
				),
			),
			array( 'id' )
		);
	}

	/**
	 * Build the long-form workflow preparation schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_prepare_post_schema(): array {
		return $this->object_schema(
			array(
				'brief'                    => array(
					'type'        => 'string',
					'description' => 'Content brief or user request to plan against.',
				),
				'post_type'                => array(
					'type'        => 'string',
					'description' => 'Target WordPress post type. Defaults to post.',
				),
				'audience'                 => array(
					'type'        => 'string',
					'description' => 'Intended reader or customer segment.',
				),
				'seo_intent'               => array(
					'type'        => 'string',
					'description' => 'Search intent, target query, or SEO goal.',
				),
				'desired_word_count'       => array(
					'type'        => 'integer',
					'minimum'     => self::MIN_LONG_FORM_WORDS,
					'maximum'     => self::MAX_LONG_FORM_WORDS,
					'description' => 'Target word count for long-form content. Values are clamped to 3000-5000 words.',
				),
				'content_mode'             => $this->content_mode_schema(),
				'content_type'             => array(
					'type'        => 'string',
					'description' => 'Optional natural-language content type alias such as blog post, landing page, service page, product page, case study, or visual layout. content_mode is preferred when known.',
				),
				'layout_intent'            => array(
					'type'        => 'string',
					'description' => 'Layout direction such as hero plus three-column cards, media/text sections, pricing grid, comparison columns, or FSE-style page composition.',
				),
				'visual_reference_summary' => array(
					'type'        => 'string',
					'description' => 'Concise non-sensitive summary of an attached image/screenshot/design reference. The MCP server does not inspect images directly; the assistant should translate the image into layout requirements here.',
				),
				'section_requirements'     => $this->string_list_schema( 'Requested page/article sections, for example hero, feature grid, testimonials, FAQ, comparison, or CTA.', 12 ),
				'preferred_block_families' => $this->block_family_list_schema( 'Preferred block families such as layout, media, or text.' ),
				'preferred_blocks'         => $this->string_list_schema( 'Preferred registered block names, for example core/columns, core/group, core/cover, or core/media-text.', 20 ),
				'preferred_patterns'       => $this->string_list_schema( 'Preferred registered pattern names when known.', 12 ),
				'existing_post_id'         => array(
					'type'        => 'integer',
					'description' => 'Existing post ID when planning an update workflow.',
				),
				'workflow_session_id'      => $this->workflow_session_id_schema(),
			),
			array( 'brief' )
		);
	}

	/**
	 * Build the workflow draft creation schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_create_draft_schema(): array {
		return $this->object_schema(
			array_merge(
				$this->workflow_content_fields(),
				$this->rankmath_fields(),
				array(
					'post_type'           => array(
						'type'        => 'string',
						'description' => 'Target WordPress post type. Defaults to post.',
					),
					'workflow_session_id' => $this->workflow_session_id_schema(),
				)
			),
			array( 'title', 'content' )
		);
	}

	/**
	 * Build the workflow post update schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_update_post_schema(): array {
		return $this->object_schema(
			array_merge(
				array(
					'id'                  => array(
						'type'        => 'integer',
						'description' => 'Existing WordPress content item ID.',
					),
					'update_mode'         => array(
						'type'        => 'string',
						'enum'        => array( 'replace', 'sections' ),
						'description' => 'Use replace for a full block document or sections when section_map contains the updated serialized section content.',
					),
					'section_map'         => array(
						'type'                 => 'object',
						'description'          => 'Map stable section IDs to updated serialized block section objects. The workflow combines sections into a full block document before validation.',
						'additionalProperties' => array(
							'type'                 => 'object',
							'properties'           => array(
								'content'    => array(
									'type'        => 'string',
									'maxLength'   => self::MAX_SERIALIZED_CONTENT_BYTES,
									'description' => 'Serialized WordPress block markup for this section. Never use raw HTML or core/html.',
								),
								'id'         => array(
									'type'        => 'string',
									'description' => 'Optional stable section ID. The map key is used when omitted.',
								),
								'section_id' => array(
									'type'        => 'string',
									'description' => 'Optional alias for id.',
								),
								'anchor'     => array(
									'type'        => 'string',
									'description' => 'Optional heading anchor for the section.',
								),
								'heading'    => array(
									'type'        => 'string',
									'description' => 'Optional heading text used to resolve the section ID.',
								),
							),
							'required'             => array( 'content' ),
							'additionalProperties' => false,
						),
					),
					'status'              => $this->content_status_schema(),
					'workflow_session_id' => $this->workflow_session_id_schema(),
				),
				$this->workflow_content_fields(),
				$this->rankmath_fields()
			),
			array( 'id' )
		);
	}

	/**
	 * Build the CC0 image search schema.
	 *
	 * @return array<string, mixed>
	 */
	private function content_media_search_schema(): array {
		return $this->object_schema(
			array(
				'query'    => array(
					'type'        => 'string',
					'description' => 'Image search topic. Openverse results are restricted to CC0.',
				),
				'topic'    => array(
					'type'        => 'string',
					'description' => 'Alias for query.',
				),
				'page'     => $this->page_schema(),
				'per_page' => $this->per_page_schema( 10, 'Image candidates to return. Defaults to 5 and is capped at 10.' ),
			)
		);
	}

	/**
	 * Build the content media apply workflow schema.
	 *
	 * @return array<string, mixed>
	 */
	private function content_media_apply_image_schema(): array {
		return $this->object_schema(
			array(
				'post_id'            => array(
					'type'        => 'integer',
					'description' => 'Existing WordPress post, page, or custom content item ID.',
				),
				'id'                 => array(
					'type'        => 'integer',
					'description' => 'Alias for post_id.',
				),
				'source_type'        => array(
					'type'        => 'string',
					'enum'        => array( 'attachment_id', 'url', 'generated_url', 'image_data', 'data_url', 'search_cc0' ),
					'description' => 'Image source. Use generated_url for externally generated AI images, image_data/data_url for direct encoded image payloads, and search_cc0 to import from Openverse CC0 results.',
				),
				'target'             => array(
					'type'        => 'string',
					'enum'        => array( 'featured_image', 'insert_block' ),
					'description' => 'Whether to set the image as featured media or insert a media block into post content.',
				),
				'attachment_id'      => array(
					'type'        => 'integer',
					'description' => 'Existing image attachment ID when source_type is attachment_id.',
				),
				'media_id'           => array(
					'type'        => 'integer',
					'description' => 'Alias for attachment_id.',
				),
				'image_id'           => array(
					'type'        => 'integer',
					'description' => 'Alias for attachment_id.',
				),
				'url'                => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => 'Public HTTP or HTTPS image URL for url or generated_url sources.',
				),
				'image_url'          => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => 'Alias for url.',
				),
				'data_url'           => array(
					'type'        => 'string',
					'maxLength'   => 15000000,
					'description' => 'Base64 image data URL for image_data/data_url sources.',
				),
				'data_base64'        => array(
					'type'        => 'string',
					'maxLength'   => 15000000,
					'description' => 'Raw base64 image data for image_data sources. Prefer data_url when the client can provide it.',
				),
				'image_base64'       => array(
					'type'        => 'string',
					'maxLength'   => 15000000,
					'description' => 'Alias for data_base64.',
				),
				'mime_type'          => array(
					'type'        => 'string',
					'description' => 'Image MIME type required when raw base64 data is provided.',
				),
				'filename'           => array(
					'type'        => 'string',
					'description' => 'Preferred filename for encoded image uploads.',
				),
				'query'              => array(
					'type'        => 'string',
					'description' => 'Search topic when source_type is search_cc0.',
				),
				'topic'              => array(
					'type'        => 'string',
					'description' => 'Alias for query.',
				),
				'selected_result_id' => array(
					'type'        => 'string',
					'description' => 'Openverse result ID to import after reviewing search candidates.',
				),
				'candidate_id'       => array(
					'type'        => 'string',
					'description' => 'Alias for selected_result_id.',
				),
				'selected_index'     => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => 9,
					'description' => 'Zero-based candidate index to import. Defaults to 0.',
				),
				'block_type'         => array(
					'type'        => 'string',
					'enum'        => array( 'image', 'gallery', 'cover', 'media_text' ),
					'description' => 'Core media block to insert when target is insert_block.',
				),
				'placement'          => array(
					'type'        => 'string',
					'enum'        => array( 'append', 'prepend', 'after_first_paragraph', 'after_heading' ),
					'description' => 'Where to insert the media block in existing content.',
				),
				'section_id'         => array(
					'type'        => 'string',
					'description' => 'Heading anchor or normalized heading text required when placement is after_heading.',
				),
				'after_heading'      => array(
					'type'        => 'string',
					'description' => 'Alias for section_id.',
				),
				'block_text'         => array(
					'type'        => 'string',
					'description' => 'Optional paragraph text inside media_text blocks.',
				),
				'title'              => array( 'type' => 'string' ),
				'alt_text'           => array( 'type' => 'string' ),
				'caption'            => array( 'type' => 'string' ),
				'description'        => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Build the Rank Math workflow schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_rankmath_schema(): array {
		return $this->object_schema(
			array_merge(
				array(
					'id'                  => array(
						'type'        => 'integer',
						'description' => 'Existing WordPress content item ID.',
					),
					'workflow_session_id' => $this->workflow_session_id_schema(),
				),
				$this->rankmath_fields()
			),
			array( 'id' )
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
					'description' => 'Use queued for faster MCP responses on larger refreshes. Defaults to sync for backward compatibility.',
				),
				'queued'    => array(
					'type'        => 'boolean',
					'description' => 'When true, create a queued WordPress cron job and return a job_key immediately.',
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
	 * Return shared long-form workflow content fields.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_content_fields(): array {
		return array(
			'title'                    => array( 'type' => 'string' ),
			'content'                  => array(
				'type'        => 'string',
				'maxLength'   => self::MAX_SERIALIZED_CONTENT_BYTES,
				'description' => 'Serialized WordPress block content for the full document. Required for create. For update, provide content or section_map. Never use raw HTML or core/html.',
			),
			'excerpt'                  => array( 'type' => 'string' ),
			'slug'                     => array( 'type' => 'string' ),
			'date'                     => $this->content_date_schema(),
			'featured_media'           => array(
				'type'        => 'integer',
				'description' => 'Existing image attachment ID to assign as the featured image.',
			),
			'clear_featured_media'     => array(
				'type'        => 'boolean',
				'description' => 'Set true to intentionally remove the current featured image.',
			),
			'author'                   => array(
				'type'        => 'integer',
				'description' => 'Existing WordPress user ID to assign as author.',
			),
			'taxonomies'               => $this->taxonomy_assignment_schema( 'Map taxonomy slugs to existing term IDs or term slugs.' ),
			'content_mode'             => $this->content_mode_schema(),
			'layout_intent'            => array(
				'type'        => 'string',
				'description' => 'Expected layout direction used for block validation warnings.',
			),
			'visual_reference_summary' => array(
				'type'        => 'string',
				'description' => 'Concise non-sensitive summary of the visual reference used to produce this block document.',
			),
			'expected_block_families'  => $this->block_family_list_schema( 'Block families expected in the serialized content. Use layout for columns/grids/cards/page sections.' ),
			'expected_blocks'          => $this->string_list_schema( 'Specific block names expected in the serialized content, such as core/columns or core/media-text.', 20 ),
		);
	}

	/**
	 * Build the content-mode schema for layout-aware workflows.
	 *
	 * @return array<string, mixed>
	 */
	private function content_mode_schema(): array {
		return array(
			'type'        => 'string',
			'enum'        => self::CONTENT_MODES,
			'description' => 'Content shape. Use article for prose-heavy blog posts, page or landing_page for FSE-style pages, visual_layout when an image/screenshot/layout direction should drive block choice, and service_page/product_page/case_study for those structured page types.',
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
	 * Build a workflow session ID field schema.
	 *
	 * @return array<string, string>
	 */
	private function workflow_session_id_schema(): array {
		return array(
			'type'        => 'string',
			'description' => 'Optional workflow session ID from workflow_session_start. Pass it through workflow calls so progress is preserved outside client chat memory.',
		);
	}

	/**
	 * Build the workflow session state schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_session_state_schema(): array {
		return array(
			'type'        => 'string',
			'enum'        => array( 'routed', 'started', 'prepared', 'validated', 'draft_created', 'updated', 'seo_applied', 'needs_review', 'failed', 'complete' ),
			'description' => 'Compact workflow state.',
		);
	}

	/**
	 * Build a block-family list schema.
	 *
	 * @param string $description Schema description.
	 * @return array<string, mixed>
	 */
	private function block_family_list_schema( string $description ): array {
		$schema                  = $this->string_list_schema( $description, 8 );
		$schema['items']['enum'] = self::BLOCK_FAMILIES;

		return $schema;
	}

	/**
	 * Return shared Rank Math SEO fields.
	 *
	 * @return array<string, mixed>
	 */
	private function rankmath_fields(): array {
		return array(
			'meta_title'       => array(
				'type'        => 'string',
				'description' => 'Rank Math SEO title.',
			),
			'meta_description' => array(
				'type'        => 'string',
				'description' => 'Rank Math SEO meta description.',
			),
			'focus_keywords'   => $this->string_list_schema( 'Rank Math focus keywords.', 10 ),
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
	 * Add dry-run and confirmation controls to write-capable tool schemas.
	 *
	 * @param array<string, mixed> $schema Tool schema.
	 * @return array<string, mixed>
	 */
	private function schema_with_safety_controls( array $schema ): array {
		$properties                       = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$properties['dry_run']            = array(
			'type'        => 'boolean',
			'description' => 'When true, validate the request and return a preview without changing WordPress data.',
		);
		$properties['confirmation_token'] = array(
			'type'        => 'string',
			'description' => 'Short-lived token returned by a dry run or confirmation-required response for high-risk actions.',
		);
		$properties['idempotency_key']    = array(
			'type'        => 'string',
			'maxLength'   => 128,
			'description' => 'Optional client-chosen key that makes this write retry-safe: repeating the call with the same key and arguments returns the stored result instead of executing twice. Use a new key for new work.',
		);
		$schema['properties']             = $properties;

		return $schema;
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
