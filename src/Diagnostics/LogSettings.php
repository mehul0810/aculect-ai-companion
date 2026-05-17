<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

/**
 * Centralized option access for diagnostic logging.
 */
final class LogSettings {

	public const OPTION_ENABLED        = 'aculect_ai_companion_logging_enabled';
	public const OPTION_RETENTION_DAYS = 'aculect_ai_companion_log_retention_days';

	private const DEFAULT_RETENTION_DAYS = 30;
	private const MIN_RETENTION_DAYS     = 1;
	private const MAX_RETENTION_DAYS     = 365;

	/**
	 * Determine whether diagnostic logging is enabled.
	 */
	public static function is_enabled(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
	}

	/**
	 * Persist the diagnostic logging flag.
	 *
	 * @param bool $enabled Whether diagnostic logging should run.
	 */
	public static function set_enabled( bool $enabled ): void {
		update_option( self::OPTION_ENABLED, $enabled ? '1' : '0', false );
	}

	/**
	 * Return the configured retention window in days.
	 */
	public static function retention_days(): int {
		$days = absint( get_option( self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS ) );

		if ( $days < self::MIN_RETENTION_DAYS ) {
			return self::DEFAULT_RETENTION_DAYS;
		}

		return min( $days, self::MAX_RETENTION_DAYS );
	}

	/**
	 * Ensure the default retention option exists.
	 */
	public static function ensure_defaults(): void {
		if ( false === get_option( self::OPTION_RETENTION_DAYS, false ) ) {
			update_option( self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS, false );
		}
	}

	/**
	 * Remove diagnostic logging options.
	 */
	public static function delete_options(): void {
		delete_option( self::OPTION_ENABLED );
		delete_option( self::OPTION_RETENTION_DAYS );
		delete_option( 'aculect_ai_companion_log_last_pruned_at' );
	}
}
