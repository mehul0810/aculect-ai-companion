<?php
/**
 * Connection settings payload builder.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;

/**
 * Projects effective ability policy details onto admin connection rows.
 *
 * This builder deliberately owns only the read-only connection-row projection;
 * session persistence, tab hydration, sample data, and admin actions remain
 * with SettingsPage.
 */
final class SettingsConnectionPayloadBuilder {

	/**
	 * Add effective MCP ability details to admin connection rows.
	 *
	 * @param array<int, array<string, mixed>> $sessions Connection sessions.
	 * @param AbilitiesRegistry                $registry Ability registry.
	 * @return array<int, array<string, mixed>>
	 */
	public function build( array $sessions, AbilitiesRegistry $registry ): array {
		if ( array() === $sessions ) {
			return $sessions;
		}

		$availability = new McpToolAvailability();

		return array_map(
			function ( array $session ) use ( $availability, $registry ): array {
				$user_id = absint( $session['user_id'] ?? 0 );
				$scopes  = array_values( array_map( 'strval', (array) ( $session['scopes'] ?? array() ) ) );
				$modules = $availability->ability_modules_for_user( $user_id, $registry, $scopes );
				$policy  = $availability->ability_policy_for_user( $user_id, $registry, $scopes );
				$writes  = array_filter(
					$modules,
					static fn( AbilityModuleInterface $module ): bool => ! $module->is_read_only()
				);

				$session['effective_abilities']           = array_values(
					array_map(
						fn( AbilityModuleInterface $module ): array => array(
							'id'          => $module->id(),
							'toolName'    => $registry->tool_name( $module->id() ),
							'title'       => $module->title(),
							'description' => $module->description(),
							'scopes'      => $module->required_scopes(),
							'readOnly'    => $module->is_read_only(),
						),
						$modules
					)
				);
				$session['effective_write_ability_count'] = count( $writes );
				$session['effective_ability_summary']     = array(
					'available_count'          => count( $modules ),
					'write_count'              => count( $writes ),
					'blocked_by_global_count'  => count( (array) ( $policy['blocked_by_global_ids'] ?? array() ) ),
					'blocked_by_role_count'    => count( (array) ( $policy['blocked_by_role_ids'] ?? array() ) ),
					'default_read_only_policy' => true === ( $policy['default_read_only_policy'] ?? false ),
					'explicit_role_policy'     => true === ( $policy['explicit_role_policy'] ?? false ),
					'scope_aware'              => true === ( $policy['scope_aware'] ?? false ),
					'missing_user'             => true === ( $policy['missing_user'] ?? false ),
					'missing_role'             => true === ( $policy['missing_role'] ?? false ),
				);

				return $session;
			},
			$sessions
		);
	}
}
