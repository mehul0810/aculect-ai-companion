<?php
/**
 * Tests for cache and browser transport response headers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpTransportResponsePolicy;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

require_once dirname( __DIR__, 3 ) . '/fixtures/mcp-request-stubs.php';

/**
 * Verifies that connection-specific transport responses cannot be cached.
 */
final class McpControllerTransportHeadersTest extends TestCase {

	public function test_authenticated_sse_probe_response_is_never_cacheable(): void {
		$controller = new McpController();
		$this->set_private_property(
			$controller,
			'request_auth',
			array(
				'user_id' => 1,
				'scopes'  => array( 'content:read' ),
			)
		);
		$request = new WP_REST_Request(
			array(),
			array( 'mcp-protocol-version' => McpController::PROTOCOL_VERSION_LEGACY ),
			array(),
			'GET',
			'/aculect-ai-companion/v1/mcp'
		);

		$response = $controller->filter_mcp_auth_response( $controller->describe( $request ), null, $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 405, $response->get_status() );
		$this->assert_never_cacheable( $response );
	}

	public function test_oauth_challenge_is_never_cacheable(): void {
		$response = ( new McpController() )->describe( new WP_REST_Request() );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 401, $response->get_status() );
		$this->assert_never_cacheable( $response );
	}

	public function test_mcp_cors_headers_include_only_required_transport_headers(): void {
		$headers = McpTransportResponsePolicy::filter_cors_request_headers(
			array( 'Authorization', 'Content-Type' )
		);

		foreach ( array( 'MCP-Protocol-Version', 'MCP-Method', 'MCP-Name', 'MCP-Session-Id', 'Last-Event-ID' ) as $header ) {
			self::assertContains( $header, $headers );
		}
		self::assertNotContains( 'X-Unrelated-Header', $headers );
	}

	private function assert_never_cacheable( WP_REST_Response $response ): void {
		self::assertSame( 'no-store, private', $response->header( 'Cache-Control' ) );
		self::assertSame( 'no-cache', $response->header( 'Pragma' ) );
		self::assertStringContainsString( 'Authorization', (string) $response->header( 'Vary' ) );
		self::assertStringContainsString( 'MCP-Protocol-Version', (string) $response->header( 'Vary' ) );
	}

	private function set_private_property( object $object, string $property, mixed $value ): void {
		$reflection = new \ReflectionProperty( $object, $property );
		$reflection->setValue( $object, $value );
	}
}
