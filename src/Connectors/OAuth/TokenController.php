<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Exception;
use Aculect\AICompanion\Activity\ActivityLogger;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\Entities\ClientEntity;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\RefreshTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\Server\AuthorizationServerFactory;
use Aculect\AICompanion\Connectors\OAuth\Server\KeyManager;
use Aculect\AICompanion\Diagnostics\Logger;
use Aculect\AICompanion\Diagnostics\LogSanitizer;
use League\OAuth2\Server\CryptTrait;
use League\OAuth2\Server\Exception\OAuthServerException;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles OAuth token exchange and refresh requests.
 */
final class TokenController {

	use CryptTrait {
		decrypt as private;
		setEncryptionKey as private;
	}

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
		$logger         = new Logger();
		$resource       = $this->resource_from_request( $request );
		$context        = $this->log_context( $request, $resource );
		$timeline_event = $this->token_timeline_event( $request );
		$timeline_auth  = $this->timeline_auth_context( $request );
		$started_at     = microtime( true );

		$logger->info(
			'token.received',
			'OAuth token request received.',
			$context,
			$request
		);

		if ( Helpers::mcp_resource() !== $resource ) {
			$refresh_context = $this->refresh_rejection_context( $request, 'invalid_target' );
			$timeline_auth   = $this->correlate_timeline_auth( $timeline_auth, $refresh_context );
			$logger->warning(
				'token.invalid_resource',
				'OAuth token request used an invalid resource.',
				array_merge( $context, array( 'error_code' => 'invalid_target' ) ),
				$request,
				400
			);
			$this->record_timeline_event(
				$timeline_event,
				array_merge(
					array(
						'status'       => 'error',
						'method'       => 'oauth/token',
						'grant_type'   => (string) $request->get_param( 'grant_type' ),
						'result_class' => 'invalid_target',
						'error_code'   => 'invalid_target',
						'duration_ms'  => $this->duration_ms( $started_at ),
					),
					$refresh_context
				),
				$timeline_auth
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
			$this->record_timeline_event(
				$timeline_event,
				array(
					'status'       => 400 <= $response->getStatusCode() ? 'error' : 'success',
					'method'       => 'oauth/token',
					'grant_type'   => (string) $request->get_param( 'grant_type' ),
					'result_class' => $this->token_result_class( $response->getStatusCode() ),
					'duration_ms'  => $this->duration_ms( $started_at ),
				),
				$timeline_auth
			);

			return Psr7Bridge::to_rest_response( $response );
		} catch ( OAuthServerException $exception ) {
			$refresh_context = $this->refresh_rejection_context( $request, $exception->getErrorType() );
			$timeline_auth   = $this->correlate_timeline_auth( $timeline_auth, $refresh_context );
			$logger->warning(
				'token.oauth_error',
				'OAuth token request was rejected.',
				array_merge( $context, array( 'error_code' => $exception->getErrorType() ) ),
				$request,
				$exception->getHttpStatusCode()
			);
			$this->record_timeline_event(
				$timeline_event,
				array_merge(
					array(
						'status'       => 'error',
						'method'       => 'oauth/token',
						'grant_type'   => (string) $request->get_param( 'grant_type' ),
						'result_class' => $this->token_result_class( $exception->getHttpStatusCode() ),
						'error_code'   => $exception->getErrorType(),
						'duration_ms'  => $this->duration_ms( $started_at ),
					),
					$refresh_context
				),
				$timeline_auth
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
			$this->record_timeline_event(
				$timeline_event,
				array(
					'status'       => 'error',
					'method'       => 'oauth/token',
					'grant_type'   => (string) $request->get_param( 'grant_type' ),
					'result_class' => 'server_error',
					'error_code'   => 'server_error',
					'duration_ms'  => $this->duration_ms( $started_at ),
				),
				$timeline_auth
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
	 * Return the timeline event name for the requested OAuth grant.
	 *
	 * @param WP_REST_Request $request Token request.
	 */
	private function token_timeline_event( WP_REST_Request $request ): string {
		return 'refresh_token' === (string) $request->get_param( 'grant_type' ) ? 'token_refresh' : 'token_exchange';
	}

	/**
	 * Build a support-safe result class from an OAuth token response status.
	 *
	 * @param int $status HTTP status code.
	 */
	private function token_result_class( int $status ): string {
		if ( $status >= 500 ) {
			return 'server_error';
		}

		if ( $status >= 400 ) {
			return 'oauth_error';
		}

		return 'issued';
	}

	/**
	 * Build timeline grouping context for token endpoint events.
	 *
	 * @param WP_REST_Request $request Token request.
	 * @return array<string, mixed>
	 */
	private function timeline_auth_context( WP_REST_Request $request ): array {
		$client_id = (string) $request->get_param( 'client_id' );

		return array(
			'provider'  => $this->client_provider( $request ),
			'client_id' => $client_id,
		);
	}

	/**
	 * Build support metadata for a rejected pre-auth refresh request.
	 *
	 * @param WP_REST_Request $request    Token request.
	 * @param string          $error_code OAuth error code.
	 * @return array<string, mixed>
	 */
	private function refresh_rejection_context( WP_REST_Request $request, string $error_code ): array {
		if ( 'refresh_token' !== (string) $request->get_param( 'grant_type' ) ) {
			return array();
		}

		$context = array(
			'identity_status' => 'unavailable_pre_auth',
			'recovery_action' => 'reconnect_assistant',
		);
		if ( 'invalid_grant' !== $error_code ) {
			return $context;
		}

		$token_id = $this->refresh_token_id_from_presented_token( (string) $request->get_param( 'refresh_token' ) );
		if ( '' === $token_id ) {
			return $context;
		}

		try {
			return array_merge( $context, ( new RefreshTokenRepository() )->support_context_from_token_id( $token_id ) );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return $context;
		}
	}

	/**
	 * Recover League's internal identifier from a presented refresh token.
	 *
	 * AuthorizationServerFactory supplies League with a string encryption key,
	 * so League encrypts the JSON payload with Defuse password semantics. The
	 * opaque token and decrypted payload remain request-local and are never
	 * included in activity metadata.
	 *
	 * @param string $token Presented encrypted refresh token.
	 */
	private function refresh_token_id_from_presented_token( string $token ): string {
		if ( '' === $token ) {
			return '';
		}

		try {
			$this->setEncryptionKey( KeyManager::encryption_key() );
			$payload = json_decode(
				$this->decrypt( $token ),
				true,
				512,
				JSON_THROW_ON_ERROR
			);
		} catch ( \Throwable $throwable ) {
			unset( $throwable );

			return '';
		} finally {
			$this->setEncryptionKey();
		}

		$token_id = is_array( $payload ) ? ( $payload['refresh_token_id'] ?? null ) : null;

		return is_string( $token_id ) ? trim( $token_id ) : '';
	}

	/**
	 * Fill missing timeline grouping fields from an existing stored connection.
	 *
	 * @param array<string, mixed> $auth    Request-derived timeline context.
	 * @param array<string, mixed> $context Stored refresh-token support context.
	 * @return array<string, mixed>
	 */
	private function correlate_timeline_auth( array $auth, array $context ): array {
		if ( '' === (string) ( $auth['provider'] ?? '' ) && isset( $context['provider'] ) ) {
			$auth['provider'] = (string) $context['provider'];
		}

		if ( '' === (string) ( $auth['client_id'] ?? '' ) && isset( $context['connection_client_id'] ) ) {
			$auth['client_id'] = (string) $context['connection_client_id'];
		}

		return $auth;
	}

	/**
	 * Record a token endpoint timeline event without making token issuance depend on logging.
	 *
	 * @param string               $event    Timeline event type.
	 * @param array<string, mixed> $metadata Support-safe metadata.
	 * @param array<string, mixed> $auth     OAuth client context.
	 */
	private function record_timeline_event( string $event, array $metadata, array $auth ): void {
		try {
			( new ActivityLogger() )->record_timeline_event( $event, $metadata, $auth );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}

	/**
	 * Return elapsed milliseconds from a token request timestamp.
	 *
	 * @param float $started_at Request start timestamp.
	 */
	private function duration_ms( float $started_at ): int {
		return max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
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
