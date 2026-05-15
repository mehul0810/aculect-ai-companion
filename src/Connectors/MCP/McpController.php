<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\TokenValidator;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles the streamable HTTP MCP endpoint.
 */
final class McpController {

	/**
	 * Register the public MCP endpoint.
	 */
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

	/**
	 * Describe the authenticated MCP endpoint.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|array<string, mixed>
	 */
	public function describe( WP_REST_Request $request ): WP_REST_Response|array {
		$auth = ( new TokenValidator() )->authenticate( $request );
		if ( array() === $auth ) {
			return $this->auth_challenge_response( null, 'content:read', 401, 'invalid_token' );
		}

		if ( str_contains( (string) $request->get_header( 'accept' ), 'text/event-stream' ) ) {
			$this->send_event_stream();
		}

		return array(
			'name'           => 'Aculect AI Companion MCP',
			'protocol'       => 'mcp',
			'version'        => ACULECT_AI_COMPANION_VERSION,
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

	/**
	 * Handle JSON-RPC messages sent to the MCP endpoint.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|array<string, mixed>
	 */
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

		$auth = ( new TokenValidator() )->authenticate( $request );
		if ( array() === $auth ) {
			return $this->auth_challenge_response( $id, 'content:read', 401, 'invalid_token' );
		}

		wp_set_current_user( (int) $auth['user_id'] );

		switch ( $method ) {
			case 'initialize':
				return $this->rpc_result(
					$id,
					array(
						'protocolVersion' => '2025-06-18',
						'serverInfo'      => array(
							'name'    => 'Aculect AI Companion MCP',
							'version' => ACULECT_AI_COMPANION_VERSION,
						),
						'capabilities'    => array(
							'tools' => new \stdClass(),
						),
					)
				);

			case 'tools/list':
				return $this->rpc_result( $id, $this->list_tools() );

			case 'tools/call':
				$registry = new AbilitiesRegistry();
				$tool     = $registry->internal_id( (string) ( $body['params']['name'] ?? '' ) );
				if ( ! $registry->is_known( $tool ) ) {
					return $this->tool_error_result( $id, 'Unknown tool.' );
				}

				if ( ! $registry->is_enabled( $tool ) ) {
					return $this->tool_error_result( $id, 'This ability is disabled in Aculect AI Companion settings.' );
				}

				$required = $registry->required_scopes( $tool );
				if ( ! $this->has_scopes( (array) ( $auth['scopes'] ?? array() ), $required ) ) {
					return $this->auth_challenge_response( $id, implode( ' ', $required ), 403, 'insufficient_scope' );
				}

				$result = $this->call_tool( $tool, (array) ( $body['params']['arguments'] ?? array() ) );
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

	/**
	 * Return a minimal server-sent event stream for clients probing SSE support.
	 */
	private function send_event_stream(): void {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Accel-Buffering: no' );
		echo ": aculect-ai-companion-mcp-stream\n\n";
		flush();
		exit;
	}

	/**
	 * Build the MCP tools/list payload from enabled Aculect AI Companion abilities.
	 *
	 * @return array{tools: list<array<string, mixed>>}
	 */
	private function list_tools(): array {
		$registry = new AbilitiesRegistry();

		return array(
			'tools' => array_values( array_map( array( $this, 'tool_from_definition' ), $registry->enabled_definitions() ) ),
		);
	}

	/**
	 * Convert an internal ability definition into an MCP tool descriptor.
	 *
	 * The `name` field uses the public, client-safe identifier while schemas and
	 * dispatch continue to use Aculect AI Companion's internal dotted ability IDs.
	 *
	 * @param array<string, bool|string> $definition Ability definition.
	 * @return array<string, mixed>
	 */
	private function tool_from_definition( array $definition ): array {
		$registry    = new AbilitiesRegistry();
		$internal_id = (string) $definition['id'];
		$scopes      = array( (string) $definition['scope'] );
		$security    = $this->security_schemes( $scopes );

		return array(
			'name'            => $registry->tool_name( $internal_id ),
			'title'           => (string) $definition['title'],
			'description'     => (string) $definition['description'],
			'inputSchema'     => $this->input_schema_for_tool( $internal_id ),
			'securitySchemes' => $security,
			'_meta'           => array( 'securitySchemes' => $security ),
			'annotations'     => array( 'readOnlyHint' => (bool) $definition['readOnly'] ),
		);
	}

	/**
	 * Return the input schema for a tool.
	 *
	 * @param string $tool Internal ID, legacy alias, or public tool name.
	 * @return array<string, mixed>
	 */
	private function input_schema_for_tool( string $tool ): array {
		$tool = ( new AbilitiesRegistry() )->internal_id( $tool );

		return match ( $tool ) {
			'content.list_items' => array(
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
			'content.get_item' => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
			),
			'content.create_item' => array(
				'type'       => 'object',
				'properties' => array(
					'post_type' => array( 'type' => 'string' ),
					'title'     => array( 'type' => 'string' ),
					'content'   => array( 'type' => 'string' ),
					'excerpt'   => array( 'type' => 'string' ),
					'slug'      => array( 'type' => 'string' ),
					'status'    => array( 'type' => 'string' ),
				),
			),
			'content.update_item' => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'      => array( 'type' => 'integer' ),
					'title'   => array( 'type' => 'string' ),
					'content' => array( 'type' => 'string' ),
					'excerpt' => array( 'type' => 'string' ),
					'slug'    => array( 'type' => 'string' ),
					'status'  => array( 'type' => 'string' ),
				),
			),
			'taxonomy.list_terms' => array(
				'type'       => 'object',
				'required'   => array( 'taxonomy' ),
				'properties' => array(
					'taxonomy'   => array( 'type' => 'string' ),
					'page'       => array( 'type' => 'integer' ),
					'per_page'   => array( 'type' => 'integer' ),
					'search'     => array( 'type' => 'string' ),
					'hide_empty' => array( 'type' => 'boolean' ),
				),
			),
			'taxonomy.create_term' => array(
				'type'       => 'object',
				'required'   => array( 'taxonomy', 'name' ),
				'properties' => array(
					'taxonomy'    => array( 'type' => 'string' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'parent'      => array( 'type' => 'integer' ),
				),
			),
			'taxonomy.update_term' => array(
				'type'       => 'object',
				'required'   => array( 'taxonomy', 'term_id' ),
				'properties' => array(
					'taxonomy'    => array( 'type' => 'string' ),
					'term_id'     => array( 'type' => 'integer' ),
					'name'        => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'parent'      => array( 'type' => 'integer' ),
				),
			),
			'media.list_items' => array(
				'type'       => 'object',
				'properties' => array(
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			),
			'media.upload_item' => array(
				'type'       => 'object',
				'required'   => array( 'url' ),
				'properties' => array(
					'url'         => array(
						'type'        => 'string',
						'description' => 'Public HTTP or HTTPS media URL to upload.',
					),
					'title'       => array( 'type' => 'string' ),
					'alt_text'    => array( 'type' => 'string' ),
					'caption'     => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'post_id'     => array( 'type' => 'integer' ),
				),
			),
			'comments.list_items' => array(
				'type'       => 'object',
				'properties' => array(
					'status'   => array(
						'type'        => 'string',
						'description' => 'Comment status: all, hold, approve, spam, or trash.',
					),
					'post_id'  => array( 'type' => 'integer' ),
					'search'   => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			),
			'comments.get_item' => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
			),
			'comments.create_item' => array(
				'type'       => 'object',
				'required'   => array( 'post_id', 'content' ),
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'content' => array( 'type' => 'string' ),
					'status'  => array(
						'type'        => 'string',
						'description' => 'Optional status for moderators: hold or approve.',
					),
				),
			),
			'comments.update_item' => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'      => array( 'type' => 'integer' ),
					'content' => array( 'type' => 'string' ),
					'status'  => array(
						'type'        => 'string',
						'description' => 'Comment status: hold, approve, spam, or trash.',
					),
				),
			),
			'wp_abilities.discover' => array(
				'type'       => 'object',
				'properties' => array(
					'search'   => array( 'type' => 'string' ),
					'category' => array( 'type' => 'string' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
				),
			),
			'wp_abilities.get_info' => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array( 'id' => array( 'type' => 'string' ) ),
			),
			'wp_abilities.run' => array(
				'type'       => 'object',
				'required'   => array( 'id' ),
				'properties' => array(
					'id'        => array( 'type' => 'string' ),
					'arguments' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
			),
			default => array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			),
		};
	}

