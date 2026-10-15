<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\Repositories\ClientRepository;
use Aculect\AICompanion\Diagnostics\Logger;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Serves OAuth discovery metadata for MCP protected resources.
 */
final class DiscoveryController {

	/**
	 * Keep public discovery cache entries short enough that endpoint and
	 * capability changes do not remain pinned in connector caches for an hour.
	 */
	private const PUBLIC_METADATA_CACHE_MAX_AGE = 300;

	/**
	 * Register root-level well-known rewrite rules.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource(/.+)?/?$',
			'index.php?aculect_ai_companion_well_known=' . Helpers::PROTECTED_RESOURCE_METADATA . '&aculect_ai_companion_well_known_resource_path=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'^\.well-known/oauth-authorization-server(/.+)?/?$',
			'index.php?aculect_ai_companion_well_known=' . Helpers::AUTHORIZATION_METADATA . '&aculect_ai_companion_well_known_issuer_path=$matches[1]',
			'top'
		);
	}

	/**
	 * Permit public access to OAuth discovery metadata.
	 *
	 * RFC 8414 / RFC 9728 metadata documents must be readable before any
	 * credential exists so MCP clients can locate the authorization server.
	 * Responses contain only public endpoint URLs and supported capabilities.
	 *
	 * @return true
	 */
	public function check_discovery_permission(): bool {
		return true;
	}

