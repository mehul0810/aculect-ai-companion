<?php
/**
 * Built-in MCP tool profile definitions and resolution.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Defines deterministic MCP tool profile visibility without expanding policy.
 */
final class McpToolProfiles {

	public const PROFILE_READ_ONLY_AUDIT       = 'read_only_audit';
	public const PROFILE_CONTENT_MANAGEMENT    = 'content_management';
	public const PROFILE_SITE_MANAGEMENT       = 'site_management';
	public const PROFILE_FULL_ACCESS           = 'full_access';
	public const OPTION_GLOBAL_DEFAULT_PROFILE = 'aculect_ai_companion_mcp_tool_profile_default';
	public const OPTION_ROLE_DEFAULT_PROFILES  = 'aculect_ai_companion_mcp_tool_profile_role_defaults';

	/**
	 * Return sanitized profile definitions keyed by profile ID.
	 *
	 * @param AbilitiesRegistry|null $registry Optional ability registry.
	 * @return array<string, array<string, mixed>>
	 */
	public function profiles( ?AbilitiesRegistry $registry = null ): array {
		$registry = $registry ?? new AbilitiesRegistry();
		$profiles = array(
			self::PROFILE_READ_ONLY_AUDIT    => array(
				'id'                => self::PROFILE_READ_ONLY_AUDIT,
				'label'             => 'Read-only audit',
				'description'       => 'Read-only discovery, diagnostics, retrieval, and audit workflows. No write-capable tools are visible.',
				'included_groups'   => array(
					'Site Information',
					'Plugin Lifecycle',
					'Content',
					'Content Groups',
					'Media',
					'Comments',
					'Site Editor Intelligence',
					'Site Structure Discovery',
					'User Access',
					'User Access Discovery',
					'Admin Menu Intelligence',
					'Workflow Guides',
					'Intelligence Index',
					'Activity Learning',
					'Core Schema Discovery',
					'WordPress Actions',
					'Aculect Intelligence',
				),
				'hidden_groups'     => array(
					'Content Workflows',
					'Content Media Workflows',
					'SEO Workflows',
					'Site Workflows',
				),
				'read_only_default' => true,
				'risk_level'        => 'read-only',
			),
			self::PROFILE_CONTENT_MANAGEMENT => array(
				'id'                => self::PROFILE_CONTENT_MANAGEMENT,
				'label'             => 'Content management',
				'description'       => 'Content planning, draft creation, post updates, media application, SEO, and content review workflows.',
				'included_groups'   => array(
					'Content',
					'Content Groups',
					'Content Workflows',
					'Content Media Workflows',
					'SEO Workflows',
					'Media',
					'Comments',
					'Site Information',
					'Site Structure Discovery',
					'Workflow Guides',
					'Intelligence Index',
					'Activity Learning',
					'Core Schema Discovery',
					'Aculect Intelligence',
				),
				'hidden_groups'     => array(
					'Admin Menu Intelligence',
					'Site Editor Intelligence',
					'Site Workflows',
					'User Access',
					'User Access Discovery',
					'WordPress Actions',
				),
				'read_only_default' => false,
				'risk_level'        => 'write',
			),
			self::PROFILE_SITE_MANAGEMENT    => array(
				'id'                => self::PROFILE_SITE_MANAGEMENT,
				'label'             => 'Site management',
				'description'       => 'Site readiness, Site Editor context, admin navigation, user access discovery, and safe management workflows.',
				'included_groups'   => array(
					'Site Information',
					'Site Workflows',
					'Plugin Lifecycle',
					'Site Editor Intelligence',
					'Site Structure Discovery',
					'Admin Menu Intelligence',
					'User Access',
					'User Access Discovery',
					'Workflow Guides',
					'Intelligence Index',
					'Activity Learning',
					'Core Schema Discovery',
					'Aculect Intelligence',
				),
				'hidden_groups'     => array(
					'Content Workflows',
					'Content Media Workflows',
					'SEO Workflows',
					'Media',
					'Comments',
					'WordPress Actions',
				),
				'read_only_default' => false,
				'risk_level'        => 'moderate',
			),
			self::PROFILE_FULL_ACCESS        => array(
				'id'                => self::PROFILE_FULL_ACCESS,
				'label'             => 'Full access',
				'description'       => 'All globally enabled and otherwise authorized MCP tools for the connected WordPress user.',
				'included_groups'   => array(),
				'hidden_groups'     => array(),
				'read_only_default' => false,
				'risk_level'        => 'write',
			),
		);

		$filtered  = apply_filters( 'aculect_ai_companion_mcp_tool_profiles', $profiles, $registry );
		$sanitized = $this->sanitize_profiles( is_array( $filtered ) ? array_merge( $profiles, $filtered ) : $profiles, $registry );

		return array_key_exists( self::PROFILE_READ_ONLY_AUDIT, $sanitized )
			? $sanitized
			: $this->sanitize_profiles( $profiles, $registry );
	}

