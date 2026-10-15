<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\McpController;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/Support/McpAppsSessionSalt.php';
require_once dirname( __DIR__, 3 ) . '/Support/McpAppsRandomFailure.php';

final class McpAppsNegotiationTest extends TestCase {

	private mixed $previous_random_bytes_failure;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_random_bytes_failure = $GLOBALS['aculect_ai_companion_test_random_bytes_failure'] ?? null;
		$GLOBALS['aculect_ai_companion_test_transients']           = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']     = array();
		$GLOBALS['aculect_ai_companion_test_salt']                 = 'mcp-app-session-test-salt-auth';
		$GLOBALS['mcp_approval_test_salt']                         = 'unit-test-only-stable-salt-auth';
		$GLOBALS['aculect_ai_companion_test_random_bytes_failure'] = false;
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_transients']           = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']     = array();
		$GLOBALS['aculect_ai_companion_test_salt']                 = 'mcp-app-session-test-salt-auth';
		$GLOBALS['mcp_approval_test_salt']                         = 'unit-test-only-stable-salt-auth';
		if ( null === $this->previous_random_bytes_failure ) {
			unset( $GLOBALS['aculect_ai_companion_test_random_bytes_failure'] );
		} else {
			$GLOBALS['aculect_ai_companion_test_random_bytes_failure'] = $this->previous_random_bytes_failure;
		}
		parent::tearDown();
	}

	public function test_feature_is_off_by_default_even_when_client_advertises_it(): void {
		$request      = $this->initialize_request( true );
		$capabilities = array(
			'tools'     => array( 'listChanged' => false ),
			'resources' => array( 'listChanged' => false ),
		);

		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'initialize', $request, '2025-11-25', $this->auth() ) );
		self::assertSame( $capabilities, McpAppsNegotiation::initialize_capabilities( $capabilities, true ) );
	}

	public function test_initialize_negotiates_supported_legacy_client_in_a_protocol_session(): void {
		$this->enable_feature();
		$auth         = $this->auth();
		$capabilities = array(
			'tools'     => array( 'listChanged' => false ),
			'resources' => array( 'listChanged' => false ),
		);

		$request    = $this->initialize_request( true );
		$session_id = McpAppsNegotiation::create_legacy_session_id( $request['params'], $auth );
		self::assertNotNull( $session_id );
		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'initialize', $request, '2025-11-25', $auth ) );
		self::assertSame(
			array(
				'tools'      => array( 'listChanged' => false ),
				'resources'  => array( 'listChanged' => false ),
				'extensions' => array( McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => array( McpAppsNegotiation::MIME_TYPE ) ) ),
			),
			McpAppsNegotiation::initialize_capabilities( $capabilities, true )
		);
		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'tools/list', array( 'method' => 'tools/list' ), '2025-11-25', $auth, (string) $session_id ) );
	}

	public function test_initialize_without_supported_mime_type_does_not_enable_ui(): void {
		$this->enable_feature();
		$auth = $this->auth();

		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'initialize', $this->initialize_request( false ), '2025-11-25', $auth ) );
		self::assertNull( McpAppsNegotiation::create_legacy_session_id( $this->initialize_request( false )['params'], $auth ) );
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth ) );
	}

	public function test_malformed_and_partial_client_capabilities_fail_closed(): void {
		$this->enable_feature();
		$auth  = $this->auth();
		$cases = array(
			array(),
			array( 'capabilities' => 'ui' ),
			array( 'capabilities' => array( 'extensions' => 'ui' ) ),
			array( 'capabilities' => array( 'extensions' => array( McpAppsNegotiation::EXTENSION => true ) ) ),
			array( 'capabilities' => array( 'extensions' => array( McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => 'text/html;profile=mcp-app' ) ) ) ),
			array( 'capabilities' => array( 'extensions' => array( McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => array( 'text/html' ) ) ) ) ),
		);

		foreach ( $cases as $params ) {
			self::assertFalse( McpAppsNegotiation::enabled_for_request( 'initialize', array( 'params' => $params ), '2025-11-25', $auth ) );
			self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth ) );
		}
	}

	public function test_legacy_parallel_initializations_using_one_token_have_isolated_ui_state(): void {
		$this->enable_feature();
		$auth      = $this->auth();
		$first_id  = McpAppsNegotiation::create_legacy_session_id( $this->initialize_request( true )['params'], $auth );
		$second_id = McpAppsNegotiation::create_legacy_session_id( $this->initialize_request( true )['params'], $auth );

		self::assertNotNull( $first_id );
		self::assertNotSame( $first_id, $second_id );
		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth, (string) $first_id ) );
		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth, (string) $second_id ) );
	}

	public function test_legacy_session_ids_are_stateless_and_parallel_sessions_remain_distinct(): void {
		$this->enable_feature();
		$auth       = $this->auth();
		$parameters = $this->initialize_request( true )['params'];
		$first      = McpAppsNegotiation::create_legacy_session_id( $parameters, $auth );
		$second     = McpAppsNegotiation::create_legacy_session_id( $parameters, $auth );

		self::assertNotNull( $first );
		self::assertNotSame( $first, $second );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_transients'] );
		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth, (string) $first ) );
		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth, (string) $second ) );
	}

	public function test_legacy_session_rejects_tampering_expiry_and_future_timestamps(): void {
		$this->enable_feature();
		$auth       = $this->auth();
		$session_id = McpAppsNegotiation::create_legacy_session_id( $this->initialize_request( true )['params'], $auth );
		self::assertNotNull( $session_id );
		$tampered = substr( $session_id, 0, -1 ) . ( str_ends_with( $session_id, '0' ) ? '1' : '0' );
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth, $tampered ) );

		$expired = $this->signed_id( time() - 86401, $auth );
		$future  = $this->signed_id( time() + 61, $auth );
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth, $expired ) );
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth, $future ) );
	}

	public function test_legacy_session_rejects_cross_client_user_and_site_bindings(): void {
		$this->enable_feature();
		$auth       = $this->auth();
		$parameters = $this->initialize_request( true )['params'];
		$session_id = McpAppsNegotiation::create_legacy_session_id( $parameters, $auth );
		self::assertNotNull( $session_id );

		$other_client              = $auth;
		$other_client['client_id'] = 'another-client';
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $other_client, $session_id ) );
		$replacement = McpAppsNegotiation::create_legacy_session_id( $parameters, $other_client, $session_id );
		self::assertNotSame( $session_id, $replacement );

		$other_user            = $auth;
		$other_user['user_id'] = 2;
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $other_user, $session_id ) );

		$GLOBALS['aculect_ai_companion_test_blog_id'] = 2;
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth, $session_id ) );
		$GLOBALS['aculect_ai_companion_test_blog_id'] = 1;
	}

	public function test_legacy_session_survives_access_token_refresh_but_remains_client_and_user_bound(): void {
		$this->enable_feature();
		$auth                  = $this->auth() + array( 'user_id' => 42 );
		$session_id            = McpAppsNegotiation::create_legacy_session_id( $this->initialize_request( true )['params'], $auth );
		$refreshed             = $auth;
		$refreshed['token_id'] = 'rotated-access-token';

		self::assertNotNull( $session_id );
		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $refreshed, (string) $session_id ) );

		$other_client              = $refreshed;
		$other_client['client_id'] = 'different-oauth-client';
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $other_client, (string) $session_id ) );

		$other_user            = $refreshed;
		$other_user['user_id'] = 43;
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $other_user, (string) $session_id ) );
	}

	public function test_session_creation_fails_closed_when_site_signing_salt_is_unavailable(): void {
		$this->enable_feature();
		$GLOBALS['aculect_ai_companion_test_salt'] = '';
		$GLOBALS['mcp_approval_test_salt']         = '';
		$auth                                      = $this->auth();

		self::assertNull( McpAppsNegotiation::create_legacy_session_id( $this->initialize_request( true )['params'], $auth ) );
	}

	public function test_session_creation_fails_closed_when_random_nonce_generation_throws(): void {
		$this->enable_feature();
		$GLOBALS['aculect_ai_companion_test_random_bytes_failure'] = true;

		self::assertNull( McpAppsNegotiation::create_legacy_session_id( $this->initialize_request( true )['params'], $this->auth() ) );
	}

	/**
	 * Build a signed ID fixture at an arbitrary issued-at timestamp.
	 *
	 * @param int                 $issued_at Issued-at Unix timestamp.
	 * @param array<string,mixed> $auth      Authenticated OAuth context.
	 */
	private function signed_id( int $issued_at, array $auth ): string {
		$timestamp = sprintf( '%08x', $issued_at );
		$nonce     = bin2hex( random_bytes( 12 ) );
		$payload   = 'aculect.mcp-apps-session.v1' . "\0" . $timestamp . "\0" . $nonce . "\0" . get_current_blog_id() . "\0" . $auth['client_id'] . "\0" . $auth['user_id'];
		$mac       = substr( hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) ), 0, 32 );

		return $timestamp . $nonce . $mac;
	}

	public function test_missing_or_malformed_legacy_session_id_fails_closed_to_text_only(): void {
		$this->enable_feature();
		$auth       = $this->auth();
		$session_id = McpAppsNegotiation::create_legacy_session_id( $this->initialize_request( true )['params'], $auth );

		self::assertNotNull( $session_id );
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth ) );
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth, 'not-a-session-id' ) );
	}

	public function test_legacy_session_id_is_random_and_does_not_expose_oauth_token_identifiers(): void {
		$this->enable_feature();
		$auth       = $this->auth();
		$session_id = McpAppsNegotiation::create_legacy_session_id( $this->initialize_request( true )['params'], $auth );

		self::assertNotNull( $session_id );
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $session_id );
		self::assertStringNotContainsString( $auth['token_id'], $session_id );
		self::assertStringNotContainsString( $auth['client_id'], $session_id );
	}

	public function test_stateless_requests_require_the_exact_per_request_capability(): void {
		$this->enable_feature();
		$request = array(
			'params' => array(
				'_meta' => array(
					'io.modelcontextprotocol/clientCapabilities' => array(
						'extensions' => array(
							McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => array( McpAppsNegotiation::MIME_TYPE ) ),
						),
					),
				),
			),
		);

		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'tools/list', $request, McpController::PROTOCOL_VERSION_CURRENT, $this->auth() ) );
		$request['params']['_meta']['io.modelcontextprotocol/clientCapabilities']['extensions'][ McpAppsNegotiation::EXTENSION ]['mimeTypes'] = array( 'text/html' );
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', $request, McpController::PROTOCOL_VERSION_CURRENT, $this->auth() ) );
	}

	public function test_stateless_requests_do_not_fall_back_to_legacy_cached_support(): void {
		$this->enable_feature();
		$auth = $this->auth();
		McpAppsNegotiation::enabled_for_request( 'initialize', $this->initialize_request( true ), '2025-11-25', $auth );

		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), McpController::PROTOCOL_VERSION_CURRENT, $auth ) );
	}

	public function test_discovery_only_advertises_extension_when_opted_in(): void {
		$disabled = McpAppsNegotiation::discovery_payload( array( '2026-07-28' ), 'Instructions.' );
		self::assertArrayNotHasKey( 'extensions', $disabled['capabilities'] );

		$this->enable_feature();
		$enabled = McpAppsNegotiation::discovery_payload( array( '2026-07-28' ), 'Instructions.' );
		self::assertArrayHasKey( McpAppsNegotiation::EXTENSION, $enabled['capabilities']['extensions'] );
	}

	public function test_site_info_tool_gets_read_only_app_metadata(): void {
		$this->enable_feature();
		$metadata = McpAppsNegotiation::tool_metadata( 'site.get_info', array(), 'Reading…', 'Read.', true );

		self::assertSame( McpAppsNegotiation::SITE_INFO_URI, $metadata['ui']['resourceUri'] );
		self::assertSame( array( 'model' ), $metadata['ui']['visibility'] );
		$non_ui_metadata = McpAppsNegotiation::tool_metadata( 'site.get_settings', array( array( 'type' => 'oauth2' ) ), 'Reading…', 'Read.', true );
		self::assertArrayNotHasKey( 'ui', $non_ui_metadata );
		self::assertSame( array( array( 'type' => 'oauth2' ) ), $non_ui_metadata['securitySchemes'] );
		self::assertArrayNotHasKey( 'ui', McpAppsNegotiation::tool_metadata( 'site.get_info', array(), 'Reading…', 'Read.', false ) );
	}

	/**
	 * Configure the site-level feature opt-in used by the test.
	 */
	private function enable_feature(): void {
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_apps_enabled'] = static fn (): bool => true;
	}

	/**
	 * Build a legacy MCP initialize request with the optional UI extension.
	 *
	 * @param bool $supported Whether to advertise the UI MIME type.
	 * @return array<string, mixed>
	 */
	private function initialize_request( bool $supported ): array {
		return array(
			'params' => array(
				'capabilities' => array(
					'extensions' => $supported
						? array( McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => array( McpAppsNegotiation::MIME_TYPE ) ) )
						: array(),
				),
			),
		);
	}

	/**
	 * Return a test OAuth client context.
	 *
	 * @return array<string, mixed>
	 */
	private function auth(): array {
		return array(
			'client_id' => 'test-mcp-apps-client',
			'token_id'  => 'first-token',
			'user_id'   => 1,
		);
	}
}
