<?php

declare(strict_types=1);

namespace Quark\Connectors\MCP;

final class ContentController {

	public function register_routes(): void {
		// Internal MCP tools only.
	}

	public function list_post_types(): array {
		return array( 'items' => ( new AbilitiesService() )->list_post_types() );
	}

	public function list_items( array $data ): array {
		return ( new AbilitiesService() )->list_items( $data );
	}

	public function get_item( array $data ): array {
		return ( new AbilitiesService() )->get_item( (int) ( $data['id'] ?? 0 ) );
	}

	public function create_item( array $data ): array {
		return ( new AbilitiesService() )->create_item( $data );
	}

	public function create_draft( array $data ): array {
		return ( new AbilitiesService() )->create_draft( $data );
	}

	public function update_item( array $data ): array {
		return ( new AbilitiesService() )->update_item( $data );
	}

	public function list_taxonomies(): array {
		return array( 'items' => ( new AbilitiesService() )->list_taxonomies() );
	}

	public function list_terms( array $data ): array {
		return ( new AbilitiesService() )->list_terms( $data );
	}

	public function create_term( array $data ): array {
		return ( new AbilitiesService() )->create_term( $data );
	}

	public function update_term( array $data ): array {
		return ( new AbilitiesService() )->update_term( $data );
	}

	public function list_media( array $data ): array {
		return ( new AbilitiesService() )->list_media( $data );
	}

	public function upload_media( array $data ): array {
		return ( new AbilitiesService() )->upload_media( $data );
	}

	public function list_comments( array $data ): array {
		return ( new AbilitiesService() )->list_comments( $data );
	}

	public function get_comment( array $data ): array {
		return ( new AbilitiesService() )->get_comment( $data );
	}

	public function create_comment( array $data ): array {
		return ( new AbilitiesService() )->create_comment( $data );
	}

	public function update_comment( array $data ): array {
		return ( new AbilitiesService() )->update_comment( $data );
	}

	public function get_settings(): array {
		return ( new AbilitiesService() )->get_settings();
	}

	public function get_site_info(): array {
		return ( new AbilitiesService() )->get_site_info();
	}

	public function list_plugins(): array {
		return ( new AbilitiesService() )->list_plugins();
	}

	public function list_themes(): array {
		return ( new AbilitiesService() )->list_themes();
	}

	public function discover_wp_abilities( array $data ): array {
		return ( new WordPressAbilitiesBridge() )->discover( $data );
	}

	public function get_wp_ability_info( array $data ): array {
		return ( new WordPressAbilitiesBridge() )->get_info( $data );
	}

	public function run_wp_ability( array $data ): array {
		return ( new WordPressAbilitiesBridge() )->run( $data );
	}
}
