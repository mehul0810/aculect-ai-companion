<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Database;

/**
 * Owns Quark's OAuth storage schema and legacy token migration.
 *
 * OAuth client, code, access-token, and refresh-token records need indexed
 * lookups by hashed protocol identifiers. WordPress options/transients are not
 * appropriate here because token revocation must be immediate and queryable by
 * token hash, client, user, resource, and expiry.
 */
final class Installer {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned OAuth protocol tables require uncached reads/writes and controlled schema changes.

	private const DB_VERSION             = '2026.05.11.1';
	private const OPTION_DB_VERSION      = 'quark_oauth_db_version';
	private const OPTION_MIGRATED_LEGACY = 'quark_oauth_legacy_migrated';

	/**
	 * Create or update the OAuth tables and migrate option-backed legacy state.
	 */
	public static function install(): void {
		$installed = (string) get_option( self::OPTION_DB_VERSION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
		}

		self::migrate_legacy_option_tokens();
	}

	/**
	 * Activation entry point for OAuth storage setup.
	 */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Return plugin-owned OAuth table names for the current WordPress prefix.
	 *
	 * @return array<string, string>
	 */
	public static function table_names(): array {
		global $wpdb;

		return array(
			'clients'        => $wpdb->prefix . 'quark_oauth_clients',
			'access_tokens'  => $wpdb->prefix . 'quark_oauth_access_tokens',
			'refresh_tokens' => $wpdb->prefix . 'quark_oauth_refresh_tokens',
			'auth_codes'     => $wpdb->prefix . 'quark_oauth_auth_codes',
		);
	}

	/**
	 * Remove OAuth tables and schema options when the user opts into data removal.
	 */
	public static function uninstall(): void {
		global $wpdb;

		foreach ( self::table_names() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( self::OPTION_DB_VERSION );
		delete_option( self::OPTION_MIGRATED_LEGACY );
	}

	/**
	 * Create or upgrade custom OAuth tables through WordPress dbDelta().
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$tables  = self::table_names();

		$sql = "CREATE TABLE {$tables['clients']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            client_secret_hash varchar(255) DEFAULT NULL,
            client_name varchar(255) NOT NULL,
            provider varchar(40) NOT NULL DEFAULT 'mcp',
            redirect_uris longtext NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            is_confidential tinyint(1) NOT NULL DEFAULT 1,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY client_id (client_id),
            KEY provider (provider),
            KEY user_id (user_id),
            KEY revoked (revoked)
        ) {$charset};

        CREATE TABLE {$tables['auth_codes']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code_hash char(64) NOT NULL,
            client_id varchar(100) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            scopes text DEFAULT NULL,
            resource text NOT NULL,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY code_hash (code_hash),
            KEY client_id (client_id),
            KEY user_id (user_id),
            KEY revoked (revoked),
            KEY expires_at (expires_at)
        ) {$charset};

        CREATE TABLE {$tables['access_tokens']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash char(64) NOT NULL,
            client_id varchar(100) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            scopes text DEFAULT NULL,
            resource text NOT NULL,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY client_id (client_id),
            KEY user_id (user_id),
            KEY revoked (revoked),
            KEY expires_at (expires_at)
        ) {$charset};

        CREATE TABLE {$tables['refresh_tokens']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token_hash char(64) NOT NULL,
            access_token_hash char(64) NOT NULL,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY access_token_hash (access_token_hash),
            KEY revoked (revoked),
            KEY expires_at (expires_at)
        ) {$charset};\n";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Revoke option-backed prototype OAuth state from earlier beta builds.
	 */
	private static function migrate_legacy_option_tokens(): void {
		if ( '1' === (string) get_option( self::OPTION_MIGRATED_LEGACY, '0' ) ) {
			return;
		}

		delete_option( 'quark_oauth_tokens' );
		delete_option( 'quark_oauth_codes' );
		update_option(
			'quark_chatgpt_connection_state',
			array(
				'active'     => false,
				'updated_at' => time(),
			),
			false
		);
		update_option( self::OPTION_MIGRATED_LEGACY, '1', false );
	}
}
