<?php
/**
 * Shared MCP tool availability policy.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Keeps MCP discovery, diagnostics, and intelligence guidance on one policy source.
 */
final class McpToolAvailability {

	/**
	 * OAuth scopes granted to the active MCP request, when known.
	 *
	 * @var list<string>|null
	 */
	private static ?array $current_granted_scopes = null;

	/**
	 * Set request-local OAuth scopes for intelligence context generation.
	 *
	 * @param array<mixed>|null $scopes Granted OAuth scopes, or null when unknown.
	 */
	public static function set_current_granted_scopes( ?array $scopes ): void {
		self::$current_granted_scopes = null === $scopes ? null : self::normalize_scope_list( $scopes );
	}

	/**
	 * Return enabled operation modules followed by always-on intelligence modules.
	 *
	 * @param int                       $user_id      WordPress user ID.
	 * @param AbilitiesRegistry|null    $registry     Optional ability registry.
	 * @param IntelligenceRegistry|null $intelligence Optional intelligence registry.
	 * @param array<mixed>|null         $granted_scopes Optional granted OAuth scopes.
	 * @return array<string, AbilityModuleInterface>
	 */
	public function tool_modules_for_user( int $user_id, ?AbilitiesRegistry $registry = null, ?IntelligenceRegistry $intelligence = null, ?array $granted_scopes = null ): array {
		$registry     = $registry ?? new AbilitiesRegistry();
		$intelligence = $intelligence ?? new IntelligenceRegistry();
		$scopes       = null === $granted_scopes ? null : self::normalize_scope_list( $granted_scopes );

		return array_merge(
			$this->ability_modules_for_user( $user_id, $registry, $scopes ),
			$this->scope_filtered_modules( $intelligence->modules(), $scopes )
		);
	}

	/**
	 * Return enabled operation modules for one user.
	 *
	 * @param int                    $user_id  WordPress user ID.
	 * @param AbilitiesRegistry|null $registry Optional ability registry.
	 * @param array<mixed>|null      $granted_scopes Optional granted OAuth scopes.
	 * @return array<string, AbilityModuleInterface>
	 */
	public function ability_modules_for_user( int $user_id, ?AbilitiesRegistry $registry = null, ?array $granted_scopes = null ): array {
		$registry = $registry ?? new AbilitiesRegistry();
		$scopes   = null === $granted_scopes ? null : self::normalize_scope_list( $granted_scopes );

		$modules        = ( new RoleAbilitiesPolicy() )->enabled_modules_for_user( $user_id, $registry ) + $registry->derived_workflow_modules() + $registry->always_on_read_intelligence_modules();
		$policy         = $this->ability_policy_for_user( $user_id, $registry, $scopes );
		$global_enabled = (array) ( $policy['global_enabled_ids'] ?? array() );
		$role_allowed   = (array) ( $policy['role_allowed_ids'] ?? array() );

		return array_filter(
			$modules,
			fn ( AbilityModuleInterface $module ): bool => $this->module_allowed( $module->id(), $global_enabled, $role_allowed, $registry )
				&& $this->dependencies_available( $module->id(), $global_enabled, $role_allowed, $registry, $scopes )
				&& $this->scopes_available( $module->required_scopes(), $scopes )
		);
	}

