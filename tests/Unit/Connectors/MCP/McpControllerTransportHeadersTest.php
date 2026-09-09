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
		$request  = new WP_REST_Request(
			array(),
			array(
				'accept'               => 'application/json, text/event-stream',
				'mcp-protocol-version' => McpController::PROTOCOL_VERSION_LEGACY,
			),
			array(),
			'GET',
			'/aculect-ai-companion/v1/mcp'
		);
		$response = $controller->filter_mcp_auth_response( $controller->describe( $request ), null, $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );
		$this->assert_never_cacheable( $response );
		self::assertTrue( McpTransportResponsePolicy::is_sse_probe_response( $response, $request ) );
	}

	public function test_sse_probe_requires_an_event_stream_accept_header(): void {
		$request = new WP_REST_Request(
			array(),
			array( 'accept' => 'application/json' ),
			array(),
			'GET',
			'/aculect-ai-companion/v1/mcp'
		);
		self::assertFalse( McpTransportResponsePolicy::accepts_sse( $request ) );
		self::assertFalse( McpTransportResponsePolicy::is_sse_probe_response( new WP_REST_Response( null, 200 ), $request ) );
	}

	public function test_sse_probe_payload_primes_a_reconnectable_sse_stream(): void {
		$payload = McpTransportResponsePolicy::sse_probe_payload();

		self::assertMatchesRegularExpression( '/^id: [a-f0-9]{32}\\nretry: 1000\\ndata:\\n: /', $payload );
		self::assertStringEndsWith( "\n\n", $payload );
		self::assertGreaterThanOrEqual( 2080, strlen( $payload ) );
	}

	public function test_current_sse_probe_response_uses_the_requested_protocol_header(): void {
		$controller = new McpController();
		$this->set_private_property(
			$controller,
			'request_auth',
			array(
				'user_id' => 1,
				'scopes'  => array( 'content:read' ),
			)
		);
		$request         = new WP_REST_Request(
			array(),
			array(
				'accept'               => 'text/event-stream, application/json',
				'mcp-protocol-version' => McpController::PROTOCOL_VERSION_CURRENT,
			),
			array(),
			'GET',
			'/aculect-ai-companion/v1/mcp'
		);
		$transport_error = new \ReflectionMethod( $controller, 'transport_error' );

		self::assertNull( $transport_error->invoke( $controller, $request ) );

		$response = $controller->filter_mcp_auth_response( $controller->describe( $request ), null, $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( McpController::PROTOCOL_VERSION_CURRENT, $response->header( 'MCP-Protocol-Version' ) );
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
