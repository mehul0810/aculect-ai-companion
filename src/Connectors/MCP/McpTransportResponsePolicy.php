<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

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
}
