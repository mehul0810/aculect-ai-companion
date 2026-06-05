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
	 * Return enabled operation modules followed by always-on intelligence modules.
	 *
	 * @param int                       $user_id      WordPress user ID.
	 * @param AbilitiesRegistry|null    $registry     Optional ability registry.
	 * @param IntelligenceRegistry|null $intelligence Optional intelligence registry.
	 * @return array<string, AbilityModuleInterface>
	 */
	public function tool_modules_for_user( int $user_id, ?AbilitiesRegistry $registry = null, ?IntelligenceRegistry $intelligence = null ): array {
		$registry     = $registry ?? new AbilitiesRegistry();
		$intelligence = $intelligence ?? new IntelligenceRegistry();

		return array_merge(
			$this->ability_modules_for_user( $user_id, $registry ),
			$intelligence->modules()
		);
	}

	/**
	 * Return enabled operation modules for one user.
	 *
	 * @param int                    $user_id  WordPress user ID.
	 * @param AbilitiesRegistry|null $registry Optional ability registry.
	 * @return array<string, AbilityModuleInterface>
	 */
	public function ability_modules_for_user( int $user_id, ?AbilitiesRegistry $registry = null ): array {
		$registry = $registry ?? new AbilitiesRegistry();

		return ( new RoleAbilitiesPolicy() )->enabled_modules_for_user( $user_id, $registry );
	}

	/**
	 * Return policy details that explain why tools are or are not exposed.
	 *
	 * @param int                    $user_id  WordPress user ID.
	 * @param AbilitiesRegistry|null $registry Optional ability registry.
	 * @return array<string, mixed>
	 */
	public function ability_policy_for_user( int $user_id, ?AbilitiesRegistry $registry = null ): array {
		$registry       = $registry ?? new AbilitiesRegistry();
		$policy         = new RoleAbilitiesPolicy();
		$all_ids        = array_keys( $registry->definitions() );
		$global_enabled = $registry->enabled_ids();
		$role_allowed   = $policy->allowed_ids_for_user( $user_id, $registry );
		$exposed        = array_values( array_intersect( $all_ids, $global_enabled, $role_allowed ) );
		$roles          = $this->roles_for_user( $user_id );

		$explicit_role_policy     = count(
			array_filter(
				$roles,
				static fn ( string $role ): bool => $policy->has_explicit_policy( $role )
			)
		) > 0;
		$default_read_only_policy = array() !== $roles
			&& ! in_array( 'administrator', $roles, true )
			&& ! $explicit_role_policy;

		return array(
			'user_id'                   => $user_id,
			'user_roles'                => $roles,
			'global_enabled_count'      => count( $global_enabled ),
			'role_allowed_count'        => count( $role_allowed ),
			'exposed_ability_count'     => count( $exposed ),
			'all_ability_count'         => count( $all_ids ),
			'global_enabled_ids'        => array_values( $global_enabled ),
			'role_allowed_ids'          => array_values( $role_allowed ),
			'exposed_ability_ids'       => $exposed,
			'blocked_by_global_ids'     => array_values( array_diff( $all_ids, $global_enabled ) ),
			'blocked_by_role_ids'       => array_values( array_diff( $global_enabled, $role_allowed ) ),
			'explicit_role_policy'      => $explicit_role_policy,
			'default_read_only_policy'  => $default_read_only_policy,
			'operation_tool_names'      => array_values( array_map( array( $registry, 'tool_name' ), $exposed ) ),
			'global_enabled_tool_names' => array_values( array_map( array( $registry, 'tool_name' ), $global_enabled ) ),
		);
	}

	/**
	 * Return policy details for the current WordPress user.
	 *
	 * @param AbilitiesRegistry|null $registry Optional ability registry.
	 * @return array<string, mixed>
	 */
	public function ability_policy_for_current_user( ?AbilitiesRegistry $registry = null ): array {
		return $this->ability_policy_for_user( $this->current_user_id(), $registry );
	}

	/**
	 * Return a session-aware operation manifest for intelligence tools.
	 *
	 * @param int                    $user_id  WordPress user ID.
	 * @param AbilitiesRegistry|null $registry Optional ability registry.
	 * @return array<string, mixed>
	 */
	public function operations_manifest_for_user( int $user_id, ?AbilitiesRegistry $registry = null ): array {
		$registry = $registry ?? new AbilitiesRegistry();
		$policy   = $this->ability_policy_for_user( $user_id, $registry );

		return array(
			'description'     => 'Exact MCP operational tool names and availability for the connected WordPress user.',
			'decision_rule'   => 'Admin/global ability settings and role policy decide what is callable. Intelligence should choose only among available tools unless it needs to explain a blocked workflow.',
			'visibility_rule' => 'If a tool is unavailable, check blocked_by_global_ids and blocked_by_role_ids before assuming WordPress data or permissions are unavailable.',
			'policy'          => array(
				'user_id'                  => $policy['user_id'],
				'user_roles'               => $policy['user_roles'],
				'global_enabled_count'     => $policy['global_enabled_count'],
				'role_allowed_count'       => $policy['role_allowed_count'],
				'exposed_ability_count'    => $policy['exposed_ability_count'],
				'all_ability_count'        => $policy['all_ability_count'],
				'explicit_role_policy'     => $policy['explicit_role_policy'],
				'default_read_only_policy' => $policy['default_read_only_policy'],
				'blocked_by_global_ids'    => $policy['blocked_by_global_ids'],
				'blocked_by_role_ids'      => $policy['blocked_by_role_ids'],
			),
			'content'         => $this->operation_group(
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
			'content_groups'  => $this->operation_group(
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
			'media'           => $this->operation_group(
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
			'comments'        => $this->operation_group(
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
			'actions'         => $this->operation_group(
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
		return $this->operations_manifest_for_user( $this->current_user_id(), $registry );
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
		$global_enabled = (array) ( $policy['global_enabled_ids'] ?? array() );
		$role_allowed   = (array) ( $policy['role_allowed_ids'] ?? array() );
		$exposed        = (array) ( $policy['exposed_ability_ids'] ?? array() );
		$module         = $registry->module( $ability_id );
		$blocked_by     = '';

		if ( ! in_array( $ability_id, $global_enabled, true ) ) {
			$blocked_by = 'global_disabled';
		} elseif ( ! in_array( $ability_id, $role_allowed, true ) ) {
			$blocked_by = true === ( $policy['default_read_only_policy'] ?? false ) && null !== $module && ! $module->is_read_only()
				? 'role_default_read_only'
				: 'role_policy';
		}

		return array(
			'ability_id'      => $ability_id,
			'tool'            => $registry->tool_name( $ability_id ),
			'available'       => in_array( $ability_id, $exposed, true ),
			'blocked_by'      => $blocked_by,
			'required_scopes' => null === $module ? array() : $module->required_scopes(),
			'read_only'       => null === $module || $module->is_read_only(),
		);
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
}
