<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth;

use Exception;
use Quark\Connectors\Helpers;
use Quark\Connectors\OAuth\Server\AuthorizationServerFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class TokenController {

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

	public function token( WP_REST_Request $request ): WP_REST_Response {
		$resource = $this->resource_from_request( $request );
		if ( Helpers::mcp_resource() !== $resource ) {
			return $this->error( 'invalid_target', 'The requested resource does not match this Quark MCP server.', 400 );
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

	private function resource_from_request( WP_REST_Request $request ): string {
		$resource = (string) $request->get_param( 'resource' );
		if ( '' === $resource ) {
			$resource = (string) $request->get_param( 'audience' );
		}

		return '' === $resource ? Helpers::mcp_resource() : Helpers::normalize_resource( $resource );
	}

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
