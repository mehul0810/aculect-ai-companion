<?php

declare(strict_types=1);

namespace Quark\Connectors\MCP;

final class AbilitiesRegistry {

	public const OPTION_ENABLED_ABILITIES = 'quark_enabled_abilities';
	private const TOOL_NAME_PATTERN       = '/^[a-zA-Z0-9_-]{1,64}$/';

	public function definitions(): array {
		return array(
			'site.list_post_types'     => array(
				'id'          => 'site.list_post_types',
				'title'       => 'List Post Types',
				'description' => 'List readable WordPress post types, including custom post types.',
				'group'       => 'Content',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'content.list_items'       => array(
				'id'          => 'content.list_items',
				'title'       => 'List Content Items',
				'description' => 'List content for any enabled post type with pagination.',
				'group'       => 'Content',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'content.get_item'         => array(
				'id'          => 'content.get_item',
				'title'       => 'Read Content Item',
				'description' => 'Read one content item by ID from any enabled post type.',
				'group'       => 'Content',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'content.create_item'      => array(
				'id'          => 'content.create_item',
				'title'       => 'Create Content Item',
				'description' => 'Create a post, page, or custom post type item.',
				'group'       => 'Content',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
			'content.update_item'      => array(
				'id'          => 'content.update_item',
				'title'       => 'Update Content Item',
				'description' => 'Update title, content, excerpt, slug, or status for an existing item.',
				'group'       => 'Content',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
			'taxonomy.list_taxonomies' => array(
				'id'          => 'taxonomy.list_taxonomies',
				'title'       => 'List Taxonomies',
				'description' => 'List available taxonomies, including custom taxonomies.',
				'group'       => 'Taxonomies',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'taxonomy.list_terms'      => array(
				'id'          => 'taxonomy.list_terms',
				'title'       => 'List Terms',
				'description' => 'List terms in any enabled taxonomy with pagination.',
				'group'       => 'Taxonomies',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'taxonomy.create_term'     => array(
				'id'          => 'taxonomy.create_term',
				'title'       => 'Create Term',
				'description' => 'Create a term in a built-in or custom taxonomy.',
				'group'       => 'Taxonomies',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
			'taxonomy.update_term'     => array(
				'id'          => 'taxonomy.update_term',
				'title'       => 'Update Term',
				'description' => 'Update a term in a built-in or custom taxonomy.',
				'group'       => 'Taxonomies',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
			'media.list_items'         => array(
				'id'          => 'media.list_items',
				'title'       => 'List Media Items',
				'description' => 'List media library attachments with pagination.',
				'group'       => 'Media',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'site.get_settings'        => array(
				'id'          => 'site.get_settings',
				'title'       => 'Read Site Settings',
				'description' => 'Read safe, non-secret site settings.',
				'group'       => 'Site',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
		);
	}

	public function public_definitions(): array {
		$enabled = $this->enabled_ids();
		return array_map(
			function ( array $definition ) use ( $enabled ): array {
				$definition['enabled']  = in_array( (string) $definition['id'], $enabled, true );
				$definition['toolName'] = $this->tool_name( (string) $definition['id'] );
				return $definition;
			},
			array_values( $this->definitions() )
		);
	}

	public function enabled_definitions(): array {
		$enabled     = $this->enabled_ids();
		$definitions = $this->definitions();
		return array_intersect_key( $definitions, array_flip( $enabled ) );
	}

	public function enabled_ids(): array {
		$stored = get_option( self::OPTION_ENABLED_ABILITIES, null );
		if ( ! is_array( $stored ) ) {
			return array_keys( $this->definitions() );
		}

		return $this->sanitize_ids( $stored );
	}

	public function save_enabled_ids( array $ids ): void {
		update_option( self::OPTION_ENABLED_ABILITIES, $this->sanitize_ids( $ids ), false );
	}

	public function is_known( string $id ): bool {
		return array_key_exists( $this->internal_id( $id ), $this->definitions() );
	}

	public function is_enabled( string $id ): bool {
		return in_array( $this->internal_id( $id ), $this->enabled_ids(), true );
	}

	public function required_scopes( string $id ): array {
		$definition = $this->definitions()[ $this->internal_id( $id ) ] ?? array();
		$scope      = (string) ( $definition['scope'] ?? 'content:read' );
		return array( $scope );
	}

	public function internal_id( string $id ): string {
		$id = $this->normalize_alias( $id );
		if ( array_key_exists( $id, $this->definitions() ) ) {
			return $id;
		}

		foreach ( array_keys( $this->definitions() ) as $definition_id ) {
			if ( hash_equals( $this->tool_name( (string) $definition_id ), $id ) ) {
				return (string) $definition_id;
			}
		}

		return $id;
	}

	public function tool_name( string $id ): string {
		$id        = $this->normalize_alias( $id );
		$tool_name = preg_replace( '/[^a-zA-Z0-9_-]+/', '_', $id );
		$tool_name = trim( (string) $tool_name, '_-' );

		if ( '' === $tool_name ) {
			$tool_name = 'quark_tool';
		}

		$tool_name = substr( $tool_name, 0, 64 );

		return $this->is_valid_tool_name( $tool_name ) ? $tool_name : 'quark_tool';
	}

	public function is_valid_tool_name( string $name ): bool {
		return 1 === preg_match( self::TOOL_NAME_PATTERN, $name );
	}

	public function normalize_alias( string $id ): string {
		$aliases = array(
			'content.create_draft' => 'content.create_item',
			'content_create_draft' => 'content.create_item',
		);

		return $aliases[ $id ] ?? $id;
	}

	private function sanitize_ids( array $ids ): array {
		$definitions = $this->definitions();
		$sanitized   = array();

		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = preg_replace( '/[^a-zA-Z0-9:_\-.]/', '', (string) $id );
			$id = $this->internal_id( (string) $id );
			if ( array_key_exists( $id, $definitions ) ) {
				$sanitized[] = $id;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}
}
