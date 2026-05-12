<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth;

use Exception;
use Quark\Connectors\Helpers;
use Quark\Connectors\OAuth\Repositories\AccessTokenRepository;
use Quark\Connectors\OAuth\Server\ResourceServerFactory;
use League\OAuth2\Server\Exception\OAuthServerException;
use WP_REST_Request;

/**
 * Validates bearer tokens and maps them to MCP request context.
 */
final class TokenValidator {

	/**
	 * Authenticate a REST request with the OAuth resource server.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return array<string, mixed>
	 */
	public function authenticate( WP_REST_Request $request ): array {
		try {
			$requested_resource = (string) $request->get_param( 'resource' );
			if ( '' === $requested_resource ) {
				$requested_resource = (string) $request->get_header( 'resource' );
			}
			if ( '' !== $requested_resource && Helpers::mcp_resource() !== Helpers::normalize_resource( $requested_resource ) ) {
				return array();
			}

			$validated = ResourceServerFactory::create()->validateAuthenticatedRequest( Psr7Bridge::from_rest_request( $request ) );
			$token_id  = (string) $validated->getAttribute( 'oauth_access_token_id' );
			$context   = ( new AccessTokenRepository() )->context_from_token_id( $token_id );

			if ( array() === $context || Helpers::mcp_resource() !== Helpers::normalize_resource( (string) ( $context['resource'] ?? '' ) ) ) {
				return array();
			}

			return $context;
		} catch ( OAuthServerException | Exception ) {
			return array();
		}
	}

	/**
	 * Build the OAuth resource challenge header used by unauthenticated MCP calls.
	 *
	 * @param string $scope Required scope.
	 * @param string $error OAuth error code.
	 * @return string
	 */
	public static function www_authenticate_header( string $scope = 'content:read', string $error = 'invalid_token' ): string {
		return sprintf(
			'Bearer resource_metadata="%s", scope="%s", error="%s", error_description="%s"',
			self::quote( Helpers::protected_resource_metadata_url() ),
			self::quote( $scope ),
			self::quote( $error ),
			self::quote( 'Authorize Quark to continue' )
		);
	}

	/**
	 * Escape a WWW-Authenticate parameter value.
	 *
	 * @param string $value Header value.
	 * @return string
	 */
	private static function quote( string $value ): string {
		return addcslashes( sanitize_text_field( $value ), '\\"' );
	}
}