	/**
	 * Dispatch an MCP tool call to the content controller.
	 *
	 * @param string       $tool Internal ability ID.
	 * @param array<mixed> $args Tool arguments.
	 * @return array<mixed>
	 */
	private function call_tool( string $tool, array $args ): array {
		$content = new ContentController();

		return match ( $tool ) {
			'site.list_post_types' => $content->list_post_types(),
			'content.list_items' => $content->list_items( $args ),
			'content.get_item' => $content->get_item( $args ),
			'content.create_item' => $content->create_item( $args ),
			'content.create_draft' => $content->create_draft( $args ),
			'content.update_item' => $content->update_item( $args ),
			'taxonomy.list_taxonomies' => $content->list_taxonomies(),
			'taxonomy.list_terms' => $content->list_terms( $args ),
			'taxonomy.create_term' => $content->create_term( $args ),
			'taxonomy.update_term' => $content->update_term( $args ),
			'media.list_items' => $content->list_media( $args ),
			'media.upload_item' => $content->upload_media( $args ),
			'comments.list_items' => $content->list_comments( $args ),
			'comments.get_item' => $content->get_comment( $args ),
			'comments.create_item' => $content->create_comment( $args ),
			'comments.update_item' => $content->update_comment( $args ),
			'site.get_settings' => $content->get_settings(),
			'site.get_info' => $content->get_site_info(),
			'site.list_plugins' => $content->list_plugins(),
			'site.list_themes' => $content->list_themes(),
			'wp_abilities.discover' => $content->discover_wp_abilities( $args ),
			'wp_abilities.get_info' => $content->get_wp_ability_info( $args ),
			'wp_abilities.run' => $content->run_wp_ability( $args ),
			default => array( 'error' => 'Unknown tool' ),
		};
	}

