<?php
/**
 * Tests for transitional Streamable HTTP MCP compatibility.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpProtocolVersion;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

require_once dirname( __DIR__, 3 ) . '/fixtures/mcp-request-stubs.php';

/**
 * Covers the initialize lifecycle retained by MCP 2025-11-25.
 */
final class McpProtocolCompatibilityTest extends TestCase {

	public function test_transitional_protocol_is_known_and_uses_initialize(): void {
		self::assertTrue( McpProtocolVersion::is_known( McpProtocolVersion::TRANSITIONAL ) );
		self::assertTrue( McpProtocolVersion::uses_initialize( McpProtocolVersion::TRANSITIONAL ) );
		self::assertFalse( McpProtocolVersion::uses_initialize( McpProtocolVersion::CURRENT ) );
	}

	public function test_transitional_initialize_negotiates_the_exact_requested_version(): void {
		$controller = $this->authenticated_controller();
		$response   = $controller->handle_rpc(
			new WP_REST_Request(
				array(),
				array( 'mcp-protocol-version' => McpProtocolVersion::TRANSITIONAL ),
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array( 'protocolVersion' => McpProtocolVersion::TRANSITIONAL ),
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			)
		);

		self::assertIsArray( $response );
		self::assertSame( McpProtocolVersion::TRANSITIONAL, $response['result']['protocolVersion'] ?? '' );
	}

	public function test_transitional_initialized_notification_is_accepted(): void {
		$response = $this->authenticated_controller()->handle_rpc(
			new WP_REST_Request(
				array(),
				array( 'mcp-protocol-version' => McpProtocolVersion::TRANSITIONAL ),
				array(
					'jsonrpc' => '2.0',
					'method'  => 'notifications/initialized',
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			)
		);

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 202, $response->get_status() );
	}

	private function authenticated_controller(): McpController {
		$controller = new McpController();
		$property   = new \ReflectionProperty( $controller, 'request_auth' );
		$property->setValue(
			$controller,
			array(
				'user_id' => 1,
				'scopes'  => array( 'content:read' ),
			)
		);

		return $controller;
	}
}
