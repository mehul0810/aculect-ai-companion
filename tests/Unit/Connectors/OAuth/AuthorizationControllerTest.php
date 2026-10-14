<?php
/**
 * Tests for OAuth authorization request handling.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\MCP\RoleConnectionEntryPoint;
use Aculect\AICompanion\Connectors\OAuth\AuthorizationController;
use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use Aculect\AICompanion\Connectors\OAuth\IssuerBinding;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Error;

/**
 * Verifies OAuth request parameters are sanitized and validated before use.
 */
final class AuthorizationControllerTest extends TestCase {

	private array $original_server = array();

	protected function setUp(): void {
		parent::setUp();
		$this->original_server = $_SERVER;

		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_transients']      = array();
		$GLOBALS['aculect_ai_companion_test_hooks']           = array(
			'actions' => array(),
			'filters' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array( 'manage_options' );
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			7 => new \WP_User(
				array(
					'ID'    => 7,
					'roles' => array( 'editor' ),
				)
			),
			9 => new \WP_User(
				array(
					'ID'    => 9,
					'roles' => array( 'author' ),
				)
			),
		);
		$_GET  = array();
		$_POST = array();
		update_option( 'aculect_ai_companion_oauth_db_version', '2026.08.19.1', false );
		update_option( 'aculect_ai_companion_oauth_issuer_backfill', IssuerBinding::hash(), false );
	}

	protected function tearDown(): void {
		$_SERVER = $this->original_server;
		parent::tearDown();
	}

	public function test_consent_request_budget_stops_new_transients_for_one_source(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.23';
		$controller             = new AuthorizationController();
		$params                 = array(
			'response_type'         => 'code',
			'client_id'             => 'client-1',
			'redirect_uri'          => 'https://client.example/callback',
			'code_challenge'        => str_repeat( 'a', 43 ),
			'code_challenge_method' => 'S256',
		);

		for ( $attempt = 0; $attempt < 30; ++$attempt ) {
			self::assertIsString( $this->invokePrivate( $controller, 'store_consent_request', array( $params ) ) );
		}

		$before = array_filter(
			array_keys( $GLOBALS['aculect_ai_companion_test_transients'] ),
			static fn( string $key ): bool => str_starts_with( $key, 'aculect_ai_companion_oauth_consent_' )
		);
		self::assertCount( 30, $before );

		$limited = $this->invokePrivate( $controller, 'store_consent_request', array( $params ) );
		self::assertInstanceOf( WP_Error::class, $limited );
		self::assertSame( 429, $limited->get_error_data()['status'] );

		$after = array_filter(
			array_keys( $GLOBALS['aculect_ai_companion_test_transients'] ),
			static fn( string $key ): bool => str_starts_with( $key, 'aculect_ai_companion_oauth_consent_' )
		);
		self::assertCount( 30, $after );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.24';
		self::assertIsString( $this->invokePrivate( $controller, 'store_consent_request', array( $params ) ) );
	}

	public function test_params_from_array_allowlists_and_sanitizes_oauth_parameters(): void {
		$challenge = str_repeat( 'a', 43 );
		$params    = $this->invokePrivate(
			new AuthorizationController(),
			'params_from_array',
			array(
				array(
					'response_type'          => 'code',
					'client_id'              => '<strong>client-1</strong>',
					'redirect_uri'           => 'https://chatgpt.com/oauth/callback?client=1',
					'scope'                  => "content:read\tcontent:draft",
					'state'                  => '<b>state-value</b>',
					'code_challenge'         => $challenge . '=!',
					'code_challenge_method'  => 's256',
					'resource'               => 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
					'_wpnonce'               => 'nonce-value',
					'decision'               => 'approve',
					'unexpected_extra_value' => 'discard me',
				),
			)
		);

		self::assertSame(
			array(
				'response_type',
				'client_id',
				'redirect_uri',
				'scope',
				'state',
				'code_challenge',
				'code_challenge_method',
				'resource',
			),
			array_keys( $params )
		);
		self::assertSame( 'client-1', $params['client_id'] );
		self::assertSame( 'content:read content:draft', $params['scope'] );
		self::assertSame( 'state-value', $params['state'] );
		self::assertSame( $challenge, $params['code_challenge'] );
		self::assertSame( 'S256', $params['code_challenge_method'] );
	}

