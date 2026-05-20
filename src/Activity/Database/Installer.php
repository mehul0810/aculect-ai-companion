<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Activity\Database;

/**
 * Owns the connected AI activity log storage schema.
 */
final class Installer {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned activity log table requires controlled schema changes.

	private const DB_VERSION        = '2026.05.20.1';
	private const OPTION_DB_VERSION = 'aculect_ai_companion_activity_db_version';

	/**
	 * Create or update the activity log table.
	 */
	public static function install(): void {
		$installed = (string) get_option( self::OPTION_DB_VERSION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_table();
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
		}
	}

	/**
	 * Activation entry point.
	 */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Return the activity log table name for the current WordPress prefix.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_companion_activity';
	}

	/**
	 * Remove activity log storage and schema option.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table_name() ) );

		delete_option( self::OPTION_DB_VERSION );
	}

	/**
	 * Create or upgrade the activity table through dbDelta().
	 */
	private static function create_table(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$table   = self::table_name();

		$sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            provider varchar(40) DEFAULT NULL,
            client_id varchar(100) DEFAULT NULL,
            client_name varchar(255) DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            action varchar(100) NOT NULL,
            target_type varchar(60) DEFAULT NULL,
            target_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'success',
            error_code varchar(100) DEFAULT NULL,
            message text DEFAULT NULL,
            context longtext DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY provider (provider),
            KEY client_id (client_id),
            KEY user_id (user_id),
            KEY action (action),
            KEY target (target_type, target_id),
            KEY status (status),
            KEY error_code (error_code)
        ) {$charset};\n";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
