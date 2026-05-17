<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics\Database;

use Aculect\AICompanion\Diagnostics\LogSettings;

/**
 * Owns the diagnostic logging storage schema.
 */
final class Installer {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned diagnostic log table requires controlled schema changes.

	private const DB_VERSION        = '2026.05.17.1';
	private const OPTION_DB_VERSION = 'aculect_ai_companion_logs_db_version';

	/**
	 * Create or update the diagnostic log table.
	 */
	public static function install(): void {
		$installed = (string) get_option( self::OPTION_DB_VERSION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			if ( '0' !== $installed || ! self::table_exists() ) {
				self::create_table();
			}
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
		}

		LogSettings::ensure_defaults();
	}

	/**
	 * Activation entry point.
	 */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Return the diagnostic log table name for the current WordPress prefix.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_companion_logs';
	}

	/**
	 * Remove diagnostic logging storage and options.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table_name() ) );

		delete_option( self::OPTION_DB_VERSION );
		LogSettings::delete_options();
	}

	/**
	 * Create or upgrade the diagnostic log table through dbDelta().
	 */
	private static function create_table(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = self::table_name();

		$sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level varchar(20) NOT NULL DEFAULT 'info',
            event varchar(80) NOT NULL,
            provider varchar(40) DEFAULT NULL,
            request_method varchar(10) DEFAULT NULL,
            request_route varchar(255) DEFAULT NULL,
            http_status smallint(5) unsigned DEFAULT NULL,
            error_code varchar(100) DEFAULT NULL,
            message text DEFAULT NULL,
            context longtext DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY level (level),
            KEY event (event),
            KEY provider (provider),
            KEY error_code (error_code)
        ) {$charset};\n";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Determine whether the diagnostics log table already exists.
	 */
	private static function table_exists(): bool {
		global $wpdb;

		$table = self::table_name();

		return $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
