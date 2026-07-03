<?php
/**
 * Read-only user, role, and capability discovery abilities.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Builds bounded, privacy-minimized user and role diagnostics.
 */
final class UserRoleCapabilitiesDiscovery extends AbstractAbilityService {

	private const MAX_PER_PAGE     = 50;
	private const DEFAULT_PER_PAGE = 20;
	private const MAX_ROLES        = 100;

	private const SITE_CAPABILITIES = array(
		'read',
		'edit_posts',
		'publish_posts',
		'upload_files',
		'moderate_comments',
		'edit_theme_options',
		'manage_options',
		'list_users',
		'promote_users',
		'activate_plugins',
		'switch_themes',
	);

	private const ROLE_CAPABILITY_CATEGORIES = array(
		'content'     => array(
			'can_read'         => 'read',
			'can_edit_posts'   => 'edit_posts',
			'can_publish'      => 'publish_posts',
			'can_upload_media' => 'upload_files',
			'can_moderate'     => 'moderate_comments',
		),
		'site_admin'  => array(
			'can_manage_options'   => 'manage_options',
			'can_edit_theme'       => 'edit_theme_options',
			'can_activate_plugins' => 'activate_plugins',
			'can_switch_themes'    => 'switch_themes',
		),
		'user_access' => array(
			'can_list_users'    => 'list_users',
			'can_promote_users' => 'promote_users',
			'can_create_users'  => 'create_users',
			'can_delete_users'  => 'delete_users',
		),
	);

	/**
	 * Return the connected WordPress user's safe access summary.
	 *
	 * @return array<string, mixed>
	 */
	public function current_access_summary(): array {
		$user_id = get_current_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;

		if ( ! $user ) {
			return array(
				'user_id'             => $user_id,
				'roles'               => array(),
				'capabilities'        => $this->current_user_capabilities(),
				'available'           => false,
				'blocked_unavailable' => array( 'missing_connected_user' ),
				'read_only'           => true,
				'privacy'             => $this->privacy_notice(),
			);
		}

		$roles = $this->safe_roles_from_user( $user );

		return array(
			'user_id'             => $user_id,
			'roles'               => $roles,
			'capabilities'        => $this->current_user_capabilities(),
			'available'           => true,
			'blocked_unavailable' => $this->current_user_blocked_reasons( $roles ),
			'read_only'           => true,
			'privacy'             => $this->privacy_notice(),
		);
	}

