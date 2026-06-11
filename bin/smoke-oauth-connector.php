#!/usr/bin/env php
<?php
/**
 * Smoke test the OAuth connector registration and authorize handoff.
 *
 * @package Aculect_AI_Companion
 */

declare(strict_types=1);

const ACULECT_SMOKE_VERSION = '1.0.0';

/**
 * @param string[] $argv Command arguments.
 */
function aculect_smoke_main( array $argv ): int {
	if ( in_array( '--help', $argv, true ) || in_array( '-h', $argv, true ) ) {
		aculect_smoke_usage();
		return 0;
	}

	$base_url      = aculect_smoke_option( $argv, 'base-url' ) ?? getenv( 'ACULECT_SMOKE_BASE_URL' );
	$cookie_header = getenv( 'ACULECT_SMOKE_COOKIE_HEADER' );
	$dcr_attempts  = aculect_smoke_int_option( $argv, 'dcr-attempts', getenv( 'ACULECT_SMOKE_DCR_ATTEMPTS' ) ?: '2' );

	if ( ! is_string( $base_url ) || '' === trim( $base_url ) ) {
		aculect_smoke_error( 'Missing base URL. Set ACULECT_SMOKE_BASE_URL or pass --base-url=https://example.test.' );
		return 1;
	}

	if ( ! is_string( $cookie_header ) || '' === trim( $cookie_header ) ) {
		aculect_smoke_error( 'Missing logged-in cookie header. Set ACULECT_SMOKE_COOKIE_HEADER from a WordPress admin browser session.' );
		return 1;
	}

	$base_url     = aculect_smoke_normalize_base_url( $base_url );
	$dcr_attempts = max( 1, min( 5, $dcr_attempts ) );

	aculect_smoke_line( 'Aculect AI Companion OAuth smoke ' . ACULECT_SMOKE_VERSION );
	aculect_smoke_line( 'Target: ' . aculect_smoke_sanitized_url( $base_url ) );
	aculect_smoke_line( 'DCR attempts: ' . (string) $dcr_attempts );

	$client = null;
	for ( $attempt = 1; $attempt <= $dcr_attempts; ++$attempt ) {
		$client = aculect_smoke_register_client( $base_url, $attempt );
		aculect_smoke_line( 'PASS DCR attempt ' . (string) $attempt . ' returned 201 and a client_id.' );
	}

	if ( ! is_array( $client ) ) {
		throw new RuntimeException( 'Dynamic client registration did not produce a usable client fixture.' );
	}

	$authorize_url = aculect_smoke_authorize_url( $base_url, $client );

	$logged_out = aculect_smoke_request(
		'GET',
		$authorize_url,
		array( 'Accept: text/html,application/xhtml+xml' )
	);
	aculect_smoke_assert_redirect_status( $logged_out, 'logged-out authorize' );
	aculect_smoke_assert_logged_out_location( $logged_out );
	aculect_smoke_line( 'PASS logged-out authorize redirects to login with admin consent redirect_to.' );

	$logged_in = aculect_smoke_request(
		'GET',
		$authorize_url,
		array(
			'Accept: text/html,application/xhtml+xml',
			'Cookie: ' . trim( $cookie_header ),
		)
	);
	aculect_smoke_assert_redirect_status( $logged_in, 'logged-in authorize' );
	aculect_smoke_assert_logged_in_location( $logged_in );
	aculect_smoke_line( 'PASS logged-in authorize redirects directly to admin consent.' );
	aculect_smoke_line( 'OAuth connector smoke passed.' );

	return 0;
}

function aculect_smoke_usage(): void {
	echo "Usage:\n";
	echo "  ACULECT_SMOKE_BASE_URL='https://example.test' \\\n";
	echo "  ACULECT_SMOKE_COOKIE_HEADER='wordpress_logged_in_...=...' \\\n";
	echo "  composer smoke:oauth\n\n";
	echo "Options:\n";
	echo "  --base-url=URL       WordPress site base URL. Env: ACULECT_SMOKE_BASE_URL.\n";
	echo "  --dcr-attempts=N     Valid DCR registrations to attempt. Default: 2. Max: 5.\n";
	echo "  --help               Show this help.\n\n";
	echo "Sensitive values are used only for requests. The script does not print cookies, client secrets, tokens, authorization codes, or raw response bodies.\n";
}

