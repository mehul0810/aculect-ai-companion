<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\Server\ResourceServerFactory;
use WP_REST_Request;

/**
 * Validates bearer tokens and maps them to MCP request context.
 */
final class TokenValidator {

	private const FAILURE_NONE                      = 'none';
	private const FAILURE_RESOURCE_MISMATCH         = 'resource_mismatch';
	private const FAILURE_TOKEN_VALIDATION          = 'token_validation_failed';
	private const FAILURE_TOKEN_CONTEXT_MISSING     = 'token_context_missing';
	private const FAILURE_CONTEXT_RESOURCE_MISMATCH = 'context_resource_mismatch';

	private string $failure_reason = self::FAILURE_NONE;

	/**
	 * Authenticate a REST request with the OAuth resource server.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return array<string, mixed>
	 */
	public function authenticate( WP_REST_Request $request ): array {
		$this->failure_reason = self::FAILURE_NONE;

		try {
			$requested_resource = (string) $request->get_param( 'resource' );
			if ( '' === $requested_resource ) {
				$requested_resource = (string) $request->get_header( 'resource' );
			}
			if ( '' !== $requested_resource && Helpers::mcp_resource() !== Helpers::normalize_resource( $requested_resource ) ) {
				$this->failure_reason = self::FAILURE_RESOURCE_MISMATCH;
				return array();
			}

			$validated = ResourceServerFactory::create()->validateAuthenticatedRequest( Psr7Bridge::from_rest_request( $request ) );
			$token_id  = (string) $validated->getAttribute( 'oauth_access_token_id' );
			$context   = ( new AccessTokenRepository() )->context_from_token_id( $token_id );

			if ( array() === $context || Helpers::mcp_resource() !== Helpers::normalize_resource( (string) ( $context['resource'] ?? '' ) ) ) {
				$this->failure_reason = array() === $context
					? self::FAILURE_TOKEN_CONTEXT_MISSING
					: self::FAILURE_CONTEXT_RESOURCE_MISMATCH;
				return array();
			}

			return $context;
		} catch ( \Throwable ) {
			$this->failure_reason = self::FAILURE_TOKEN_VALIDATION;
			return array();
		}
	}

	/**
	 * Return a fixed, non-secret reason for the latest failed validation.
	 *
	 * This is intended for bounded diagnostics only; callers must not expose it
	 * in OAuth or MCP responses.
	 */
	public function failure_reason(): string {
		return $this->failure_reason;
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
			self::quote( 'Authorize Aculect AI Companion to continue' )
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
