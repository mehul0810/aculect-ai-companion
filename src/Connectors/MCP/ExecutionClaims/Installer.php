<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\ExecutionClaims;

/**
 * Owns the transactional execution-claims schema.
 */
final class Installer {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned claim authority requires controlled schema changes.

	private const DB_VERSION        = '2026.08.19.1';
	private const OPTION_DB_VERSION = 'aculect_ai_companion_execution_claims_db_version';

	public static function install( bool $verify_table = false ): bool {
		unset( $verify_table );
		$installed = (string) get_option( self::OPTION_DB_VERSION, '0' );
		$missing   = ! self::table_exists();
		if ( version_compare( $installed, self::DB_VERSION, '<' ) || $missing ) {
			try {
				self::create_table();
			} catch ( \Throwable ) {
				self::invalidate_schema_version();
				return false;
			}

			if ( ! self::table_exists() ) {
				self::invalidate_schema_version();
				return false;
			}

			$updated = update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
			if ( ! $updated && self::DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '0' ) ) {
				self::invalidate_schema_version();
				return false;
			}
		}

		return true;
	}

	public static function activate(): void {
		self::install( true );
	}

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_companion_execution_claims';
	}

	public static function table_exists(): bool {
		global $wpdb;

		$table = self::table_name();
		return $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	public static function uninstall(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table_name() ) );
		delete_option( self::OPTION_DB_VERSION );
	}

	private static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			confirmation_key_hash char(64) DEFAULT NULL,
			idempotency_key_hash char(64) DEFAULT NULL,
			payload_hash char(64) NOT NULL,
			tool_hash char(64) NOT NULL,
			identity_hash char(64) NOT NULL,
			owner_hash char(64) DEFAULT NULL,
			fence bigint(20) unsigned NOT NULL DEFAULT 1,
			state varchar(16) NOT NULL DEFAULT 'claimed',
			result_json longtext DEFAULT NULL,
			result_hash char(64) DEFAULT NULL,
			lease_expires_at datetime DEFAULT NULL,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			retain_until datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY confirmation_key_hash (confirmation_key_hash),
			UNIQUE KEY idempotency_key_hash (idempotency_key_hash),
			KEY state_lease (state, lease_expires_at),
			KEY state_retain (state, retain_until),
			KEY updated_at (updated_at)
		) ENGINE=InnoDB {$charset};\n";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}

	/**
	 * Invalidate stale schema truth after an unsuccessful repair.
	 */
	private static function invalidate_schema_version(): void {
		delete_option( self::OPTION_DB_VERSION );
		if ( '0' !== (string) get_option( self::OPTION_DB_VERSION, '0' ) ) {
			update_option( self::OPTION_DB_VERSION, '0', false );
		}
	}
}
