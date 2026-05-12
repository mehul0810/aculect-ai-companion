<?php

declare(strict_types=1);

namespace Quark\Connectors\MCP;

/**
 * Dispatches MCP tool calls to WordPress content and abilities services.
 */
final class ContentController {

	/**
	 * Reserved for future direct REST route registration.
	 */
	public function register_routes(): void {
		// Internal MCP tools only.
	}

	/**
	 * List available post types.
	 *
	 * @return array<string, mixed>
	 */
	public function list_post_types(): array {
		return array( 'items' => ( new AbilitiesService() )->list_post_types() );
	}

	/**
	 * List posts for a post type.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_items( array $data ): array {
		return ( new AbilitiesService() )->list_items( $data );
	}

	/**
	 * Read a single post by ID.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_item( array $data ): array {
		return ( new AbilitiesService() )->get_item( (int) ( $data['id'] ?? 0 ) );
	}

	/**
	 * Create a post, page, or custom post type item.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function create_item( array $data ): array {
		return ( new AbilitiesService() )->create_item( $data );
	}

	/**
	 * Create a draft content item.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function create_draft( array $data ): array {
		return ( new AbilitiesService() )->create_draft( $data );
	}

	/**
	 * Update a content item.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function update_item( array $data ): array {
		return ( new AbilitiesService() )->update_item( $data );
	}

	/**
	 * List available taxonomies.
	 *
	 * @return array<string, mixed>
	 */
	public function list_taxonomies(): array {
		return array( 'items' => ( new AbilitiesService() )->list_taxonomies() );
	}

	/**
	 * List terms for a taxonomy.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_terms( array $data ): array {
		return ( new AbilitiesService() )->list_terms( $data );
	}

	/**
	 * Create a taxonomy term.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function create_term( array $data ): array {
		return ( new AbilitiesService() )->create_term( $data );
	}

	/**
	 * Update a taxonomy term.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function update_term( array $data ): array {
		return ( new AbilitiesService() )->update_term( $data );
	}

	/**
	 * List media attachments.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_media( array $data ): array {
		return ( new AbilitiesService() )->list_media( $data );
	}

	/**
	 * Upload media from a public URL.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function upload_media( array $data ): array {
		return ( new AbilitiesService() )->upload_media( $data );
	}

	/**
	 * List comments.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_comments( array $data ): array {
		return ( new AbilitiesService() )->list_comments( $data );
	}

	/**
	 * Read one comment.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_comment( array $data ): array {
		return ( new AbilitiesService() )->get_comment( $data );
	}

	/**
	 * Create a comment.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function create_comment( array $data ): array {
		return ( new AbilitiesService() )->create_comment( $data );
	}

	/**
	 * Update a comment.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function update_comment( array $data ): array {
		return ( new AbilitiesService() )->update_comment( $data );
	}

	/**
	 * Return safe site settings.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return ( new AbilitiesService() )->get_settings();
	}

	/**
	 * Return site identity and environment information.
	 *
	 * @return array<string, mixed>
	 */
	public function get_site_info(): array {
		return ( new AbilitiesService() )->get_site_info();
	}

	/**
	 * List installed plugins.
	 *
	 * @return array<string, mixed>
	 */
	public function list_plugins(): array {
		return ( new AbilitiesService() )->list_plugins();
	}

	/**
	 * List installed themes.
	 *
	 * @return array<string, mixed>
	 */
	public function list_themes(): array {
		return ( new AbilitiesService() )->list_themes();
	}

	/**
	 * Discover WordPress Abilities API registrations.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function discover_wp_abilities( array $data ): array {
		return ( new WordPressAbilitiesBridge() )->discover( $data );
	}

	/**
	 * Inspect one WordPress ability.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_wp_ability_info( array $data ): array {
		return ( new WordPressAbilitiesBridge() )->get_info( $data );
	}

	/**
	 * Run one WordPress ability.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function run_wp_ability( array $data ): array {
		return ( new WordPressAbilitiesBridge() )->run( $data );
	}
}
