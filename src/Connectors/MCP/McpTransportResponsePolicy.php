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

	private static string $request_id = '';

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
	 * Safe response headers that browser-based MCP diagnostics may inspect.
	 *
	 * @var string[]
	 */
	private const CORS_RESPONSE_HEADERS = array(
		'MCP-Protocol-Version',
		'WWW-Authenticate',
		'X-Aculect-MCP-Request-ID',
	);

	/**
	 * Register transport-wide response and browser header hooks.
	 */
	public static function register_hooks(): void {
		add_filter( 'rest_allowed_cors_headers', array( self::class, 'filter_cors_request_headers' ) );
		add_filter( 'rest_exposed_cors_headers', array( self::class, 'filter_exposed_cors_headers' ) );
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_sse_probe' ), 20, 4 );
	}

	/**
	 * Start a new request correlation scope.
	 */
	public static function begin_request(): void {
		self::$request_id = self::new_request_id();
	}

	/**
	 * Return the opaque request ID for the current transport scope.
	 */
	public static function request_id(): string {
		if ( '' === self::$request_id ) {
			self::begin_request();
		}

		return self::$request_id;
	}

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
	 * Expose only protocol and opaque diagnostic response headers to browsers.
	 *
	 * @param string[] $headers Existing exposed response headers.
	 * @return string[]
	 */
	public static function filter_exposed_cors_headers( array $headers ): array {
		foreach ( self::CORS_RESPONSE_HEADERS as $header ) {
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
		// WordPress core and common reverse proxies do not all honor the same
		// cache-control directive. Keep every MCP response request-specific,
		// including OAuth challenges and the optional authenticated SSE probe.
		$response->header( 'Cache-Control', 'no-store, private, no-cache, max-age=0, must-revalidate' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );
		$response->header( 'CDN-Cache-Control', 'no-store' );
		$response->header( 'Surrogate-Control', 'no-store' );
		$response->header( 'X-Accel-Expires', '0' );
		$response->header( 'Vary', 'Authorization, Accept, Origin, MCP-Protocol-Version, MCP-Method, MCP-Name, MCP-Session-Id, Last-Event-ID' );
	}

	/**
	 * Apply the request correlation and cache policy headers together.
	 *
	 * @param WP_REST_Response $response REST response.
	 */
	public static function apply_request_headers( WP_REST_Response $response ): void {
		$response->header( 'X-Aculect-MCP-Request-ID', self::request_id() );
		self::apply_cache_headers( $response );
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
	 * @param WP_REST_Request $request          REST request.
	 * @param string          $protocol_version Resolved MCP protocol version.
	 */
	public static function get_response( WP_REST_Request $request, string $protocol_version = McpProtocolVersion::INITIAL ): WP_REST_Response {
		if ( self::accepts_sse( $request ) && McpProtocolVersion::uses_get_transport( $protocol_version ) ) {
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
	 * Serve an authenticated SSE probe for clients that open the optional
	 * Streamable HTTP GET channel before issuing JSON-RPC POST requests.
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
	 * Produce a valid, empty SSE event large enough to cross common proxy
	 * buffers. A comment alone is not dispatched as an event by SSE clients,
	 * so it cannot safely prime a reconnecting Streamable HTTP client.
	 */
	public static function sse_probe_payload(): string {
		return 'id: ' . bin2hex( random_bytes( 16 ) ) . "\n"
			. "retry: 1000\n"
			. "data:\n"
			. ': ' . str_repeat( ' ', 2048 ) . "\n\n";
	}

	/**
	 * Return an opaque request ID without depending on the complete WordPress
	 * function set in lightweight tests.
	 */
	private static function new_request_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}

		try {
			$bytes = random_bytes( 16 );
		} catch ( \Throwable ) {
			$bytes = hash( 'sha256', uniqid( '', true ), true );
		}

		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}
}
