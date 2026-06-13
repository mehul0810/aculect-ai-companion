<?php
/**
 * Tests for OAuth repository token handling helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AuthCodeRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\RefreshTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationFingerprint;
use Aculect\AICompanion\Connectors\OAuth\Entities\AccessTokenEntity;
use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use Aculect\AICompanion\Connectors\OAuth\Entities\ScopeEntity;
use Aculect\AICompanion\Connectors\OAuth\RequestContext;
use DateTimeImmutable;
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
				'write_permission_enabled' => '1',
				'access_level'             => ConnectionAccessLevel::FULL_WRITE,
				'client_name'             => 'ChatGPT',
				'provider'                => 'chatgpt',
			),
		);
		$GLOBALS['wpdb'] = $wpdb;

		$sessions = ( new AccessTokenRepository() )->list_active_sessions();

		self::assertCount( 1, $sessions );
		self::assertSame( 'active', $sessions[0]['status'] );
		self::assertSame( '2026-07-01 00:00:00', $sessions[0]['expires_at'] );
		self::assertTrue( $sessions[0]['write_permission_enabled'] );
		self::assertSame( ConnectionAccessLevel::FULL_WRITE, $sessions[0]['access_level'] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $wpdb->prepared[0]['args'][0] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_refresh_tokens', $wpdb->prepared[0]['args'][1] );
		self::assertStringContainsString( 'MAX(expires_at) AS expires_at', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'active_refresh.access_token_hash = access_tokens.token_hash', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'access_tokens.write_permission_enabled', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'access_tokens.access_level', $wpdb->prepared[0]['query'] );
	}

	public function test_access_token_context_includes_write_permission_flag(): void {
		$wpdb      = new FakeAccessTokenWpdb();
		$wpdb->row = array(
			'id'                       => '9',
			'token_hash'               => hash( 'sha256', 'raw-access-token' ),
			'client_id'                => 'client-1',
			'user_id'                  => '7',
			'scopes'                   => '["content:read","content:draft"]',
			'resource'                 => 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
			'revoked'                  => '0',
			'expires_at'               => '2099-01-01 00:00:00',
			'last_used_at'             => '2026-06-01 00:00:00',
			'write_permission_enabled' => '1',
			'access_level'             => ConnectionAccessLevel::SELECTIVE_WRITE,
			'client_name'              => 'Claude',
			'provider'                 => 'claude',
		);
		$GLOBALS['wpdb'] = $wpdb;

		$context = ( new AccessTokenRepository() )->context_from_token_id( 'raw-access-token' );

		self::assertSame( 7, $context['user_id'] );
		self::assertSame( 'Claude', $context['client_name'] );
		self::assertTrue( $context['write_permission_enabled'] );
		self::assertSame( ConnectionAccessLevel::SELECTIVE_WRITE, $context['access_level'] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $wpdb->prepared[0]['args'][0] );
		self::assertStringContainsString( 'access_tokens.token_hash = %s', $wpdb->prepared[0]['query'] );
	}

	public function test_set_write_permission_updates_refreshable_active_connection(): void {
		$wpdb               = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb']    = $wpdb;
		$wpdb->query_result = 1;

		self::assertTrue( ( new AccessTokenRepository() )->set_write_permission( 5, true ) );
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $wpdb->prepared[0]['args'][0] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_refresh_tokens', $wpdb->prepared[0]['args'][1] );
		self::assertSame( 1, $wpdb->prepared[0]['args'][2] );
		self::assertSame( ConnectionAccessLevel::SELECTIVE_WRITE, $wpdb->prepared[0]['args'][3] );
		self::assertSame( 5, $wpdb->prepared[0]['args'][4] );
		self::assertStringContainsString( 'SET access_tokens.write_permission_enabled = %d', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'access_tokens.access_level = %s', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'access_tokens.revoked = 0', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'refresh_tokens.revoked = 0', $wpdb->prepared[0]['query'] );
		self::assertStringContainsString( 'refresh_tokens.expires_at >= %s', $wpdb->prepared[0]['query'] );
	}

	public function test_set_access_level_updates_refreshable_active_connection(): void {
		$wpdb               = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb']    = $wpdb;
		$wpdb->query_result = 1;

		self::assertTrue( ( new AccessTokenRepository() )->set_access_level( 5, ConnectionAccessLevel::EXECUTE ) );
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $wpdb->prepared[0]['args'][0] );
		self::assertSame( 'wp_aculect_ai_companion_oauth_refresh_tokens', $wpdb->prepared[0]['args'][1] );
		self::assertSame( 1, $wpdb->prepared[0]['args'][2] );
		self::assertSame( ConnectionAccessLevel::EXECUTE, $wpdb->prepared[0]['args'][3] );
		self::assertSame( 5, $wpdb->prepared[0]['args'][4] );
		self::assertStringContainsString( 'access_tokens.access_level = %s', $wpdb->prepared[0]['query'] );
	}

	public function test_set_access_level_read_disables_direct_write_flag(): void {
		$wpdb               = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb']    = $wpdb;
		$wpdb->query_result = 1;

		self::assertTrue( ( new AccessTokenRepository() )->set_access_level( 5, ConnectionAccessLevel::READ ) );
		self::assertSame( 0, $wpdb->prepared[0]['args'][2] );
		self::assertSame( ConnectionAccessLevel::READ, $wpdb->prepared[0]['args'][3] );
	}

	public function test_refresh_rotation_carries_write_permission_to_replacement_access_token(): void {
		$wpdb      = new FakeAccessTokenWpdb();
		$wpdb->row = array(
			'client_id'                => 'client-refresh',
			'user_id'                  => '7',
			'resource'                 => 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
			'write_permission_enabled' => '1',
			'access_level'             => ConnectionAccessLevel::FULL_WRITE,
		);
		$GLOBALS['wpdb'] = $wpdb;
		$repository      = new AccessTokenRepository();

		$repository->revokeAccessToken( 'old-access-token' );

		RequestContext::set_resource( 'https://example.com/wp-json/aculect-ai-companion/v1/mcp' );
		$repository->persistNewAccessToken(
			$this->access_token_entity(
				'new-access-token',
				'client-refresh',
				'7'
			)
		);
		RequestContext::reset();

		self::assertSame( array( 'get_row', 'update', 'update', 'insert' ), $wpdb->operations );
		self::assertSame( 'wp_aculect_ai_companion_oauth_access_tokens', $wpdb->inserts[0]['table'] );
		self::assertSame( 1, $wpdb->inserts[0]['data']['write_permission_enabled'] );
		self::assertSame( ConnectionAccessLevel::FULL_WRITE, $wpdb->inserts[0]['data']['access_level'] );
	}

	public function test_refresh_rotation_maps_legacy_write_permission_to_selective_write(): void {
		$wpdb      = new FakeAccessTokenWpdb();
		$wpdb->row = array(
			'client_id'                => 'client-refresh',
			'user_id'                  => '7',
			'resource'                 => 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
			'write_permission_enabled' => '1',
		);
		$GLOBALS['wpdb'] = $wpdb;
		$repository      = new AccessTokenRepository();

		$repository->revokeAccessToken( 'old-access-token' );

		RequestContext::set_resource( 'https://example.com/wp-json/aculect-ai-companion/v1/mcp' );
		$repository->persistNewAccessToken(
			$this->access_token_entity(
				'new-access-token',
				'client-refresh',
				'7'
			)
		);
		RequestContext::reset();

		self::assertSame( 1, $wpdb->inserts[0]['data']['write_permission_enabled'] );
		self::assertSame( ConnectionAccessLevel::SELECTIVE_WRITE, $wpdb->inserts[0]['data']['access_level'] );
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

	public function test_create_public_client_omits_secret_material(): void {
		$wpdb            = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$credentials = ( new ClientRepository() )->create_client(
			'Public MCP Client',
			array( 'http://localhost/callback' ),
			false
		);

		self::assertIsArray( $credentials );
		self::assertNull( $credentials['client_secret'] );
		self::assertSame( 0, $wpdb->inserts[0]['data']['is_confidential'] );
		self::assertNull( $wpdb->inserts[0]['data']['client_secret_hash'] );
	}

	public function test_validate_client_accepts_public_client_without_secret(): void {
		$wpdb            = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->row       = $this->client_row(
			array(
				'client_id'                => 'public-client',
				'is_confidential'          => '0',
				'client_secret_hash'       => null,
				'redirect_uris'            => '["http:\/\/localhost\/callback"]',
				'registration_fingerprint' => ClientRegistrationFingerprint::from_redirect_uris( array( 'http://localhost/callback' ) ),
			)
		);

		self::assertTrue( ( new ClientRepository() )->validateClient( 'public-client', null, 'authorization_code' ) );
	}

	public function test_validate_client_rejects_confidential_client_without_secret(): void {
		$wpdb            = new FakeAccessTokenWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$wpdb->row       = $this->client_row(
			array(
				'client_id'                => 'confidential-client',
				'is_confidential'          => '1',
				'client_secret_hash'       => wp_hash_password( 'secret-value' ),
				'redirect_uris'            => '["https:\/\/chatgpt.com\/oauth\/callback"]',
				'registration_fingerprint' => ClientRegistrationFingerprint::from_redirect_uris( array( 'https://chatgpt.com/oauth/callback' ) ),
			)
		);

		self::assertFalse( ( new ClientRepository() )->validateClient( 'confidential-client', null, 'authorization_code' ) );
		self::assertTrue( ( new ClientRepository() )->validateClient( 'confidential-client', 'secret-value', 'authorization_code' ) );
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

	/**
	 * Build an access-token entity for repository persistence tests.
	 *
	 * @param string $identifier Token identifier.
	 * @param string $client_id  OAuth client identifier.
	 * @param string $user_id    WordPress user identifier.
	 */
	private function access_token_entity( string $identifier, string $client_id, string $user_id ): AccessTokenEntity {
		$client = new ClientEntity();
		$client->setIdentifier( $client_id );
		$client->setName( 'Test client' );

		$token = new AccessTokenEntity();
		$token->setIdentifier( $identifier );
		$token->setClient( $client );
		$token->setUserIdentifier( $user_id );
		$token->setExpiryDateTime( new DateTimeImmutable( '2099-01-01 00:00:00' ) );
		$token->addScope( new ScopeEntity( 'content:read' ) );
		$token->addScope( new ScopeEntity( 'content:draft' ) );

		return $token;
	}

	/**
	 * Build a hydrated OAuth client row.
	 *
	 * @param array<string, mixed> $overrides Row overrides.
	 * @return array<string, mixed>
	 */
	private function client_row( array $overrides ): array {
		return array_merge(
			array(
				'id'                       => '1',
				'client_id'                => 'client-1',
				'client_secret_hash'       => wp_hash_password( 'secret-value' ),
				'client_name'              => 'Test client',
				'provider'                 => 'mcp',
				'redirect_uris'            => '["https:\/\/example.com\/callback"]',
				'registration_fingerprint' => ClientRegistrationFingerprint::from_redirect_uris( array( 'https://example.com/callback' ) ),
				'user_id'                  => null,
				'is_confidential'          => '1',
				'revoked'                  => '0',
				'created_at'               => '2026-06-01 00:00:00',
			),
			$overrides
		);
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
	 * Configured row returned by get_row().
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $row = null;

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
	 * Return a configured row.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output ): ?array {
		unset( $query, $output );

		$this->operations[] = 'get_row';

		return $this->row;
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
		$this->operations[] = 'update';

		return $this->update_result;
	}
}