	/**
	 * Check whether a token includes every required scope.
	 *
	 * @param string[] $token_scopes Granted token scopes.
	 * @param string[] $required     Required scopes.
	 * @return bool
	 */
	private function has_scopes( array $token_scopes, array $required ): bool {
		$token_scopes = array_map( 'strval', $token_scopes );
		foreach ( $required as $scope ) {
			if ( ! in_array( $scope, $token_scopes, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build OAuth security scheme metadata for an MCP tool.
	 *
	 * @param string[] $scopes Required scopes.
	 * @return array<int, array<string, mixed>>
	 */
	private function security_schemes( array $scopes ): array {
		return array(
			array(
				'type'   => 'oauth2',
				'scopes' => $scopes,
			),
		);
	}

	/**
	 * Return a JSON-RPC authorization challenge response.
	 *
	 * @param string|int|null $id     JSON-RPC request ID.
	 * @param string          $scope  Required scope.
	 * @param int             $status HTTP status.
	 * @param string          $error  OAuth error code.
	 * @return WP_REST_Response
	 */
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

	/**
	 * Return a JSON-RPC tool error result.
	 *
	 * @param string|int|null $id      JSON-RPC request ID.
	 * @param string          $message Error message.
	 * @return array<string, mixed>
	 */
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

	/**
	 * Wrap a JSON-RPC result.
	 *
	 * @param string|int|null     $id     JSON-RPC request ID.
	 * @param array<string,mixed> $result Result payload.
	 * @return array<string, mixed>
	 */
	private function rpc_result( string|int|null $id, array $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Build a JSON-RPC error response.
	 *
	 * @param string|int|null     $id      JSON-RPC request ID.
	 * @param int                 $code    JSON-RPC error code.
	 * @param string              $message Error message.
	 * @param array<string,mixed> $data    Optional error data.
	 * @return array<string, mixed>
	 */
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
