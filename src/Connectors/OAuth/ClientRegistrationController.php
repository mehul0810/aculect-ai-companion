<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth;

use Quark\Connectors\Helpers;
use Quark\Connectors\OAuth\Repositories\ClientRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ClientRegistrationController {

	public function register_routes(): void {
		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/oauth/register',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'register_client' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function register_client( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! $this->rate_limit( $request ) ) {
			return new WP_Error( 'rate_limit_exceeded', 'Too many registration requests. Please try again later.', array( 'status' => 429 ) );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$client_name   = sanitize_text_field( (string) ( $body['client_name'] ?? 'Quark MCP Client' ) );
		$redirect_uris = $this->redirect_uris( (array) ( $body['redirect_uris'] ?? array() ) );
		if ( array() === $redirect_uris ) {
			return new WP_Error( 'invalid_redirect_uri', 'At least one valid redirect URI is required.', array( 'status' => 400 ) );
		}

		$client = ( new ClientRepository() )->create_client( $client_name, $redirect_uris, true, null );
		if ( ! is_array( $client ) ) {
			return new WP_Error( 'registration_failed', 'Unable to register OAuth client.', array( 'status' => 500 ) );
		}

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

	private function rate_limit( WP_REST_Request $request ): bool {
		$identity = $this->request_ip( $request );
		$key      = 'quark_dcr_' . md5( $identity );
		$attempts = (int) get_transient( $key );

		if ( $attempts >= 120 ) {
			return false;
		}

		set_transient( $key, $attempts + 1, HOUR_IN_SECONDS );
		return true;
	}

	private function request_ip( WP_REST_Request $request ): string {
		foreach ( array( 'cf-connecting-ip', 'x-real-ip', 'x-forwarded-for' ) as $header ) {
			$value = trim( (string) $request->get_header( $header ) );
			if ( '' !== $value ) {
				return sanitize_text_field( explode( ',', $value )[0] );
			}
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : 'anonymous';
	}
}
