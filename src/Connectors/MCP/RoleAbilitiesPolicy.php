<?php
/**
 * Role-based ability policy.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Stores per-role ability overrides while preserving global ability policy.
 */
final class RoleAbilitiesPolicy {

	public const OPTION_ROLE_ABILITIES = 'aculect_ai_companion_role_abilities';

	/**
	 * Return admin-facing role policy data.
	 *
	 * @param AbilitiesRegistry $registry             Ability registry.
	 * @param bool              $include_user_samples Whether to include sample affected users.
	 * @return array<string, mixed>
	 */
	public function admin_payload( AbilitiesRegistry $registry, bool $include_user_samples = true ): array {
		$roles       = array();
		$user_counts = $this->role_user_counts();

		foreach ( $this->registered_roles() as $slug => $role ) {
			$allowed_ids = $this->allowed_ids_for_role( (string) $slug, $registry );
			$roles[]     = array(
				'id'           => (string) $slug,
				'label'        => translate_user_role( (string) ( $role['name'] ?? $slug ) ),
				'userCount'    => $user_counts[ (string) $slug ] ?? 0,
				'users'        => $include_user_samples ? $this->role_user_samples( (string) $slug ) : array(),
				'explicit'     => $this->has_explicit_policy( (string) $slug ),
				'allowedIds'   => $allowed_ids,
				'enabledCount' => count( $allowed_ids ),
			);
		}

		return array(
			'roles'             => $roles,
			'globalEnabledIds'  => $registry->enabled_ids(),
			'defaultPolicyName' => 'Global ability policy',
		);
	}

	/**
	 * Return ability IDs allowed for a role.
	 *
	 * @param string            $role     Role slug.
	 * @param AbilitiesRegistry $registry Ability registry.
	 * @return list<string>
	 */
	public function allowed_ids_for_role( string $role, AbilitiesRegistry $registry ): array {
		$role           = $this->sanitize_role( $role );
		$global_enabled = $registry->enabled_ids();
		if ( '' === $role ) {
			return $global_enabled;
		}

		$policies = $this->policies( $registry );
		if ( ! array_key_exists( $role, $policies ) ) {
			return $global_enabled;
		}

		return array_values( array_intersect( $policies[ $role ], $global_enabled ) );
	}

	/**
	 * Return ability IDs allowed for a user based on every assigned role.
	 *
	 * @param int               $user_id  WordPress user ID.
	 * @param AbilitiesRegistry $registry Ability registry.
	 * @return list<string>
	 */
	public function allowed_ids_for_user( int $user_id, AbilitiesRegistry $registry ): array {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return $registry->enabled_ids();
		}

		$user  = get_userdata( $user_id );
		$roles = (array) ( $user->roles ?? array() );
		if ( array() === $roles ) {
			return $registry->enabled_ids();
		}

