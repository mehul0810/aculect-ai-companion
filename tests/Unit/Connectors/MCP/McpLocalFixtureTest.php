<?php
/**
 * End-to-end MCP controller fixture coverage without WordPress credentials.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpController;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WP_REST_Request;
use WP_REST_Response;

require_once dirname( __DIR__, 3 ) . '/fixtures/mcp-request-stubs.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';

/**
 * Proves the real PHP controller can complete the same read-only flow used by
 * the SDK wire smoke before any live OAuth or WordPress site is involved.
 */
final class McpLocalFixtureTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_current_user_id']  = 1;
		$GLOBALS['aculect_ai_companion_test_users']            = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Local MCP Fixture Administrator',
			),
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps']      = array();
		$GLOBALS['aculect_ai_companion_test_options']          = array(
			'blogname'            => 'Aculect Local MCP Fixture',
			'blogdescription'     => 'Deterministic no-secret MCP test site.',
			'active_plugins'      => array( 'aculect-ai-companion/aculect-ai-companion.php' ),
			'permalink_structure' => '/%postname%/',
		);
		$GLOBALS['aculect_ai_companion_test_environment_type'] = 'local';
	}

	public function test_fixture_completes_initialize_discovery_and_read_call(): void {
		$controller = $this->authenticated_controller();
		$initialize = $this->request(
			$controller,
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'capabilities'    => array(),
					'clientInfo'      => array(
						'name'    => 'aculect-local-fixture',
						'version' => '1',
					),
				),
			),
			array( 'mcp-protocol-version' => '2025-11-25' )
		);

		self::assertSame( '2025-11-25', $initialize['result']['protocolVersion'] ?? null );

		$tools  = array();
		$cursor = '';
		do {
			$page   = $this->request(
				$controller,
				array(
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'tools/list',
					'params'  => '' === $cursor ? array() : array( 'cursor' => $cursor ),
				),
				array( 'mcp-protocol-version' => '2025-11-25' )
			);
			$tools  = array_merge( $tools, $page['result']['tools'] ?? array() );
			$cursor = (string) ( $page['result']['nextCursor'] ?? '' );
		} while ( '' !== $cursor );

		$names = array_column( $tools, 'name' );
		self::assertNotEmpty( $tools );
		self::assertContains( 'site_get_info', $names );
		self::assertSame( count( $names ), count( array_unique( $names ) ) );

		$call = $this->request(
			$controller,
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'site_get_info',
					'arguments' => array(),
				),
			),
			array( 'mcp-protocol-version' => '2025-11-25' )
		);

		self::assertFalse( $call['result']['isError'] ?? false );
		$payload = json_decode( (string) ( $call['result']['content'][0]['text'] ?? '' ), true );
		self::assertIsArray( $payload );
		self::assertSame( '0.8.0', $payload['aculect_ai_companion_version'] ?? null );
		self::assertSame( 'Aculect Local MCP Fixture', $payload['name'] ?? null );
	}

	public function test_fixture_rejects_missing_bearer_before_tool_execution(): void {
		$controller = new McpController();
		$response   = $this->request_response(
			$controller,
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'initialize',
				'params'  => array( 'protocolVersion' => '2025-11-25' ),
			),
			array( 'mcp-protocol-version' => '2025-11-25' )
		);

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 401, $response->get_status() );
		self::assertTrue( $response->get_data()['result']['isError'] ?? false );
		self::assertSame( 'Authorization required.', $response->get_data()['result']['content'][0]['text'] ?? null );
	}

	public function test_fixture_surfaces_unknown_tool_as_structured_error(): void {
		$result = $this->request(
			$this->authenticated_controller(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => 'not_a_real_tool',
					'arguments' => array(),
				),
			),
			array( 'mcp-protocol-version' => '2025-11-25' )
		);

		self::assertTrue( $result['result']['isError'] ?? false );
		self::assertSame( 'Unknown tool.', $result['result']['content'][0]['text'] ?? null );
	}

	private function authenticated_controller(): McpController {
		$controller = new McpController();
		$auth       = new ReflectionProperty( $controller, 'request_auth' );
		$auth->setValue(
			$controller,
			array(
				'user_id'   => 1,
				'client_id' => 'local-mcp-fixture',
				'provider'  => 'local-fixture',
				'scopes'    => array( 'content:read', 'content:draft' ),
				'profile'   => 'full_access',
			)
		);

		return $controller;
	}

	/**
	 * Send one JSON-RPC request and return its decoded body.
	 *
	 * @param McpController        $controller MCP controller.
	 * @param array<string,mixed>  $body       JSON-RPC body.
	 * @param array<string,string> $headers    Request headers.
	 * @return array<string,mixed>
	 */
	private function request( McpController $controller, array $body, array $headers ): array {
		$response = $this->request_response( $controller, $body, $headers );
		if ( $response instanceof WP_REST_Response ) {
			$response = $response->get_data();
		}
		self::assertIsArray( $response );

		return $response;
	}

	/**
	 * Send one JSON-RPC request through the controller.
	 *
	 * @param McpController        $controller MCP controller.
	 * @param array<string,mixed>  $body       JSON-RPC body.
	 * @param array<string,string> $headers    Request headers.
	 */
	private function request_response( McpController $controller, array $body, array $headers ): WP_REST_Response|array {
		return $controller->handle_rpc(
			new WP_REST_Request(
				array(),
				$headers,
				$body,
				'POST',
				'/aculect-ai-companion/v1/mcp',
				(string) wp_json_encode( $body )
			)
		);
	}
}