	public function test_posted_helpers_unslash_sanitize_and_validate_form_fields(): void {
		$_POST = array(
			'request_token' => 'ABCD\\1234-not-a-token',
			'_wpnonce'      => 'nonce\\value',
			'decision'      => 'approve',
		);

		$controller = new AuthorizationController();

		self::assertSame( 'abcd1234ae', $this->invokePrivate( $controller, 'posted_request_token' ) );
		self::assertSame( 'noncevalue', $this->invokePrivate( $controller, 'posted_nonce' ) );
		self::assertSame( 'approve', $this->invokePrivate( $controller, 'posted_decision' ) );
	}

	public function test_pkce_and_scope_validation_are_strict(): void {
		$controller = new AuthorizationController();

		self::assertTrue( $this->invokePrivate( $controller, 'valid_code_challenge', array( str_repeat( 'a', 43 ) ) ) );
		self::assertFalse( $this->invokePrivate( $controller, 'valid_code_challenge', array( str_repeat( 'a', 42 ) ) ) );
		self::assertTrue( $this->invokePrivate( $controller, 'scope_tokens_supported', array( array( 'content:read', 'content:draft', 'offline_access' ) ) ) );
		self::assertFalse( $this->invokePrivate( $controller, 'scope_tokens_supported', array( array( 'content:read', 'options:write' ) ) ) );
	}

	public function test_redirect_validation_allows_only_the_registered_loopback_port_to_vary(): void {
		$controller = new AuthorizationController();
		$client     = new ClientEntity();
		$client->setRedirectUri(
			array(
				'https://claude.ai/api/mcp/auth_callback',
				'http://localhost/callback',
			)
		);

		self::assertTrue(
			$this->invokePrivate(
				$controller,
				'redirect_uri_allowed',
				array( $client, 'https://claude.ai/api/mcp/auth_callback' )
			)
		);
		self::assertTrue(
			$this->invokePrivate(
				$controller,
				'redirect_uri_allowed',
				array( $client, 'http://localhost:3118/callback' )
			)
		);
		self::assertFalse(
			$this->invokePrivate(
				$controller,
				'redirect_uri_allowed',
				array( $client, 'http://localhost:3118/other' )
			)
		);
		self::assertFalse(
			$this->invokePrivate(
				$controller,
				'redirect_uri_allowed',
				array( $client, 'https://claude.ai:8443/api/mcp/auth_callback' )
			)
		);
	}

	public function test_admin_consent_url_uses_wordpress_settings_page(): void {
		$url = $this->invokePrivate(
			new AuthorizationController(),
			'admin_consent_url',
			array( 'abcdef1234567890' )
		);

		self::assertIsString( $url );
		self::assertStringStartsWith( 'https://example.com/wp-admin/admin.php?', $url );
		self::assertStringContainsString( 'page=aculect-ai-companion-oauth-consent', $url );
		self::assertStringContainsString( 'request_token=abcdef1234567890', $url );
	}

	public function test_authorization_endpoint_uses_root_route_for_browser_cookie_auth(): void {
		self::assertSame(
			'https://example.com/aculect-ai-companion/oauth/authorize',
			Helpers::authorization_endpoint()
		);
		self::assertStringNotContainsString(
			'/wp-json/',
			Helpers::authorization_endpoint()
		);
	}

	public function test_success_and_error_authorization_locations_include_exact_rfc9207_issuer(): void {
		$controller = new AuthorizationController();
		$success    = $this->invokePrivate(
			$controller,
			'authorization_response_location',
			array( 'https://client.example/callback?code=abc&state=state-value' )
		);
		$error      = $this->invokePrivate(
			$controller,
			'authorization_response_location',
			array(
				'https://client.example/callback',
				array(
					'error' => 'access_denied',
					'state' => 'state-value',
				),
			)
		);

		self::assertSame( Helpers::issuer(), $this->query_param( $success, 'iss' ) );
		self::assertSame( Helpers::issuer(), $this->query_param( $error, 'iss' ) );
		self::assertSame( 'abc', $this->query_param( $success, 'code' ) );
		self::assertSame( 'access_denied', $this->query_param( $error, 'error' ) );
	}