/**
 * @param string[] $argv Command arguments.
 */
function aculect_smoke_option( array $argv, string $name ): ?string {
	$prefix = '--' . $name . '=';
	foreach ( $argv as $arg ) {
		if ( str_starts_with( $arg, $prefix ) ) {
			return substr( $arg, strlen( $prefix ) );
		}
	}

	return null;
}

/**
 * @param string[] $argv Command arguments.
 */
function aculect_smoke_int_option( array $argv, string $name, string $default ): int {
	$value = aculect_smoke_option( $argv, $name ) ?? $default;

	return (int) preg_replace( '/[^0-9]/', '', $value );
}

function aculect_smoke_normalize_base_url( string $base_url ): string {
	$base_url = rtrim( trim( $base_url ), '/' );
	$parts    = parse_url( $base_url );

	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		throw new InvalidArgumentException( 'Base URL must be absolute and include a scheme and host.' );
	}

	return $base_url;
}

/**
 * @return array{client_id: string, redirect_uri: string}
 */
function aculect_smoke_register_client( string $base_url, int $attempt ): array {
	$redirect_uri = 'https://chatgpt.com/connector/oauth/aculect-smoke-' . rawurlencode( gmdate( 'YmdHis' ) . '-' . bin2hex( random_bytes( 4 ) ) . '-' . (string) $attempt );
	$payload      = array(
		'client_name'   => 'Aculect AI Companion OAuth Smoke',
		'redirect_uris' => array( $redirect_uri ),
	);

	$result = aculect_smoke_request(
		'POST',
		$base_url . '/wp-json/aculect-ai-companion/v1/oauth/register',
		array(
			'Accept: application/json',
			'Content-Type: application/json',
		),
		json_encode( $payload, JSON_THROW_ON_ERROR )
	);

	if ( 201 !== $result['status'] ) {
		throw new RuntimeException( 'DCR attempt ' . (string) $attempt . ' expected HTTP 201, got HTTP ' . (string) $result['status'] . '.' );
	}

	$data = json_decode( $result['body'], true );
	if ( ! is_array( $data ) || empty( $data['client_id'] ) || ! is_string( $data['client_id'] ) ) {
		throw new RuntimeException( 'DCR attempt ' . (string) $attempt . ' did not return a client_id.' );
	}

	return array(
		'client_id'    => $data['client_id'],
		'redirect_uri' => $redirect_uri,
	);
}

/**
 * @param array{client_id: string, redirect_uri: string} $client Client fixture.
 */
function aculect_smoke_authorize_url( string $base_url, array $client ): string {
	$query = http_build_query(
		array(
			'response_type'         => 'code',
			'client_id'             => $client['client_id'],
			'redirect_uri'          => $client['redirect_uri'],
			'scope'                 => 'content:read content:draft',
			'code_challenge'        => str_repeat( 'a', 43 ),
			'code_challenge_method' => 'S256',
			'resource'              => $base_url . '/wp-json/aculect-ai-companion/v1/mcp',
			'state'                 => 'oauth_smoke_state',
		),
		'',
		'&',
		PHP_QUERY_RFC3986
	);

	return $base_url . '/oauth/authorize?' . $query;
}

/**
 * @param string[] $headers HTTP request headers.
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function aculect_smoke_request( string $method, string $url, array $headers, ?string $body = null ): array {
	$options = array(
		'http' => array(
			'method'          => $method,
			'header'          => implode( "\r\n", $headers ),
			'ignore_errors'   => true,
			'follow_location' => 0,
			'max_redirects'   => 0,
			'timeout'         => 15,
		),
	);

	if ( null !== $body ) {
		$options['http']['content'] = $body;
	}

	$context = stream_context_create( $options );
	$body    = @file_get_contents( $url, false, $context );
	$body    = false === $body ? '' : $body;

	// phpcs:ignore PHPCompatibility.Variables.RemovedPredefinedGlobalVariables.http_response_headerDeprecated
	$response_headers = isset( $http_response_header ) && is_array( $http_response_header ) ? $http_response_header : array();

	return array(
		'status'  => aculect_smoke_status_code( $response_headers ),
		'headers' => aculect_smoke_headers( $response_headers ),
		'body'    => $body,
	);
}

/**
 * @param string[] $headers Raw response headers.
 */
