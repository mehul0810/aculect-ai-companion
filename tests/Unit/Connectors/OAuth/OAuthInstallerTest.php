<?php
/**
 * Tests for OAuth storage schema installation helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationFingerprint;
use Aculect\AICompanion\Connectors\OAuth\Database\Installer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused installer tests replace wpdb with a local test double.

/**
 * Verifies OAuth schema and migration helpers stay compatible with DCR cleanup.
 */
final class OAuthInstallerTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['aculect_ai_companion_test_db_delta_callback'] );
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		unset( $GLOBALS['aculect_ai_companion_test_db_delta_callback'] );

		parent::tearDown();
	}

	public function test_schema_sql_includes_registration_fingerprint_contract(): void {
		$sql = $this->invokePrivateStatic(
			'schema_sql',
			array(
				array(
					'clients'        => 'wp_aculect_ai_companion_oauth_clients',
					'auth_codes'     => 'wp_aculect_ai_companion_oauth_auth_codes',
					'access_tokens'  => 'wp_aculect_ai_companion_oauth_access_tokens',
					'refresh_tokens' => 'wp_aculect_ai_companion_oauth_refresh_tokens',
				),
				'DEFAULT CHARSET=utf8mb4',
			)
		);

		self::assertIsString( $sql );
		self::assertStringContainsString( "registration_fingerprint char(64) NOT NULL DEFAULT ''", $sql );
		self::assertStringContainsString( 'write_permission_enabled tinyint(1) NOT NULL DEFAULT 0', $sql );
		self::assertStringContainsString( "access_level varchar(32) NOT NULL DEFAULT 'read'", $sql );
		self::assertStringContainsString( 'KEY provider_registration_revoked (provider, registration_fingerprint, revoked)', $sql );
		self::assertStringContainsString( 'KEY revoked_updated_at (revoked, updated_at)', $sql );
		self::assertStringContainsString( 'KEY active_refresh (revoked, expires_at, access_token_hash)', $sql );
	}

	public function test_backfill_updates_valid_empty_registration_fingerprints_in_batches(): void {
		$wpdb          = new FakeOAuthInstallerWpdb();
		$wpdb->results = array(
			array(
				'id'            => '12',
				'redirect_uris' => '["https:\/\/example.com\/b","https:\/\/example.com\/a"]',
			),
			array(
				'id'            => '0',
				'redirect_uris' => '["https:\/\/example.com\/skip"]',
			),
			array(
				'id'            => '13',
				'redirect_uris' => 'not-json',
			),
		);
		$GLOBALS['wpdb'] = $wpdb;

		$this->invokePrivateStatic( 'backfill_client_registration_fingerprints' );

		self::assertSame( 'SELECT id, redirect_uris FROM %i WHERE registration_fingerprint = %s LIMIT %d', $wpdb->prepared[0]['query'] );
		self::assertSame(
			array( 'wp_aculect_ai_companion_oauth_clients', '', 500 ),
			$wpdb->prepared[0]['args']
		);
		self::assertCount( 1, $wpdb->updates );
		self::assertSame( 'wp_aculect_ai_companion_oauth_clients', $wpdb->updates[0]['table'] );
		self::assertSame(
			ClientRegistrationFingerprint::from_encoded_redirect_uris( '["https:\/\/example.com\/b","https:\/\/example.com\/a"]' ),
			$wpdb->updates[0]['data']['registration_fingerprint']
		);
		self::assertSame( array( 'id' => 12 ), $wpdb->updates[0]['where'] );
	}

	public function test_current_schema_repairs_one_missing_table_without_replacing_existing_stores(): void {
		$wpdb                  = new FakeOAuthInstallerWpdb();
		$wpdb->existing_tables = array(
			'wp_7_aculect_ai_companion_oauth_clients',
			'wp_7_aculect_ai_companion_oauth_access_tokens',
			'wp_7_aculect_ai_companion_oauth_refresh_tokens',
		);
		$wpdb->prefix          = 'wp_7_';
		$wpdb->client_rows     = array( 'existing-client-row' );
		$GLOBALS['wpdb']       = $wpdb;
		update_option( 'aculect_ai_companion_oauth_db_version', '2026.06.03.1', false );
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;
			$wpdb->existing_tables[]  = 'wp_7_aculect_ai_companion_oauth_auth_codes';

			return array( 'created auth codes table' );
		};

		self::assertTrue( Installer::install( true ) );
		self::assertSame( array( 'existing-client-row' ), $wpdb->client_rows );
		self::assertSame( array(), Installer::missing_table_keys() );
		self::assertCount( 1, $wpdb->db_delta_queries );
		self::assertStringContainsString( 'wp_7_aculect_ai_companion_oauth_auth_codes', $wpdb->db_delta_queries[0] );
	}

	public function test_current_schema_reports_failed_repair_when_a_required_table_remains_missing(): void {
		$wpdb                  = new FakeOAuthInstallerWpdb();
		$wpdb->existing_tables = array(
			'wp_aculect_ai_companion_oauth_clients',
			'wp_aculect_ai_companion_oauth_access_tokens',
			'wp_aculect_ai_companion_oauth_refresh_tokens',
		);
		$GLOBALS['wpdb']       = $wpdb;
		update_option( 'aculect_ai_companion_oauth_db_version', '2026.06.03.1', false );

		self::assertFalse( Installer::install( true ) );
		self::assertSame( array( 'auth_codes' ), Installer::missing_table_keys() );
	}

	public function test_activation_verifies_all_current_multisite_oauth_tables(): void {
		$wpdb                  = new FakeOAuthInstallerWpdb();
		$wpdb->prefix          = 'wp_7_';
		$wpdb->existing_tables = array(
			'wp_7_aculect_ai_companion_oauth_clients',
			'wp_7_aculect_ai_companion_oauth_access_tokens',
			'wp_7_aculect_ai_companion_oauth_refresh_tokens',
			'wp_7_aculect_ai_companion_oauth_auth_codes',
		);
		$GLOBALS['wpdb']       = $wpdb;
		update_option( 'aculect_ai_companion_oauth_db_version', '2026.06.03.1', false );

		Installer::activate();

		self::assertSame( 4, $wpdb->get_var_calls );
		self::assertSame( array(), Installer::missing_table_keys() );
	}

	/**
	 * Invoke a private static method for focused unit coverage.
	 *
	 * @param string      $method    Method name.
	 * @param list<mixed> $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivateStatic( string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( Installer::class, $method );

		return $reflection->invokeArgs( null, $arguments );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test double is intentionally local to this file.

/**
 * Minimal wpdb test double for OAuth installer migrations.
 */