	public function test_server_error_description_does_not_expose_exception_details(): void {
		$description = $this->invokePrivate( new AuthorizationController(), 'server_error_description' );

		self::assertSame( 'Aculect AI Companion could not complete the authorization request. Try reconnecting the client.', $description );
		self::assertStringNotContainsString( 'SQL', $description );
		self::assertStringNotContainsString( 'Exception', $description );
	}

	public function test_consent_markup_uses_connection_request_framing(): void {
		$client = new ClientEntity();
		$client->setName( 'Unrelated client' );
		$client->setProvider( 'mcp' );

		ob_start();
		$this->invokePrivate(
			new AuthorizationController(),
			'render_consent_markup',
			array(
				'abcdef1234567890',
				array(
					'response_type'         => 'code',
					'client_id'             => 'client-1',
					'redirect_uri'          => 'https://chatgpt.com/connector_platform_oauth_redirect',
					'scope'                 => 'content:read content:draft',
					'code_challenge'        => str_repeat( 'a', 43 ),
					'code_challenge_method' => 'S256',
					'resource'              => Helpers::mcp_resource(),
				),
				$client,
				Helpers::mcp_resource(),
			)
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Review connection request', $html );
		self::assertStringContainsString(
			'An OAuth client is requesting permission to connect to your WordPress site through Aculect AI Companion.',
			$html
		);
		self::assertStringContainsString( 'Connection details', $html );
		self::assertStringContainsString( 'What this client will be able to do', $html );
		self::assertStringContainsString( 'Client identity is not verified', $html );
		self::assertStringContainsString( 'Matches a documented provider callback', $html );
		self::assertStringContainsString( 'Recognized return destination', $html );
		self::assertStringContainsString( 'ChatGPT', $html );
		self::assertStringContainsString( 'Unrelated client', $html );
		self::assertStringContainsString( 'https://chatgpt.com/connector_platform_oauth_redirect', $html );
		self::assertStringContainsString( 'client-1', $html );
		self::assertStringContainsString( 'Security and privacy', $html );
		self::assertStringContainsString( 'Approve connection', $html );
		self::assertStringContainsString( 'Deny', $html );
		self::assertStringContainsString( 'name="request_token"', $html );
		self::assertStringContainsString( 'nonce-aculect_ai_companion_oauth_authorize_identity_v2', $html );
		self::assertStringNotContainsString( 'name="client_id"', $html );
		self::assertStringNotContainsString( 'Approve AI assistant access', $html );
		self::assertStringNotContainsString( 'ChatGPT is requesting permission', $html );
		self::assertStringNotContainsString( 'What ChatGPT will be able to do', $html );
		self::assertStringNotContainsString( 'Verified provider callback', $html );
	}

	public function test_consent_markup_does_not_promote_spoofed_existing_provider_state(): void {
		$client = new ClientEntity();
		$client->setName( 'ChatGPT' );
		$client->setProvider( 'chatgpt' );

		ob_start();
		$this->invokePrivate(
			new AuthorizationController(),
			'render_consent_markup',
			array(
				'abcdef1234567890',
				array(
					'response_type'         => 'code',
					'client_id'             => 'attacker-client',
					'redirect_uri'          => 'https://attacker.example/oauth/callback',
					'scope'                 => 'content:read content:draft',
					'code_challenge'        => str_repeat( 'a', 43 ),
					'code_challenge_method' => 'S256',
					'resource'              => Helpers::mcp_resource(),
				),
				$client,
				Helpers::mcp_resource(),
			)
		);
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'An OAuth client is requesting permission', $html );
		self::assertStringContainsString( 'Client identity is not verified', $html );
		self::assertStringContainsString( 'No documented provider callback match', $html );
		self::assertStringContainsString( 'No documented provider match', $html );
		self::assertStringContainsString( 'Client-provided name', $html );
		self::assertStringContainsString( 'ChatGPT', $html );
		self::assertStringContainsString( 'https://attacker.example/oauth/callback', $html );
		self::assertStringContainsString( 'attacker-client', $html );
		self::assertStringContainsString( 'What this client will be able to do', $html );
		self::assertStringNotContainsString( 'ChatGPT is requesting permission', $html );
		self::assertStringNotContainsString( 'What ChatGPT will be able to do', $html );
	}

