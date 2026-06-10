<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Closure;

/**
 * Builds first-party MCP ability modules.
 */
final class FirstPartyAbilityModules {

	/**
	 * Return first-party modules keyed by internal ability ID.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	public function all(): array {
		$modules = array(
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
				'Save or update one durable local Aculect Intelligence memory item for future site, brand, content, SEO, or workflow guidance.',
				'Aculect Memory',
				'content:draft',
				false,
				$this->memory_save_schema(),
				static fn ( array $args ): array => ( new IntelligenceIndexAbilities() )->save_memory( $args )
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
						'status'    => $this->string_or_string_list_schema( 'Single post status or comma-separated post statuses.' ),
						'page'      => array( 'type' => 'integer' ),
						'per_page'  => array( 'type' => 'integer' ),
						'context'   => array(
							'type'        => 'string',
							'enum'        => array( 'compact', 'full' ),
							'description' => 'Use compact for list browsing or full to include full content bodies. Defaults to compact.',
						),
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
							'description' => 'Serialized WordPress block content. Use registered blocks and patterns, and never use the Custom HTML block (core/html).',
						),
						'excerpt'        => array( 'type' => 'string' ),
						'slug'           => array( 'type' => 'string' ),
						'status'         => array( 'type' => 'string' ),
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
							'description' => 'Serialized WordPress block content. Use registered blocks and patterns, and never use the Custom HTML block (core/html).',
						),
						'excerpt'              => array( 'type' => 'string' ),
						'slug'                 => array( 'type' => 'string' ),
						'status'               => array( 'type' => 'string' ),
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
						'focus_keywords'   => $this->string_or_string_list_schema( 'Single focus keyword or comma-separated focus keywords.' ),
					),
					array( 'id' )
				),
				static fn ( array $args ): array => ( new SeoAbilities() )->update_seo( $args )
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
						'page'       => array( 'type' => 'integer' ),
						'per_page'   => array( 'type' => 'integer' ),
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
						'page'        => array( 'type' => 'integer' ),
						'per_page'    => array( 'type' => 'integer' ),
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
						'date_after'  => array( 'type' => 'string' ),
						'date_before' => array( 'type' => 'string' ),
						'context'     => array(
							'type'        => 'string',
							'enum'        => array( 'compact', 'full' ),
							'description' => 'Use compact for list browsing or full to include full attachment body fields. Defaults to compact.',
						),
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
						'page'           => array( 'type' => 'integer' ),
						'per_page'       => array( 'type' => 'integer' ),
						'context'        => array(
							'type'        => 'string',
							'enum'        => array( 'compact', 'full' ),
							'description' => 'Use compact for moderation queues or full to include comment bodies. Defaults to compact.',
						),
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
						'page'     => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
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
	 * @param string  $id          Internal ability ID.
	 * @param string  $title       Admin-facing title.
	 * @param string  $description Assistant-facing description.
	 * @param string  $group       Admin grouping label.
	 * @param string  $scope       Required OAuth scope.
	 * @param bool    $read_only   Whether the ability is read-only.
	 * @param array   $schema      Input schema.
	 * @param Closure $handler     Execution callback.
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
	 * @return array<string, mixed>
	 */
	private function object_schema( array $properties, array $required = array() ): array {
		$schema = array(
			'type'       => 'object',
			'properties' => $properties,
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
			'type'       => 'object',
			'properties' => new \stdClass(),
		);
	}