final class FakeOAuthInstallerWpdb {

	public string $prefix = 'wp_';

	/** @var list<string> */
	public array $existing_tables = array();

	/** @var list<string> */
	public array $client_rows = array();

	/** @var list<string> */
	public array $db_delta_queries = array();

	public int $get_var_calls = 0;

	/**
	 * Prepared SQL calls.
	 *
	 * @var array<int, array{query: string, args: array<int, mixed>}>
	 */
	public array $prepared = array();

	/**
	 * Rows returned by get_results().
	 *
	 * @var array<int, array<string, mixed>>|false
	 */
	public array|false $results = array();

	/**
	 * Update calls.
	 *
	 * @var array<int, array{table: string, data: array<string, mixed>, where: array<string, mixed>}>
	 */
	public array $updates = array();

	/**
	 * Record a prepared SQL template and arguments.
	 *
	 * @param string $query SQL query with placeholders.
	 * @param mixed  ...$args Placeholder arguments.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared[] = array(
			'query' => $query,
			'args'  => $args,
		);

		return $query;
	}

	/**
	 * Return configured result rows.
	 *
	 * @param string $query  Prepared SQL query.
	 * @param string $output Requested output type.
	 * @return array<int, array<string, mixed>>|false
	 */
	public function get_results( string $query, string $output ): array|false {
		unset( $query, $output );

		return $this->results;
	}

	public function get_var( string $query ): string {
		unset( $query );

		++$this->get_var_calls;
		$table = (string) ( $this->prepared[ array_key_last( $this->prepared ) ]['args'][0] ?? '' );

		return in_array( $table, $this->existing_tables, true ) ? $table : '';
	}

	public function esc_like( string $text ): string {
		return $text;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4';
	}

	/**
	 * Record an update call.
	 *
	 * @param string               $table         Table name.
	 * @param array<string, mixed> $data          Update data.
	 * @param array<string, mixed> $where         Update where clause.
	 * @param string[]             $format        Data formats.
	 * @param string[]             $where_format  Where formats.
	 */
	public function update( string $table, array $data, array $where, array $format, array $where_format ): int {
		unset( $format, $where_format );

		$this->updates[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);

		return 1;
	}
}
