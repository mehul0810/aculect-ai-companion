<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Exception;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\Server\AuthorizationServerFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles OAuth token exchange and refresh requests.
 */
final class TokenController {

	/**
	 * Register the OAuth token REST endpoint.
	 */
	public function register_routes(): void {
		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/oauth/token',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'token' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Exchange authorization codes or refresh tokens for access tokens.
	 *
	 * @param WP_REST_Request $request Token request.
	 * @return WP_REST_Response
	 */
	public function token( WP_REST_Request $request ): WP_REST_Response {
		$resource = $this->resource_from_request( $request );
		if ( Helpers::mcp_resource() !== $resource ) {
			return $this->error( 'invalid_target', 'The requested resource does not match this Aculect AI Companion MCP server.', 400 );
		}

		try {
			RequestContext::set_resource( $resource );
			$response = AuthorizationServerFactory::create()->respondToAccessTokenRequest(
				Psr7Bridge::from_rest_request( $request ),
				Psr7Bridge::response()
			);

			return Psr7Bridge::to_rest_response( $response );
		} catch ( OAuthServerException $exception ) {
			return Psr7Bridge::to_rest_response( $exception->generateHttpResponse( Psr7Bridge::response() ) );
		} catch ( Exception $exception ) {
			return $this->error( 'server_error', $exception->getMessage(), 500 );
		} finally {
			RequestContext::reset();
		}
	}

	/**
	 * Resolve and normalize the requested resource indicator.
	 *
	 * @param WP_REST_Request $request Token request.
	 * @return string
	 */
	private function resource_from_request( WP_REST_Request $request ): string {
		$resource = (string) $request->get_param( 'resource' );
		if ( '' === $resource ) {
			$resource = (string) $request->get_param( 'audience' );
		}

		return '' === $resource ? Helpers::mcp_resource() : Helpers::normalize_resource( $resource );
	}

	/**
	 * Build an OAuth JSON error response with no-store headers.
	 *
	 * @param string $error       OAuth error code.
	 * @param string $description Human-readable description.
	 * @param int    $status      HTTP status code.
	 * @return WP_REST_Response
	 */
	private function error( string $error, string $description, int $status ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}
}
