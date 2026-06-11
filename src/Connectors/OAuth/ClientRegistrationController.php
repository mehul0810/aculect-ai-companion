<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Diagnostics\Logger;
use Aculect\AICompanion\Diagnostics\LogSanitizer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles OAuth Dynamic Client Registration for MCP clients.
 */
final class ClientRegistrationController {

	/**
	 * Register the client registration REST endpoint.
	 */
	public function register_routes(): void {
		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/oauth/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'register_client' ),
				'permission_callback' => array( $this, 'check_registration_permission' ),
			)
		);
	}

	/**
	 * Permit public access to Dynamic Client Registration.
	 *
	 * RFC 7591 DCR is intentionally unauthenticated: AI clients (Claude,
	 * ChatGPT, Codex) register themselves before any credential exists.
	 * Valid connector retries must not fail behind a plugin-level 429. Abuse is
	 * bounded by redirect URI validation, duplicate-client pruning, and the
	 * active-client cap enforced in register_client().
	 *
	 * @return true
	 */
	public function check_registration_permission(): bool {
		return true;
	}

	/**
	 * Register a confidential OAuth client from validated DCR metadata.
	 *
	 * @param WP_REST_Request $request Registration request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_client( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$logger            = new Logger();
		$sanitizer         = new LogSanitizer();
		$client_name       = sanitize_text_field( (string) ( $body['client_name'] ?? 'Aculect AI Companion MCP Client' ) );
		$raw_redirect_uris = $this->raw_redirect_uris( (array) ( $body['redirect_uris'] ?? array() ) );
		$provider          = Helpers::provider_from_client( $client_name, $raw_redirect_uris );

		$logger->info(
			'dcr.received',
			'Dynamic client registration request received.',
			array(
				'provider'           => $provider,
				'client_name'        => $client_name,
				'redirect_hosts'     => $sanitizer->redirect_hosts( $raw_redirect_uris ),
				'redirect_uri_count' => count( $raw_redirect_uris ),
			),
			$request
		);

		$redirect_uris = $this->redirect_uris( $raw_redirect_uris );
		if ( array() === $redirect_uris ) {
			$logger->warning(
				'dcr.invalid_redirect_uri',
				'Dynamic client registration did not include a valid redirect URI.',
				array(
					'provider'           => $provider,
					'error_code'         => 'invalid_redirect_uri',
					'redirect_hosts'     => $sanitizer->redirect_hosts( $raw_redirect_uris ),
					'redirect_uri_count' => count( $raw_redirect_uris ),
				),
				$request,
				400
			);
			return new WP_Error( 'invalid_redirect_uri', 'At least one valid redirect URI is required.', array( 'status' => 400 ) );
		}

		$client = ( new ClientRepository() )->create_client( $client_name, $redirect_uris, true, null );
		if ( ! is_array( $client ) ) {
			$logger->error(
				'dcr.registration_failed',
				'Dynamic client registration failed while storing the OAuth client.',
				array(
					'provider'           => $provider,
					'error_code'         => 'registration_failed',
					'redirect_hosts'     => $sanitizer->redirect_hosts( $redirect_uris ),
					'redirect_uri_count' => count( $redirect_uris ),
				),
				$request,
				500
			);
			return new WP_Error( 'registration_failed', 'Unable to register OAuth client.', array( 'status' => 500 ) );
		}

		$logger->info(
			'dcr.registered',
			'Dynamic client registration completed.',
			array(
				'provider'           => (string) ( $client['provider'] ?? $provider ),
				'redirect_hosts'     => $sanitizer->redirect_hosts( $redirect_uris ),
				'redirect_uri_count' => count( $redirect_uris ),
			),
			$request,
			201
		);

		return new WP_REST_Response(
			array(
				'client_id'                  => (string) $client['client_id'],
				'client_secret'              => (string) $client['client_secret'],
				'client_id_issued_at'        => time(),
				'client_secret_expires_at'   => 0,
				'client_name'                => $client_name,
				'redirect_uris'              => $redirect_uris,
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'client_secret_post',
				'scope'                      => implode( ' ', Helpers::supported_scopes() ),
			),
			201
		);
	}

	/**
	 * Validate and de-duplicate redirect URIs supplied by the client.
	 *
	 * @param array<int, mixed> $uris Raw redirect URI values.
	 * @return string[]
	 */
	private function redirect_uris( array $uris ): array {
		$valid = array();
		foreach ( $uris as $uri ) {
			if ( ! is_scalar( $uri ) ) {
				continue;
			}

			$uri = esc_url_raw( (string) $uri );
			if ( '' !== $uri && Helpers::is_allowed_redirect_uri( $uri ) ) {
				$valid[] = $uri;
			}
		}

		return array_values( array_unique( $valid ) );
	}

	/**
	 * Return scalar redirect URI candidates for diagnostics and validation.
	 *
	 * @param array<int, mixed> $uris Raw redirect URI values.
	 * @return string[]
	 */
	private function raw_redirect_uris( array $uris ): array {
		return array_values(
			array_filter(
				array_map(
					static fn( mixed $uri ): string => is_scalar( $uri ) ? (string) $uri : '',
					$uris
				)
			)
		);
	}
}