	/**
	 * Return policy details that explain why tools are or are not exposed.
	 *
	 * @param int                    $user_id  WordPress user ID.
	 * @param AbilitiesRegistry|null $registry Optional ability registry.
	 * @param array<mixed>|null      $granted_scopes Optional granted OAuth scopes.
	 * @return array<string, mixed>
	 */
	public function ability_policy_for_user( int $user_id, ?AbilitiesRegistry $registry = null, ?array $granted_scopes = null ): array {
		$registry       = $registry ?? new AbilitiesRegistry();
		$scopes         = null === $granted_scopes ? null : self::normalize_scope_list( $granted_scopes );
		$policy         = new RoleAbilitiesPolicy();
		$all_ids        = array_keys( $registry->definitions() );
		$configurable   = array_values( array_filter( $all_ids, array( $registry, 'is_configurable' ) ) );
		$global_enabled = $registry->enabled_ids();
		$role_allowed   = $policy->allowed_ids_for_user( $user_id, $registry );
		$exposed        = array_values(
			array_filter(
				$all_ids,
				fn ( string $ability_id ): bool => $this->module_allowed( $ability_id, $global_enabled, $role_allowed, $registry )
					&& $this->dependencies_available( $ability_id, $global_enabled, $role_allowed, $registry, $scopes )
					&& $this->scopes_available( $registry->required_scopes( $ability_id ), $scopes )
			)
		);
		$roles          = $this->roles_for_user( $user_id );
		$user_state     = $this->user_policy_state( $user_id, $roles );

		$explicit_role_policy = count(
			array_filter(
				$roles,
				static fn ( string $role ): bool => $policy->has_explicit_policy( $role )
			)
		) > 0;
		if ( 'default_read_only' === $user_state && $explicit_role_policy ) {
			$user_state = 'explicit_role_policy';
		}
		$default_read_only_policy = 'default_read_only' === $user_state && ! $explicit_role_policy;

		return array(
			'user_id'                   => $user_id,
			'user_roles'                => $roles,
			'user_policy_state'         => $user_state,
			'global_enabled_count'      => count( $global_enabled ),
			'role_allowed_count'        => count( $role_allowed ),
			'exposed_ability_count'     => count( $exposed ),
			'all_ability_count'         => count( $all_ids ),
			'global_enabled_ids'        => array_values( $global_enabled ),
			'role_allowed_ids'          => array_values( $role_allowed ),
			'exposed_ability_ids'       => $exposed,
			'blocked_by_global_ids'     => array_values( array_diff( $configurable, $global_enabled ) ),
			'blocked_by_role_ids'       => array_values( array_diff( $global_enabled, $role_allowed ) ),
			'explicit_role_policy'      => $explicit_role_policy,
			'default_read_only_policy'  => $default_read_only_policy,
			'missing_user'              => 'missing_user' === $user_state,
			'missing_role'              => 'missing_role' === $user_state,
			'operation_tool_names'      => array_values( array_map( array( $registry, 'tool_name' ), $exposed ) ),
			'global_enabled_tool_names' => array_values( array_map( array( $registry, 'tool_name' ), $global_enabled ) ),
			'scope_aware'               => null !== $scopes,
			'granted_scopes'            => null === $scopes ? array() : $scopes,
		);
	}

	/**
	 * Return policy details for the current WordPress user.
	 *
	 * @param AbilitiesRegistry|null $registry Optional ability registry.
	 * @return array<string, mixed>
	 */
	public function ability_policy_for_current_user( ?AbilitiesRegistry $registry = null ): array {
		return $this->ability_policy_for_user( $this->current_user_id(), $registry, self::$current_granted_scopes );
	}

