<?php
/**
 * Database schema for durable custom workflow runs.
 *
 * @package Aculect\AICompanion\Workflows\Database
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Database;

/**
 * Owns site-scoped run and step state for internal custom workflows.
 *
 * Definition snapshots remain owned by Installer. This class only creates the
 * execution tables, keeping lifecycle state separate from the catalog.
 */
final class RunInstaller {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned workflow run tables require controlled schema changes.

	private const DB_VERSION        = '2026.08.29.1';
	private const OPTION_DB_VERSION = 'aculect_ai_companion_workflow_runs_db_version';

	/**
	 * Create or repair the workflow run tables.
	 *
	 * @return bool Whether both tables are available.
	 */
	public static function install(): bool {
		$missing = self::missing_table_keys();
		$stored  = (string) get_option( self::OPTION_DB_VERSION, '0' );
		if ( array() !== $missing || version_compare( $stored, self::DB_VERSION, '<' ) ) {
			try {
				self::create_tables();
			} catch ( \Throwable ) {
				return false;
			}

			$missing = self::missing_table_keys();
		}

		if ( array() !== $missing ) {
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

	/** Create tables during plugin activation. */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Remove workflow run storage and its schema option.
	 *
	 * The plugin uninstall entry point calls this only after the site owner has
	 * explicitly enabled full data removal.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$tables = self::table_names();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tables['steps'] ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tables['runs'] ) );

		delete_option( self::OPTION_DB_VERSION );
	}

	/**
	 * Return site-scoped run table names.
	 *
	 * @return array{runs:string,steps:string}
	 */
	public static function table_names(): array {
		global $wpdb;

		return array(
			'runs'  => $wpdb->prefix . 'aculect_ai_workflow_runs',
			'steps' => $wpdb->prefix . 'aculect_ai_workflow_run_steps',
		);
	}

	/**
	 * Return logical tables that are not present.
	 *
	 * @return list<string>
	 */
	public static function missing_table_keys(): array {
		global $wpdb;

		$missing = array();
		foreach ( self::table_names() as $key => $table ) {
			if ( $table !== (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * Return the exact dbDelta-compatible schema.
	 *
	 * @param array{runs:string,steps:string} $tables  Table names.
	 * @param string                          $charset Charset declaration.
	 */
	public static function schema_sql( array $tables, string $charset ): string {
		$runs  = $tables['runs'];
		$steps = $tables['steps'];

		return implode(
			"\n",
			array(
				"CREATE TABLE {$runs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(64) NOT NULL,
            workflow_id varchar(64) NOT NULL,
            workflow_version int(10) unsigned NOT NULL,
            definition_checksum char(64) NOT NULL,
            plan_hash char(64) NOT NULL,
            input_hash char(64) NOT NULL,
            input_ciphertext longtext NOT NULL,
            state varchar(32) NOT NULL,
            state_version bigint(20) unsigned NOT NULL DEFAULT 1,
            outcome_code varchar(64) NOT NULL DEFAULT '',
            waiting_expires_at datetime NULL,
            created_by bigint(20) unsigned NOT NULL,
            updated_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY run_id (run_id),
            KEY workflow_state (workflow_id, state, updated_at, id),
            KEY state_updated (state, updated_at, id)
        ) {$charset};",
				"CREATE TABLE {$steps} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_pk bigint(20) unsigned NOT NULL,
            step_id varchar(64) NOT NULL,
            step_position int(10) unsigned NOT NULL,
            adapter_id varchar(64) NOT NULL,
            adapter_version int(10) unsigned NOT NULL,
            ability_id varchar(128) NOT NULL,
            kind varchar(16) NOT NULL,
            state varchar(20) NOT NULL DEFAULT 'pending',
            attempt int(10) unsigned NOT NULL DEFAULT 0,
            fence bigint(20) unsigned NOT NULL DEFAULT 1,
            result_code varchar(64) NOT NULL DEFAULT '',
            output_ciphertext longtext NOT NULL,
            output_hash char(64) NOT NULL DEFAULT '',
            error_code varchar(64) NOT NULL DEFAULT '',
            lease_expires_at datetime NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY run_step (run_pk, step_id),
            KEY run_state_position (run_pk, state, step_position, id),
            KEY lease_state (state, lease_expires_at, id)
        ) {$charset};",
			)
		);
	}

	/** Create tables through dbDelta(). */
	private static function create_tables(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::schema_sql( self::table_names(), $wpdb->get_charset_collate() ) );
	}
}
