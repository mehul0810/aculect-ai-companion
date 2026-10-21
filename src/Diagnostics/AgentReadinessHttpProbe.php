<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

use Aculect\AICompanion\Connectors\Helpers;
use Closure;
use WP_Error;

/**
 * Performs bounded same-origin requests for explicit agent-readiness runs.
 */
final class AgentReadinessHttpProbe {

	private const TIMEOUT_SECONDS = 2;
	private const RESPONSE_BYTES  = 65536;
	private const MAX_REDIRECTS   = 1;
	private const REQUEST_BUDGET  = 10;

	private int $requests_made = 0;

	/**
	 * Adapter used to make a request.
	 *
	 * @var Closure(string,array<string,mixed>):(array<string,mixed>|WP_Error)
	 */
	private Closure $http_request;

	/**
	 * Create the probe.
	 *
	 * @param callable(string,array<string,mixed>):(array<string,mixed>|WP_Error)|null $http_request HTTP adapter.
	 */
	public function __construct( ?callable $http_request = null ) {
		$this->http_request = $http_request
			? Closure::fromCallable( $http_request )
			: static fn( string $url, array $args ): array|WP_Error => wp_safe_remote_request( $url, $args );
	}

	/**
	 * Reset the total request budget for a new explicit diagnostic run.
	 */
	public function reset(): void {
		$this->requests_made = 0;
	}

	/**
	 * Request a same-origin URL with strict response and redirect limits.
	 *
	 * @param string               $url             Target URL.
	 * @param string               $method          HTTP method.
	 * @param array<string,string> $headers         Request headers.
	 * @param string|null          $expected_origin Known endpoint URL defining the allowed origin.
	 * @return array<string,mixed>
	 */
	public function request( string $url, string $method = 'GET', array $headers = array(), ?string $expected_origin = null ): array {
		$origin = wp_parse_url( $expected_origin ?? home_url( '/' ) );
		$target = wp_parse_url( $url );
		if ( ! is_array( $origin ) || ! is_array( $target ) || ! $this->same_origin( $origin, $target ) ) {
			return $this->blocked();
		}

		$redirects = 0;
		while ( $this->requests_made < self::REQUEST_BUDGET ) {
			$response = $this->request_once( $url, $method, $headers );
			if ( is_wp_error( $response ) || ! is_array( $response ) ) {
				return $this->blocked();
			}

			$status   = (int) wp_remote_retrieve_response_code( $response );
			$location = $this->response_header( $response, 'location' );
			if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) || '' === $location ) {
				return $this->summarize_response( $response, $status );
			}
			if ( $redirects >= self::MAX_REDIRECTS ) {
				return $this->blocked();
			}

