<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth;

use Quark\Connectors\Helpers;
use Quark\Connectors\OAuth\Repositories\ClientRepository;
use WP_REST_Response;
use WP_REST_Server;

final class DiscoveryController {

	public function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource(/.+)?/?$',
			'index.php?quark_well_known=' . Helpers::PROTECTED_RESOURCE_METADATA . '&quark_well_known_resource_path=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server(/.+)?/?$',
			'index.php?quark_well_known=' . Helpers::AUTHORIZATION_METADATA . '&quark_well_known_issuer_path=$matches[1]',
			'top'
		);
	}

	public function register_rest_routes(): void {
		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/oauth/resource',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => $this->protected_resource_metadata(),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/oauth/metadata',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => $this->authorization_server_metadata(),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/.well-known/oauth-protected-resource',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => $this->protected_resource_metadata(),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/.well-known/oauth-authorization-server',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => $this->authorization_server_metadata(),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function protected_resource_metadata( string $requested_resource_path = '' ): WP_REST_Response {
		$resource                = Helpers::mcp_resource();
		$resource_path           = Helpers::resource_path( $resource );
		$requested_resource_path = untrailingslashit( $requested_resource_path );

		if ( '' !== $requested_resource_path && $requested_resource_path !== $resource_path ) {
			return new WP_REST_Response( array( 'error' => 'invalid_target' ), 404 );
		}

		$response = new WP_REST_Response(
			array(
				'resource'                              => $resource,
				'authorization_servers'                 => array( Helpers::issuer() ),
				'scopes_supported'                      => Helpers::supported_scopes(),
				'resource_documentation'                => 'https://github.com/mehul0810/quark',
				'token_endpoint_auth_methods_supported' => array( 'client_secret_post', 'client_secret_basic' ),
			),
			200
		);
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	public function authorization_server_metadata( string $requested_issuer_path = '' ): WP_REST_Response {
		$issuer_path           = untrailingslashit( (string) wp_parse_url( Helpers::issuer(), PHP_URL_PATH ) );
		$requested_issuer_path = untrailingslashit( $requested_issuer_path );

		if ( '' !== $requested_issuer_path && $requested_issuer_path !== $issuer_path ) {
			return new WP_REST_Response( array( 'error' => 'invalid_issuer' ), 404 );
		}

		$response = new WP_REST_Response(
			array(
				'issuer'                                => Helpers::issuer(),
				'authorization_endpoint'                => Helpers::authorization_endpoint(),
				'token_endpoint'                        => Helpers::token_endpoint(),
				'registration_endpoint'                 => Helpers::registration_endpoint(),
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'token_endpoint_auth_methods_supported' => array( 'client_secret_post', 'client_secret_basic' ),
				'code_challenge_methods_supported'      => array( 'S256' ),
				'scopes_supported'                      => Helpers::supported_scopes(),
				'resource_indicators_supported'         => true,
				'protected_resources'                   => array( Helpers::mcp_resource() ),
			),
			200
		);
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	public function render_well_known_metadata(): void {
		$document      = (string) get_query_var( 'quark_well_known' );
		$resource_path = (string) get_query_var( 'quark_well_known_resource_path' );
		$issuer_path   = (string) get_query_var( 'quark_well_known_issuer_path' );

		if ( '' === $document ) {
			$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
			if ( preg_match( '#/\.well-known/oauth-protected-resource(?P<resource_path>/.+)?/?$#', $path, $matches ) ) {
				$document      = Helpers::PROTECTED_RESOURCE_METADATA;
				$resource_path = (string) ( $matches['resource_path'] ?? '' );
			} elseif ( preg_match( '#/\.well-known/oauth-authorization-server(?P<issuer_path>/.+)?/?$#', $path, $matches ) ) {
				$document    = Helpers::AUTHORIZATION_METADATA;
				$issuer_path = (string) ( $matches['issuer_path'] ?? '' );
			}
		}

		if ( '' === $document ) {
			return;
		}

		$response = Helpers::PROTECTED_RESOURCE_METADATA === $document
			? $this->protected_resource_metadata( $resource_path )
			: $this->authorization_server_metadata( $issuer_path );

		status_header( $response->get_status() );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $response->get_data(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}

	public function diagnostics(): array {
		return array(
			'mcp_url'                           => Helpers::mcp_resource(),
			'resource_metadata_url'             => Helpers::protected_resource_metadata_url(),
			'authorization_server_metadata_url' => Helpers::authorization_metadata_url(),
			'issuer'                            => Helpers::issuer(),
			'authorization_endpoint'            => Helpers::authorization_endpoint(),
			'token_endpoint'                    => Helpers::token_endpoint(),
			'registration_endpoint'             => Helpers::registration_endpoint(),
			'scopes'                            => implode( ' ', Helpers::supported_scopes() ),
			'registered_clients'                => ( new ClientRepository() )->list_clients(),
		);
	}
}
