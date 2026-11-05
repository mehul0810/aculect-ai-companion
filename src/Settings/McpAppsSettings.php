<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Settings;

/**
 * Per-site opt-in for interactive assistant responses.
 */
final class McpAppsSettings {

	public const OPTION_ENABLED = 'aculect_ai_companion_mcp_apps_enabled';

	/**
	 * Read the saved preference independently of site-code overrides.
	 */
	public static function saved_enabled(): bool {
		return '1' === get_option( self::OPTION_ENABLED, '0' );
	}

	/**
	 * Resolve the preference while preserving the existing feature filter.
	 */
	public static function is_enabled(): bool {
		return true === apply_filters( 'aculect_ai_companion_mcp_apps_enabled', self::saved_enabled() );
	}

	/**
	 * Persist the site administrator's preference.
	 *
	 * @param bool $enabled Whether to offer MCP Apps to compatible clients.
	 */
	public static function set_enabled( bool $enabled ): void {
		update_option( self::OPTION_ENABLED, $enabled ? '1' : '0', false );
	}

	/**
	 * Return saved and effective state for the Advanced Settings control.
	 *
	 * @return array{enabled: bool, effectiveEnabled: bool}
	 */
	public static function admin_payload(): array {
		return array(
			'enabled'          => self::saved_enabled(),
			'effectiveEnabled' => self::is_enabled(),
		);
	}

	/**
	 * Restore the default-off preference.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_ENABLED );
	}
}