			$url        = $this->resolve_redirect( $url, $location );
			$next_parts = wp_parse_url( $url );
			if ( ! is_array( $next_parts ) || ! $this->same_origin( $origin, $next_parts ) ) {
				return $this->blocked();
			}
			++$redirects;
			if ( 303 === $status ) {
				$method = 'GET';
			}
		}

		return $this->blocked();
	}

	/**
	 * Make a single network request.
	 *
	 * @param string               $url     Target URL.
	 * @param string               $method  HTTP method.
	 * @param array<string,string> $headers Request headers.
	 * @return array<string,mixed>|WP_Error
	 */
	private function request_once( string $url, string $method, array $headers ): array|WP_Error {
		++$this->requests_made;
		if ( 'POST' === $method ) {
			$headers['Content-Type'] = 'application/json';
			$headers['Accept']       = 'application/json';
		}
		return ( $this->http_request )(
			$url,
			array(
				'method'              => $method,
				'timeout'             => self::TIMEOUT_SECONDS,
				'redirection'         => 0,
				'limit_response_size' => self::RESPONSE_BYTES,
				'headers'             => $headers,
				'user-agent'          => 'Aculect-AI-Companion-Agent-Readiness',
				'body'                => 'POST' === $method ? $this->initialize_payload() : null,
			)
		);
	}

	/**
	 * Create a harmless unauthenticated initialization request payload.
	 */
	private function initialize_payload(): string {
		return (string) wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 'readiness',
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-03-26',
					'capabilities'    => array(),
					'clientInfo'      => array(
						'name'    => 'aculect-readiness-check',
						'version' => '1',
					),
				),
			)
		);
	}

	/**
	 * Extract only safe signal booleans and bounded transient text.
	 *
	 * @param array<string,mixed> $response HTTP response.
	 * @param int                 $status   Response code.
	 * @return array<string,mixed>
	 */
	private function summarize_response( array $response, int $status ): array {
		$content_type = $this->response_header( $response, 'content-type' );
		$body         = substr( (string) wp_remote_retrieve_body( $response ), 0, self::RESPONSE_BYTES );
		$challenge    = $this->response_header( $response, 'www-authenticate' );
		return array(
			'status'                 => $status,
			'body'                   => $body,
			'content_type'           => strtolower( $content_type ),
			'edge_challenge'         => $this->is_edge_challenge( $response, $body, $content_type ),
			'bearer_challenge_valid' => 1 === preg_match( '/^\s*Bearer\b/i', $challenge )
				&& str_contains( $challenge, 'resource_metadata="' . Helpers::protected_resource_metadata_url( Helpers::mcp_resource() ) . '"' ),
			'link_header_present'    => '' !== $this->response_header( $response, 'link' ),
			'markdown_media_type'    => str_contains( strtolower( $content_type ), 'text/markdown' ),
			'content_signal_seen'    => '' !== $this->response_header( $response, 'content-signal' ),
		);
	}

	/**
	 * Resolve a same-host redirect target.
	 *
	 * @param string $url      Current URL.
	 * @param string $location Redirect location.
	 */
	private function resolve_redirect( string $url, string $location ): string {
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}
		if ( str_starts_with( $location, '//' ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path = str_starts_with( $location, '/' ) ? $location : trailingslashit( dirname( $parts['path'] ?? '/' ) ) . $location;
		return $parts['scheme'] . '://' . $parts['host'] . $port . '/' . ltrim( $path, '/' );
	}

	/**
	 * Read one response header.
	 *
	 * @param array<string,mixed> $response HTTP response.
	 * @param string              $name     Header name.
	 */
	private function response_header( array $response, string $name ): string {
		$headers = wp_remote_retrieve_headers( $response );
		if ( isset( $headers[ $name ] ) ) {
			$value = $headers[ $name ];
			return is_scalar( $value ) ? (string) $value : '';
		}
		foreach ( $headers as $header => $value ) {
			if ( strtolower( (string) $header ) === strtolower( $name ) ) {
				return is_scalar( $value ) ? (string) $value : '';
			}
		}
		return '';
	}

	/**
	 * Detect common edge challenge responses without retaining headers or page text.
	 *
	 * @param array<string,mixed> $response HTTP response.
	 * @param string              $body     Bounded temporary body.
	 * @param string              $mime     Response media type.
	 */
	private function is_edge_challenge( array $response, string $body, string $mime ): bool {
		$mitigation = strtolower( $this->response_header( $response, 'cf-mitigated' ) );
		$body       = strtolower( $body );
		return 'challenge' === $mitigation
			|| ( str_contains( $mime, 'text/html' ) && ( str_contains( $body, 'just a moment' ) || str_contains( $body, 'checking your browser' ) || str_contains( $body, 'attention required' ) ) );
	}

	/**
	 * Compare scheme, host, and effective port, rejecting user-info URLs.
	 *
	 * @param array<string,mixed> $origin Parsed origin.
	 * @param array<string,mixed> $target Parsed target.
	 */
	private function same_origin( array $origin, array $target ): bool {
		if ( isset( $origin['user'] ) || isset( $target['user'] ) || isset( $origin['pass'] ) || isset( $target['pass'] ) ) {
			return false;
		}
		$origin_port = (int) ( $origin['port'] ?? ( 'https' === ( $origin['scheme'] ?? '' ) ? 443 : 80 ) );
		$target_port = (int) ( $target['port'] ?? ( 'https' === ( $target['scheme'] ?? '' ) ? 443 : 80 ) );
		return strtolower( (string) ( $origin['scheme'] ?? '' ) ) === strtolower( (string) ( $target['scheme'] ?? '' ) )
			&& strtolower( (string) ( $origin['host'] ?? '' ) ) === strtolower( (string) ( $target['host'] ?? '' ) )
			&& $origin_port === $target_port;
	}

	/**
	 * Mark a request as blocked before an origin response was observed.
	 *
	 * @return array<string,mixed>
	 */
	private function blocked(): array {
		return array(
			'status'  => 0,
			'blocked' => true,
		);
	}
}