	/**
	 * Return a bounded privileged role summary.
	 *
	 * @return array<string, mixed>
	 */
	public function roles_summary(): array {
		if ( ! current_user_can( 'promote_users' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect role and capability summaries.' );
		}

		$roles       = $this->roles();
		$user_counts = $this->role_user_counts();
		$items       = array();

		foreach ( $roles as $slug => $role ) {
			$capabilities = is_array( $role['capabilities'] ?? null ) ? $role['capabilities'] : array();
			$items[]      = array(
				'slug'                  => sanitize_key( (string) $slug ),
				'label'                 => translate_user_role( (string) ( $role['name'] ?? $slug ) ),
				'user_count'            => (int) ( $user_counts[ (string) $slug ] ?? 0 ),
				'capability_categories' => $this->role_capability_categories( $capabilities ),
			);

			if ( count( $items ) >= self::MAX_ROLES ) {
				break;
			}
		}

		return array(
			'items'               => $items,
			'total'               => count( $roles ),
			'returned'            => count( $items ),
			'bounded'             => true,
			'max_roles'           => self::MAX_ROLES,
			'read_only'           => true,
			'required_capability' => 'promote_users',
			'privacy'             => $this->privacy_notice(),
		);
	}

	/**
	 * Return a capped, safe user list.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_safe_users( array $args ): array {
		if ( ! current_user_can( 'list_users' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to list users.' );
		}

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
		$role     = sanitize_key( (string) ( $args['role'] ?? '' ) );
		$query    = array(
			'number' => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
			'fields' => array( 'ID', 'display_name', 'roles' ),
		);

		if ( '' !== $role ) {
			$query['role'] = $role;
		}

		$users = array_values( get_users( $query ) );

		return array(
			'items'               => array_map( array( $this, 'map_safe_user' ), $users ),
			'page'                => $page,
			'per_page'            => $per_page,
			'bounded'             => true,
			'read_only'           => true,
			'role_filter'         => '' === $role ? null : $role,
			'required_capability' => 'list_users',
			'privacy'             => $this->privacy_notice(),
		);
	}

	/**
	 * Return curated current-user capability booleans relevant to Aculect operations.
	 *
	 * @return array<string, bool>
	 */
	private function current_user_capabilities(): array {
		$capabilities = array();
		foreach ( self::SITE_CAPABILITIES as $capability ) {
			$capabilities[ $capability ] = current_user_can( $capability );
		}

		return $capabilities;
	}

	/**
	 * Return blocked/unavailable reasons for common Aculect operation families.
	 *
	 * @param string[] $roles Current user role slugs.
	 * @return list<string>
	 */
	private function current_user_blocked_reasons( array $roles ): array {
		$reasons = array();
		if ( array() === $roles ) {
			$reasons[] = 'no_roles_assigned';
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			$reasons[] = 'media_upload_unavailable';
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			$reasons[] = 'site_editor_unavailable';
		}
		if ( ! current_user_can( 'list_users' ) ) {
			$reasons[] = 'user_inventory_unavailable';
		}
		if ( ! current_user_can( 'promote_users' ) ) {
			$reasons[] = 'role_summary_unavailable';
		}

		return $reasons;
	}

	/**
	 * Return role definitions from WordPress.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function roles(): array {
		$roles = wp_roles()->roles;

		return array_map( 'array_filter', $roles );
	}

	/**
	 * Return user counts by role.
	 *
	 * @return array<string, int>
	 */
	private function role_user_counts(): array {
		$counts = function_exists( 'count_users' ) ? count_users() : array();
		$roles  = is_array( $counts['avail_roles'] ?? null ) ? $counts['avail_roles'] : array();

		return array_map( 'intval', $roles );
	}

	/**
	 * Convert a role capability map into curated category booleans.
	 *
	 * @param array<string, mixed> $capabilities Raw role capability map.
	 * @return array<string, array<string, bool>>
	 */
	private function role_capability_categories( array $capabilities ): array {
		$categories = array();
		foreach ( self::ROLE_CAPABILITY_CATEGORIES as $category => $map ) {
			$categories[ $category ] = array();
			foreach ( $map as $label => $capability ) {
				$categories[ $category ][ $label ] = ! empty( $capabilities[ $capability ] );
			}
		}

		return $categories;
	}

	/**
	 * Map a user object to privacy-safe fields only.
	 *
	 * @param mixed $user User object.
	 * @return array<string, mixed>
	 */
	private function map_safe_user( mixed $user ): array {
		return array(
			'id'           => (int) ( is_object( $user ) ? ( $user->ID ?? 0 ) : 0 ),
			'display_name' => sanitize_text_field( (string) ( is_object( $user ) ? ( $user->display_name ?? '' ) : '' ) ),
			'roles'        => is_object( $user ) ? $this->safe_roles_from_user( $user ) : array(),
		);
	}

	/**
	 * Return sanitized role slugs from a user-like object.
	 *
	 * @param object $user User-like object.
	 * @return list<string>
	 */
	private function safe_roles_from_user( object $user ): array {
		return array_values(
			array_filter(
				array_map( 'sanitize_key', array_map( 'strval', (array) ( $user->roles ?? array() ) ) )
			)
		);
	}

	/**
	 * Return the privacy contract repeated in responses.
	 *
	 * @return array<string, mixed>
	 */
	private function privacy_notice(): array {
		return array(
			'safe_fields_only' => true,
			'excluded_fields'  => array( 'email', 'application_passwords', 'sessions', 'tokens', 'reset_keys', 'raw_user_meta', 'private_user_meta', 'password_hashes', 'allcaps', 'raw_caps', 'network_inventory' ),
		);
	}
}
