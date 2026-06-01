<?php
/**
 * Tests for OAuth repository token handling helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AuthCodeRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\RefreshTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationFingerprint;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused repository tests replace wpdb with a local test double.

/**
 * Verifies token material is reduced to deterministic hashes before storage.
 */
final class OAuthRepositoryTest extends TestCase {

	public function test_access_refresh_and_auth_code_identifiers_are_hashed_consistently(): void {
		$raw = 'raw-token-material';

		$access_hash  = $this->hash( new AccessTokenRepository(), $raw );
		$refresh_hash = $this->hash( new RefreshTokenRepository(), $raw );
		$code_hash    = $this->hash( new AuthCodeRepository(), $raw );

		self::assertSame( hash( 'sha256', $raw ), $access_hash );
		self::assertSame( $access_hash, $refresh_hash );
		self::assertSame( $access_hash, $code_hash );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $access_hash );
		self::assertNotSame( $raw, $access_hash );
	}

	public function test_active_session_counts_are_grouped_by_user(): void {
		$wpdb            = new FakeAccessTokenWpdb();
		$wpdb->results   = array(
			array(
				'user_id'      => '7',
				'active_count' => '2',
			),
			array(
				'user_id'      => '12',
				'active_count' => '1',
			),
		);
		$GLOBALS['wpdb'] = $wpdb;

		$counts = ( new AccessTokenRepository() )->active_session_counts_by_user();

		self::assertSame(
			array(
				7  => 2,
				12 => 1,
			),
			$counts
		);
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $wpdb->prepared[0]['args'][0] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_refresh_tokens', $wpdb->prepared[0]['args'][1] );
		self::assertStringContainsString( 'COUNT(DISTINCT access_tokens.id)', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'refresh_tokens.revoked = 0', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'refresh_tokens.expires_at >= %s', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'GROUP BY access_tokens.user_id', $wpdb->prepared[0]['query'] );
	}

	public function test_active_token_count_uses_refreshable_connections(): void {
		$wpdb             = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb']  = $wpdb;
		$wpdb->var_result = 3;

		self::assertSame( 3, ( new AccessTokenRepository() )->active_token_count() );
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $wpdb->prepared[0]['args'][0] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_refresh_tokens', $wpdb->prepared[0]['args'][1] );
		self::assertStringContainsString( 'COUNT(DISTINCT access_tokens.id)', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'refresh_tokens.revoked = 0', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'refresh_tokens.expires_at >= %s', $wpdb->prepared[0]['query'] );
	}

	public function test_active_sessions_show_connection_expiry_from_refresh_token(): void {
		$wpdb            = new FakeAccessTokenWpdb();
		$wpdb->results   = array(
			array(
				'id'                      => '5',
				'client_id'               => 'client-1',
				'user_id'                 => '0',
				'scopes'                  => '["content:read"]',
				'resource'                => 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
				'access_token_expires_at' => '2026-06-01 01:00:00',
				'connection_expires_at'   => '2026-07-01 00:00:00',
				'created_at'              => '2026-06-01 00:00:00',
				'last_used_at'            => '',
				'client_name'             => 'ChatGPT',
				'provider'                => 'chatgpt',
			),
		);
		$GLOBALS['wpdb'] = $wpdb;

		$sessions = ( new AccessTokenRepository() )->list_active_sessions();

		self::assertCount( 1, $sessions );
		self::assertSame( 'active', $sessions[0]['status'] );
		self::assertSame( '2026-07-01 00:00:00', $sessions[0]['expires_at'] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $wpdb->prepared[0]['args'][0] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_refresh_tokens', $wpdb->prepared[0]['args'][1] );
		self::assertStringContainsString( 'MAX(expires_at) AS expires_at', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'active_refresh.access_token_hash = access_tokens.token_hash', $wpdb->prepared[0]['query'] );
	}

	public function test_revoke_user_marks_only_selected_users_tokens_revoked(): void {
		$wpdb            = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$revoked = ( new AccessTokenRepository() )->revoke_user( 7 );

		self::assertSame( 2, $revoked );
		self::assertSame( 'wp_aculect_ai_companion_oauth_refresh_tokens', $wpdb->prepared[0]['args'][0] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $wpdb->prepared[0]['args'][1] );
		self::assertSame( 7, $wpdb->prepared[0]['args'][2] );
		self::assertStringContainsString( 'access_tokens.user_id = %d', $wpdb->prepared[0]['query'] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $wpdb->updates[0]['table'] );
		self::assertSame( array( 'revoked' => 1 ), $wpdb->updates[0]['data'] );
		self::assertSame(
			array(
				'user_id' => 7,
				'revoked' => 0,
			),
			$wpdb->updates[0]['where']
		);
	}

	public function test_revoke_user_ignores_invalid_user_ids(): void {
		$wpdb            = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		self::assertSame( 0, ( new AccessTokenRepository() )->revoke_user( 0 ) );
		self::assertSame( array(), $wpdb->updates );
		self::assertSame( array(), $wpdb->queries );
	}

	public function test_duplicate_client_cleanup_preserves_live_tokens_and_auth_codes(): void {
		$wpdb            = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$revoked = ( new ClientRepository() )->revoke_unused_duplicate_clients(
			'chatgpt',
			array( 'https://chatgpt.com/oauth/callback' ),
			'2026-05-28 00:00:00'
		);

		self::assertSame( 1, $revoked );
		self::assertStringContainsString( 'UPDATE %i clients', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'clients.registration_fingerprint = %s', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'active_tokens.revoked = 0', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'active_codes.revoked = 0', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'LIMIT %d', $wpdb->prepared[0]['query'] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_clients', $wpdb->prepared[0]['args'][0] );
		self::assertSame( 'chatgpt', $wpdb->prepared[0]['args'][1] );
		self::assertSame(
			ClientRegistrationFingerprint::from_redirect_uris( array( 'https://chatgpt.com/oauth/callback' ) ),
			$wpdb->prepared[0]['args'][2]
		);
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $wpdb->prepared[0]['args'][3] );
		self::assertSame( '2026-05-28 00:00:00', $wpdb->prepared[0]['args'][4] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_auth_codes', $wpdb->prepared[0]['args'][5] );
		self::assertSame( '2026-05-28 00:00:00', $wpdb->prepared[0]['args'][6] );
		self::assertSame( 25, $wpdb->prepared[0]['args'][7] );
	}

	public function test_duplicate_client_cleanup_uses_order_insensitive_redirect_fingerprints(): void {
		$first  = ClientRegistrationFingerprint::from_redirect_uris(
			array(
				'https://example.com/b/callback',
				'https://example.com/a/callback',
			)
		);
		$second = ClientRegistrationFingerprint::from_redirect_uris(
			array(
				'https://example.com/a/callback',
				'https://example.com/b/callback',
			)
		);

		self::assertSame( $first, $second );

		$wpdb            = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		( new ClientRepository() )->revoke_unused_duplicate_clients(
			'mcp',
			array(
				'https://example.com/b/callback',
				'https://example.com/a/callback',
			),
			'2026-05-28 00:00:00'
		);

		self::assertSame( $first, $wpdb->prepared[0]['args'][2] );
		self::assertStringNotContainsString( 'clients.redirect_uris = %s', $wpdb->prepared[0]['query'] );
	}

	public function test_prune_revoked_clients_deletes_only_old_revoked_rows(): void {
		$wpdb            = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$deleted = ( new ClientRepository() )->prune_revoked_clients( '2026-05-01 00:00:00', 37 );

		self::assertSame( 1, $deleted );
		self::assertSame( 'wp_aculect_ai_companion_oauth_clients', $wpdb->prepared[0]['args'][0] );
		self::assertSame( '2026-05-01 00:00:00', $wpdb->prepared[0]['args'][1] );
		self::assertSame( 37, $wpdb->prepared[0]['args'][2] );
		self::assertStringContainsString( 'DELETE FROM %i', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'revoked = 1', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'updated_at < %s', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'LIMIT %d', $wpdb->prepared[0]['query'] );
	}

	public function test_create_client_runs_duplicate_cleanup_before_insert(): void {
		$wpdb            = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$credentials = ( new ClientRepository() )->create_client(
			'ChatGPT Connector',
			array( 'https://chatgpt.com/oauth/callback' )
		);

		self::assertIsArray( $credentials );
		self::assertSame( 'chatgpt', $credentials['provider'] );
		self::assertNotEmpty( $credentials['client_id'] );
		self::assertNotEmpty( $credentials['client_secret'] );
		self::assertSame( array( 'query', 'insert' ), $wpdb->operations );
		self::assertStringContainsString( 'UPDATE %i clients', $wpdb->prepared[0]['query'] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_clients', $wpdb->inserts[0]['table'] );
		self::assertSame( 'chatgpt', $wpdb->inserts[0]['data']['provider'] );
		self::assertSame( '["https:\/\/chatgpt.com\/oauth\/callback"]', $wpdb->inserts[0]['data']['redirect_uris'] );
		self::assertSame(
			ClientRegistrationFingerprint::from_redirect_uris( array( 'https://chatgpt.com/oauth/callback' ) ),
			$wpdb->inserts[0]['data']['registration_fingerprint']
		);
		self::assertSame( 0, $wpdb->inserts[0]['data']['revoked'] );
	}

	/**
	 * Invoke the private hash helper on a repository.
	 *
	 * @param object $repository Repository instance.
	 * @param string $raw        Raw identifier.
	 */
	private function hash( object $repository, string $raw ): string {
		$reflection = new ReflectionMethod( $repository, 'hash_identifier' );

		return (string) $reflection->invokeArgs( $repository, array( $raw ) );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- This test double is intentionally local to the repository tests.

/**
 * Minimal wpdb test double for user-scoped access-token queries.
 */
final class FakeAccessTokenWpdb {

	public string $prefix = 'wp_';

	/**
	 * Prepared SQL calls.
	 *
	 * @var array<int, array{query: string, args: array<int, mixed>}>
	 */
	public array $prepared = array();

	/**
	 * Update calls.
	 *
	 * @var array<int, array{table: string, data: array<string, mixed>, where: array<string, mixed>}>
	 */
	public array $updates = array();

	/**
	 * Raw query calls.
	 *
	 * @var string[]
	 */
	public array $queries = array();

	/**
	 * Insert calls.
	 *
	 * @var array<int, array{table: string, data: array<string, mixed>}>
	 */
	public array $inserts = array();

	/**
	 * Operation order.
	 *
	 * @var string[]
	 */
	public array $operations = array();

	/**
	 * Configured result rows.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $results = array();

	public int $var_result = 0;

	public int|false $update_result = 2;

	public int|false $query_result = 1;

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
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $query, $output );

		return $this->results;
	}

	/**
	 * Return a configured scalar query value.
	 *
	 * @param string $query SQL query.
	 */
	public function get_var( string $query ): int {
		unset( $query );

		return $this->var_result;
	}

	/**
	 * Record a query call.
	 *
	 * @param string $query SQL query.
	 */
	public function query( string $query ): int|false {
		$this->queries[]    = $query;
		$this->operations[] = 'query';

		return $this->query_result;
	}

	/**
	 * Record an insert call.
	 *
	 * @param string               $table   Table name.
	 * @param array<string, mixed> $data    Insert data.
	 * @param string[]             $formats Insert formats.
	 */
	public function insert( string $table, array $data, array $formats ): int|false {
		unset( $formats );

		$this->inserts[]    = array(
			'table' => $table,
			'data'  => $data,
		);
		$this->operations[] = 'insert';

		return 1;
	}

	/**
	 * Record an update call.
	 *
	 * @param string               $table        Table name.
	 * @param array<string, mixed> $data         Update data.
	 * @param array<string, mixed> $where        Where data.
	 * @param string[]             $data_formats Data formats.
	 * @param string[]             $where_format Where formats.
	 */
	public function update( string $table, array $data, array $where, array $data_formats, array $where_format ): int|false {
		unset( $data_formats, $where_format );

		$this->updates[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);

		return $this->update_result;
	}
}