	public function test_pre_083_consent_nonce_cannot_approve_after_identity_boundary_upgrade(): void {
		$legacy_nonce         = wp_create_nonce( 'aculect_ai_companion_oauth_authorize' );
		$current              = new \ReflectionClass( AuthorizationController::class );
		$current_nonce_action = (string) $current->getConstant( 'NONCE_ACTION' );

		self::assertSame( 'aculect_ai_companion_oauth_authorize_identity_v2', $current_nonce_action );
		self::assertFalse( wp_verify_nonce( $legacy_nonce, $current_nonce_action ) );
		self::assertTrue( wp_verify_nonce( wp_create_nonce( $current_nonce_action ), $current_nonce_action ) );
	}

	public function test_load_consent_request_returns_null_for_expired_request(): void {
		$controller = new AuthorizationController();

		set_transient(
			'aculect_ai_companion_oauth_consent_expired',
			array(
				'params'     => array(
					'response_type'         => 'code',
					'client_id'             => 'client-1',
					'redirect_uri'          => 'https://chatgpt.com/oauth/callback',
					'code_challenge'        => str_repeat( 'a', 43 ),
					'code_challenge_method' => 'S256',
				),
				'user_id'    => 7,
				'expires_at' => 99,
			),
			600
		);

		self::assertNull(
			$this->invokePrivate( $controller, 'load_consent_request', array( 'expired', 100 ) )
		);
	}

	public function test_requested_scopes_allowed_for_current_user_rejects_unavailable_scope(): void {
		RoleConnectionEntryPoint::save( true, array( 'editor' ) );

		$allowed = $this->invokePrivate(
			new AuthorizationController(),
			'requested_scopes_allowed_for_current_user',
			array( array( 'options:write' ) )
		);

		self::assertFalse( $allowed );
	}

	public function test_offline_access_supports_refresh_without_granting_content_privileges(): void {
		RoleConnectionEntryPoint::save( true, array( 'editor' ) );
		$controller = new AuthorizationController();
		$scopes     = $this->invokePrivate( $controller, 'allowed_scopes_for_user', array( 7 ) );

		self::assertContains( 'content:read', $scopes );
		self::assertContains( 'offline_access', $scopes );
		self::assertNotContains( 'options:write', $scopes );
		self::assertTrue(
			$this->invokePrivate(
				$controller,
				'requested_scopes_allowed_for_current_user',
				array( array( 'content:read', 'offline_access' ) )
			)
		);
	}

	public function test_assert_current_user_can_consent_allows_configured_non_admin_with_own_client(): void {
		RoleConnectionEntryPoint::save( true, array( 'editor' ) );

		$client = new ClientEntity();
		$client->setIdentifier( 'client-1' );
		$client->setName( 'ChatGPT Connector' );
		$client->setUserId( 7 );

		$this->invokePrivate(
			new AuthorizationController(),
			'assert_current_user_can_consent',
			array(
				array(
					'scope' => 'content:read',
				),
				$client,
				7,
				false,
			)
		);

		self::assertTrue( true );
	}

	public function test_assert_current_user_can_consent_rejects_request_for_different_user(): void {
		RoleConnectionEntryPoint::save( true, array( 'editor' ) );

		$client = new ClientEntity();
		$client->setIdentifier( 'client-1' );
		$client->setUserId( 7 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'This authorization request belongs to a different WordPress user.' );

		$this->invokePrivate(
			new AuthorizationController(),
			'assert_current_user_can_consent',
			array(
				array(
					'scope' => 'content:read',
				),
				$client,
				9,
				true,
			)
		);
	}

	public function test_assert_current_user_can_consent_rejects_admin_for_different_users_request(): void {
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 9;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();

		$client = new ClientEntity();
		$client->setIdentifier( 'client-1' );
		$client->setUserId( 7 );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'This authorization request belongs to a different WordPress user.' );

		$this->invokePrivate(
			new AuthorizationController(),
			'assert_current_user_can_consent',
			array(
				array(
					'scope' => 'content:read',
				),
				$client,
				7,
				true,
			)
		);
	}

	/**
	 * Invoke a private method for focused unit coverage without widening runtime API.
	 *
	 * @param object $object    Object instance.
	 * @param string $method    Method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}

	private function query_param( string $url, string $key ): string {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $params );

		return is_scalar( $params[ $key ] ?? null ) ? (string) $params[ $key ] : '';
	}
}
