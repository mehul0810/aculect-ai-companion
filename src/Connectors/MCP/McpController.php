<?php

declare(strict_types=1);

namespace Quark\Connectors\MCP;

use Quark\Connectors\Helpers;
use Quark\Connectors\OAuth\TokenValidator;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class McpController {

	public function register_routes(): void {
		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/mcp',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'describe' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/mcp',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_rpc' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function describe( WP_REST_Request $request ): WP_REST_Response|array {
		$auth = ( new TokenValidator() )->authenticate( $request );
		if ( array() === $auth ) {
			return $this->auth_challenge_response( null, 'content:read', 401, 'invalid_token' );
		}

		if ( str_contains( (string) $request->get_header( 'accept' ), 'text/event-stream' ) ) {
			$this->send_event_stream();
		}

		return array(
			'name'           => 'Quark MCP',
			'protocol'       => 'mcp',
			'version'        => QUARK_VERSION,
			'transport'      => 'streamable-http',
			'auth'           => 'oauth2.1',
			'authentication' => array(
				'type'                  => 'oauth2.1',
				'resource'              => Helpers::mcp_resource(),
				'resource_metadata_url' => Helpers::protected_resource_metadata_url(),
			),
			'endpoints'      => array(
				'http' => Helpers::mcp_resource(),
			),
		);
	}

	public function handle_rpc( WP_REST_Request $request ): WP_REST_Response|array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return $this->rpc_error( null, -32600, 'Invalid Request' );
		}

		$id = $body['id'] ?? null;
		if ( ! is_string( $id ) && ! is_int( $id ) ) {
			$id = null;
		}

		$method = (string) ( $body['method'] ?? '' );
		if ( ! array_key_exists( 'id', $body ) && str_starts_with( $method, 'notifications/' ) ) {
			return new WP_REST_Response( null, 202 );
		}

		switch ( $method ) {
			case 'initialize':
				return $this->rpc_result(
					$id,
					array(
						'protocolVersion' => '2025-06-18',
						'serverInfo'      => array(
							'name'    => 'Quark MCP',
							'version' => QUARK_VERSION,
						),
						'capabilities'    => array(
							'tools' => new \stdClass(),
						),
					)
				);

			case 'tools/list':
				return $this->rpc_result( $id, $this->list_tools() );

			case 'tools/call':
				$auth = ( new TokenValidator() )->authenticate( $request );
				if ( array() === $auth ) {
					return $this->auth_challenge_response( $id, 'content:read', 401, 'invalid_token' );
				}

				wp_set_current_user( (int) $auth['user_id'] );

				$tool = (string) ( $body['params']['name'] ?? '' );
				if ( ! $this->is_known_tool( $tool ) ) {
					return $this->tool_error_result( $id, 'Unknown tool.' );
				}

				$required = $this->required_scopes( $tool );
				if ( ! $this->has_scopes( (array) ( $auth['scopes'] ?? array() ), $required ) ) {
					return $this->auth_challenge_response( $id, implode( ' ', $required ), 403, 'insufficient_scope' );
				}

				$result = $this->call_tool( (array) ( $body['params'] ?? array() ) );
				return $this->rpc_result(
					$id,
					array(
						'content'           => array(
							array(
								'type' => 'text',
								'text' => (string) wp_json_encode( $result ),
							),
						),
						'structuredContent' => $result,
					)
				);
		}

		return $this->rpc_error( $id, -32601, 'Method not found' );
	}

	private function send_event_stream(): void {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Accel-Buffering: no' );
		echo ": quark-mcp-stream\n\n";
		flush();
		exit;
	}

	private function list_tools(): array {
		$read_security  = $this->security_schemes( array( 'content:read' ) );
		$draft_security = $this->security_schemes( array( 'content:draft' ) );

		return array(
			'tools' => array(
				array(
					'name'            => 'site.list_post_types',
					'title'           => 'List Post Types',
					'description'     => 'List readable post types.',
					'inputSchema'     => array(
						'type'       => 'object',
						'properties' => new \stdClass(),
					),
					'securitySchemes' => $read_security,
					'_meta'           => array( 'securitySchemes' => $read_security ),
					'annotations'     => array( 'readOnlyHint' => true ),
				),
				array(
					'name'            => 'content.list_items',
					'title'           => 'List Content Items',
					'description'     => 'List content items with pagination.',
					'inputSchema'     => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array( 'type' => 'string' ),
							'status'    => array(
								'oneOf' => array(
									array( 'type' => 'string' ),
									array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
								),
							),
							'page'      => array( 'type' => 'integer' ),
							'per_page'  => array( 'type' => 'integer' ),
						),
					),
					'securitySchemes' => $read_security,
					'_meta'           => array( 'securitySchemes' => $read_security ),
					'annotations'     => array( 'readOnlyHint' => true ),
				),
				array(
					'name'            => 'content.get_item',
					'title'           => 'Get Content Item',
					'description'     => 'Read one content item by ID.',
					'inputSchema'     => array(
						'type'       => 'object',
						'required'   => array( 'id' ),
						'properties' => array( 'id' => array( 'type' => 'integer' ) ),
					),
					'securitySchemes' => $read_security,
					'_meta'           => array( 'securitySchemes' => $read_security ),
					'annotations'     => array( 'readOnlyHint' => true ),
				),
				array(
					'name'            => 'content.create_draft',
					'title'           => 'Create Draft',
					'description'     => 'Create a draft content item.',
					'inputSchema'     => array(
						'type'       => 'object',
						'properties' => array(
							'post_type' => array( 'type' => 'string' ),
							'title'     => array( 'type' => 'string' ),
							'content'   => array( 'type' => 'string' ),
						),
					),
					'securitySchemes' => $draft_security,
					'_meta'           => array( 'securitySchemes' => $draft_security ),
					'annotations'     => array( 'readOnlyHint' => false ),
				),
				array(
					'name'            => 'taxonomy.list_taxonomies',
					'title'           => 'List Taxonomies',
					'description'     => 'List taxonomies.',
					'inputSchema'     => array(
						'type'       => 'object',
						'properties' => new \stdClass(),
					),
					'securitySchemes' => $read_security,
					'_meta'           => array( 'securitySchemes' => $read_security ),
					'annotations'     => array( 'readOnlyHint' => true ),
				),
				array(
					'name'            => 'taxonomy.list_terms',
					'title'           => 'List Terms',
					'description'     => 'List terms in a taxonomy.',
					'inputSchema'     => array(
						'type'       => 'object',
						'properties' => array( 'taxonomy' => array( 'type' => 'string' ) ),
					),
					'securitySchemes' => $read_security,
					'_meta'           => array( 'securitySchemes' => $read_security ),
					'annotations'     => array( 'readOnlyHint' => true ),
				),
				array(
					'name'            => 'media.list_items',
					'title'           => 'List Media Items',
					'description'     => 'List media items.',
					'inputSchema'     => array(
						'type'       => 'object',
						'properties' => array(
							'page'     => array( 'type' => 'integer' ),
							'per_page' => array( 'type' => 'integer' ),
						),
					),
					'securitySchemes' => $read_security,
					'_meta'           => array( 'securitySchemes' => $read_security ),
					'annotations'     => array( 'readOnlyHint' => true ),
				),
				array(
					'name'            => 'site.get_settings',
					'title'           => 'Get Site Settings',
					'description'     => 'Read safe site settings.',
					'inputSchema'     => array(
						'type'       => 'object',
						'properties' => new \stdClass(),
					),
					'securitySchemes' => $read_security,
					'_meta'           => array( 'securitySchemes' => $read_security ),
					'annotations'     => array( 'readOnlyHint' => true ),
				),
			),
		);
	}

	private function call_tool( array $params ): array {
		$tool    = (string) ( $params['name'] ?? '' );
		$args    = (array) ( $params['arguments'] ?? array() );
		$content = new ContentController();

		return match ( $tool ) {
			'site.list_post_types' => $content->list_post_types(),
			'content.list_items' => $content->list_items( $args ),
			'content.get_item' => $content->get_item( $args ),
			'content.create_draft' => $content->create_draft( $args ),
			'taxonomy.list_taxonomies' => $content->list_taxonomies(),
			'taxonomy.list_terms' => $content->list_terms( $args ),
			'media.list_items' => $content->list_media( $args ),
			'site.get_settings' => $content->get_settings(),
			default => array( 'error' => 'Unknown tool' ),
		};
	}

	private function is_known_tool( string $tool ): bool {
		return in_array(
			$tool,
			array(
				'site.list_post_types',
				'content.list_items',
				'content.get_item',
				'content.create_draft',
				'taxonomy.list_taxonomies',
				'taxonomy.list_terms',
				'media.list_items',
				'site.get_settings',
			),
			true
		);
	}

	private function required_scopes( string $tool ): array {
		return 'content.create_draft' === $tool ? array( 'content:draft' ) : array( 'content:read' );
	}

	private function has_scopes( array $token_scopes, array $required ): bool {
		$token_scopes = array_map( 'strval', $token_scopes );
		foreach ( $required as $scope ) {
			if ( ! in_array( $scope, $token_scopes, true ) ) {
				return false;
			}
		}

		return true;
	}

	private function security_schemes( array $scopes ): array {
		return array(
			array(
				'type'   => 'oauth2',
				'scopes' => $scopes,
			),
		);
	}

	private function auth_challenge_response( string|int|null $id, string $scope, int $status, string $error ): WP_REST_Response {
		$response = new WP_REST_Response(
			$this->rpc_result(
				$id,
				array(
					'content'           => array(
						array(
							'type' => 'text',
							'text' => 'Authorization required.',
						),
					),
					'structuredContent' => new \stdClass(),
					'_meta'             => array(
						'mcp/www_authenticate' => array( TokenValidator::www_authenticate_header( $scope, $error ) ),
					),
					'isError'           => true,
				)
			),
			$status
		);
		$response->header( 'WWW-Authenticate', TokenValidator::www_authenticate_header( $scope, $error ) );

		return $response;
	}

	private function tool_error_result( string|int|null $id, string $message ): array {
		return $this->rpc_result(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => $message,
					),
				),
				'structuredContent' => new \stdClass(),
				'isError'           => true,
			)
		);
	}

	private function rpc_result( string|int|null $id, array $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private function rpc_error( string|int|null $id, int $code, string $message, array $data = array() ): array {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( array() !== $data ) {
			$error['data'] = $data;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}
}
