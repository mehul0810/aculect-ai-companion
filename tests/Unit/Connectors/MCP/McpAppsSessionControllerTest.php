<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

require_once dirname( __DIR__, 3 ) . '/Support/McpAppsSessionSalt.php';
require_once dirname( __DIR__, 3 ) . '/Support/McpAppsRandomFailure.php';

use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpProtocolVersion;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WP_REST_Request;
use WP_REST_Response;

final class McpAppsSessionControllerTest extends TestCase {

	private mixed $previous_random_bytes_failure;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_random_bytes_failure = $GLOBALS['aculect_ai_companion_test_random_bytes_failure'] ?? null;
		$GLOBALS['aculect_ai_companion_test_transients']           = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']     = array(
			'aculect_ai_companion_mcp_apps_enabled' => static fn (): bool => true,
		);
		$GLOBALS['aculect_ai_companion_test_salt']                 = 'mcp-app-session-test-salt-auth';
		$GLOBALS['mcp_approval_test_salt']                         = 'unit-test-only-stable-salt-auth';
		$GLOBALS['aculect_ai_companion_test_random_bytes_failure'] = false;
	}

	protected function tearDown(): void {
		if ( null === $this->previous_random_bytes_failure ) {
			unset( $GLOBALS['aculect_ai_companion_test_random_bytes_failure'] );
		} else {
			$GLOBALS['aculect_ai_companion_test_random_bytes_failure'] = $this->previous_random_bytes_failure;
		}
		parent::tearDown();
	}

	public function test_initialize_returns_standard_session_header_and_refresh_can_reuse_it(): void {
		$controller = new McpController();
		$this->set_auth( $controller, 'initial-access-token' );
		$initialize = $controller->handle_rpc( $this->initialize_request() );

		self::assertInstanceOf( WP_REST_Response::class, $initialize );
		$session_id = $initialize->header( 'MCP-Session-Id' );
		self::assertIsString( $session_id );
		self::assertMatchesRegularExpression( '/\A[a-f0-9]{64}\z/', $session_id );
		self::assertArrayHasKey( 'io.modelcontextprotocol/ui', $initialize->get_data()['result']['capabilities']['extensions'] ?? array() );

		$this->set_auth( $controller, 'rotated-access-token' );
		$refreshed_initialize = $controller->handle_rpc( $this->initialize_request( $session_id ) );
		self::assertInstanceOf( WP_REST_Response::class, $refreshed_initialize );
		self::assertSame( $session_id, $refreshed_initialize->header( 'MCP-Session-Id' ) );

		$controller->handle_rpc( $this->tools_list_request( $session_id ) );
		self::assertTrue( $this->property_value( $controller, 'mcp_apps_ui_enabled' ) );

		$controller->handle_rpc( $this->tools_list_request( '' ) );
		self::assertFalse( $this->property_value( $controller, 'mcp_apps_ui_enabled' ) );
	}

	public function test_initialize_falls_back_to_text_only_when_stateless_session_signing_fails(): void {
		$GLOBALS['aculect_ai_companion_test_random_bytes_failure'] = true;
		$controller = new McpController();
		$this->set_auth( $controller, 'initial-access-token' );
		$response = $controller->handle_rpc( $this->initialize_request() );

		self::assertIsArray( $response );
		self::assertArrayNotHasKey( 'MCP-Session-Id', $response );
		self::assertArrayNotHasKey( 'extensions', $response['result']['capabilities'] ?? array() );
	}

	private function set_auth( McpController $controller, string $token_id ): void {
		$property = new ReflectionProperty( $controller, 'request_auth' );
		$property->setValue(
			$controller,
			array(
				'user_id'   => 1,
				'client_id' => 'shared-oauth-client',
				'token_id'  => $token_id,
				'scopes'    => array( 'content:read' ),
			)
		);
	}

	private function property_value( McpController $controller, string $name ): mixed {
		return ( new ReflectionProperty( $controller, $name ) )->getValue( $controller );
	}

	private function tools_list_request( string $session_id ): WP_REST_Request {
		$headers = array( 'mcp-protocol-version' => McpProtocolVersion::TRANSITIONAL );
		if ( '' !== $session_id ) {
			$headers['mcp-session-id'] = $session_id;
		}

		return new WP_REST_Request(
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
				'params'  => array(),
			),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);
	}

	private function initialize_request( string $session_id = '' ): WP_REST_Request {
		$headers = array( 'mcp-protocol-version' => McpProtocolVersion::TRANSITIONAL );
		if ( '' !== $session_id ) {
			$headers['mcp-session-id'] = $session_id;
		}

		return new WP_REST_Request(
			array(),
			$headers,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => McpProtocolVersion::TRANSITIONAL,
					'capabilities'    => array(
						'extensions' => array(
							'io.modelcontextprotocol/ui' => array( 'mimeTypes' => array( 'text/html;profile=mcp-app' ) ),
						),
					),
				),
			),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);
	}
}