	/**
	 * Return a session-aware operation manifest for intelligence tools.
	 *
	 * @param int                    $user_id  WordPress user ID.
	 * @param AbilitiesRegistry|null $registry Optional ability registry.
	 * @param array<mixed>|null      $granted_scopes Optional granted OAuth scopes.
	 * @return array<string, mixed>
	 */
	public function operations_manifest_for_user( int $user_id, ?AbilitiesRegistry $registry = null, ?array $granted_scopes = null ): array {
		$registry = $registry ?? new AbilitiesRegistry();
		$policy   = $this->ability_policy_for_user( $user_id, $registry, $granted_scopes );

		return array(
			'description'        => 'Exact MCP operational tool names and availability for the connected WordPress user. Choose only available tools; blocked_by explains why an operation is unavailable (global_disabled, role_policy, role_default_read_only, missing_user, missing_role, or oauth_scope) before assuming WordPress data or permissions are missing.',
			'policy'             => array(
				'user_id'                  => $policy['user_id'],
				'user_roles'               => $policy['user_roles'],
				'user_policy_state'        => $policy['user_policy_state'],
				'exposed_ability_count'    => $policy['exposed_ability_count'],
				'all_ability_count'        => $policy['all_ability_count'],
				'explicit_role_policy'     => $policy['explicit_role_policy'],
				'default_read_only_policy' => $policy['default_read_only_policy'],
				'scope_aware'              => $policy['scope_aware'],
				'granted_scopes'           => $policy['granted_scopes'],
				'missing_user'             => $policy['missing_user'],
				'missing_role'             => $policy['missing_role'],
			),
			'site_information'   => $this->operation_group(
				array(
					'get_info'     => 'site.get_info',
					'get_health'   => 'site.get_health',
					'list_plugins' => 'site.list_plugins',
					'list_themes'  => 'site.list_themes',
				),
				$policy,
				$registry
			),
			'content'            => $this->operation_group(
				array(
					'list_types' => 'site.list_post_types',
					'list_items' => 'content.list_items',
					'get_item'   => 'content.get_item',
					'create'     => 'content.create_item',
					'update'     => 'content.update_item',
					'seo'        => 'content.update_seo',
				),
				$policy,
				$registry
			),
			'workflows'          => $this->operation_group(
				array(
					'prepare_post'        => 'content_workflow.prepare_post',
					'create_draft'        => 'content_workflow.create_draft',
					'update_post'         => 'content_workflow.update_post',
					'update_rankmath_seo' => 'seo_workflow.update_rankmath',
				),
				$policy,
				$registry
			),
			'intelligence_index' => $this->operation_group(
				array(
					'refresh_batch'  => 'content_index.refresh_batch',
					'search_items'   => 'content_search.items',
					'search_chunks'  => 'content_search.chunks',
					'find_related'   => 'content_find.related',
					'internal_links' => 'content_find.internal_links',
					'memory_list'    => 'memory.list',
					'memory_save'    => 'memory.save',
					'batch_status'   => 'content_batch.status',
				),
				$policy,
				$registry
			),
			'content_groups'     => $this->operation_group(
				array(
					'list_taxonomies' => 'taxonomy.list_taxonomies',
					'list_terms'      => 'taxonomy.list_terms',
					'create_term'     => 'taxonomy.create_term',
					'update_term'     => 'taxonomy.update_term',
					'set_term_image'  => 'taxonomy.set_term_image',
				),
				$policy,
				$registry
			),
			'media'              => $this->operation_group(
				array(
					'list'   => 'media.list_items',
					'get'    => 'media.get_item',
					'upload' => 'media.upload_item',
					'update' => 'media.update_item',
					'trash'  => 'media.delete_item',
					'rename' => 'media.rename_file',
				),
				$policy,
				$registry
			),
			'comments'           => $this->operation_group(
				array(
					'list'        => 'comments.list_items',
					'get'         => 'comments.get_item',
					'reply'       => 'comments.create_item',
					'moderate'    => 'comments.update_item',
					'bulk_update' => 'comments.bulk_update',
				),
				$policy,
				$registry
			),
			'actions'            => $this->operation_group(
				array(
					'discover' => 'wp_abilities.discover',
					'inspect'  => 'wp_abilities.get_info',
					'run'      => 'wp_abilities.run',
				),
				$policy,
				$registry
			),
		);
	}

	/**
	 * Return a session-aware operation manifest for the current WordPress user.
	 *
	 * @param AbilitiesRegistry|null $registry Optional ability registry.
	 * @return array<string, mixed>
	 */
	public function operations_manifest_for_current_user( ?AbilitiesRegistry $registry = null ): array {
		return $this->operations_manifest_for_user( $this->current_user_id(), $registry, self::$current_granted_scopes );
	}

	/**
	 * Build one named operation group.
	 *
	 * @param array<string, string> $abilities Operation key to internal ability ID.
	 * @param array<string, mixed>  $policy    Ability policy details.
	 * @param AbilitiesRegistry     $registry  Ability registry.
	 * @return array<string, array<string, mixed>>
	 */
	private function operation_group( array $abilities, array $policy, AbilitiesRegistry $registry ): array {
		$group = array();
		foreach ( $abilities as $operation => $ability_id ) {
			$group[ $operation ] = $this->operation_entry( $ability_id, $policy, $registry );
		}

		return $group;
	}