	/**
	 * Register REST aliases for OAuth metadata documents.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/oauth/resource',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => $this->protected_resource_metadata(),
				'permission_callback' => array( $this, 'check_discovery_permission' ),
			)
		);

		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/oauth/metadata',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => $this->authorization_server_metadata(),
				'permission_callback' => array( $this, 'check_discovery_permission' ),
			)
		);

		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/.well-known/oauth-protected-resource',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => $this->protected_resource_metadata(),
				'permission_callback' => array( $this, 'check_discovery_permission' ),
			)
		);

		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/.well-known/oauth-authorization-server',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => fn() => $this->authorization_server_metadata(),
				'permission_callback' => array( $this, 'check_discovery_permission' ),
			)
		);
	}

	/**
	 * Return OAuth protected-resource metadata.
	 *
	 * @param string $requested_resource_path Optional path from well-known URL.
	 * @return WP_REST_Response
	 */
	public function protected_resource_metadata( string $requested_resource_path = '' ): WP_REST_Response {
		$resource                = Helpers::mcp_resource();
		$resource_path           = Helpers::resource_path( $resource );
		$requested_resource_path = untrailingslashit( $requested_resource_path );

		if ( '' !== $requested_resource_path && $requested_resource_path !== $resource_path ) {
			( new Logger() )->warning(
				'metadata.invalid_target',
				'Protected resource metadata was requested for an unknown resource path.',
				array(
					'error_code'              => 'invalid_target',
					'requested_resource_path' => $requested_resource_path,
					'expected_resource_path'  => $resource_path,
				),
				null,
				404
			);
			return new WP_REST_Response( array( 'error' => 'invalid_target' ), 404 );
		}

		$response = new WP_REST_Response(
			array(
				'resource'                              => $resource,
				'authorization_servers'                 => array( Helpers::authorization_server_issuer() ),
				'scopes_supported'                      => Helpers::supported_scopes(),
				'resource_documentation'                => 'https://github.com/mehul0810/aculect-ai-companion',
				'token_endpoint_auth_methods_supported' => TokenEndpointAuthMethod::supported(),
			),
			200
		);
		$response->header( 'Cache-Control', 'public, max-age=' . self::PUBLIC_METADATA_CACHE_MAX_AGE );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * Return OAuth authorization-server metadata.
	 *
	 * @param string $requested_issuer_path Optional issuer path from well-known URL.
	 * @return WP_REST_Response
	 */
	public function authorization_server_metadata( string $requested_issuer_path = '' ): WP_REST_Response {
		$site_issuer_path      = untrailingslashit( (string) wp_parse_url( Helpers::issuer(), PHP_URL_PATH ) );
		$requested_issuer_path = untrailingslashit( $requested_issuer_path );

		if ( '' !== $requested_issuer_path && $requested_issuer_path !== $site_issuer_path ) {
			( new Logger() )->warning(
				'metadata.invalid_issuer',
				'Authorization server metadata was requested for an unknown issuer path.',
				array(
					'error_code'            => 'invalid_issuer',
					'requested_issuer_path' => $requested_issuer_path,
					'site_issuer_path'      => $site_issuer_path,
				),
				null,
				404
			);
			return new WP_REST_Response( array( 'error' => 'invalid_issuer' ), 404 );
		}

		$response = new WP_REST_Response(
			array(
				'issuer'                                => Helpers::authorization_server_issuer(),
				'authorization_endpoint'                => Helpers::authorization_endpoint(),
				'token_endpoint'                        => Helpers::token_endpoint(),
				'registration_endpoint'                 => Helpers::registration_endpoint(),
				'response_types_supported'              => array( 'code' ),
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'token_endpoint_auth_methods_supported' => TokenEndpointAuthMethod::supported(),
				'code_challenge_methods_supported'      => array( 'S256' ),
				'client_id_metadata_document_supported' => false,
				'authorization_response_iss_parameter_supported' => true,
				'scopes_supported'                      => Helpers::supported_scopes(),
				'resource_indicators_supported'         => true,
				'protected_resources'                   => array( Helpers::mcp_resource() ),
			),
			200
		);
		$response->header( 'Cache-Control', 'public, max-age=' . self::PUBLIC_METADATA_CACHE_MAX_AGE );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * Resolve a root-level well-known URL to the matching blog in one network.
	 *
	 * WordPress boots /.well-known/... on the main blog even when an RFC-derived
	 * metadata URL contains a subsite path. Only an exact active subsite's own
	 * resource or issuer is returned after switching its WordPress context.
	 *
	 * @param string $document Metadata document name.
	 * @param string $requested_path Path suffix from the well-known URL.
	 */
	public function well_known_metadata_response( string $document, string $requested_path = '' ): WP_REST_Response {
		if ( ! in_array( $document, array( Helpers::PROTECTED_RESOURCE_METADATA, Helpers::AUTHORIZATION_METADATA ), true ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_document' ), 404 );
		}

		$requested_path = untrailingslashit( $requested_path );
		$current_path   = Helpers::PROTECTED_RESOURCE_METADATA === $document
			? Helpers::resource_path()
			: untrailingslashit( (string) wp_parse_url( Helpers::issuer(), PHP_URL_PATH ) );
		if ( ! is_multisite() || '' === $requested_path || $requested_path === $current_path ) {
			return $this->metadata_for_current_site( $document, $requested_path );
		}

		$network = get_network();
		if ( ! $network instanceof \WP_Network || '' === $network->domain || 0 >= (int) $network->id ) {
			return $this->metadata_for_current_site( $document, $requested_path );
		}
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$request_host   = is_string( $_SERVER['HTTP_HOST'] ) ? (string) wp_parse_url( 'http://' . $_SERVER['HTTP_HOST'], PHP_URL_HOST ) : '';
			$canonical_host = (string) wp_parse_url( Helpers::issuer(), PHP_URL_HOST );
			if ( '' === $request_host || '' === $canonical_host || 0 !== strcasecmp( $request_host, $canonical_host ) ) {
				return $this->metadata_for_current_site( $document, $requested_path );
			}
		}
		$site = get_site_by_path( $network->domain, $requested_path );
		// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Both WordPress blog IDs are runtime values.
		if ( ! $site instanceof \WP_Site || (int) $site->site_id !== (int) $network->id || (int) $site->blog_id === get_current_blog_id() || 0 >= (int) $site->blog_id || 0 !== (int) $site->deleted || 0 !== (int) $site->archived || 0 !== (int) $site->spam ) {
			return $this->metadata_for_current_site( $document, $requested_path );
		}

		switch_to_blog( (int) $site->blog_id );
		try {
			$basename       = plugin_basename( ACULECT_AI_COMPANION_PLUGIN_FILE );
			$active_plugins = get_option( 'active_plugins', array() );
			$network_active = get_site_option( 'active_sitewide_plugins', array() );
			if ( ( ! is_array( $active_plugins ) || ! in_array( $basename, $active_plugins, true ) ) && ( ! is_array( $network_active ) || ! isset( $network_active[ $basename ] ) ) ) {
				return new WP_REST_Response( array( 'error' => Helpers::PROTECTED_RESOURCE_METADATA === $document ? 'invalid_target' : 'invalid_issuer' ), 404 );
			}

			return $this->metadata_for_current_site( $document, $requested_path );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Render one document for the currently selected WordPress blog.
	 *
	 * @param string $document Metadata document name.
	 * @param string $requested_path Path suffix from the well-known URL.
	 */
	private function metadata_for_current_site( string $document, string $requested_path ): WP_REST_Response {
		return Helpers::PROTECTED_RESOURCE_METADATA === $document
			? $this->protected_resource_metadata( $requested_path )
			: $this->authorization_server_metadata( $requested_path );
	}

	/**
	 * Render well-known metadata for non-REST root-level requests.
	 */
	public function render_well_known_metadata(): void {
		$document      = (string) get_query_var( 'aculect_ai_companion_well_known' );
		$resource_path = (string) get_query_var( 'aculect_ai_companion_well_known_resource_path' );
		$issuer_path   = (string) get_query_var( 'aculect_ai_companion_well_known_issuer_path' );

		if ( '' === $document ) {
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
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

		$response = $this->well_known_metadata_response(
			$document,
			Helpers::PROTECTED_RESOURCE_METADATA === $document ? $resource_path : $issuer_path
		);

		status_header( $response->get_status() );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Cache-Control: public, max-age=' . self::PUBLIC_METADATA_CACHE_MAX_AGE );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $response->get_data(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Return metadata diagnostics for the admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public function diagnostics(): array {
		return array(
			'mcp_url'                           => Helpers::mcp_resource(),
			'resource_metadata_url'             => Helpers::protected_resource_metadata_url(),
			'authorization_server_metadata_url' => Helpers::authorization_metadata_url(),
			'issuer'                            => Helpers::issuer(),
			'authorization_endpoint'            => Helpers::authorization_endpoint(),
			'token_endpoint'                    => Helpers::token_endpoint(),
			'registration_endpoint'             => Helpers::registration_endpoint(),
			'client_registration_profile'       => 'dynamic_client_registration',
			'client_id_metadata_documents'      => 'not_supported',
			'scopes'                            => implode( ' ', Helpers::supported_scopes() ),
			'registered_clients'                => ( new ClientRepository() )->list_clients(),
		);
	}
}
