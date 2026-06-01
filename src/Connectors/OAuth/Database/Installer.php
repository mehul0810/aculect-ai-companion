<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Database;

use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationFingerprint;

/**
 * Owns Aculect AI Companion's OAuth storage schema and legacy token migration.
 *
 * OAuth client, code, access-token, and refresh-token records need indexed
 * lookups by hashed protocol identifiers. WordPress options/transients are not
 * appropriate here because token revocation must be immediate and queryable by
 * token hash, client, user, resource, and expiry.
 */
final class Installer {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned OAuth protocol tables require uncached reads/writes and controlled schema changes.

	private const DB_VERSION                 = '2026.06.01.1';
	private const OPTION_DB_VERSION          = 'aculect_ai_companion_oauth_db_version';
	private const OPTION_MIGRATED_LEGACY     = 'aculect_ai_companion_oauth_legacy_migrated';
	private const FINGERPRINT_BACKFILL_LIMIT = 500;

	/**
	 * Create or update the OAuth tables and migrate option-backed legacy state.
	 */
	public static function install(): void {
		$installed           = (string) get_option( self::OPTION_DB_VERSION, '0' );
		$needs_schema_update = version_compare( $installed, self::DB_VERSION, '<' );
		if ( $needs_schema_update ) {
			self::create_tables();
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
			self::backfill_client_registration_fingerprints();
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
			'clients'        => $wpdb->prefix . 'aculect_ai_companion_oauth_clients',
			'access_tokens'  => $wpdb->prefix . 'aculect_ai_companion_oauth_access_tokens',
			'refresh_tokens' => $wpdb->prefix . 'aculect_ai_companion_oauth_refresh_tokens',
			'auth_codes'     => $wpdb->prefix . 'aculect_ai_companion_oauth_auth_codes',
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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema_sql( $tables, $charset ) );
	}

	/**
	 * Return the full OAuth schema SQL for dbDelta().
	 *
	 * @param array<string, string> $tables  Table names keyed by logical store.
	 * @param string                $charset Database charset/collation clause.
	 */
	private static function schema_sql( array $tables, string $charset ): string {
		return implode(
			"\n\n",
			array(
				self::clients_table_sql( $tables['clients'], $charset ),
				self::auth_codes_table_sql( $tables['auth_codes'], $charset ),
				self::access_tokens_table_sql( $tables['access_tokens'], $charset ),
				self::refresh_tokens_table_sql( $tables['refresh_tokens'], $charset ),
			)
		) . "\n";
	}

	/**
	 * Return the DCR client table schema.
	 *
	 * @param string $table   Table name.
	 * @param string $charset Database charset/collation clause.
	 */
	private static function clients_table_sql( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_id varchar(100) NOT NULL,
            client_secret_hash varchar(255) DEFAULT NULL,
            client_name varchar(255) NOT NULL,
            provider varchar(40) NOT NULL DEFAULT 'mcp',
            redirect_uris longtext NOT NULL,
            registration_fingerprint char(64) NOT NULL DEFAULT '',
            user_id bigint(20) unsigned DEFAULT NULL,
            is_confidential tinyint(1) NOT NULL DEFAULT 1,
            revoked tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY client_id (client_id),
            KEY provider (provider),
            KEY provider_registration_revoked (provider, registration_fingerprint, revoked),
            KEY user_id (user_id),
            KEY revoked (revoked),
            KEY revoked_updated_at (revoked, updated_at)
        ) {$charset};";
	}

	/**
	 * Return the authorization code table schema.
	 *
	 * @param string $table   Table name.
	 * @param string $charset Database charset/collation clause.
	 */
	private static function auth_codes_table_sql( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
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
        ) {$charset};";
	}

	/**
	 * Return the access token table schema.
	 *
	 * @param string $table   Table name.
	 * @param string $charset Database charset/collation clause.
	 */
	private static function access_tokens_table_sql( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
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
        ) {$charset};";
	}

	/**
	 * Return the refresh token table schema.
	 *
	 * @param string $table   Table name.
	 * @param string $charset Database charset/collation clause.
	 */
	private static function refresh_tokens_table_sql( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
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
            KEY expires_at (expires_at),
            KEY active_refresh (revoked, expires_at, access_token_hash)
        ) {$charset};";
	}

	/**
	 * Backfill fingerprints for existing DCR clients after the schema upgrade.
	 */
	private static function backfill_client_registration_fingerprints(): void {
		global $wpdb;

		$table = self::table_names()['clients'];
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, redirect_uris FROM %i WHERE registration_fingerprint = %s LIMIT %d',
				$table,
				'',
				self::FINGERPRINT_BACKFILL_LIMIT
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$id          = absint( $row['id'] ?? 0 );
			$fingerprint = ClientRegistrationFingerprint::from_encoded_redirect_uris( (string) ( $row['redirect_uris'] ?? '' ) );
			if ( $id <= 0 || null === $fingerprint ) {
				continue;
			}

			$wpdb->update(
				$table,
				array( 'registration_fingerprint' => $fingerprint ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Revoke option-backed prototype OAuth state from earlier beta builds.
	 */
	private static function migrate_legacy_option_tokens(): void {
		if ( '1' === (string) get_option( self::OPTION_MIGRATED_LEGACY, '0' ) ) {
			return;
		}

		delete_option( 'aculect_ai_companion_oauth_tokens' );
		delete_option( 'aculect_ai_companion_oauth_codes' );
		update_option(
			'aculect_ai_companion_chatgpt_connection_state',
			array(
				'active'     => false,
				'updated_at' => time(),
			),
			false
		);
		update_option( self::OPTION_MIGRATED_LEGACY, '1', false );
	}
}
