<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Keeps MCP response caching and browser request-header policy separate from
 * request dispatch and authorization.
 */
final class McpTransportResponsePolicy {

	/**
	 * Request headers used by the Streamable HTTP transport that browser
	 * clients may include in a CORS preflight.
	 *
	 * This allowlist does not relax the endpoint's Origin validation.
	 *
	 * @var string[]
	 */
	private const CORS_REQUEST_HEADERS = array(
		'MCP-Protocol-Version',
		'MCP-Method',
		'MCP-Name',
		'MCP-Session-Id',
		'Last-Event-ID',
	);

	/**
	 * Permit the protocol's non-simple request headers on WordPress REST CORS
	 * preflights without widening the allowed Origin policy.
	 *
	 * @param string[] $headers Existing allowed request headers.
	 * @return string[]
	 */
	public static function filter_cors_request_headers( array $headers ): array {
		foreach ( self::CORS_REQUEST_HEADERS as $header ) {
			if ( ! in_array( $header, $headers, true ) ) {
				$headers[] = $header;
			}
		}

		return $headers;
	}

	/**
	 * Prevent caches from replaying OAuth challenges, request-specific JSON-RPC
	 * responses, or an authenticated SSE-probe response to another client.
	 *
	 * @param WP_REST_Response $response REST response.
	 */
	public static function apply_cache_headers( WP_REST_Response $response ): void {
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Vary', 'Authorization, Accept, Origin, MCP-Protocol-Version, MCP-Method, MCP-Name, MCP-Session-Id, Last-Event-ID' );
	}

	/**
	 * Return whether the client explicitly accepts an SSE response.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public static function accepts_sse( WP_REST_Request $request ): bool {
		foreach ( explode( ',', strtolower( (string) $request->get_header( 'accept' ) ) ) as $accepted_type ) {
			if ( 'text/event-stream' === trim( explode( ';', $accepted_type, 2 )[0] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the authenticated GET response for the MCP endpoint.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public static function get_response( WP_REST_Request $request ): WP_REST_Response {
		if ( self::accepts_sse( $request ) ) {
			return new WP_REST_Response( null, 200 );
		}

		$response = new WP_REST_Response(
			array(
				'code'    => 'mcp_get_not_supported',
				'message' => 'This stateless MCP endpoint accepts POST requests only.',
			),
			405
		);
		$response->header( 'Allow', 'POST' );

		return $response;
	}

	/**
	 * Serve a minimal authenticated SSE probe for clients that require one
	 * before using Streamable HTTP POST requests.
	 *
	 * @param bool             $served  Whether the REST request was already served.
	 * @param WP_REST_Response $response REST response.
	 * @param WP_REST_Request  $request REST request.
	 * @param mixed            $server  REST server.
	 */
	public static function serve_sse_probe( bool $served, WP_REST_Response $response, WP_REST_Request $request, mixed $server = null ): bool {
		unset( $server );

		if ( $served || ! self::is_sse_probe_response( $response, $request ) ) {
			return $served;
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/event-stream; charset=UTF-8' );
			header( 'X-Accel-Buffering: no' );
		}

		echo esc_html( self::sse_probe_payload() );
		flush();

		return true;
	}

	/**
	 * Return whether the response may be emitted as the MCP SSE probe.
	 *
	 * A successful response is possible only after the controller's OAuth
	 * permission callback has authenticated the request.
	 *
	 * @param WP_REST_Response $response REST response.
	 * @param WP_REST_Request  $request REST request.
	 */
	public static function is_sse_probe_response( WP_REST_Response $response, WP_REST_Request $request ): bool {
		return 200 === $response->get_status()
			&& 'GET' === $request->get_method()
			&& '/aculect-ai-companion/v1/mcp' === $request->get_route()
			&& self::accepts_sse( $request );
	}

	/**
	 * Produce a valid SSE comment large enough to cross common proxy buffers.
	 */
	public static function sse_probe_payload(): string {
		return ': ' . str_repeat( ' ', 2048 ) . "\n\n";
	}
}