function aculect_smoke_status_code( array $headers ): int {
	$status_line = $headers[0] ?? '';
	if ( 1 === preg_match( '/^HTTP\/\S+\s+(\d{3})\b/', $status_line, $matches ) ) {
		return (int) $matches[1];
	}

	return 0;
}

/**
 * @param string[] $headers Raw response headers.
 * @return array<string, string>
 */
function aculect_smoke_headers( array $headers ): array {
	$parsed = array();

	foreach ( $headers as $line ) {
		if ( ! str_contains( $line, ':' ) ) {
			continue;
		}

		list( $name, $value ) = explode( ':', $line, 2 );
		$parsed[ strtolower( trim( $name ) ) ] = trim( $value );
	}

	return $parsed;
}

/**
 * @param array{status: int, headers: array<string, string>, body: string} $result HTTP result.
 */
function aculect_smoke_assert_redirect_status( array $result, string $label ): void {
	if ( ! in_array( $result['status'], array( 301, 302, 303, 307, 308 ), true ) ) {
		throw new RuntimeException( ucfirst( $label ) . ' expected a redirect, got HTTP ' . (string) $result['status'] . '.' );
	}

	if ( empty( $result['headers']['location'] ) ) {
		throw new RuntimeException( ucfirst( $label ) . ' did not include a Location header.' );
	}
}

/**
 * @param array{status: int, headers: array<string, string>, body: string} $result HTTP result.
 */
function aculect_smoke_assert_logged_out_location( array $result ): void {
	$location = $result['headers']['location'];
	$decoded  = rawurldecode( $location );

	if ( ! str_contains( $decoded, 'wp-login.php' ) ) {
		throw new RuntimeException( 'Logged-out authorize expected wp-login.php, got ' . aculect_smoke_sanitized_url( $location ) . '.' );
	}

	if ( ! str_contains( $decoded, 'options-general.php' ) || ! str_contains( $decoded, 'page=aculect-ai-companion' ) || ! str_contains( $decoded, 'view=oauth-consent' ) ) {
		throw new RuntimeException( 'Logged-out authorize login redirect_to did not target the Aculect consent screen.' );
	}

	if ( str_contains( $decoded, '/wp-json/aculect-ai-companion/v1/oauth/authorize' ) ) {
		throw new RuntimeException( 'Logged-out authorize redirect_to points back to the REST authorize endpoint.' );
	}
}

/**
 * @param array{status: int, headers: array<string, string>, body: string} $result HTTP result.
 */
function aculect_smoke_assert_logged_in_location( array $result ): void {
	$location = $result['headers']['location'];
	$decoded  = rawurldecode( $location );

	if ( str_contains( $decoded, 'wp-login.php' ) ) {
		throw new RuntimeException( 'Logged-in authorize redirected to login instead of admin consent.' );
	}

	if ( ! str_contains( $decoded, 'options-general.php' ) || ! str_contains( $decoded, 'page=aculect-ai-companion' ) || ! str_contains( $decoded, 'view=oauth-consent' ) ) {
		throw new RuntimeException( 'Logged-in authorize did not redirect to the Aculect consent screen. Location: ' . aculect_smoke_sanitized_url( $location ) . '.' );
	}
}

function aculect_smoke_sanitized_url( string $url ): string {
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) ) {
		return '[unparseable-url]';
	}

	$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
	$host   = (string) ( $parts['host'] ?? '' );
	$port   = isset( $parts['port'] ) ? ':' . (string) $parts['port'] : '';
	$path   = (string) ( $parts['path'] ?? '' );

	return ( '' === $host ? $path : $scheme . $host . $port . $path );
}

function aculect_smoke_line( string $message ): void {
	fwrite( STDOUT, $message . PHP_EOL );
}

function aculect_smoke_error( string $message ): void {
	fwrite( STDERR, 'ERROR ' . $message . PHP_EOL );
}

try {
	exit( aculect_smoke_main( $argv ) );
} catch ( Throwable $throwable ) {
	aculect_smoke_error( $throwable->getMessage() );
	exit( 1 );
}
