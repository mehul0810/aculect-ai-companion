<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Exception;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Connectors\OAuth\Server\AuthorizationServerFactory;
use Aculect\AICompanion\Diagnostics\Logger;
use Aculect\AICompanion\Diagnostics\LogSanitizer;
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
				'permission_callback' => array( $this, 'check_token_permission' ),
			)
		);

		RateLimiter::register_retry_after_header();
	}

	/**
	 * Permit rate-limited public access to the OAuth token endpoint.
	 *
	 * RFC 6749 token endpoints authenticate with grant credentials in the
	 * request body, not WordPress auth, so the route must stay public. The
	 * per-IP window blunts authorization-code and refresh-token brute force
	 * without breaking normal Claude/ChatGPT/Codex refresh cycles.
	 *
	 * @return true|\WP_Error
	 */
	public function check_token_permission(): bool|\WP_Error {
		return ( new RateLimiter() )->check( 'oauth_token', 30, MINUTE_IN_SECONDS );
	}

	/**
	 * Exchange authorization codes or refresh tokens for access tokens.
	 *
	 * @param WP_REST_Request $request Token request.
	 * @return WP_REST_Response
	 */
	public function token( WP_REST_Request $request ): WP_REST_Response {
		$logger   = new Logger();
		$resource = $this->resource_from_request( $request );
		$context  = $this->log_context( $request, $resource );

		$logger->info(
			'token.received',
			'OAuth token request received.',
			$context,
			$request
		);

		if ( Helpers::mcp_resource() !== $resource ) {
			$logger->warning(
				'token.invalid_resource',
				'OAuth token request used an invalid resource.',
				array_merge( $context, array( 'error_code' => 'invalid_target' ) ),
				$request,
				400
			);
			return $this->error( 'invalid_target', 'The requested resource does not match this Aculect AI Companion MCP server.', 400 );
		}

		try {
			RequestContext::set_resource( $resource );
			$response = AuthorizationServerFactory::create()->respondToAccessTokenRequest(
				Psr7Bridge::from_rest_request( $request ),
				Psr7Bridge::response()
			);

			$logger->info(
				'token.issued',
				'OAuth token request completed.',
				$context,
				$request,
				$response->getStatusCode()
			);

			return Psr7Bridge::to_rest_response( $response );
		} catch ( OAuthServerException $exception ) {
			$logger->warning(
				'token.oauth_error',
				'OAuth token request was rejected.',
				array_merge( $context, array( 'error_code' => $exception->getErrorType() ) ),
				$request,
				$exception->getHttpStatusCode()
			);
			return Psr7Bridge::to_rest_response( $exception->generateHttpResponse( Psr7Bridge::response() ) );
		} catch ( Exception $exception ) {
			unset( $exception );
			$logger->error(
				'token.failed',
				'OAuth token request failed.',
				array_merge( $context, array( 'error_code' => 'server_error' ) ),
				$request,
				500
			);
			return $this->error( 'server_error', $this->server_error_description(), 500 );
		} finally {
			RequestContext::reset();
		}
	}

	/**
	 * Return a generic server-error description safe for OAuth clients.
	 */
	private function server_error_description(): string {
		return 'The OAuth token request failed. Try again or reconnect the client.';
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
	 * Build sanitized diagnostic context for token events.
	 *
	 * @param WP_REST_Request $request  Token request.
	 * @param string          $resource Requested resource.
	 * @return array<string, mixed>
	 */
	private function log_context( WP_REST_Request $request, string $resource ): array {
		$sanitizer = new LogSanitizer();

		return array(
			'provider'   => $this->client_provider( $request ),
			'grant_type' => (string) $request->get_param( 'grant_type' ),
			'resource'   => $sanitizer->sanitize_url( $resource ),
		);
	}

	/**
	 * Resolve the registered provider for a token request client.
	 *
	 * @param WP_REST_Request $request Token request.
	 */
	private function client_provider( WP_REST_Request $request ): string {
		$client_id = (string) $request->get_param( 'client_id' );
		if ( '' === $client_id ) {
			return '';
		}

		$client = ( new ClientRepository() )->getClientEntity( $client_id );

		return $client instanceof ClientEntity ? $client->getProvider() : '';
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
