<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Owns the bounded pending-operation approval table schema.
 */
final class PendingApprovalInstaller {

	private const DB_VERSION        = '2026.09.29.1';
	private const OPTION_DB_VERSION = 'aculect_ai_companion_pending_approvals_db_version';
	private static bool $installed  = false;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- This store requires an authoritative indexed table and controlled schema creation.

	public static function install(): bool {
		if ( self::$installed ) {
			return true;
		}
		$stored_version = (string) get_option( self::OPTION_DB_VERSION, '0' );
		if ( version_compare( $stored_version, self::DB_VERSION, '<' ) || ! self::table_exists() ) {
			try {
				self::create_table();
			} catch ( \Throwable ) {
				delete_option( self::OPTION_DB_VERSION );
				return false;
			}
			if ( ! self::table_exists() ) {
				delete_option( self::OPTION_DB_VERSION );
				return false;
			}
			if ( ! update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false ) && self::DB_VERSION !== (string) get_option( self::OPTION_DB_VERSION, '0' ) ) {
				delete_option( self::OPTION_DB_VERSION );
				return false;
			}
		}

		self::$installed = true;
		return true;
	}

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_companion_pending_approvals';
	}

	public static function reset_request_cache(): void {
		self::$installed = false;
	}

	private static function table_exists(): bool {
		global $wpdb;

		$table = self::table_name();
		return $table === (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	private static function create_table(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			blog_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			client_hash char(64) NOT NULL,
			session_hash char(64) NOT NULL,
			request_key char(64) NOT NULL,
			tool_name varchar(64) NOT NULL,
			operation_fingerprint char(64) NOT NULL,
			payload_ciphertext longtext NOT NULL,
			status varchar(16) NOT NULL DEFAULT 'pending',
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			decided_at datetime DEFAULT NULL,
			decided_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY actor_pending (blog_id, user_id, status, expires_at),
			UNIQUE KEY exact_request (request_key),
			KEY expiry (expires_at)
		) ENGINE=InnoDB {$charset};\n";
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );
	}
}
