<?php
/**
 * Tests for OAuth token endpoint helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use DateTimeImmutable;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\Entities\AccessTokenEntity;
use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use Aculect\AICompanion\Connectors\OAuth\Entities\RefreshTokenEntity;
use Aculect\AICompanion\Connectors\OAuth\Server\KeyManager;
use Aculect\AICompanion\Connectors\OAuth\TokenController;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Nyholm\Psr7\Response as Psr7Response;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_REST_Request;
use WP_REST_Response;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused token timeline tests replace wpdb with a local test double.

/**
 * Verifies token endpoint resource handling and error response shape.
 */
final class TokenControllerTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		KeyManager::delete_keys();
	}

	protected function tearDown(): void {
		KeyManager::delete_keys();
		delete_option( 'aculect_ai_companion_activity_last_pruned_at' );

		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}

		parent::tearDown();
	}

	public function test_resource_from_request_prefers_resource_then_audience_then_default(): void {
		$controller = new TokenController();

		self::assertSame(
			'https://client.example/resource',
			$this->invokePrivate(
				$controller,
				'resource_from_request',
				array( new WP_REST_Request( array( 'resource' => 'https://client.example/resource/' ) ) )
			)
		);

		self::assertSame(
			'https://client.example/audience',
			$this->invokePrivate(
				$controller,
				'resource_from_request',
				array( new WP_REST_Request( array( 'audience' => 'https://client.example/audience/' ) ) )
			)
		);

		self::assertSame(
			Helpers::mcp_resource(),
			$this->invokePrivate( $controller, 'resource_from_request', array( new WP_REST_Request() ) )
		);
	}

	public function test_error_response_uses_oauth_shape_and_no_store_headers(): void {
		$response = $this->invokePrivate(
			new TokenController(),
			'error',
			array( 'invalid_target', 'Resource mismatch.', 400 )
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 400, $response->get_status() );
		self::assertSame(
			array(
				'error'             => 'invalid_target',
				'error_description' => 'Resource mismatch.',
			),
			$response->get_data()
		);
		self::assertSame( 'no-store', $response->header( 'Cache-Control' ) );
		self::assertSame( 'no-cache', $response->header( 'Pragma' ) );
	}

	public function test_server_error_description_does_not_expose_exception_details(): void {
		$description = $this->invokePrivate( new TokenController(), 'server_error_description' );

		self::assertSame( 'The OAuth token request failed. Try again or reconnect the client.', $description );
		self::assertStringNotContainsString( 'SQL', $description );
		self::assertStringNotContainsString( 'Exception', $description );
	}

	public function test_removed_dcr_client_receives_401_invalid_client_for_reregistration(): void {
		$wpdb            = new FakeTokenTimelineWpdb( null );
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_activity_last_pruned_at', time(), false );

		$response = ( new TokenController() )->token(
			new WP_REST_Request(
				array(
					'grant_type' => 'authorization_code',
					'client_id'  => 'removed-dcr-client',
					'code'       => 'not-inspected-before-client-validation',
					'resource'   => Helpers::mcp_resource(),
				)
			)
		);

		self::assertSame( 401, $response->get_status() );
		self::assertSame(
			array(
				'error'             => 'invalid_client',
				'error_description' => 'Client authentication failed. Register the client again.',
			),
			$response->get_data()
		);
		self::assertSame( 'no-store', $response->header( 'Cache-Control' ) );
		self::assertSame( 'no-cache', $response->header( 'Pragma' ) );
		self::assertStringNotContainsString( 'removed-dcr-client', (string) wp_json_encode( $response->get_data() ) );

		$activity = $wpdb->last_insert_for_table( 'wp_aculect_ai_companion_activity' );
		self::assertSame( 'invalid_client', $activity['data']['error_code'] );
		self::assertStringNotContainsString( 'removed-dcr-client', (string) wp_json_encode( $activity ) );
	}

	public function test_token_timeline_event_classifies_exchange_and_refresh_grants(): void {
		$controller = new TokenController();

		self::assertSame(
			'token_exchange',
			$this->invokePrivate(
				$controller,
				'token_timeline_event',
				array( new WP_REST_Request( array( 'grant_type' => 'authorization_code' ) ) )
			)
		);
		self::assertSame(
			'token_refresh',
			$this->invokePrivate(
				$controller,
				'token_timeline_event',
				array( new WP_REST_Request( array( 'grant_type' => 'refresh_token' ) ) )
			)
		);
	}

	public function test_token_result_class_is_bounded_for_timeline_metadata(): void {
		$controller = new TokenController();

		self::assertSame( 'issued', $this->invokePrivate( $controller, 'token_result_class', array( 200 ) ) );
		self::assertSame( 'oauth_error', $this->invokePrivate( $controller, 'token_result_class', array( 400 ) ) );
		self::assertSame( 'server_error', $this->invokePrivate( $controller, 'token_result_class', array( 500 ) ) );
	}

	public function test_timeline_auth_context_uses_client_identifier_without_secret_material(): void {
		$context = $this->invokePrivate(
			new TokenController(),
			'timeline_auth_context',
			array(
				new WP_REST_Request(
					array(
						'client_id'     => '',
						'client_secret' => 'do-not-store',
						'refresh_token' => 'do-not-store',
					)
				),
			)
		);

		self::assertSame( '', $context['provider'] );
		self::assertSame( '', $context['client_id'] );
		self::assertArrayNotHasKey( 'client_secret', $context );
		self::assertArrayNotHasKey( 'refresh_token', $context );
	}

	public function test_invalid_grant_refresh_context_uses_league_issued_token_identifier_for_stored_states(): void {
		$cases = array(
			'revoked' => array(
				'row'        => array(
					'revoked'    => '1',
					'expires_at' => '2099-01-01 00:00:00',
				),
				'expires_at' => '2099-01-01 00:00:00',
				'expected'   => 'revoked',
			),
			'expired' => array(
				'row'        => array(
					'revoked'    => '0',
					'expires_at' => '2000-01-01 00:00:00',
				),
				'expires_at' => '2000-01-01 00:00:00',
				'expected'   => 'expired',
			),
			'active'  => array(
				'row'        => array(
					'revoked'    => '0',
					'expires_at' => '2099-01-01 00:00:00',
				),
				'expires_at' => '2099-01-01 00:00:00',
				'expected'   => 'active_in_storage',
			),
			'missing' => array(
				'row'        => null,
				'expires_at' => '2099-01-01 00:00:00',
				'expected'   => 'not_found',
			),
		);

		foreach ( $cases as $label => $case ) {
			$token_id        = 'refresh-token-' . $label;
			$presented_token = $this->league_issued_refresh_token( $token_id, new DateTimeImmutable( $case['expires_at'] . ' UTC' ) );
			$row             = is_array( $case['row'] )
				? array_merge(
					$case['row'],
					array(
						'connection_id' => '42',
						'client_id'     => 'stored-codex-client',
						'provider'      => 'codex',
					)
				)
				: null;
			$wpdb            = new FakeTokenTimelineWpdb( $row );
			$GLOBALS['wpdb'] = $wpdb;
			$controller      = new TokenController();
			$request         = new WP_REST_Request(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $presented_token,
				)
			);

			$context = $this->invokePrivate( $controller, 'refresh_rejection_context', array( $request, 'invalid_grant' ) );

			self::assertSame( 'unavailable_pre_auth', $context['identity_status'], $label );
			self::assertSame( 'reconnect_assistant', $context['recovery_action'], $label );
			self::assertSame( $case['expected'], $context['refresh_token_state'], $label );
			self::assertArrayNotHasKey( 'user_id', $context, $label );
			self::assertArrayNotHasKey( 'refresh_token', $context, $label );
			self::assertSame( hash( 'sha256', $token_id ), $wpdb->prepared[0]['args'][3], $label );
			self::assertNotSame( hash( 'sha256', $presented_token ), $wpdb->prepared[0]['args'][3], $label );
			self::assertNotContains( $presented_token, $wpdb->prepared[0]['args'], $label );

			if ( is_array( $row ) ) {
				$auth = $this->invokePrivate(
					$controller,
					'correlate_timeline_auth',
					array(
						array(
							'provider'  => '',
							'client_id' => '',
						),
						$context,
					)
				);
				self::assertSame( 42, $context['connection_id'], $label );
				self::assertSame( 'codex', $auth['provider'], $label );
				self::assertSame( 'stored-codex-client', $auth['client_id'], $label );
			}
		}
	}

	public function test_malformed_refresh_token_remains_unclassified_and_is_not_queried(): void {
		$wpdb            = new FakeTokenTimelineWpdb(
			array(
				'revoked'    => '1',
				'expires_at' => '2099-01-01 00:00:00',
			)
		);
		$GLOBALS['wpdb'] = $wpdb;
		$request         = new WP_REST_Request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'malformed-refresh-token',
			)
		);

		$context = $this->invokePrivate( new TokenController(), 'refresh_rejection_context', array( $request, 'invalid_grant' ) );

		self::assertSame( 'unavailable_pre_auth', $context['identity_status'] );
		self::assertSame( 'reconnect_assistant', $context['recovery_action'] );
		self::assertArrayNotHasKey( 'refresh_token_state', $context );
		self::assertSame( array(), $wpdb->prepared );
	}

	public function test_invalid_target_refresh_records_pre_auth_context_without_changing_client_response(): void {
		$raw_token        = 'do-not-store-invalid-target-token';
		$wpdb             = new FakeTokenTimelineWpdb( null );
		$GLOBALS['wpdb']  = $wpdb;
		$controller       = new TokenController();
		$expected_message = 'Refresh was rejected before a WordPress identity was available. This request did not authenticate a WordPress session. Reconnect the assistant to restore access.';
		update_option( 'aculect_ai_companion_activity_last_pruned_at', time(), false );

		$response = $controller->token(
			new WP_REST_Request(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $raw_token,
					'resource'      => 'https://invalid.example/mcp',
				)
			)
		);
		$activity = $wpdb->last_insert_for_table( 'wp_aculect_ai_companion_activity' );
		$context  = json_decode( (string) $activity['data']['context'], true, 512, JSON_THROW_ON_ERROR );

		self::assertSame( 400, $response->get_status() );
		self::assertSame(
			array(
				'error'             => 'invalid_target',
				'error_description' => 'The requested resource does not match this Aculect AI Companion MCP server.',
			),
			$response->get_data()
		);
		self::assertSame( 'mcp.timeline.token_refresh', $activity['data']['action'] );
		self::assertSame( 'invalid_target', $activity['data']['error_code'] );
		self::assertSame( 0, $activity['data']['user_id'] );
		self::assertSame( $expected_message, $activity['data']['message'] );
		self::assertSame( 'unavailable_pre_auth', $context['identity_status'] );
		self::assertSame( 'reconnect_assistant', $context['recovery_action'] );
		self::assertArrayNotHasKey( 'refresh_token_state', $context );
		self::assertStringNotContainsString( $raw_token, (string) wp_json_encode( $activity ) );
		self::assertSame( array(), $wpdb->prepared );
	}

	public function test_other_refresh_errors_get_pre_auth_guidance_without_token_state(): void {
		$request = new WP_REST_Request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => 'do-not-read',
			)
		);

		$context = $this->invokePrivate( new TokenController(), 'refresh_rejection_context', array( $request, 'invalid_client' ) );

		self::assertSame( 'unavailable_pre_auth', $context['identity_status'] );
		self::assertSame( 'reconnect_assistant', $context['recovery_action'] );
		self::assertArrayNotHasKey( 'refresh_token_state', $context );
	}

	/**
	 * Invoke a private method for focused unit coverage.
	 *
	 * @param object            $object    Object instance.
	 * @param string            $method    Method name.
	 * @param array<int, mixed> $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}

	/**
	 * Issue an encrypted refresh token with League's production response type.
	 *
	 * @param string            $token_id Refresh-token identifier persisted by the repository.
	 * @param DateTimeImmutable $expiry   Refresh-token expiry.
	 */
	private function league_issued_refresh_token( string $token_id, DateTimeImmutable $expiry ): string {
		$client = new ClientEntity();
		$client->setIdentifier( 'stored-codex-client' );
		$client->setName( 'Codex' );

		$private_key = new CryptKey( KeyManager::private_key(), null, false );
		$access      = new AccessTokenEntity();
		$access->setIdentifier( 'access-token-' . $token_id );
		$access->setClient( $client );
		$access->setUserIdentifier( '7' );
		$access->setExpiryDateTime( new DateTimeImmutable( '+1 hour' ) );
		$access->setPrivateKey( $private_key );

		$refresh = new RefreshTokenEntity();
		$refresh->setIdentifier( $token_id );
		$refresh->setAccessToken( $access );
		$refresh->setExpiryDateTime( $expiry );

		$response_type = new BearerTokenResponse();
		$response_type->setPrivateKey( $private_key );
		$response_type->setEncryptionKey( KeyManager::encryption_key() );
		$response_type->setAccessToken( $access );
		$response_type->setRefreshToken( $refresh );
		$response = $response_type->generateHttpResponse( new Psr7Response() );
		$data     = json_decode( (string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR );

		self::assertIsArray( $data );
		self::assertArrayHasKey( 'refresh_token', $data );

		return (string) $data['refresh_token'];
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- This test double is intentionally local to token timeline coverage.

/**
 * Minimal wpdb test double for refresh-token timeline correlation.
 */
final class FakeTokenTimelineWpdb {

	public string $prefix = 'wp_';

	/**
	 * Prepared SQL calls.
	 *
	 * @var array<int, array{query: string, args: array<int, mixed>}>
	 */
	public array $prepared = array();

	/**
	 * Insert calls.
	 *
	 * @var array<int, array{table: string, data: array<string, mixed>, formats: array<int, string>}>
	 */
	public array $inserts = array();

	/**
	 * Initialize the test double.
	 *
	 * @param array<string, mixed>|null $row Configured row.
	 */
	public function __construct( private ?array $row ) {
	}

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
	 * Return the configured row.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output ): ?array {
		unset( $query, $output );

		return $this->row;
	}

	/**
	 * Record an insert.
	 *
	 * @param string               $table   Table name.
	 * @param array<string, mixed> $data    Insert data.
	 * @param string[]             $formats Insert formats.
	 */
	public function insert( string $table, array $data, array $formats ): int {
		$this->inserts[] = array(
			'table'   => $table,
			'data'    => $data,
			'formats' => $formats,
		);

		return 1;
	}

	/**
	 * Return the last captured insert for a table.
	 *
	 * @param string $table Table name.
	 * @return array{table: string, data: array<string, mixed>, formats: array<int, string>}
	 * @throws \RuntimeException When the expected insert was not captured.
	 */
	public function last_insert_for_table( string $table ): array {
		foreach ( array_reverse( $this->inserts ) as $insert ) {
			if ( $table === $insert['table'] ) {
				return $insert;
			}
		}

		throw new \RuntimeException( 'Expected activity insert was not captured.' );
	}
}
