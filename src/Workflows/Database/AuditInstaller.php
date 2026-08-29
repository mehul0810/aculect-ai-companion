<?php
/**
 * Database schema for custom workflow audit events.
 *
 * @package Aculect\AICompanion\Workflows\Database
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Database;

/**
 * Owns the site-scoped, summary-only workflow audit table.
 */
final class AuditInstaller {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned audit table requires controlled schema changes.

	private const DB_VERSION        = '2026.08.29.1';
	private const OPTION_DB_VERSION = 'aculect_ai_companion_workflow_audit_db_version';

	/**
	 * Create or repair the audit table.
	 *
	 * @return bool Whether the table is available at the expected version.
	 */
	public static function install(): bool {
		$stored = (string) get_option( self::OPTION_DB_VERSION, '0' );
		if ( version_compare( $stored, self::DB_VERSION, '<' ) || array() !== self::missing_table_keys() ) {
			try {
				self::create_table();
			} catch ( \Throwable ) {
				return false;
			}
		}

		if ( array() !== self::missing_table_keys() ) {
			return false;
		}

		if ( self::DB_VERSION !== $stored ) {
			$updated = update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
			if ( ! $updated && self::DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '0' ) ) {
				return false;
			}
		}

		return true;
	}

	/** Create the audit table during plugin activation. */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Remove audit storage and its schema option.
	 *
	 * Called only by the opt-in uninstall path.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table_name() ) );
		delete_option( self::OPTION_DB_VERSION );
	}

	/** Return the current site-scoped audit table name. */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_workflow_audit';
	}

	/**
	 * Return logical tables that are not present.
	 *
	 * @return list<string>
	 */
	public static function missing_table_keys(): array {
		global $wpdb;

		return self::table_name() === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table_name() ) ) ) ? array() : array( 'audit' );
	}

	/**
	 * Return the exact dbDelta-compatible schema declaration.
	 *
	 * @param string $table   Table name.
	 * @param string $charset Charset declaration.
	 */
	public static function schema_sql( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(64) NOT NULL,
            workflow_id varchar(64) NOT NULL,
            workflow_version int(10) unsigned NOT NULL,
            definition_checksum char(64) NOT NULL,
            event_type varchar(32) NOT NULL,
            step_id varchar(64) NOT NULL DEFAULT '',
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            outcome_code varchar(64) NOT NULL DEFAULT '',
            approval_reference_hash char(64) NOT NULL DEFAULT '',
            changed_fields text NOT NULL,
            rollback_note varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY run_created (run_id, created_at, id),
            KEY workflow_created (workflow_id, created_at, id),
            KEY event_created (event_type, created_at, id)
        ) {$charset};";
	}

	/** Create the table through dbDelta(). */
	private static function create_table(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::schema_sql( self::table_name(), $wpdb->get_charset_collate() ) );
	}
}
