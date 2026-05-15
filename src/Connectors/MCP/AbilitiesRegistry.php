<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Registry of Aculect AI Companion abilities exposed through MCP tools.
 *
 * Internal IDs intentionally use dotted namespaces for maintainability. Public
 * MCP tool names are generated separately because clients such as Claude reject
 * tool names that do not match a stricter identifier pattern.
 */
final class AbilitiesRegistry {

	public const OPTION_ENABLED_ABILITIES = 'aculect_ai_companion_enabled_abilities';
	private const TOOL_NAME_PATTERN       = '/^[a-zA-Z0-9_-]{1,64}$/';

	/**
	 * Return all abilities Aculect AI Companion can expose to assistant clients.
	 *
	 * @return array<string, array<string, bool|string>>
	 */
	public function definitions(): array {
		return array(
			'site.list_post_types'     => array(
				'id'          => 'site.list_post_types',
				'title'       => 'List Content Types',
				'description' => 'List readable WordPress content types, including custom ones.',
				'group'       => 'Content',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'content.list_items'       => array(
				'id'          => 'content.list_items',
				'title'       => 'List Posts and Pages',
				'description' => 'List content for any enabled content type with pagination.',
				'group'       => 'Content',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'content.get_item'         => array(
				'id'          => 'content.get_item',
				'title'       => 'Read a Post or Page',
				'description' => 'Read one content item by ID from any enabled content type.',
				'group'       => 'Content',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'content.create_item'      => array(
				'id'          => 'content.create_item',
				'title'       => 'Create a Post or Page',
				'description' => 'Create a post, page, or custom content item.',
				'group'       => 'Content',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
			'content.update_item'      => array(
				'id'          => 'content.update_item',
				'title'       => 'Update a Post or Page',
				'description' => 'Update title, content, excerpt, slug, or status for an existing item.',
				'group'       => 'Content',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
			'taxonomy.list_taxonomies' => array(
				'id'          => 'taxonomy.list_taxonomies',
				'title'       => 'List Content Groups',
				'description' => 'List available categories, tags, and custom content groups.',
				'group'       => 'Content Groups',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'taxonomy.list_terms'      => array(
				'id'          => 'taxonomy.list_terms',
				'title'       => 'List Categories and Tags',
				'description' => 'List categories, tags, or custom content groups with pagination.',
				'group'       => 'Content Groups',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'taxonomy.create_term'     => array(
				'id'          => 'taxonomy.create_term',
				'title'       => 'Create a Category or Tag',
				'description' => 'Create a category, tag, or custom content group.',
				'group'       => 'Content Groups',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
			'taxonomy.update_term'     => array(
				'id'          => 'taxonomy.update_term',
				'title'       => 'Update a Category or Tag',
				'description' => 'Update a category, tag, or custom content group.',
				'group'       => 'Content Groups',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
			'media.list_items'         => array(
				'id'          => 'media.list_items',
				'title'       => 'List Media',
				'description' => 'List media library attachments with pagination.',
				'group'       => 'Media',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'site.get_settings'        => array(
				'id'          => 'site.get_settings',
				'title'       => 'View Site Settings',
				'description' => 'Read safe, non-secret site settings.',
				'group'       => 'Site',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'site.get_info'            => array(
				'id'          => 'site.get_info',
				'title'       => 'View Site Information',
				'description' => 'Read WordPress version, PHP version, active theme, and basic site metadata.',
				'group'       => 'Site',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'site.list_plugins'        => array(
				'id'          => 'site.list_plugins',
				'title'       => 'List Plugins',
				'description' => 'List installed WordPress plugins and active state for users who can manage plugins.',
				'group'       => 'Site',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'site.list_themes'         => array(
				'id'          => 'site.list_themes',
				'title'       => 'List Themes',
				'description' => 'List installed WordPress themes and active state for users who can manage themes.',
				'group'       => 'Site',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'comments.list_items'      => array(
				'id'          => 'comments.list_items',
				'title'       => 'List Comments for Review',
				'description' => 'List WordPress comments with pagination and moderation-safe fields.',
				'group'       => 'Comments',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'comments.get_item'        => array(
				'id'          => 'comments.get_item',
				'title'       => 'Read a Comment',
				'description' => 'Read a single WordPress comment by ID.',
				'group'       => 'Comments',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'comments.create_item'     => array(
				'id'          => 'comments.create_item',
				'title'       => 'Reply to a Comment',
				'description' => 'Create a WordPress comment as the connected user.',
				'group'       => 'Comments',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
			'comments.update_item'     => array(
				'id'          => 'comments.update_item',
				'title'       => 'Moderate a Comment',
				'description' => 'Update comment content or moderation status.',
				'group'       => 'Comments',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
			'media.upload_item'        => array(
				'id'          => 'media.upload_item',
				'title'       => 'Upload Media From a URL',
				'description' => 'Upload media to the WordPress media library from a public URL with SSRF checks.',
				'group'       => 'Media',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
			'wp_abilities.discover'    => array(
				'id'          => 'wp_abilities.discover',
				'title'       => 'Discover WordPress Actions',
				'description' => 'Discover supported actions registered by WordPress and plugins.',
				'group'       => 'WordPress Actions',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'wp_abilities.get_info'    => array(
				'id'          => 'wp_abilities.get_info',
				'title'       => 'Inspect a WordPress Action',
				'description' => 'Review details for a supported action registered by WordPress or a plugin.',
				'group'       => 'WordPress Actions',
				'scope'       => 'content:read',
				'readOnly'    => true,
			),
			'wp_abilities.run'         => array(
				'id'          => 'wp_abilities.run',
				'title'       => 'Run a WordPress Action',
				'description' => 'Run a supported public WordPress action using the connected user permissions.',
				'group'       => 'WordPress Actions',
				'scope'       => 'content:draft',
				'readOnly'    => false,
			),
		);
	}

	/**
	 * Return ability definitions formatted for the admin UI.
	 *
	 * @return list<array<string, bool|string>>
	 */
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

	/**
	 * Return only definitions enabled for MCP exposure.
	 *
	 * @return array<string, array<string, bool|string>>
	 */
	public function enabled_definitions(): array {
		$enabled     = $this->enabled_ids();
		$definitions = $this->definitions();
		return array_intersect_key( $definitions, array_flip( $enabled ) );
	}

	/**
	 * Return enabled internal ability IDs.
	 *
	 * @return list<string>
	 */
	public function enabled_ids(): array {
		$stored = get_option( self::OPTION_ENABLED_ABILITIES, null );
		if ( ! is_array( $stored ) ) {
			return array_keys( $this->definitions() );
		}

		return $this->sanitize_ids( $stored );
	}

	/**
	 * Persist enabled ability IDs after normalizing aliases and public names.
	 *
	 * @param array $ids Ability IDs or public tool names.
	 */
	public function save_enabled_ids( array $ids ): void {
		update_option( self::OPTION_ENABLED_ABILITIES, $this->sanitize_ids( $ids ), false );
	}

	/**
	 * Check whether an ability exists.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function is_known( string $id ): bool {
		return array_key_exists( $this->internal_id( $id ), $this->definitions() );
	}

	/**
	 * Check whether an ability is enabled.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function is_enabled( string $id ): bool {
		return in_array( $this->internal_id( $id ), $this->enabled_ids(), true );
	}

	/**
	 * Return OAuth scopes required for an ability.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 * @return list<string>
	 */
	public function required_scopes( string $id ): array {
		$definition = $this->definitions()[ $this->internal_id( $id ) ] ?? array();
		$scope      = (string) ( $definition['scope'] ?? 'content:read' );
		return array( $scope );
	}

	/**
	 * Convert a public tool name or legacy alias back to the internal ID.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
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

	/**
	 * Build a client-safe MCP tool name for an internal ability ID.
	 *
	 * @param string $id Internal ID or legacy alias.
	 */
	public function tool_name( string $id ): string {
		$id        = $this->normalize_alias( $id );
		$tool_name = preg_replace( '/[^a-zA-Z0-9_-]+/', '_', $id );
		$tool_name = trim( (string) $tool_name, '_-' );

		if ( '' === $tool_name ) {
			$tool_name = 'aculect_ai_companion_tool';
		}

		$tool_name = substr( $tool_name, 0, 64 );

		return $this->is_valid_tool_name( $tool_name ) ? $tool_name : 'aculect_ai_companion_tool';
	}

	/**
	 * Validate an MCP tool name against the stricter client-safe pattern.
	 *
	 * @param string $name Public MCP tool name.
	 */
	public function is_valid_tool_name( string $name ): bool {
		return 1 === preg_match( self::TOOL_NAME_PATTERN, $name );
	}

	/**
	 * Normalize deprecated ability names to the current internal ID.
	 *
	 * @param string $id Internal ID, legacy alias, or public tool name.
	 */
	public function normalize_alias( string $id ): string {
		$aliases = array(
			'content.create_draft' => 'content.create_item',
			'content_create_draft' => 'content.create_item',
		);

		return $aliases[ $id ] ?? $id;
	}

	/**
	 * Sanitize persisted enabled IDs and drop unknown values.
	 *
	 * @param array $ids Ability IDs or public tool names.
	 * @return list<string>
	 */
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
