<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\RoleConnectionEntryPoint;
use Aculect\AICompanion\Diagnostics\LogSettings;
use Aculect\AICompanion\Settings\McpAppsSettings;

/**
 * Persist the Advanced form after SettingsPage verifies capability and nonce.
 */
final class AdvancedSettings {

	/**
	 * Save supported fields from the authorized admin form.
	 *
	 * @param array<string, mixed> $values Submitted form values, still slashed.
	 */
	public static function save( array $values ): void {
		LogSettings::set_enabled( self::form_flag( $values['diagnostic_logging_enabled'] ?? '' ) );
		$roles = isset( $values['role_connection_roles'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $values['role_connection_roles'] ) )
			: array();
		RoleConnectionEntryPoint::save( self::form_flag( $values['role_connections_enabled'] ?? '' ), $roles );
		RoleAbilitiesPolicy::set_editing_enabled( self::form_flag( $values['role_abilities_enabled'] ?? '' ) );

		// Older open forms do not submit this field; retain their existing opt-in.
		if ( array_key_exists( 'mcp_apps_enabled', $values ) ) {
			McpAppsSettings::set_enabled( '1' === $values['mcp_apps_enabled'] );
		}
	}

	/**
	 * Preserve the existing checkbox normalization for older settings.
	 *
	 * @param mixed $value Submitted checkbox value.
	 */
	private static function form_flag( mixed $value ): bool {
		return is_scalar( $value ) && '1' === sanitize_text_field( wp_unslash( (string) $value ) );
	}
}
