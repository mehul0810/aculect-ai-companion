<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Converts between WordPress REST objects and PSR-7 objects for league/oauth2-server.
 */
final class Psr7Bridge {

	/**
	 * Build a PSR-7 server request from a WordPress REST request.
	 *
	 * @param WP_REST_Request $request WordPress REST request.
	 * @return ServerRequestInterface
	 */
	public static function from_rest_request( WP_REST_Request $request ): ServerRequestInterface {
		$headers = array();
		foreach ( $request->get_headers() as $key => $value ) {
			$header_key             = str_replace( '_', '-', ucwords( strtolower( $key ), '_' ) );
			$headers[ $header_key ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		if ( empty( $headers['Authorization'] ) ) {
			foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $server_key ) {
				if ( ! empty( $_SERVER[ $server_key ] ) && is_scalar( $_SERVER[ $server_key ] ) ) {
					$headers['Authorization'] = sanitize_text_field( wp_unslash( (string) $_SERVER[ $server_key ] ) );
					break;
				}
			}
		}

		$uri          = rest_url( ltrim( $request->get_route(), '/' ) );
		$query_params = $request->get_query_params();
		if ( array() !== $query_params ) {
			$uri = add_query_arg( $query_params, $uri );
		}

		$psr_request = new ServerRequest( $request->get_method(), $uri, $headers, $request->get_body() );
		$body_params = $request->get_body_params();
		if ( array() === $body_params ) {
			$json_params = $request->get_json_params();
			$body_params = $json_params;
		}
		if ( array() !== $body_params ) {
			$psr_request = $psr_request->withParsedBody( $body_params );
		}

		if ( array() !== $query_params ) {
			$psr_request = $psr_request->withQueryParams( $query_params );
		}

		return $psr_request;
	}

	/**
	 * Build a minimal PSR-7 request for internal authorization processing.
	 *
	 * @param string              $method       HTTP method.
	 * @param string              $uri          Request URI.
	 * @param array<string,mixed> $query_params Query parameters.
	 * @param array<string,mixed> $parsed_body  Parsed request body.
	 * @return ServerRequestInterface
	 */
	public static function server_request( string $method, string $uri, array $query_params = array(), array $parsed_body = array() ): ServerRequestInterface {
		$request = new ServerRequest( $method, $uri );
		if ( array() !== $query_params ) {
			$request = $request->withQueryParams( $query_params );
		}
		if ( array() !== $parsed_body ) {
			$request = $request->withParsedBody( $parsed_body );
		}

		return $request;
	}

	/**
	 * Create a PSR-7 response for OAuth server calls.
	 *
	 * @param int                 $status  HTTP status.
	 * @param array<string,mixed> $headers Headers.
	 * @param string              $body    Response body.
	 * @return ResponseInterface
	 */
	public static function response( int $status = 200, array $headers = array(), string $body = '' ): ResponseInterface {
		return new Response( $status, $headers, $body );
	}

	/**
	 * Convert a PSR-7 response to a WordPress REST response.
	 *
	 * @param ResponseInterface $response PSR-7 response.
	 * @return WP_REST_Response
	 */
	public static function to_rest_response( ResponseInterface $response ): WP_REST_Response {
		$body = (string) $response->getBody();
		$data = json_decode( $body, true );
		if ( null === $data && '' !== $body ) {
			$data = $body;
		}

		$rest_response = new WP_REST_Response( $data, $response->getStatusCode() );
		foreach ( $response->getHeaders() as $name => $values ) {
			foreach ( $values as $value ) {
				$rest_response->header( $name, $value );
			}
		}

		return $rest_response;
	}
}