	/**
	 * Build one operation availability entry.
	 *
	 * @param string               $ability_id Internal ability ID.
	 * @param array<string, mixed> $policy     Ability policy details.
	 * @param AbilitiesRegistry    $registry   Ability registry.
	 * @return array<string, mixed>
	 */
	private function operation_entry( string $ability_id, array $policy, AbilitiesRegistry $registry ): array {
		$global_enabled       = (array) ( $policy['global_enabled_ids'] ?? array() );
		$role_allowed         = (array) ( $policy['role_allowed_ids'] ?? array() );
		$exposed              = (array) ( $policy['exposed_ability_ids'] ?? array() );
		$scope_aware          = true === ( $policy['scope_aware'] ?? false );
		$granted_scopes       = self::normalize_scope_list( (array) ( $policy['granted_scopes'] ?? array() ) );
		$module               = $registry->module( $ability_id );
		$required_scopes      = null === $module ? $registry->required_scopes( $ability_id ) : $module->required_scopes();
		$is_read_only         = null === $module ? $registry->is_read_only( $ability_id ) : $module->is_read_only();
		$is_derived_workflow  = $registry->is_derived_workflow( $ability_id );
		$is_always_on         = $registry->is_always_on_read_intelligence( $ability_id );
		$blocked_by           = '';
		$blocked_dependencies = array();
		$missing_scopes       = array();

		if ( ! $is_derived_workflow && ! $is_always_on && ! in_array( $ability_id, $global_enabled, true ) ) {
			$blocked_by = 'global_disabled';
		} elseif ( ! $is_derived_workflow && ! $is_always_on && ! in_array( $ability_id, $role_allowed, true ) ) {
			$blocked_by = $this->role_block_reason( $policy, $module );
		} elseif ( $scope_aware && ! $this->scopes_available( $required_scopes, $granted_scopes ) ) {
			$blocked_by     = 'oauth_scope';
			$missing_scopes = $this->missing_scopes( $required_scopes, $granted_scopes );
		} else {
			foreach ( $registry->dependency_ids( $ability_id ) as $dependency_id ) {
				$dependency = $registry->module( $dependency_id );

				if ( ! in_array( $dependency_id, $global_enabled, true ) ) {
					$blocked_by             = 'global_disabled';
					$blocked_dependencies[] = $dependency_id;
				} elseif ( ! in_array( $dependency_id, $role_allowed, true ) ) {
					$blocked_by             = $this->role_block_reason( $policy, $dependency );
					$blocked_dependencies[] = $dependency_id;
				} elseif ( $scope_aware ) {
					$dependency_scopes = null === $dependency ? $registry->required_scopes( $dependency_id ) : $dependency->required_scopes();
					if ( ! $this->scopes_available( $dependency_scopes, $granted_scopes ) ) {
						$blocked_by             = 'oauth_scope';
						$blocked_dependencies[] = $dependency_id;
						$missing_scopes         = array_merge( $missing_scopes, $this->missing_scopes( $dependency_scopes, $granted_scopes ) );
					}
				}
			}
		}

		$available = in_array( $ability_id, $exposed, true ) && '' === $blocked_by;

		$entry = array(
			'tool'            => $registry->tool_name( $ability_id ),
			'available'       => $available,
			'required_scopes' => array_values( $required_scopes ),
			'read_only'       => $is_read_only,
		);

		if ( $is_derived_workflow ) {
			$entry['derived']            = true;
			$entry['dependency_ids']     = $registry->dependency_ids( $ability_id );
			$entry['dependency_tools']   = array_values( array_map( array( $registry, 'tool_name' ), $entry['dependency_ids'] ) );
			$entry['availability_model'] = 'derived_from_dependencies';
		}

		if ( $is_always_on ) {
			$entry['always_on']          = true;
			$entry['availability_model'] = 'always_on_read_intelligence';
		}

		if ( ! $available ) {
			$entry['blocked_by'] = array() === $blocked_dependencies
				? $blocked_by
				: $blocked_by . ':' . implode( ',', array_unique( $blocked_dependencies ) );
			if ( array() !== $missing_scopes ) {
				$entry['missing_scopes'] = array_values( array_unique( $missing_scopes ) );
			}
		}

		return $entry;
	}