		$allowed = array();
		foreach ( $roles as $role ) {
			$allowed = array_merge( $allowed, $this->allowed_ids_for_role( (string) $role, $registry ) );
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Return enabled modules allowed for a specific user.
	 *
	 * @param int               $user_id  WordPress user ID.
	 * @param AbilitiesRegistry $registry Ability registry.
	 * @return array<string, AbilityModuleInterface>
	 */
	public function enabled_modules_for_user( int $user_id, AbilitiesRegistry $registry ): array {
		return array_intersect_key(
			$registry->enabled_modules(),
			array_flip( $this->allowed_ids_for_user( $user_id, $registry ) )
		);
	}

	/**
	 * Check whether a user can access an ability.
	 *
	 * @param string            $ability_id Ability ID or tool name.
	 * @param int               $user_id    WordPress user ID.
	 * @param AbilitiesRegistry $registry   Ability registry.
	 */
	public function is_allowed_for_user( string $ability_id, int $user_id, AbilitiesRegistry $registry ): bool {
		$ability_id = $registry->internal_id( $ability_id );

		return in_array( $ability_id, $this->allowed_ids_for_user( $user_id, $registry ), true );
	}

	/**
	 * Save explicit role policy.
	 *
	 * @param string            $role        Role slug.
	 * @param array<mixed>      $ability_ids Ability IDs or tool names.
	 * @param AbilitiesRegistry $registry    Ability registry.
	 */
	public function save_role_policy( string $role, array $ability_ids, AbilitiesRegistry $registry ): bool {
		$role = $this->sanitize_role( $role );
		if ( '' === $role ) {
			return false;
		}

		$policies          = $this->policies( $registry );
		$policies[ $role ] = $this->sanitize_ability_ids( $ability_ids, $registry );
		update_option( self::OPTION_ROLE_ABILITIES, $policies, false );

		return true;
	}

	/**
	 * Copy one role policy to another.
	 *
	 * @param string            $from_role Source role.
	 * @param string            $to_role   Destination role.
	 * @param AbilitiesRegistry $registry  Ability registry.
	 */
	public function copy_role_policy( string $from_role, string $to_role, AbilitiesRegistry $registry ): bool {
		$from_role = $this->sanitize_role( $from_role );
		$to_role   = $this->sanitize_role( $to_role );
		if ( '' === $from_role || '' === $to_role || $from_role === $to_role ) {
			return false;
		}

		return $this->save_role_policy( $to_role, $this->allowed_ids_for_role( $from_role, $registry ), $registry );
	}

	/**
	 * Reset one role to inherited global policy.
	 *
	 * @param string            $role     Role slug.
	 * @param AbilitiesRegistry $registry Ability registry.
	 */
	public function reset_role_policy( string $role, AbilitiesRegistry $registry ): bool {
		$role = $this->sanitize_role( $role );
		if ( '' === $role ) {
			return false;
		}

		$policies = $this->policies( $registry );
		unset( $policies[ $role ] );
		update_option( self::OPTION_ROLE_ABILITIES, $policies, false );

		return true;
	}

	/**
	 * Delete stored policy.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_ROLE_ABILITIES );
	}

	/**
	 * Check whether a role has an explicit override.
	 *
	 * @param string $role Role slug.
	 */
	public function has_explicit_policy( string $role ): bool {
		$role = $this->sanitize_role( $role );
		if ( '' === $role ) {
			return false;
		}

		$stored = get_option( self::OPTION_ROLE_ABILITIES, array() );
		return is_array( $stored ) && array_key_exists( $role, $stored );
	}

	/**
	 * Return sanitized stored policies.
	 *
	 * @param AbilitiesRegistry $registry Ability registry.
	 * @return array<string, list<string>>
	 */
	private function policies( AbilitiesRegistry $registry ): array {
		$stored = get_option( self::OPTION_ROLE_ABILITIES, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$policies = array();
		foreach ( $stored as $role => $ability_ids ) {
			$role = $this->sanitize_role( (string) $role );
			if ( '' === $role || ! is_array( $ability_ids ) ) {
				continue;
			}

			$policies[ $role ] = $this->sanitize_ability_ids( $ability_ids, $registry );
		}

		return $policies;
	}

	/**
	 * Sanitize ability IDs against the registry.
	 *
	 * @param array<mixed>      $ability_ids Raw ability IDs.
	 * @param AbilitiesRegistry $registry    Ability registry.
	 * @return list<string>
	 */
	private function sanitize_ability_ids( array $ability_ids, AbilitiesRegistry $registry ): array {
		$sanitized = array();
		foreach ( $ability_ids as $ability_id ) {
			if ( ! is_scalar( $ability_id ) ) {
				continue;
			}

			$ability_id = $registry->internal_id( sanitize_text_field( (string) $ability_id ) );
			if ( $registry->is_known( $ability_id ) ) {
				$sanitized[] = $ability_id;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Sanitize and validate a role slug.
	 *
	 * @param string $role Role slug.
	 */
	private function sanitize_role( string $role ): string {
		$role = sanitize_key( $role );
		return array_key_exists( $role, $this->registered_roles() ) ? $role : '';
	}

	/**
	 * Return registered roles.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function registered_roles(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}

		$wp_roles = wp_roles();
		return $wp_roles->roles;
	}

	/**
	 * Return user counts keyed by role.
	 *
	 * @return array<string, int>
	 */
	private function role_user_counts(): array {
		if ( ! function_exists( 'count_users' ) ) {
			return array();
		}

		$counts = count_users();
		$roles  = array();
		foreach ( $counts['avail_roles'] as $role => $count ) {
			if ( ! is_scalar( $role ) ) {
				continue;
			}

			$roles[ (string) $role ] = absint( $count );
		}

		return $roles;
	}

	/**
	 * Return a minimal affected-user sample for the admin UI.
	 *
	 * @param string $role Role slug.
	 * @return list<array<string, string|int>>
	 */
	private function role_user_samples( string $role ): array {
		if ( ! function_exists( 'get_users' ) ) {
			return array();
		}

		$users = get_users(
			array(
				'role'    => $role,
				'number'  => 8,
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name', 'user_login' ),
			)
		);

		$samples = array();
		foreach ( $users as $user ) {
			if ( ! is_object( $user ) ) {
				continue;
			}

			$samples[] = array(
				'id'    => (int) ( $user->ID ?? 0 ),
				'label' => sanitize_text_field( (string) ( $user->display_name ?? $user->user_login ?? '' ) ),
			);
		}

		return $samples;
	}
}
