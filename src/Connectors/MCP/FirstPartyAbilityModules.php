<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Brand\BrandProfile;
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
						'status'    => $this->string_or_string_list_schema(),
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
				'brand.get_profile',
				'Get Brand Profile',
				'Read sanitized brand guidance for content and featured image workflows.',
				'Brand',
				'content:read',
				true,
				$this->empty_schema(),
				static fn (): array => ( new BrandProfile() )->public_profile()
			),
			$this->module(
				'blocks.list_available',
				'List Available Blocks',
				'List registered WordPress blocks with usage guidance. Never use the Custom HTML block (core/html).',
				'Block Knowledge',
				'content:read',
				true,
				$this->object_schema(
					array(
						'search'    => array( 'type' => 'string' ),
						'namespace' => array(
							'type'        => 'string',
							'description' => 'Optional block namespace such as core, woocommerce, or a plugin namespace.',
						),
						'category'  => array( 'type' => 'string' ),
						'inserter'  => array(
							'type'        => 'boolean',
							'description' => 'Filter by whether the block is intended to appear in inserter-style selection flows.',
						),
						'page'      => array( 'type' => 'integer' ),
						'per_page'  => array( 'type' => 'integer' ),
						'context'   => array(
							'type'        => 'string',
							'enum'        => array( 'compact', 'full' ),
							'description' => 'Use compact for browsing or full to include attribute/support keys. Defaults to compact.',
						),
					)
				),
				static fn ( array $args ): array => ( new BlockKnowledgeAbilities() )->list_blocks( $args )
			),
			$this->module(
				'blocks.get_info',
				'Inspect a Block',
				'Read detailed guidance for one registered WordPress block. Never use the Custom HTML block (core/html).',
				'Block Knowledge',
				'content:read',
				true,
				$this->object_schema(
					array(
						'name' => array(
							'type'        => 'string',
							'description' => 'Registered block name such as core/paragraph.',
						),
					),
					array( 'name' )
				),
				static fn ( array $args ): array => ( new BlockKnowledgeAbilities() )->get_block_info( $args )
			),
			$this->module(
				'patterns.list_available',
				'List Available Patterns',
				'List registered WordPress block patterns with usage guidance. Avoid patterns that contain Custom HTML blocks.',
				'Block Knowledge',
				'content:read',
				true,
				$this->object_schema(
					array(
						'search'     => array( 'type' => 'string' ),
						'category'   => array( 'type' => 'string' ),
						'block_type' => array(
							'type'        => 'string',
							'description' => 'Optional related block type such as core/post-content or core/query.',
						),
						'inserter'   => array(
							'type'        => 'boolean',
							'description' => 'Filter by whether the pattern is intended to appear in inserter-style selection flows.',
						),
						'page'       => array( 'type' => 'integer' ),
						'per_page'   => array( 'type' => 'integer' ),
						'context'    => array(
							'type'        => 'string',
							'enum'        => array( 'compact', 'full' ),
							'description' => 'Use compact for browsing or full to include bounded content previews. Defaults to compact.',
						),
					)
				),
				static fn ( array $args ): array => ( new BlockKnowledgeAbilities() )->list_patterns( $args )
			),
			$this->module(
				'patterns.get_info',
				'Inspect a Pattern',
				'Read detailed guidance for one registered WordPress block pattern and optionally include bounded block markup.',
				'Block Knowledge',
				'content:read',
				true,
				$this->object_schema(
					array(
						'name'            => array(
							'type'        => 'string',
							'description' => 'Registered pattern name such as theme/hero.',
						),
						'include_content' => array(
							'type'        => 'boolean',
							'description' => 'When true, include bounded pattern block markup. Use only when the exact pattern markup is needed.',
						),
					),
					array( 'name' )
				),
				static fn ( array $args ): array => ( new BlockKnowledgeAbilities() )->get_pattern_info( $args )
			),
			$this->module(
				'content.validate_blocks',
				'Validate Block Content',
				'Validate serialized block content before writing it and reject Custom HTML block usage.',
				'Block Knowledge',
				'content:read',
				true,
				$this->object_schema(
					array(
						'content' => array(
							'type'        => 'string',
							'description' => 'Serialized WordPress block content to validate before create or update operations.',
						),
					),
					array( 'content' )
				),
				static fn ( array $args ): array => ( new BlockKnowledgeAbilities() )->validate_block_content( $args )
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
						'focus_keywords'   => $this->string_or_string_list_schema(),
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
	 * @return array<string, mixed>
	 */
	private function string_or_string_list_schema(): array {
		return array(
			'oneOf' => array(
				array( 'type' => 'string' ),
				array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
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
			'description'          => $description,
			'additionalProperties' => array(
				'oneOf' => array(
					array( 'type' => 'integer' ),
					array( 'type' => 'string' ),
					array(
						'type'  => 'array',
						'items' => array(
							'oneOf' => array(
								array( 'type' => 'integer' ),
								array( 'type' => 'string' ),
							),
						),
					),
				),
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