	/**
	 * Check workflow dependencies against global and role policy.
	 *
	 * @param string            $ability_id     Ability ID.
	 * @param string[]          $global_enabled Globally enabled IDs.
	 * @param string[]          $role_allowed   Role-allowed IDs.
	 * @param AbilitiesRegistry $registry       Ability registry.
	 * @param array|null        $granted_scopes Optional granted OAuth scopes.
	 * @phpstan-param list<string>|null $granted_scopes
	 */
	private function dependencies_available( string $ability_id, array $global_enabled, array $role_allowed, AbilitiesRegistry $registry, ?array $granted_scopes = null ): bool {
		foreach ( $registry->dependency_ids( $ability_id ) as $dependency_id ) {
			if ( ! in_array( $dependency_id, $global_enabled, true ) || ! in_array( $dependency_id, $role_allowed, true ) ) {
				return false;
			}

			if ( null !== $granted_scopes && ! $this->scopes_available( $registry->required_scopes( $dependency_id ), $granted_scopes ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether the module itself is policy-allowed.
	 *
	 * Derived workflow tools are not directly policy-managed; their dependencies
	 * decide availability.
	 * Always-on read intelligence bypasses global and role ability toggles, while
	 * still respecting OAuth scopes and execution-time WordPress access checks.
	 *
	 * @param string            $ability_id     Ability ID.
	 * @param string[]          $global_enabled Globally enabled IDs.
	 * @param string[]          $role_allowed   Role-allowed IDs.
	 * @param AbilitiesRegistry $registry       Ability registry.
	 */
	private function module_allowed( string $ability_id, array $global_enabled, array $role_allowed, AbilitiesRegistry $registry ): bool {
		if ( $registry->is_derived_workflow( $ability_id ) || $registry->is_always_on_read_intelligence( $ability_id ) ) {
			return true;
		}

		return in_array( $ability_id, $global_enabled, true ) && in_array( $ability_id, $role_allowed, true );
	}

	/**
	 * Return only modules whose OAuth scopes are granted, or all modules when scopes are unknown.
	 *
	 * @param array<string, AbilityModuleInterface> $modules        Modules keyed by ID.
	 * @param array|null                            $granted_scopes Optional granted OAuth scopes.
	 * @phpstan-param list<string>|null $granted_scopes
	 * @return array<string, AbilityModuleInterface>
	 */
	private function scope_filtered_modules( array $modules, ?array $granted_scopes ): array {
		if ( null === $granted_scopes ) {
			return $modules;
		}

		return array_filter(
			$modules,
			fn ( AbilityModuleInterface $module ): bool => $this->scopes_available( $module->required_scopes(), $granted_scopes )
		);
	}

	/**
	 * Check whether every required OAuth scope is present.
	 *
	 * @param array<mixed> $required       Required OAuth scopes.
	 * @param array|null   $granted_scopes Granted OAuth scopes, or null when unknown.
	 * @phpstan-param list<string>|null $granted_scopes
	 */
	private function scopes_available( array $required, ?array $granted_scopes ): bool {
		if ( null === $granted_scopes ) {
			return true;
		}

		return array() === $this->missing_scopes( $required, $granted_scopes );
	}

	/**
	 * Return required scopes missing from a granted scope list.
	 *
	 * @param array<mixed> $required       Required OAuth scopes.
	 * @param array        $granted_scopes Granted OAuth scopes.
	 * @phpstan-param list<string> $granted_scopes
	 * @return list<string>
	 */
	private function missing_scopes( array $required, array $granted_scopes ): array {
		$required = self::normalize_scope_list( $required );

		return array_values(
			array_filter(
				$required,
				static fn ( string $scope ): bool => ! in_array( $scope, $granted_scopes, true )
			)
		);
	}

	/**
	 * Normalize OAuth scope lists.
	 *
	 * @param array<mixed> $scopes Raw scopes.
	 * @return list<string>
	 */
	private static function normalize_scope_list( array $scopes ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $scopes ),
					static fn ( string $scope ): bool => '' !== $scope
				)
			)
		);
	}

	/**
	 * Resolve a role-policy blocker label for operation diagnostics.
	 *
	 * @param array<string, mixed>        $policy Ability policy details.
	 * @param AbilityModuleInterface|null $module Blocked module, if known.
	 */
	private function role_block_reason( array $policy, ?AbilityModuleInterface $module ): string {
		if ( true === ( $policy['missing_user'] ?? false ) ) {
			return 'missing_user';
		}

		if ( true === ( $policy['missing_role'] ?? false ) ) {
			return 'missing_role';
		}

		if ( true === ( $policy['default_read_only_policy'] ?? false ) && null !== $module && ! $module->is_read_only() ) {
			return 'role_default_read_only';
		}

		return 'role_policy';
	}

	/**
	 * Return the current WordPress user ID.
	 */
	private function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	}

	/**
	 * Return role slugs for a WordPress user.
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

		return array_values( array_map( 'strval', (array) $user->roles ) );
	}

	/**
	 * Return the role-policy state for a user.
	 *
	 * @param int   $user_id WordPress user ID.
	 * @param array $roles   User role slugs.
	 * @phpstan-param list<string> $roles
	 */
	private function user_policy_state( int $user_id, array $roles ): string {
		if ( $user_id <= 0 || ! function_exists( 'get_userdata' ) ) {
			return 'missing_user';
		}

		$user = get_userdata( $user_id );
		if ( ! is_object( $user ) ) {
			return 'missing_user';
		}

		if ( array() === $roles ) {
			return 'missing_role';
		}

		if ( in_array( 'administrator', $roles, true ) ) {
			return 'administrator';
		}

		return 'default_read_only';
	}
}
