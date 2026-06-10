<?php
/**
 * Safe import, export, and reset support for plugin-owned settings.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Brand\BrandProfile;
use Aculect\AICompanion\Connectors\MCP\AccessLockdown;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\RoleConnectionEntryPoint;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\MCP\UserAccessControl;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesPolicy;
use Aculect\AICompanion\Diagnostics\LogSettings;
use Aculect\AICompanion\Intelligence\ContentIndexer;

/**
 * Owns the sanitized JSON contract for settings transfer actions.
 */
final class SettingsTransfer {

	public const SCHEMA         = 'aculect-ai-companion-settings';
	public const SCHEMA_VERSION = 1;

	public const MAX_IMPORT_BYTES = 1048576;

	/**
	 * Build a sanitized settings export payload.
	 *
	 * @return array<string, mixed>
	 */
	public function export_payload(): array {
		$registry = new AbilitiesRegistry();

		return array(
			'schema'        => self::SCHEMA,
			'schemaVersion' => self::SCHEMA_VERSION,
			'pluginVersion' => ACULECT_AI_COMPANION_VERSION,
			'exportedAt'    => gmdate( 'c' ),
			'settings'      => array(
				'enabledAbilities'    => $registry->enabled_ids(),
				'enabledWpAbilities'  => ( new WordPressAbilitiesPolicy() )->allowed_ids(),
				'confirmationGroups'  => ( new ToolSafety() )->confirmation_groups(),
				'roleAbilityPolicies' => ( new RoleAbilitiesPolicy() )->saved_policies( $registry ),
				'roleConnections'     => array(
					'enabled'      => RoleConnectionEntryPoint::is_enabled(),
					'allowedRoles' => RoleConnectionEntryPoint::allowed_roles(),
				),
				'diagnostics'         => array(
					'loggingEnabled' => LogSettings::is_enabled(),
					'retentionDays'  => LogSettings::retention_days(),
				),
				'brandProfile'        => ( new BrandProfile() )->saved(),
			),
		);
	}

	/**
	 * Build pretty JSON for a settings export download.
	 */
	public function export_json(): string {
		$json = wp_json_encode( $this->export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * Decode a JSON settings document.
	 *
	 * @param string $json Raw JSON document.
	 * @return array<string, mixed>
	 */
	public function decode_json( string $json ): array {
		$decoded = json_decode( $json, true, 64 );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Import a sanitized settings payload.
	 *
	 * @param array<string, mixed> $payload Raw decoded JSON payload.
	 */
	public function import_payload( array $payload ): bool {
		$settings = $this->settings_from_payload( $payload );
		if ( array() === $settings ) {
			return false;
		}

		$registry = new AbilitiesRegistry();
		$this->import_abilities( $settings, $registry );
		$this->import_role_connections( $settings['roleConnections'] ?? null );
		$this->import_diagnostics( $settings['diagnostics'] ?? null );
		$this->import_brand_profile( $settings['brandProfile'] ?? null );

		return true;
	}

	/**
	 * Restore plugin-owned settings to defaults without deleting connection data.
	 */
	public function reset(): void {
		delete_option( AbilitiesRegistry::OPTION_ENABLED_ABILITIES );
		WordPressAbilitiesPolicy::delete();
		ToolSafety::delete();
		RoleAbilitiesPolicy::delete();
		RoleConnectionEntryPoint::delete();
		LogSettings::delete_options();
		BrandProfile::delete();
		AccessLockdown::delete();
		UserAccessControl::delete();
		ContentIndexer::delete_options();
	}

	/**
	 * Validate and return the settings object from an export payload.
	 *
	 * @param array<string, mixed> $payload Raw decoded JSON payload.
	 * @return array<string, mixed>
	 */
	private function settings_from_payload( array $payload ): array {
		if ( self::SCHEMA !== (string) ( $payload['schema'] ?? '' ) ) {
			return array();
		}

		if ( self::SCHEMA_VERSION !== (int) ( $payload['schemaVersion'] ?? 0 ) ) {
			return array();
		}

		return isset( $payload['settings'] ) && is_array( $payload['settings'] )
			? $payload['settings']
			: array();
	}

	/**
	 * Import ability policy settings.
	 *
	 * @param array<string, mixed> $settings Settings payload.
	 * @param AbilitiesRegistry    $registry Ability registry.
	 */
	private function import_abilities( array $settings, AbilitiesRegistry $registry ): void {
		if ( array_key_exists( 'enabledAbilities', $settings ) ) {
			$registry->save_enabled_ids( $this->string_list( $settings['enabledAbilities'] ) );
		}

		if ( array_key_exists( 'enabledWpAbilities', $settings ) ) {
			( new WordPressAbilitiesPolicy() )->save_allowed_ids( $this->string_list( $settings['enabledWpAbilities'] ) );
		}

		if ( array_key_exists( 'confirmationGroups', $settings ) ) {
			( new ToolSafety() )->save_confirmation_groups( $this->string_list( $settings['confirmationGroups'] ) );
		}

		if ( array_key_exists( 'roleAbilityPolicies', $settings ) ) {
			( new RoleAbilitiesPolicy() )->replace_policies(
				$this->role_policy_map( $settings['roleAbilityPolicies'] ),
				$registry
			);
		}
	}

	/**
	 * Import role connection settings.
	 *
	 * @param mixed $settings Role connection settings.
	 */
	private function import_role_connections( mixed $settings ): void {
		if ( ! is_array( $settings ) ) {
			return;
		}

		RoleConnectionEntryPoint::save(
			$this->boolean_value( $settings['enabled'] ?? false ),
			$this->string_list( $settings['allowedRoles'] ?? array() )
		);
	}

	/**
	 * Import diagnostic settings.
	 *
	 * @param mixed $settings Diagnostic settings.
	 */
	private function import_diagnostics( mixed $settings ): void {
		if ( ! is_array( $settings ) ) {
			return;
		}

		if ( array_key_exists( 'loggingEnabled', $settings ) ) {
			LogSettings::set_enabled( $this->boolean_value( $settings['loggingEnabled'] ) );
		}

		if ( array_key_exists( 'retentionDays', $settings ) ) {
			$retention_days = $settings['retentionDays'];
			if ( is_scalar( $retention_days ) ) {
				LogSettings::set_retention_days( absint( $retention_days ) );
			}
		}
	}

	/**
	 * Import saved brand profile overrides.
	 *
	 * @param mixed $settings Brand profile settings.
	 */
	private function import_brand_profile( mixed $settings ): void {
		if ( is_array( $settings ) ) {
			( new BrandProfile() )->save( $settings );
		}
	}

	/**
	 * Sanitize a list of scalar strings.
	 *
	 * @param mixed $value Raw value.
	 * @return list<string>
	 */
	private function string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array_filter(
			array_map(
				static fn( mixed $item ): string => is_scalar( $item ) ? sanitize_text_field( (string) $item ) : '',
				$value
			)
		);

		return array_values( array_unique( $items ) );
	}

	/**
	 * Sanitize a role-to-ability-list map.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, list<string>>
	 */
	private function role_policy_map( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$policies = array();
		foreach ( $value as $role => $ability_ids ) {
			$role = sanitize_key( (string) $role );
			if ( '' === $role ) {
				continue;
			}

			$policies[ $role ] = $this->string_list( $ability_ids );
		}

		return $policies;
	}

	/**
	 * Normalize common JSON boolean representations.
	 *
	 * @param mixed $value Raw value.
	 */
	private function boolean_value( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( ! is_scalar( $value ) ) {
			return false;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
