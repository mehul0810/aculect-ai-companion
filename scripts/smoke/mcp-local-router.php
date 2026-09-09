<?php
/**
 * Local, no-secret MCP HTTP fixture router.
 *
 * This is intentionally not a production WordPress entry point. It boots the
 * repository's WordPress-light test runtime, injects one deterministic OAuth
 * context, and sends real HTTP requests through McpController::handle_rpc so
 * the SDK smoke can exercise the PHP controller over the wire.
 *
 * @package Aculect\AICompanion\Smoke
 */

declare(strict_types=1);

// The PHPUnit bootstrap intentionally exits outside CLI unless ABSPATH is
// already defined. PHP's built-in server reports SAPI `cli-server`, so define
// the fixture root before loading that shared test runtime.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/tests/bootstrap.php';
require_once dirname( __DIR__, 2 ) . '/tests/fixtures/mcp-request-stubs.php';
require_once dirname( __DIR__, 2 ) . '/tests/fixtures/site-workflow-stubs.php';

use Aculect\AICompanion\Connectors\MCP\McpController;

const ACULECT_LOCAL_MCP_PATH  = '/wp-json/aculect-ai-companion/v1/mcp';
const ACULECT_LOCAL_MCP_TOKEN = 'aculect-local-fixture-token';

$GLOBALS['aculect_ai_companion_test_current_user_id']  = 1;
$GLOBALS['aculect_ai_companion_test_users']            = array(
	1 => (object) array(
		'ID'           => 1,
		'roles'        => array( 'administrator' ),
		'display_name' => 'Local MCP Fixture Administrator',
		'user_login'   => 'local-fixture',
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

/**
 * Emit a bounded, secret-free diagnostic event for the parent smoke process.
 *
 * @param array<string, mixed> $event Event fields.
 */
function aculect_local_mcp_log( array $event ): void {
	$event['component'] = 'local-mcp-router';
	file_put_contents( 'php://stderr', 'ACULECT_LOCAL_MCP ' . wp_json_encode( $event ) . PHP_EOL );
}

/**
 * Normalize incoming headers for the WordPress-light request double.
 *
 * @return array<string, string>
 */
function aculect_local_mcp_headers(): array {
	$raw     = function_exists( 'getallheaders' ) ? getallheaders() : array();
	$headers = array();
	foreach ( is_array( $raw ) ? $raw : array() as $name => $value ) {
		$headers[ strtolower( (string) $name ) ] = trim( (string) $value );
	}

	foreach ( array( 'authorization', 'content-type', 'origin', 'mcp-method', 'mcp-protocol-version' ) as $name ) {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		if ( isset( $_SERVER[ $key ] ) && ! isset( $headers[ $name ] ) ) {
			$headers[ $name ] = trim( (string) $_SERVER[ $key ] );
		}
	}

	if ( isset( $_SERVER['CONTENT_TYPE'] ) && ! isset( $headers['content-type'] ) ) {
		$headers['content-type'] = trim( (string) $_SERVER['CONTENT_TYPE'] );
	}

	return $headers;
}

/**
 * Return the JSON-RPC request method without trusting arbitrary fields.
 *
 * @param mixed $body Decoded JSON body.
 */
function aculect_local_mcp_method( mixed $body ): string {
	return is_array( $body ) && is_string( $body['method'] ?? null ) ? $body['method'] : '';
}

/**
 * Extract a response body and status from the WordPress response double.
 *
 * @param mixed $response Controller response.
 * @return array{body:mixed,status:int,headers:array<string,string>}
 */
function aculect_local_mcp_response( mixed $response ): array {
	if ( $response instanceof WP_REST_Response ) {
		$headers = method_exists( $response, 'get_headers' ) ? $response->get_headers() : array();
		if ( array() === $headers ) {
			try {
				$header_property = new ReflectionProperty( $response, 'headers' );
				$headers         = $header_property->getValue( $response );
			} catch ( Throwable ) {
				$headers = array();
			}
		}
		return array(
			'body'    => $response->get_data(),
			'status'  => $response->get_status(),
			'headers' => is_array( $headers ) ? array_map( 'strval', $headers ) : array(),
		);
	}

	return array(
		'body'    => $response,
		'status'  => 200,
		'headers' => array(),
	);
}

$request_uri    = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
$request_parts  = wp_parse_url( $request_uri );
$request_path   = is_array( $request_parts ) && isset( $request_parts['path'] ) ? (string) $request_parts['path'] : '/';
$request_method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

if ( ACULECT_LOCAL_MCP_PATH !== $request_path ) {
	http_response_code( 404 );
	aculect_local_mcp_log(
		array(
			'method' => $request_method,
			'status' => 404,
			'reason' => 'route_not_found',
		)
	);
	exit;
}

if ( 'GET' === $request_method ) {
	header( 'Allow: POST' );
	header( 'MCP-Protocol-Version: 2025-11-25' );
	http_response_code( 405 );
	aculect_local_mcp_log(
		array(
			'method' => 'GET',
			'status' => 405,
			'reason' => 'streamable_http_probe',
		)
	);
	exit;
}

if ( 'POST' !== $request_method ) {
	header( 'Allow: POST' );
	http_response_code( 405 );
	aculect_local_mcp_log(
		array(
			'method' => $request_method,
			'status' => 405,
			'reason' => 'method_not_allowed',
		)
	);
	exit;
}

$raw_body   = (string) file_get_contents( 'php://input' );
$body       = json_decode( $raw_body, true );
$body       = is_array( $body ) ? $body : array();
$headers    = aculect_local_mcp_headers();
$method     = aculect_local_mcp_method( $body );
$controller = new McpController();

if ( preg_match( '/^Bearer\s+(.+)$/i', (string) ( $headers['authorization'] ?? '' ), $matches ) && hash_equals( ACULECT_LOCAL_MCP_TOKEN, trim( $matches[1] ) ) ) {
	$auth = new ReflectionProperty( $controller, 'request_auth' );
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
}

$request = new WP_REST_Request( array(), $headers, $body, 'POST', ACULECT_LOCAL_MCP_PATH, $raw_body );

try {
	$response     = aculect_local_mcp_response( $controller->handle_rpc( $request ) );
	$data         = $response['body'];
	$http_status  = $response['status'];
	$header_names = array_map( 'strtolower', array_keys( $response['headers'] ) );
	foreach ( $response['headers'] as $name => $value ) {
		header( $name . ': ' . $value );
	}
	$protocol = (string) ( $headers['mcp-protocol-version'] ?? '' );
	if ( is_array( $data ) && isset( $data['result']['protocolVersion'] ) && is_string( $data['result']['protocolVersion'] ) ) {
		$protocol = $data['result']['protocolVersion'];
	}
	$challenge = $data['result']['_meta']['mcp/www_authenticate'][0] ?? null;
	if ( is_string( $challenge ) && '' !== $challenge && ! in_array( 'www-authenticate', $header_names, true ) ) {
		header( 'WWW-Authenticate: ' . $challenge );
	}
	if ( '' !== $protocol && ! in_array( 'mcp-protocol-version', $header_names, true ) ) {
		header( 'MCP-Protocol-Version: ' . $protocol );
	}
	if ( ! in_array( 'cache-control', $header_names, true ) ) {
		header( 'Cache-Control: no-store, private, no-cache, max-age=0, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'CDN-Cache-Control: no-store' );
		header( 'Surrogate-Control: no-store' );
		header( 'X-Accel-Expires: 0' );
	}
	if ( null === $data ) {
		http_response_code( $http_status );
		aculect_local_mcp_log(
			array(
				'rpc'      => $method,
				'status'   => $http_status,
				'response' => 'empty',
			)
		);
		exit;
	}
	header( 'Content-Type: application/json; charset=utf-8' );
	http_response_code( $http_status );
	$json = wp_json_encode( $data );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-RPC response must remain machine-readable JSON.
	echo false === $json ? '{}' : $json;
	aculect_local_mcp_log(
		array(
			'rpc'    => $method,
			'status' => $http_status,
			'error'  => is_array( $data['error'] ?? null ) ? (string) ( $data['error']['data']['code'] ?? $data['error']['code'] ?? 'rpc_error' ) : null,
		)
	);
} catch ( Throwable $exception ) {
	http_response_code( 500 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store, private, no-cache, max-age=0, must-revalidate' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );
	header( 'CDN-Cache-Control: no-store' );
	header( 'Surrogate-Control: no-store' );
	header( 'X-Accel-Expires: 0' );
	echo wp_json_encode(
		array(
			'jsonrpc' => '2.0',
			'id'      => $body['id'] ?? null,
			'error'   => array(
				'code'    => -32603,
				'message' => 'Internal error',
				'data'    => array( 'code' => 'local_fixture_exception' ),
			),
		)
	);
	aculect_local_mcp_log(
		array(
			'rpc'       => $method,
			'status'    => 500,
			'exception' => get_class( $exception ),
			'file'      => basename( $exception->getFile() ),
			'line'      => $exception->getLine(),
		)
	);
}
