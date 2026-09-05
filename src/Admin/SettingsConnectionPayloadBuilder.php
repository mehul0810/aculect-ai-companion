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
		$projections  = array();

		return array_map(
			function ( array $session ) use ( $availability, $registry, &$projections ): array {
				$user_id = absint( $session['user_id'] ?? 0 );
				$scopes  = array_values( array_map( 'strval', (array) ( $session['scopes'] ?? array() ) ) );
				sort( $scopes );
				$key = $user_id . ':' . hash( 'sha256', implode( ' ', $scopes ) );
				if ( ! isset( $projections[ $key ] ) ) {
					$projections[ $key ] = $this->projection( $user_id, $scopes, $registry, $availability );
				}
				$session = array_merge( $session, $projections[ $key ] );

				return $session;
			},
			$sessions
		);
	}

	/**
	 * Build one reusable user-and-scope ability projection.
	 *
	 * @param int                 $user_id      WordPress user ID.
	 * @param array               $scopes       Normalized scopes.
	 * @phpstan-param list<string> $scopes
	 * @param AbilitiesRegistry   $registry     Ability registry.
	 * @param McpToolAvailability $availability Availability service.
	 * @return array<string, mixed>
	 */
	private function projection( int $user_id, array $scopes, AbilitiesRegistry $registry, McpToolAvailability $availability ): array {
		$policy  = $availability->ability_policy_for_user( $user_id, $registry, $scopes );
		$modules = array_values(
			array_filter(
				array_map( array( $registry, 'module' ), (array) ( $policy['exposed_ability_ids'] ?? array() ) ),
				static fn( mixed $module ): bool => $module instanceof AbilityModuleInterface
			)
		);
		$writes  = array_filter( $modules, static fn( AbilityModuleInterface $module ): bool => ! $module->is_read_only() );

		return array(
			'effective_abilities'           => array_map(
				fn( AbilityModuleInterface $module ): array => array(
					'id'          => $module->id(),
					'toolName'    => $registry->tool_name( $module->id() ),
					'title'       => $module->title(),
					'description' => $module->description(),
					'scopes'      => $module->required_scopes(),
					'readOnly'    => $module->is_read_only(),
				),
				$modules
			),
			'effective_write_ability_count' => count( $writes ),
			'effective_ability_summary'     => array(
				'available_count'          => count( $modules ),
				'write_count'              => count( $writes ),
				'blocked_by_global_count'  => count( (array) ( $policy['blocked_by_global_ids'] ?? array() ) ),
				'blocked_by_role_count'    => count( (array) ( $policy['blocked_by_role_ids'] ?? array() ) ),
				'default_read_only_policy' => true === ( $policy['default_read_only_policy'] ?? false ),
				'explicit_role_policy'     => true === ( $policy['explicit_role_policy'] ?? false ),
				'scope_aware'              => true === ( $policy['scope_aware'] ?? false ),
				'missing_user'             => true === ( $policy['missing_user'] ?? false ),
				'missing_role'             => true === ( $policy['missing_role'] ?? false ),
			),
		);
	}
}
