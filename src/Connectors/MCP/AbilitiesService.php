<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Facade for WordPress content, taxonomy, media, comment, and site abilities.
 */
final class AbilitiesService {

	/**
	 * List readable post types, including supported custom post types.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_post_types(): array {
		return ( new ContentAbilities() )->list_post_types();
	}

	/**
	 * List content items for a supported post type with pagination.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function list_items( array $args ): array {
		return ( new ContentAbilities() )->list_items( $args );
	}

	/**
	 * Read one content item by post ID.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public function get_item( int $post_id ): array {
		return ( new ContentAbilities() )->get_item( $post_id );
	}

	/**
	 * Create a post, page, or custom post type item.
	 *
	 * @param array<string, mixed> $data Content fields.
	 * @return array<string, mixed>
	 */
	public function create_item( array $data ): array {
		return ( new ContentAbilities() )->create_item( $data );
	}

	/**
	 * Create a draft content item.
	 *
	 * @param array<string, mixed> $data Content fields.
	 * @return array<string, mixed>
	 */
	public function create_draft( array $data ): array {
		return ( new ContentAbilities() )->create_draft( $data );
	}

	/**
	 * Update an existing content item.
	 *
	 * @param array<string, mixed> $data Content fields.
	 * @return array<string, mixed>
	 */
	public function update_item( array $data ): array {
		return ( new ContentAbilities() )->update_item( $data );
	}

	/**
	 * List supported taxonomies, including custom taxonomies.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_taxonomies(): array {
		return ( new TaxonomyAbilities() )->list_taxonomies();
	}

	/**
	 * List terms in a supported taxonomy with pagination.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function list_terms( array $args ): array {
		return ( new TaxonomyAbilities() )->list_terms( $args );
	}

	/**
	 * Create a term in a supported taxonomy.
	 *
	 * @param array<string, mixed> $data Term fields.
	 * @return array<string, mixed>
	 */
	public function create_term( array $data ): array {
		return ( new TaxonomyAbilities() )->create_term( $data );
	}

	/**
	 * Update a term in a supported taxonomy.
	 *
	 * @param array<string, mixed> $data Term fields.
	 * @return array<string, mixed>
	 */
	public function update_term( array $data ): array {
		return ( new TaxonomyAbilities() )->update_term( $data );
	}

	/**
	 * List media attachments by delegating to content listing.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function list_media( array $args ): array {
		return ( new MediaAbilities() )->list_media( $args );
	}

	/**
	 * Read one media attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public function get_media( int $attachment_id ): array {
		return ( new MediaAbilities() )->get_media( $attachment_id );
	}

	/**
	 * Update media metadata and attachment relationship.
	 *
	 * @param array<string, mixed> $data Media fields.
	 * @return array<string, mixed>
	 */
	public function update_media( array $data ): array {
		return ( new MediaAbilities() )->update_media( $data );
	}

	/**
	 * Sideload media from a public HTTP(S) URL.
	 *
	 * @param array<string, mixed> $data Upload fields.
	 * @return array<string, mixed>
	 */
	public function upload_media( array $data ): array {
		return ( new MediaAbilities() )->upload_media( $data );
	}

	/**
	 * List comments with pagination and optional filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function list_comments( array $args ): array {
		return ( new CommentAbilities() )->list_comments( $args );
	}

	/**
	 * Read one comment.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_comment( array $data ): array {
		return ( new CommentAbilities() )->get_comment( $data );
	}

	/**
	 * Create a comment on an editable post.
	 *
	 * @param array<string, mixed> $data Comment fields.
	 * @return array<string, mixed>
	 */
	public function create_comment( array $data ): array {
		return ( new CommentAbilities() )->create_comment( $data );
	}

	/**
	 * Update comment content or moderation status.
	 *
	 * @param array<string, mixed> $data Comment fields.
	 * @return array<string, mixed>
	 */
	public function update_comment( array $data ): array {
		return ( new CommentAbilities() )->update_comment( $data );
	}

	/**
	 * Bulk update comment moderation status.
	 *
	 * @param array<string, mixed> $data Comment fields.
	 * @return array<string, mixed>
	 */
	public function bulk_update_comments( array $data ): array {
		return ( new CommentAbilities() )->bulk_update_comments( $data );
	}

	/**
	 * Return a safe subset of site settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return ( new SiteAbilities() )->get_settings();
	}

	/**
	 * Return site, WordPress, PHP, theme, and connector metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_site_info(): array {
		return ( new SiteAbilities() )->get_site_info();
	}

	/**
	 * Return safe, high-level site health signals.
	 *
	 * @return array<string, mixed>
	 */
	public function get_site_health(): array {
		return ( new SiteAbilities() )->get_site_health();
	}

	/**
	 * List installed plugins for users who can activate plugins.
	 *
	 * @return array<string, mixed>
	 */
	public function list_plugins(): array {
		return ( new SiteAbilities() )->list_plugins();
	}

	/**
	 * List installed themes for users who can switch themes.
	 *
	 * @return array<string, mixed>
	 */
	public function list_themes(): array {
		return ( new SiteAbilities() )->list_themes();
	}
}
