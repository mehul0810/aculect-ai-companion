<?php
/**
 * Workflow-level role access policy.
 *
 * @package Aculect\AICompanion\Workflows\Authorization
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Authorization;

/**
 * Resolves the optional role allowlist attached to one workflow version.
 *
 * An empty allowlist means that the workflow inherits the existing Aculect
 * ability policy. A non-empty allowlist narrows discovery and execution to
 * users with one of the selected roles; administrators still pass through
 * the normal ability, capability, scope, and approval checks.
 */
final class WorkflowRoleAccessPolicy {

	private const ADMINISTRATOR = 'administrator';
	private const MAX_ROLES     = 20;

	/**
	 * Return registered non-administrator roles for the admin selector.
	 *
	 * @return list<array{id:string,label:string}>
	 */
	public function available_roles(): array {
		$roles = array();
		foreach ( $this->registered_roles() as $slug => $role ) {
			if ( ! is_array( $role ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $slug );
			if ( '' === $slug || self::ADMINISTRATOR === $slug ) {
				continue;
			}
			$roles[] = array(
				'id'    => $slug,
				'label' => translate_user_role( (string) ( $role['name'] ?? $slug ) ),
			);
		}

		usort( $roles, static fn ( array $left, array $right ): int => $left['id'] <=> $right['id'] );

		return $roles;
	}

	/**
	 * Normalize and validate a workflow role allowlist.
	 *
	 * @param mixed $value Candidate role slugs.
	 * @return list<string>
	 */
	public function normalize( mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX_ROLES ) {
			return array();
		}

		$known = array_fill_keys( array_column( $this->available_roles(), 'id' ), true );
		$roles = array();
		foreach ( $value as $role ) {
			if ( ! is_scalar( $role ) ) {
				continue;
			}
			$role = sanitize_key( (string) $role );
			if ( '' === $role || self::ADMINISTRATOR === $role || ! isset( $known[ $role ] ) ) {
				continue;
			}
			$roles[ $role ] = true;
		}

		$roles = array_keys( $roles );
		sort( $roles, SORT_STRING );

		return array_values( $roles );
	}

	/**
	 * Check whether authenticated context may use a workflow version.
	 *
	 * @param array<int,string>    $allowed_roles Stored, normalized allowlist.
	 * @phpstan-param list<string> $allowed_roles
	 * @param array<string, mixed> $auth          Authenticated context.
	 */
	public function is_allowed( array $allowed_roles, array $auth ): bool {
		if ( array() === $allowed_roles ) {
			return true;
		}

		$known = array_fill_keys( array_column( $this->available_roles(), 'id' ), true );
		foreach ( $allowed_roles as $allowed_role ) {
			if ( ! is_string( $allowed_role ) || ! isset( $known[ $allowed_role ] ) ) {
				return false;
			}
		}

		$roles = array();
		if ( isset( $auth['roles'] ) && is_array( $auth['roles'] ) ) {
			$roles = $this->role_slugs( $auth['roles'] );
		} elseif ( function_exists( 'get_userdata' ) ) {
			$user = get_userdata( (int) ( $auth['user_id'] ?? 0 ) );
			if ( is_object( $user ) ) {
				$roles = $this->role_slugs( (array) $user->roles );
			}
		}

		if ( in_array( self::ADMINISTRATOR, $roles, true ) ) {
			return true;
		}

		return array() !== array_intersect( $allowed_roles, $roles );
	}

	/**
	 * Return whether role input contains only registered, bounded roles.
	 *
	 * @param mixed $value Candidate role slugs.
	 */
	public function is_valid( mixed $value ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX_ROLES ) {
			return false;
		}

		$known = array_fill_keys( array_column( $this->available_roles(), 'id' ), true );
		$roles = array();
		foreach ( $value as $role ) {
			if ( ! is_scalar( $role ) ) {
				return false;
			}
			$role = sanitize_key( (string) $role );
			if ( '' === $role || self::ADMINISTRATOR === $role || ! isset( $known[ $role ] ) ) {
				return false;
			}
			$roles[] = $role;
		}

		return $this->normalize( $roles ) === $this->sorted_scalar_roles( $roles );
	}

	/**
	 * Return registered WordPress roles.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function registered_roles(): array {
		if ( ! function_exists( 'wp_roles' ) ) {
			return array();
		}

		return (array) wp_roles()->roles;
	}

	/**
	 * Return safe role slugs from an untrusted role list.
	 *
	 * @param mixed $value Candidate role list.
	 * @return list<string>
	 */
	private function role_slugs( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$roles = array();
		foreach ( $value as $role ) {
			if ( ! is_scalar( $role ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $role );
			if ( '' !== $slug ) {
				$roles[ $slug ] = true;
			}
		}

		return array_keys( $roles );
	}

	/**
	 * Return the deterministic scalar projection used by validation.
	 *
	 * @param mixed $value Candidate role slugs.
	 * @return list<string>
	 */
	private function sorted_scalar_roles( mixed $value ): array {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_ROLES ) {
			return array( '__invalid__' );
		}

		$roles = array();
		foreach ( $value as $role ) {
			if ( ! is_scalar( $role ) ) {
				return array( '__invalid__' );
			}
			$roles[] = sanitize_key( (string) $role );
		}
		$roles = array_values( array_unique( $roles ) );
		sort( $roles, SORT_STRING );

		return array_values( array_filter( $roles, static fn ( string $role ): bool => '' !== $role && self::ADMINISTRATOR !== $role ) );
	}
}
