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

	public function get_settings(): array {
		return ( new AbilitiesService() )->get_settings();
	}
}
