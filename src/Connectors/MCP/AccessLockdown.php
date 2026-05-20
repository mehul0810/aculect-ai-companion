<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Stores and reads the global AI access pause state.
 */
final class AccessLockdown {

	private const OPTION_PAUSED = 'aculect_ai_companion_access_paused';

	/**
	 * Determine whether connected AI access is temporarily paused.
	 */
	public static function is_paused(): bool {
		return '1' === (string) get_option( self::OPTION_PAUSED, '0' );
	}

	/**
	 * Persist the global pause state.
	 *
	 * @param bool $paused Whether access is paused.
	 */
	public static function set_paused( bool $paused ): void {
		update_option( self::OPTION_PAUSED, $paused ? '1' : '0', false );
	}

	/**
	 * Remove the pause option during full data cleanup.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_PAUSED );
	}
}
