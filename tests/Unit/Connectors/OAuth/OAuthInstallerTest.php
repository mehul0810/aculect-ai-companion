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
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

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