	/**
	 * Resolve the selected profile from explicit connection, role, global, and fallback sources.
	 *
	 * @param int                    $user_id  WordPress user ID.
	 * @param AbilitiesRegistry|null $registry Optional ability registry.
	 * @param array<string, mixed>   $context  Optional profile selection context.
	 * @return array{id:string,source:string,profile:array<string,mixed>,fallback:bool}
	 */
	public function resolve_for_user( int $user_id, ?AbilitiesRegistry $registry = null, array $context = array() ): array {
		$registry = $registry ?? new AbilitiesRegistry();
		$profiles = $this->profiles( $registry );

		foreach ( $this->resolution_candidates( $user_id, $context ) as $candidate ) {
			$id = $this->sanitize_profile_id( $candidate['id'] );
			if ( '' !== $id && array_key_exists( $id, $profiles ) ) {
				return array(
					'id'       => $id,
					'source'   => $candidate['source'],
					'profile'  => $profiles[ $id ],
					'fallback' => false,
				);
			}
		}

		return array(
			'id'       => self::PROFILE_READ_ONLY_AUDIT,
			'source'   => 'safe_fallback',
			'profile'  => $profiles[ self::PROFILE_READ_ONLY_AUDIT ],
			'fallback' => true,
		);
	}

	/**
	 * Check whether an ability is visible under the selected profile.
	 *
	 * @param string                      $ability_id Ability ID.
	 * @param array<string, mixed>        $profile    Sanitized profile definition.
	 * @param AbilitiesRegistry           $registry   Ability registry.
	 * @param AbilityModuleInterface|null $module Optional module.
	 */
	public function allows_ability( string $ability_id, array $profile, AbilitiesRegistry $registry, ?AbilityModuleInterface $module = null ): bool {
		$module = $module ?? $registry->module( $ability_id );
		if ( null === $module ) {
			return false;
		}

		if ( true === ( $profile['read_only_default'] ?? false ) && ! $module->is_read_only() ) {
			return false;
		}

		$group = $module->group();
		if ( in_array( $group, (array) ( $profile['hidden_groups'] ?? array() ), true ) ) {
			return false;
		}

		$included = (array) ( $profile['included_groups'] ?? array() );
		return array() === $included || in_array( $group, $included, true );
	}

	/**
	 * Return a role default profile ID from explicit context or saved defaults.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $context Profile context.
	 */
	private function role_default_profile_id( int $user_id, array $context ): string {
		$explicit = $this->sanitize_profile_id( $context['role_default_profile'] ?? $context['role_profile'] ?? '' );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		$defaults = get_option( self::OPTION_ROLE_DEFAULT_PROFILES, array() );
		if ( ! is_array( $defaults ) ) {
			return '';
		}

		foreach ( $this->roles_for_user( $user_id ) as $role ) {
			$profile_id = $this->sanitize_profile_id( $defaults[ $role ] ?? '' );
			if ( '' !== $profile_id ) {
				return $profile_id;
			}
		}

		return '';
	}

