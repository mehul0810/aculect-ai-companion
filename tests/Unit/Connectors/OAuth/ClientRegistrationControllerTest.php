<?php
/**
 * Tests for OAuth Dynamic Client Registration helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\ClientRegistrationController;
use Aculect\AICompanion\Connectors\OAuth\TokenEndpointAuthMethod;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Verifies DCR metadata is narrowed before storage.
 */
final class ClientRegistrationControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	public function test_registration_permission_does_not_rate_limit_valid_dcr_retries(): void {
		$controller = new ClientRegistrationController();

		self::assertTrue( $controller->check_registration_permission() );
	}

	public function test_redirect_uris_are_validated_deduplicated_and_limited_to_safe_schemes(): void {
		$uris = $this->invokePrivate(
			new ClientRegistrationController(),
			'redirect_uris',
			array(
				array(
					'https://chatgpt.com/oauth/callback',
					'https://chatgpt.com/oauth/callback',
					'http://localhost/oauth/callback',
					'http://example.com/insecure',
					'https://example.com/with-fragment#token',
					array( 'not-scalar' ),
				),
			)
		);

		self::assertSame(
			array(
				'https://chatgpt.com/oauth/callback',
				'http://localhost/oauth/callback',
			),
			$uris
		);
	}

	public function test_raw_redirect_uris_keep_only_scalar_candidates_for_diagnostics(): void {
		$uris = $this->invokePrivate(
			new ClientRegistrationController(),
			'raw_redirect_uris',
			array(
				array(
					'https://chatgpt.com/oauth/callback',
					123,
					array( 'drop' ),
					false,
				),
			)
		);

		self::assertSame(
			array(
				'https://chatgpt.com/oauth/callback',
				'123',
			),
			$uris
		);
	}

	public function test_registration_defaults_to_confidential_client_secret_post(): void {
		$wpdb            = new FakeDcrWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$response = ( new ClientRegistrationController() )->register_client(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'client_name'   => 'Default MCP Client',
					'redirect_uris' => array( 'http://localhost/callback' ),
				)
			)
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertSame( TokenEndpointAuthMethod::CLIENT_SECRET_POST, $data['token_endpoint_auth_method'] );
		self::assertNotEmpty( $data['client_secret'] );
		self::assertSame( 0, $data['client_secret_expires_at'] );
		self::assertSame( 1, $wpdb->inserts[0]['data']['is_confidential'] );
		self::assertNotEmpty( $wpdb->inserts[0]['data']['client_secret_hash'] );
	}

	public function test_registration_honors_client_secret_basic_confidential_clients(): void {
		$wpdb            = new FakeDcrWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$response = ( new ClientRegistrationController() )->register_client(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'client_name'                => 'Basic MCP Client',
					'redirect_uris'              => array( 'http://localhost/callback' ),
					'token_endpoint_auth_method' => TokenEndpointAuthMethod::CLIENT_SECRET_BASIC,
				)
			)
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		self::assertIsArray( $data );
		self::assertSame( TokenEndpointAuthMethod::CLIENT_SECRET_BASIC, $data['token_endpoint_auth_method'] );
		self::assertNotEmpty( $data['client_secret'] );
		self::assertSame( 1, $wpdb->inserts[0]['data']['is_confidential'] );
	}

	public function test_registration_honors_none_as_public_pkce_client(): void {
		$wpdb            = new FakeDcrWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$response = ( new ClientRegistrationController() )->register_client(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'client_name'                => 'Public MCP Client',
					'redirect_uris'              => array( 'http://localhost/callback' ),
					'token_endpoint_auth_method' => TokenEndpointAuthMethod::NONE,
				)
			)
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		self::assertIsArray( $data );
		self::assertSame( TokenEndpointAuthMethod::NONE, $data['token_endpoint_auth_method'] );
		self::assertArrayNotHasKey( 'client_secret', $data );
		self::assertArrayNotHasKey( 'client_secret_expires_at', $data );
		self::assertSame( 0, $wpdb->inserts[0]['data']['is_confidential'] );
		self::assertNull( $wpdb->inserts[0]['data']['client_secret_hash'] );
	}

	public function test_registration_rejects_unsupported_token_endpoint_auth_method(): void {
		$wpdb            = new FakeDcrWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		$response = ( new ClientRegistrationController() )->register_client(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'client_name'                => 'JWT MCP Client',
					'redirect_uris'              => array( 'http://localhost/callback' ),
					'token_endpoint_auth_method' => 'private_key_jwt',
				)
			)
		);

		self::assertInstanceOf( WP_Error::class, $response );
		self::assertSame( 'invalid_client_metadata', $response->get_error_code() );
		self::assertSame( array( 'status' => 400 ), $response->get_error_data() );
		self::assertSame( array(), $wpdb->inserts );
	}

	public function test_provider_attribution_identifies_common_ai_clients(): void {
		self::assertSame( 'chatgpt', Helpers::provider_from_client( 'ChatGPT Connector', array( 'https://chatgpt.com/oauth/callback' ) ) );
		self::assertSame( 'codex', Helpers::provider_from_client( 'Codex MCP Client', array( 'http://127.0.0.1:1455/callback' ) ) );
		self::assertSame( 'claude', Helpers::provider_from_client( 'Claude Desktop', array( 'http://localhost/callback' ) ) );
		self::assertSame( 'mcp', Helpers::provider_from_client( 'Local MCP Client', array( 'http://localhost/callback' ) ) );
		self::assertSame( 'mcp', Helpers::provider_from_client( 'Custom Client', array( 'https://example.org/callback' ) ) );
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
}

/**
 * Minimal wpdb test double for Dynamic Client Registration tests.
 */
final class FakeDcrWpdb {

	public string $prefix = 'wp_';

	/**
	 * Insert calls.
	 *
	 * @var array<int, array{table: string, data: array<string, mixed>}>
	 */
	public array $inserts = array();

	/**
	 * Record a prepared SQL template.
	 *
	 * @param string $query SQL query with placeholders.
	 * @param mixed  ...$args Placeholder arguments.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		unset( $args );

		return $query;
	}

	/**
	 * Return the active client count.
	 *
	 * @param string $query SQL query.
	 */
	public function get_var( string $query ): int {
		unset( $query );

		return 0;
	}

	/**
	 * Record duplicate-cleanup queries.
	 *
	 * @param string $query SQL query.
	 */
	public function query( string $query ): int {
		unset( $query );

		return 1;
	}

	/**
	 * Record a client insert.
	 *
	 * @param string               $table   Table name.
	 * @param array<string, mixed> $data    Insert data.
	 * @param string[]             $formats Insert formats.
	 */
	public function insert( string $table, array $data, array $formats ): int {
		unset( $formats );

		$this->inserts[] = array(
			'table' => $table,
			'data'  => $data,
		);

		return 1;
	}
}
