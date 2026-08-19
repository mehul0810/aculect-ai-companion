<?php
/**
 * Database schema for durable custom workflow definitions.
 *
 * @package Aculect\AICompanion\Workflows\Database
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Database;

/**
 * Owns the site-scoped workflow catalog and immutable version tables.
 */
final class Installer {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned workflow definition tables require controlled schema changes.

	private const DB_VERSION                = '2026.08.19.1';
	private const OPTION_DB_VERSION         = 'aculect_ai_companion_workflows_db_version';
	private const OPTION_VERIFICATION_STATE = 'aculect_ai_companion_workflows_db_verification';
	private const VERIFY_INTERVAL           = 12 * 3600;
	private const FAILURE_RETRY_INTERVAL    = 5 * 60;

	/**
	 * Create or repair the workflow definition tables.
	 *
	 * @param bool $verify_tables Whether to verify required tables when the stored version is current.
	 * @return bool Whether both required tables are available at the expected schema version.
	 */
	public static function install( bool $verify_tables = false ): bool {
		$now       = time();
		$installed = (string) get_option( self::OPTION_DB_VERSION, '0' );
		$state     = get_option( self::OPTION_VERIFICATION_STATE, array() );

		if ( ! $verify_tables && self::verification_is_throttled( $state, $installed, $now ) ) {
			return 'valid' === $state['status'];
		}

		$schema_is_stale = version_compare( $installed, self::DB_VERSION, '<' );
		$missing_tables  = $schema_is_stale ? array() : self::missing_table_keys();

		if ( $schema_is_stale || array() !== $missing_tables ) {
			try {
				self::create_tables();
			} catch ( \Throwable ) {
				self::record_failed_verification( $now );

				return false;
			}

			$missing_tables = self::missing_table_keys();
		}

		if ( array() !== $missing_tables ) {
			self::record_failed_verification( $now );

			return false;
		}

		if ( $schema_is_stale ) {
			$updated = update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
			if ( ! $updated && self::DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '0' ) ) {
				self::record_failed_verification( $now );

				return false;
			}

			$installed = self::DB_VERSION;
		}

		update_option(
			self::OPTION_VERIFICATION_STATE,
			array(
				'status'        => 'valid',
				'db_version'    => $installed,
				'next_check_at' => $now + self::VERIFY_INTERVAL,
			),
			false
		);

		return true;
	}

	/**
	 * Activation entry point.
	 */
	public static function activate(): void {
		self::install( true );
	}

	/**
	 * Return plugin-owned workflow table names for the current site prefix.
	 *
	 * @return array<string, string>
	 */
	public static function table_names(): array {
		global $wpdb;

		return array(
			'catalog'  => $wpdb->prefix . 'aculect_ai_workflows',
			'versions' => $wpdb->prefix . 'aculect_ai_workflow_versions',
		);
	}

	/**
	 * Return logical workflow stores whose tables are unavailable.
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
	 * Remove workflow definition storage and its schema option.
	 *
	 * The plugin's uninstall entry point calls this method only after the site
	 * owner has explicitly enabled data removal.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$tables = self::table_names();
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tables['versions'] ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $tables['catalog'] ) );

		delete_option( self::OPTION_DB_VERSION );
		delete_option( self::OPTION_VERIFICATION_STATE );
	}

	/**
	 * Whether a recent verification result may suppress another table probe.
	 *
	 * @param mixed  $state     Stored lifecycle verification state.
	 * @param string $installed Stored schema version.
	 * @param int    $now       Current Unix timestamp.
	 */
	private static function verification_is_throttled( mixed $state, string $installed, int $now ): bool {
		if ( ! is_array( $state ) || ! isset( $state['status'], $state['db_version'], $state['next_check_at'] ) ) {
			return false;
		}

		if ( ! in_array( $state['status'], array( 'valid', 'failed' ), true )
			|| $installed !== (string) $state['db_version']
			|| ! is_numeric( $state['next_check_at'] ) ) {
			return false;
		}

		return (int) $state['next_check_at'] > $now;
	}

	/**
	 * Invalidate schema truth and bound retries after an unsuccessful repair.
	 *
	 * @param int $now Current Unix timestamp.
	 */
	private static function record_failed_verification( int $now ): void {
		delete_option( self::OPTION_DB_VERSION );
		$installed = (string) get_option( self::OPTION_DB_VERSION, '0' );
		update_option(
			self::OPTION_VERIFICATION_STATE,
			array(
				'status'        => 'failed',
				'db_version'    => $installed,
				'next_check_at' => $now + self::FAILURE_RETRY_INTERVAL,
			),
			false
		);
	}

	/**
	 * Create or upgrade both workflow definition tables through dbDelta().
	 */
	private static function create_tables(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( self::schema_sql( self::table_names(), $wpdb->get_charset_collate() ) );
	}

	/**
	 * Return the exact dbDelta-compatible schema declaration.
	 *
	 * @param array<string, string> $tables  Site-scoped table names.
	 * @param string                $charset WordPress charset and collation declaration.
	 */
	private static function schema_sql( array $tables, string $charset ): string {
		$catalog  = $tables['catalog'];
		$versions = $tables['versions'];

		$sql = array(
			"CREATE TABLE {$catalog} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workflow_id varchar(64) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'draft',
            latest_version int(10) unsigned NOT NULL DEFAULT 0,
            published_version int(10) unsigned NOT NULL DEFAULT 0,
            template_id varchar(64) NOT NULL DEFAULT '',
            template_version int(10) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL,
            updated_by bigint(20) unsigned NOT NULL,
            lock_version bigint(20) unsigned NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY workflow_id (workflow_id),
            KEY status_updated (status, updated_at, id),
            KEY template_id (template_id)
        ) {$charset};",
			"CREATE TABLE {$versions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workflow_pk bigint(20) unsigned NOT NULL,
            workflow_version int(10) unsigned NOT NULL,
            definition_schema_version smallint(5) unsigned NOT NULL,
            definition_checksum char(64) NOT NULL,
            definition_status varchar(20) NOT NULL,
            input_contract_version int(10) unsigned NOT NULL,
            output_contract_version int(10) unsigned NOT NULL,
            definition_json longtext NOT NULL,
            migrated_from_version int(10) unsigned NOT NULL DEFAULT 0,
            migration_id varchar(64) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY workflow_version (workflow_pk, workflow_version),
            KEY workflow_created (workflow_pk, created_at, id),
            KEY definition_schema (definition_schema_version, id)
        ) {$charset};",
		);

		return implode( "\n", $sql );
	}
}