	/**
	 * Return resolution candidates in deterministic priority order.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $context Profile context.
	 * @return list<array{id:string,source:string}>
	 */
	private function resolution_candidates( int $user_id, array $context ): array {
		return array(
			array(
				'id'     => $this->sanitize_profile_id( $context['connection_profile'] ?? $context['profile'] ?? $context['provider_profile'] ?? '' ),
				'source' => 'connection_override',
			),
			array(
				'id'     => $this->role_default_profile_id( $user_id, $context ),
				'source' => 'role_default',
			),
			array(
				'id'     => $this->sanitize_profile_id( $context['global_default_profile'] ?? get_option( self::OPTION_GLOBAL_DEFAULT_PROFILE, self::PROFILE_FULL_ACCESS ) ),
				'source' => 'global_default',
			),
		);
	}

	/**
	 * Return current user roles.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return list<string>
	 */
	private function roles_for_user( int $user_id ): array {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return array();
		}

		$user = get_userdata( $user_id );
		if ( ! is_object( $user ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strval', (array) $user->roles ) ) );
	}

	/**
	 * Sanitize filtered profile definitions.
	 *
	 * @param array<mixed>      $profiles Profile definitions.
	 * @param AbilitiesRegistry $registry Ability registry.
	 * @return array<string, array<string, mixed>>
	 */
	private function sanitize_profiles( array $profiles, AbilitiesRegistry $registry ): array {
		$known_groups = $this->known_groups( $registry );
		$sanitized    = array();

		foreach ( $profiles as $id => $profile ) {
			if ( ! is_array( $profile ) ) {
				continue;
			}

			$id = $this->sanitize_profile_id( $profile['id'] ?? $id );
			if ( '' === $id ) {
				continue;
			}

			$sanitized[ $id ] = array(
				'id'                => $id,
				'label'             => sanitize_text_field( (string) ( $profile['label'] ?? ucwords( str_replace( '_', ' ', $id ) ) ) ),
				'description'       => sanitize_text_field( (string) ( $profile['description'] ?? '' ) ),
				'included_groups'   => $this->sanitize_groups( $profile['included_groups'] ?? array(), $known_groups ),
				'hidden_groups'     => $this->sanitize_groups( $profile['hidden_groups'] ?? array(), $known_groups ),
				'read_only_default' => true === ( $profile['read_only_default'] ?? false ),
				'risk_level'        => $this->risk_level( $profile['risk_level'] ?? '' ),
			);
		}

		return $sanitized;
	}

	/**
	 * Return known module groups.
	 *
	 * @param AbilitiesRegistry $registry Ability registry.
	 * @return list<string>
	 */
	private function known_groups( AbilitiesRegistry $registry ): array {
		$groups = array();
		foreach ( $registry->modules() as $module ) {
			$group = sanitize_text_field( $module->group() );
			if ( '' !== $group ) {
				$groups[] = $group;
			}
		}

		$groups[] = 'Aculect Intelligence';

		return array_values( array_unique( $groups ) );
	}

	/**
	 * Sanitize a group allow/deny list to known groups only.
	 *
	 * @param mixed $groups       Raw groups.
	 * @param array $known_groups Known groups.
	 * @phpstan-param list<string> $known_groups
	 * @return list<string>
	 */
	private function sanitize_groups( mixed $groups, array $known_groups ): array {
		$sanitized = array();
		foreach ( is_array( $groups ) ? $groups : array() as $group ) {
			if ( ! is_scalar( $group ) ) {
				continue;
			}

			$group = sanitize_text_field( (string) $group );
			if ( in_array( $group, $known_groups, true ) ) {
				$sanitized[] = $group;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Sanitize a profile ID.
	 *
	 * @param mixed $id Raw profile ID.
	 */
	private function sanitize_profile_id( mixed $id ): string {
		return is_scalar( $id ) ? sanitize_key( (string) $id ) : '';
	}

	/**
	 * Normalize risk level labels.
	 *
	 * @param mixed $risk_level Raw risk level.
	 */
	private function risk_level( mixed $risk_level ): string {
		$risk_level = is_scalar( $risk_level ) ? sanitize_key( (string) $risk_level ) : '';

		return in_array( $risk_level, array( 'read-only', 'moderate', 'write', 'high' ), true ) ? $risk_level : 'moderate';
	}
}