	/**
	 * Build a schema that accepts a string or list of strings.
	 *
	 * @param string $description Schema description.
	 * @return array<string, mixed>
	 */
	private function string_or_string_list_schema( string $description ): array {
		return array(
			'type'        => 'string',
			'description' => $description,
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
				'brief'              => array(
					'type'        => 'string',
					'description' => 'Content brief or user request to plan against.',
				),
				'post_type'          => array(
					'type'        => 'string',
					'description' => 'Target WordPress post type. Defaults to post.',
				),
				'audience'           => array(
					'type'        => 'string',
					'description' => 'Intended reader or customer segment.',
				),
				'seo_intent'         => array(
					'type'        => 'string',
					'description' => 'Search intent, target query, or SEO goal.',
				),
				'desired_word_count' => array(
					'type'        => 'integer',
					'description' => 'Target word count for long-form content. Values are clamped to 3000-5000 words.',
				),
				'existing_post_id'   => array(
					'type'        => 'integer',
					'description' => 'Existing post ID when planning an update workflow.',
				),
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
					'post_type' => array(
						'type'        => 'string',
						'description' => 'Target WordPress post type. Defaults to post.',
					),
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
					'id'          => array(
						'type'        => 'integer',
						'description' => 'Existing WordPress content item ID.',
					),
					'update_mode' => array(
						'type'        => 'string',
						'enum'        => array( 'replace', 'sections' ),
						'description' => 'Use replace for a full block document or sections when section_map contains the updated serialized section content.',
					),
					'section_map' => array(
						'type'                 => 'object',
						'description'          => 'Map section IDs to serialized block content or objects with a content field. The workflow combines sections into a full block document before validation.',
						'additionalProperties' => true,
					),
				),
				$this->workflow_content_fields(),
				$this->rankmath_fields()
			),
			array( 'id' )
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
					'id' => array(
						'type'        => 'integer',
						'description' => 'Existing WordPress content item ID.',
					),
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
				'status'    => $this->string_or_string_list_schema( 'Single status or comma-separated statuses. Defaults to publish, future, draft, pending, and private.' ),
				'ids'       => array(
					'type'        => 'array',
					'description' => 'Optional explicit content IDs to refresh. Maximum 100 per batch.',
					'items'       => array( 'type' => 'integer' ),
				),
				'limit'     => array(
					'type'        => 'integer',
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
				'query'     => array(
					'type'        => 'string',
					'description' => 'Search text for title, summary, terms, and indexed content.',
				),
				'post_type' => array( 'type' => 'string' ),
				'status'    => array( 'type' => 'string' ),
				'stale'     => array(
					'type'        => 'boolean',
					'description' => 'Filter to stale or fresh index rows.',
				),
				'page'      => array( 'type' => 'integer' ),
				'per_page'  => array( 'type' => 'integer' ),
				'context'   => array(
					'type'        => 'string',
					'enum'        => array( 'compact', 'full' ),
					'description' => 'Use compact for normal retrieval. Full is reserved for future expanded item fields.',
				),
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
				'page'      => array( 'type' => 'integer' ),
				'per_page'  => array( 'type' => 'integer' ),
				'context'   => array(
					'type'        => 'string',
					'enum'        => array( 'compact', 'full' ),
					'description' => 'Use full only when exact serialized block markup is needed.',
				),
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
				'limit'     => array( 'type' => 'integer' ),
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
				'limit'     => array( 'type' => 'integer' ),
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
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
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
					'type' => 'string',
					'enum' => array( 'approved', 'pending', 'dismissed' ),
				),
			),
			array( 'key', 'value' )
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
	 * Return shared long-form workflow content fields.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_content_fields(): array {
		return array(
			'title'                => array( 'type' => 'string' ),
			'content'              => array(
				'type'        => 'string',
				'description' => 'Serialized WordPress block content for the full document. Required for create. For update, provide content or section_map. Never use raw HTML or core/html.',
			),
			'excerpt'              => array( 'type' => 'string' ),
			'slug'                 => array( 'type' => 'string' ),
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
			'taxonomies'           => $this->taxonomy_assignment_schema( 'Map taxonomy slugs to existing term IDs or term slugs.' ),
		);
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
			'focus_keywords'   => $this->string_or_string_list_schema( 'Single Rank Math focus keyword or comma-separated focus keywords.' ),
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
			'description' => 'Publication date as YYYY-MM-DDTHH:MM:SS in the site timezone, YYYY-MM-DD HH:MM:SS, or ISO 8601 with a timezone offset. Future publish dates may schedule the item according to WordPress rules.',
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
			'description'          => $description . ' Values may be an existing term ID, term slug, or array of existing term IDs/slugs.',
			'additionalProperties' => true,
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
