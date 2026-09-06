<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Activity\ActivityLogger;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\TokenValidator;
use Aculect\AICompanion\Diagnostics\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles the streamable HTTP MCP endpoint.
 */
final class McpController {

	public const PROTOCOL_VERSION_CURRENT    = McpProtocolVersion::CURRENT;
	public const PROTOCOL_VERSION_LEGACY     = McpProtocolVersion::LEGACY;
	public const SUPPORTED_PROTOCOL_VERSIONS = array(
		self::PROTOCOL_VERSION_CURRENT,
		self::PROTOCOL_VERSION_LEGACY,
	);

	/**
	 * OAuth context resolved by the permission callback for the current request.
	 *
	 * @var array<string, mixed>
	 */
	private array $request_auth = array();

	private string $request_protocol_version = self::PROTOCOL_VERSION_LEGACY;
	private AbilityExecutionGateway $execution_gateway;

	/**
	 * Construct the transport controller.
	 *
	 * @param AbilityExecutionGateway|null $execution_gateway Execution policy boundary.
	 */
	public function __construct( ?AbilityExecutionGateway $execution_gateway = null ) {
		$this->execution_gateway = $execution_gateway ?? new AbilityExecutionGateway();
	}

	/**
	 * Register the OAuth-protected MCP endpoint.
	 */
	public function register_routes(): void {
		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/mcp',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'describe' ),
				'permission_callback' => array( $this, 'check_mcp_permission' ),
			)
		);

		register_rest_route(
			Helpers::REST_NAMESPACE,
			'/mcp',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_rpc' ),
				'permission_callback' => array( $this, 'check_mcp_permission' ),
			)
		);

		add_filter( 'rest_post_dispatch', array( $this, 'filter_mcp_auth_response' ), 10, 3 );
	}

	/**
	 * Authenticate MCP requests with the OAuth resource server.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|\WP_Error
	 */
	public function check_mcp_permission( WP_REST_Request $request ): bool|\WP_Error {
		$this->request_auth = array();
		$this->reset_request_protocol_version( $request );
		McpToolAvailability::set_current_granted_scopes( null );

		$request_error = ( new McpInputValidator() )->request_error( $request );
		if ( null !== $request_error ) {
			return new \WP_Error( $request_error['code'], $request_error['message'], array( 'status' => 413 ) );
		}

		$transport_error = $this->transport_error( $request );
		if ( null !== $transport_error ) {
			return new \WP_Error( $transport_error['code'], $transport_error['message'], array( 'status' => $transport_error['status'] ) );
		}

		$auth = ( new TokenValidator() )->authenticate( $request );
		if ( array() === $auth ) {
			( new Logger() )->warning(
				'mcp.invalid_token',
				'MCP request did not include a valid bearer token.',
				$this->log_context( $this->rpc_method_from_request( $request ), '', 'invalid_token' ),
				$request,
				401
			);

			return new \WP_Error( 'rest_unauthorized', 'Authorization required.', array( 'status' => 401 ) );
		}

		$this->request_auth = $auth;
		McpToolAvailability::set_current_granted_scopes( (array) ( $auth['scopes'] ?? array() ) );

		return true;
	}

	/**
	 * Reshape MCP permission failures into the OAuth challenge MCP clients expect.
	 *
	 * Claude, ChatGPT, and Codex connectors all rely on the WWW-Authenticate
	 * header with resource metadata to start the OAuth discovery flow, and on a
	 * JSON-RPC shaped body instead of the default WP_Error envelope.
	 *
	 * @param mixed           $response Dispatch result.
	 * @param mixed           $server   REST server.
	 * @param WP_REST_Request $request  REST request.
	 * @return mixed
	 */
	public function filter_mcp_auth_response( mixed $response, mixed $server, WP_REST_Request $request ): mixed {
		unset( $server );

		if ( '/' . Helpers::REST_NAMESPACE . '/mcp' !== $request->get_route() ) {
			return $response;
		}

		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'MCP-Protocol-Version', $this->request_protocol_version );
		}

		$data = $response instanceof WP_REST_Response ? $response->get_data() : null;
		if ( $response instanceof WP_REST_Response && is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) && $this->is_transport_error_code( $data['code'] ) ) {
			$transport_response = new WP_REST_Response(
				$this->rpc_error(
					$this->rpc_id_from_request( $request ),
					$this->transport_rpc_code( $data['code'] ),
					(string) ( $data['message'] ?? 'Invalid MCP transport request.' ),
					$this->transport_rpc_data( $data['code'], $request )
				),
				$response->get_status()
			);
			$transport_response->header( 'MCP-Protocol-Version', $this->request_protocol_version );
			return $transport_response;
		}

		if ( ! $response instanceof WP_REST_Response || 401 !== $response->get_status() ) {
			return $response;
		}

		$data = $response->get_data();
		if ( is_array( $data ) && array_key_exists( 'jsonrpc', $data ) ) {
			return $response;
		}

		return $this->auth_challenge_response( $this->rpc_id_from_request( $request ), $this->initial_auth_scope(), 401, 'invalid_token' );
	}

	/**
	 * Validate the request origin and protocol-specific HTTP contract.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array{code: string, message: string, status: int}|null
	 */
	private function transport_error( WP_REST_Request $request ): ?array {
		$this->reset_request_protocol_version( $request );

		$origin = trim( (string) $request->get_header( 'origin' ) );
		if ( '' !== $origin && ! $this->is_allowed_origin( $origin ) ) {
			return array(
				'code'    => 'invalid_mcp_origin',
				'message' => 'The request Origin is not allowed for this MCP endpoint.',
				'status'  => 403,
			);
		}

		if ( 'POST' !== $request->get_method() ) {
			return null;
		}

		$body           = (array) $request->get_json_params();
		$method         = (string) ( $body['method'] ?? '' );
		$version_header = (string) $request->get_header( 'mcp-protocol-version' );
		if ( '' === $version_header ) {
			if ( 'server/discover' === $method ) {
				return array(
					'code'    => 'missing_protocol_version',
					'message' => 'MCP-Protocol-Version is required for server/discover.',
					'status'  => 400,
				);
			}

			$this->request_protocol_version = self::PROTOCOL_VERSION_LEGACY;
			return null;
		}

		$version = $this->decoded_mcp_header( $version_header );
		if ( null === $version ) {
			return array(
				'code'    => 'invalid_mcp_protocol_version_header',
				'message' => 'MCP-Protocol-Version must be a valid exact mirrored header value.',
				'status'  => 400,
			);
		}

		if ( ! in_array( $version, self::SUPPORTED_PROTOCOL_VERSIONS, true ) ) {
			return array(
				'code'    => 'unsupported_protocol_version',
				'message' => 'The requested MCP protocol version is not supported.',
				'status'  => 400,
			);
		}

		$this->request_protocol_version = $version;
		if ( self::PROTOCOL_VERSION_CURRENT !== $version ) {
			return null;
		}

		$header_method = $this->decoded_mcp_header( (string) $request->get_header( 'mcp-method' ) );
		if ( null === $header_method || '' === $header_method || ! hash_equals( $method, $header_method ) ) {
			return array(
				'code'    => 'invalid_mcp_method_header',
				'message' => 'Mcp-Method must exactly match the JSON-RPC method.',
				'status'  => 400,
			);
		}

		$params       = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();
		$meta         = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();
		$capabilities = $meta['io.modelcontextprotocol/clientCapabilities'] ?? null;
		$client_info  = $meta['io.modelcontextprotocol/clientInfo'] ?? null;
		if ( ! isset( $meta['io.modelcontextprotocol/protocolVersion'] )
			|| ! is_string( $meta['io.modelcontextprotocol/protocolVersion'] )
			|| ! hash_equals( $version, $meta['io.modelcontextprotocol/protocolVersion'] )
			|| ! is_array( $capabilities )
			|| ( array() !== $capabilities && array_is_list( $capabilities ) )
			|| ( null !== $client_info && ( ! is_array( $client_info )
				|| ! isset( $client_info['name'], $client_info['version'] )
				|| ! is_string( $client_info['name'] )
				|| '' === $client_info['name']
				|| ! is_string( $client_info['version'] )
				|| '' === $client_info['version'] ) ) ) {
			return array(
				'code'    => 'invalid_mcp_request_metadata',
				'message' => 'Request metadata must include the matching protocol version and client capabilities.',
				'status'  => 400,
			);
		}

		if ( in_array( $method, array( 'tools/call', 'resources/read', 'prompts/get' ), true ) ) {
			$header_name = (string) $request->get_header( 'mcp-name' );
			$body_name   = 'resources/read' === $method
				? ( isset( $params['uri'] ) && is_string( $params['uri'] ) ? $params['uri'] : '' )
				: ( isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '' );
			$decoded     = $this->decoded_mcp_header( $header_name );
			if ( null === $decoded || '' === $decoded || ! hash_equals( $body_name, $decoded ) ) {
				return array(
					'code'    => 'invalid_mcp_name_header',
					'message' => 'Mcp-Name must exactly match the requested tool, prompt, or resource name.',
					'status'  => 400,
				);
			}
		}

		return null;
	}

	/**
	 * Decode a mirrored MCP header value, including the Base64 sentinel format.
	 *
	 * @param string $value Header value.
	 */
	private function decoded_mcp_header( string $value ): ?string {
		if ( '' === $value || 4096 < strlen( $value ) ) {
			return null;
		}

		if ( str_starts_with( $value, '=?base64?' ) && str_ends_with( $value, '?=' ) ) {
			$encoded = substr( $value, 9, -2 );
			if ( '' === $encoded || 4096 < strlen( $encoded ) || 1 !== preg_match( '/^[A-Za-z0-9+\/]+={0,2}$/D', $encoded ) ) {
				return null;
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Required by the MCP mirrored-header sentinel contract.
			$decoded = base64_decode( $encoded, true );
			if ( false === $decoded || '' === $decoded || 2048 < strlen( $decoded ) || 1 !== preg_match( '//u', $decoded ) ) {
				return null;
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required to reject non-canonical mirrored-header encodings.
			return hash_equals( $encoded, base64_encode( $decoded ) ) ? $decoded : null;
		}

		if ( trim( $value ) !== $value || 1 !== preg_match( '/^[\x20-\x7E]+$/D', $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * Check whether a WordPress REST error came from the MCP transport boundary.
	 *
	 * @param string $code Error code.
	 */
	private function is_transport_error_code( string $code ): bool {
		return in_array(
			$code,
			array(
				'invalid_mcp_origin',
				'missing_protocol_version',
				'invalid_mcp_protocol_version_header',
				'unsupported_protocol_version',
				'invalid_mcp_method_header',
				'invalid_mcp_request_metadata',
				'invalid_mcp_name_header',
			),
			true
		);
	}

	/**
	 * Return the protocol-defined JSON-RPC code for a transport failure.
	 *
	 * @param string $code Error code.
	 */
	private function transport_rpc_code( string $code ): int {
		if ( 'unsupported_protocol_version' === $code ) {
			return -32022;
		}

		return 'invalid_mcp_origin' === $code ? -32600 : -32020;
	}

	/**
	 * Build bounded public data for a transport error.
	 *
	 * @param string          $code    Error code.
	 * @param WP_REST_Request $request REST request.
	 * @return array<string, mixed>
	 */
	private function transport_rpc_data( string $code, WP_REST_Request $request ): array {
		$data = array( 'code' => $code );
		if ( 'unsupported_protocol_version' === $code ) {
			$requested         = $this->decoded_mcp_header( (string) $request->get_header( 'mcp-protocol-version' ) );
			$data['requested'] = null === $requested || 64 < strlen( $requested ) ? '' : $requested;
			$data['supported'] = self::SUPPORTED_PROTOCOL_VERSIONS;
		}

		return $data;
	}

	/**
	 * Reset per-request protocol state and retain only a valid supported header.
	 *
	 * This runs before body-bound checks so an early response cannot inherit a
	 * previous request's protocol or mislabel a current GET/413 response.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function reset_request_protocol_version( WP_REST_Request $request ): void {
		$this->request_protocol_version = self::PROTOCOL_VERSION_LEGACY;
		$header                         = (string) $request->get_header( 'mcp-protocol-version' );
		$version                        = $this->decoded_mcp_header( $header );
		if ( null !== $version && in_array( $version, self::SUPPORTED_PROTOCOL_VERSIONS, true ) ) {
			$this->request_protocol_version = $version;
		}
	}

	/**
	 * Validate a browser Origin using exact scheme, host, and effective port matching.
	 *
	 * @param string $origin Request Origin header.
	 */
	private function is_allowed_origin( string $origin ): bool {
		$normalized = $this->normalized_origin( $origin );
		if ( '' === $normalized ) {
			return false;
		}

		$allowed = array( Helpers::origin_from_url( Helpers::mcp_resource() ) );
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$filtered = apply_filters( 'aculect-ai-companion/connectors/allowed_mcp_origins', $allowed );
		if ( ! is_array( $filtered ) ) {
			$filtered = $allowed;
		}

		foreach ( $filtered as $candidate ) {
			if ( is_string( $candidate ) && hash_equals( $normalized, $this->normalized_origin( $candidate ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize an absolute origin without accepting paths, credentials, or wildcards.
	 *
	 * @param string $origin Candidate origin.
	 */
	private function normalized_origin( string $origin ): string {
		if ( 'null' === strtolower( $origin ) || str_contains( $origin, '*' ) ) {
			return '';
		}

		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host || ! in_array( $path, array( '', '/' ), true ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . $host . ':' . $port;
	}

	/**
	 * Read the JSON-RPC method for diagnostics without trusting the payload.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function rpc_method_from_request( WP_REST_Request $request ): string {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return '';
		}

		return (string) ( $body['method'] ?? '' );
	}

	/**
	 * Read the JSON-RPC request ID for challenge responses.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function rpc_id_from_request( WP_REST_Request $request ): string|int|null {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return null;
		}

		$id = $body['id'] ?? null;

		return is_string( $id ) || is_int( $id ) ? $id : null;
	}

	/**
	 * Describe the authenticated MCP endpoint.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|array<string, mixed>
	 */
	public function describe( WP_REST_Request $request ): WP_REST_Response|array {
		unset( $request );

		if ( array() === $this->request_auth ) {
			return $this->auth_challenge_response( null, $this->initial_auth_scope(), 401, 'invalid_token' );
		}

		$response = new WP_REST_Response(
			array(
				'code'    => 'mcp_get_not_supported',
				'message' => 'This stateless MCP endpoint accepts POST requests only.',
			),
			405
		);
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	/**
	 * Handle JSON-RPC messages sent to the MCP endpoint.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|array<string, mixed>
	 */
	public function handle_rpc( WP_REST_Request $request ): WP_REST_Response|array {
		$this->reset_request_protocol_version( $request );

		$request_error = ( new McpInputValidator() )->request_error( $request );
		if ( null !== $request_error ) {
			return new WP_REST_Response(
				$this->rpc_error(
					null,
					-32600,
					'Invalid Request',
					array(
						'code'    => $request_error['code'],
						'message' => $request_error['message'],
					)
				),
				413
			);
		}

		$transport_error = $this->transport_error( $request );
		if ( null !== $transport_error ) {
			return new WP_REST_Response(
				$this->rpc_error(
					$this->rpc_id_from_request( $request ),
					$this->transport_rpc_code( $transport_error['code'] ),
					'Invalid Request',
					array_merge(
						$this->transport_rpc_data( $transport_error['code'], $request ),
						array( 'message' => $transport_error['message'] )
					)
				),
				$transport_error['status']
			);
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			( new Logger() )->warning(
				'mcp.invalid_request',
				'MCP request body was not a valid JSON-RPC object.',
				$this->log_context( '', '', 'invalid_request' ),
				$request,
				200
			);
			return $this->rpc_error( null, -32600, 'Invalid Request' );
		}

		$id = $body['id'] ?? null;
		if ( ! is_string( $id ) && ! is_int( $id ) ) {
			$id = null;
		}

		$method = (string) ( $body['method'] ?? '' );
		if ( self::PROTOCOL_VERSION_CURRENT === $this->request_protocol_version && 'notifications/initialized' === $method ) {
			return new WP_REST_Response( $this->rpc_error( $id, -32601, 'Method not found' ), 404 );
		}

		if ( ! array_key_exists( 'id', $body ) && str_starts_with( $method, 'notifications/' ) ) {
			return new WP_REST_Response( null, 202 );
		}

		$auth = $this->request_auth;
		if ( array() === $auth ) {
			return $this->auth_challenge_response( $id, $this->initial_auth_scope(), 401, 'invalid_token' );
		}

		wp_set_current_user( (int) $auth['user_id'] );

		switch ( $method ) {
			case 'initialize':
				if ( self::PROTOCOL_VERSION_CURRENT === $this->request_protocol_version ) {
					return new WP_REST_Response( $this->rpc_error( $id, -32601, 'Method not found' ), 404 );
				}

				$requested_version = isset( $body['params']['protocolVersion'] ) && is_string( $body['params']['protocolVersion'] )
					? $body['params']['protocolVersion']
					: self::PROTOCOL_VERSION_LEGACY;
				if ( self::PROTOCOL_VERSION_LEGACY !== $requested_version ) {
					return new WP_REST_Response(
						$this->rpc_error(
							$id,
							-32022,
							'Unsupported protocol version',
							array(
								'code'      => 'unsupported_protocol_version',
								'requested' => $requested_version,
								'supported' => array( self::PROTOCOL_VERSION_LEGACY ),
							)
						),
						400
					);
				}
				$started_at = microtime( true );
				$result     = $this->initialize_payload( self::PROTOCOL_VERSION_LEGACY );
				$this->record_timeline_event(
					'initialize',
					array(
						'method'      => 'initialize',
						'status'      => 'success',
						'duration_ms' => $this->duration_ms( $started_at ),
					),
					$auth
				);
				return $this->rpc_result( $id, 'initialize', $result );

			case 'server/discover':
				return $this->rpc_result( $id, 'server/discover', $this->discover_payload(), true );

			case 'tools/list':
				$started_at = microtime( true );
				$cursor     = isset( $body['params']['cursor'] ) && is_string( $body['params']['cursor'] ) ? $body['params']['cursor'] : '';
				try {
					$result = $this->list_tools( $cursor );
				} catch ( \UnexpectedValueException ) {
					( new Logger() )->error(
						'mcp.invalid_tool_schema',
						'An enabled MCP tool has an invalid current-protocol schema.',
						$this->log_context( $method, (string) ( $auth['provider'] ?? '' ), 'invalid_tool_schema' ),
						$request,
						200
					);

					return $this->rpc_error( $id, -32603, 'Internal error', array( 'code' => 'invalid_tool_schema' ) );
				}
				$this->record_timeline_event(
					'tools_list',
					array(
						'method'         => 'tools/list',
						'status'         => 'success',
						'duration_ms'    => $this->duration_ms( $started_at ),
						'target_summary' => 'tools:' . count( $result['tools'] ),
					),
					$auth
				);
				return $this->rpc_result( $id, 'tools/list', $result );

			case 'resources/list':
				return $this->rpc_result( $id, 'resources/list', ( new McpResourceRegistry() )->list_resources(), true );

			case 'resources/read':
				$resource_result = ( new McpResourceRegistry() )->read_resource( (array) ( $body['params'] ?? array() ) );
				if ( self::PROTOCOL_VERSION_CURRENT === $this->request_protocol_version
					&& isset( $resource_result['error'] )
					&& in_array( $resource_result['error'], array( 'resource_not_found', 'invalid_resource_uri' ), true ) ) {
					return $this->rpc_error(
						$id,
						-32602,
						'Invalid params',
						array( 'code' => (string) $resource_result['error'] )
					);
				}

				return $this->rpc_result( $id, 'resources/read', $resource_result );

			case 'tools/call':
				$params  = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();
				$outcome = $this->execution_gateway->execute( new AbilityExecutionRequest( $params, $auth, $request ) );

				return $this->adapt_tool_execution_outcome( $id, $outcome );

		}

		( new Logger() )->warning(
			'mcp.method_not_found',
			'MCP request used an unsupported JSON-RPC method.',
			$this->log_context( $method, (string) ( $auth['provider'] ?? '' ), 'method_not_found' ),
			$request,
			200
		);
		$error = $this->rpc_error( $id, -32601, 'Method not found' );
		return self::PROTOCOL_VERSION_CURRENT === $this->request_protocol_version
			? new WP_REST_Response( $error, 404 )
			: $error;
	}

	/**
	 * Build sanitized diagnostic context for MCP events.
	 *
	 * @param string   $method          JSON-RPC method.
	 * @param string   $provider        Provider slug.
	 * @param string   $error_code      Optional error code.
	 * @param string   $tool            Optional internal tool ID.
	 * @param string[] $required_scopes Optional required scopes.
	 * @return array<string, mixed>
	 */
	private function log_context( string $method, string $provider = '', string $error_code = '', string $tool = '', array $required_scopes = array() ): array {
		$context = array(
			'provider'   => $provider,
			'rpc_method' => $method,
			'tool'       => $tool,
		);

		if ( '' !== $error_code ) {
			$context['error_code'] = $error_code;
		}

		if ( array() !== $required_scopes ) {
			$context['required_scopes'] = array_values( array_map( 'strval', $required_scopes ) );
		}

		return $context;
	}

	/**
	 * Build the complete, unpaginated tool manifest for diagnostics and exports.
	 *
	 * @return array{tools: list<array<string, mixed>>}
	 */
	public function tool_manifest_for_current_user(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;

		return $this->tool_manifest_for_user( (int) $user_id );
	}

	/**
	 * Build the complete, unpaginated tool manifest for one user and scope set.
	 *
	 * @param int                  $user_id         WordPress user ID.
	 * @param array<mixed>|null    $granted_scopes  Granted OAuth scopes, or null when unknown.
	 * @param array<string, mixed> $profile_context Optional profile selection context.
	 * @return array{tools: list<array<string, mixed>>}
	 */
	public function tool_manifest_for_user( int $user_id, ?array $granted_scopes = null, array $profile_context = array() ): array {
		$modules = ( new McpToolAvailability() )->tool_modules_for_user( $user_id, null, null, $granted_scopes, $profile_context );

		return array(
			'tools' => array_values( array_map( array( $this, 'tool_from_module' ), $modules ) ),
		);
	}

	/**
	 * Build one paginated tools/list payload for diagnostics and deterministic smoke tests.
	 *
	 * @param int                  $user_id         WordPress user ID.
	 * @param array<mixed>|null    $granted_scopes  Granted OAuth scopes, or null when unknown.
	 * @param string               $cursor          Opaque cursor from a previous page.
	 * @param array<string, mixed> $profile_context Optional profile selection context.
	 * @return array{tools: list<array<string, mixed>>, nextCursor?: string, _meta: array<string, int|string|bool>}
	 */
	public function tools_list_page_for_user( int $user_id, ?array $granted_scopes = null, string $cursor = '', array $profile_context = array() ): array {
		return ( new McpToolListPager() )->page( $this->tools_for_user( $user_id, $granted_scopes, $profile_context ), $cursor );
	}

	/**
	 * Build the exact initialize payload for diagnostics and manifest exports.
	 *
	 * @return array<string, mixed>
	 */
	public function initialize_payload_for_diagnostics(): array {
		return $this->initialize_payload();
	}

	/**
	 * Return the current tools/list page size for diagnostics.
	 */
	public static function tools_page_size(): int {
		return McpToolListPager::page_size();
	}

	/**
	 * Build the MCP tools/list payload from internal intelligence and enabled abilities.
	 *
	 * Supports MCP cursor pagination so large tool manifests are not
	 * truncated by clients with payload limits.
	 *
	 * @param string $cursor Opaque cursor from a previous page.
	 * @return array{tools: list<array<string, mixed>>, nextCursor?: string, _meta: array<string, int|string|bool>}
	 */
	private function list_tools( string $cursor = '' ): array {
		$user_id        = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$granted_scopes = array_key_exists( 'scopes', $this->request_auth ) ? (array) $this->request_auth['scopes'] : null;

		return $this->tools_list_page_for_user( (int) $user_id, $granted_scopes, $cursor, AbilityExecutionGateway::profile_context_from_auth( $this->request_auth ) );
	}

	/**
	 * Build all tool descriptors for one user and scope set.
	 *
	 * @param int                  $user_id         WordPress user ID.
	 * @param array<mixed>|null    $granted_scopes  Granted OAuth scopes, or null when unknown.
	 * @param array<string, mixed> $profile_context Optional profile selection context.
	 * @return list<array<string, mixed>>
	 */
	private function tools_for_user( int $user_id, ?array $granted_scopes = null, array $profile_context = array() ): array {
		$modules = ( new McpToolAvailability() )->tool_modules_for_user( $user_id, null, null, $granted_scopes, $profile_context );

		return array_values( array_map( array( $this, 'tool_from_module' ), $modules ) );
	}

	/**
	 * Convert an ability module into an MCP tool descriptor.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @return array<string, mixed>
	 */
	private function tool_from_module( AbilityModuleInterface $module ): array {
		$registry = new AbilitiesRegistry();
		$scopes   = $module->required_scopes();
		$security = $this->security_schemes( $scopes );
		$meta     = array(
			'securitySchemes'                => $security,
			'openai/toolInvocation/invoking' => $this->tool_invocation_status( $module, 'Running' ),
			'openai/toolInvocation/invoked'  => $this->tool_invocation_status( $module, 'Finished' ),
		);

		$input_schema = $this->schema_for_protocol( AbilityExecutionGateway::input_schema_for_module( $module ) );

		$descriptor = array(
			'name'            => $registry->tool_name( $module->id() ),
			'title'           => $module->title(),
			'description'     => $module->description(),
			'inputSchema'     => $input_schema,
			'securitySchemes' => $security,
			'_meta'           => $meta,
			'annotations'     => ( new McpToolAnnotations() )->for_module( $module ),
		);

		$output_schema = $this->output_schema_for_module( $module );
		if ( array() !== $output_schema ) {
			$descriptor['outputSchema'] = $this->schema_for_protocol( $output_schema );
		}

		return $descriptor;
	}

	/**
	 * Prepare an advertised schema for the resolved request protocol.
	 *
	 * Execution continues to validate the module's original schema. This method
	 * only controls the bounded descriptor sent to the client.
	 *
	 * @param array<string, mixed> $schema Module schema.
	 * @return array<string, mixed>|bool|\stdClass
	 * @throws \UnexpectedValueException When a current schema is invalid.
	 */
	private function schema_for_protocol( array $schema ): array|bool|\stdClass {
		$result = ( new McpSchemaCompatibility() )->prepare( $schema, $this->request_protocol_version );
		if ( ! $result['valid'] ) {
			throw new \UnexpectedValueException( 'Invalid MCP tool schema.' );
		}

		return $result['schema'];
	}

	/**
	 * Build the MCP initialize payload.
	 *
	 * @param string $protocol_version Negotiated protocol version.
	 * @return array<string, mixed>
	 */
	private function initialize_payload( string $protocol_version = self::PROTOCOL_VERSION_LEGACY ): array {
		return array(
			'protocolVersion' => $protocol_version,
			'serverInfo'      => $this->server_info(),
			'instructions'    => $this->mcp_instructions(),
			'capabilities'    => array(
				'tools'     => array(
					'listChanged' => false,
				),
				'resources' => array(
					'listChanged' => false,
				),
			),
		);
	}

	/**
	 * Build the stateless discovery result defined by MCP 2026-07-28.
	 *
	 * @return array<string, mixed>
	 */
	private function discover_payload(): array {
		return array(
			'supportedVersions' => self::SUPPORTED_PROTOCOL_VERSIONS,
			'capabilities'      => array(
				'tools'     => array( 'listChanged' => false ),
				'resources' => array( 'listChanged' => false ),
			),
			'instructions'      => $this->mcp_instructions(),
		);
	}

	/**
	 * Return server-wide workflow guidance for MCP clients.
	 */
	private function mcp_instructions(): string {
		return McpInstructions::text();
	}

	/**
	 * Return a top-level output schema for modules that publish structured content.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @return array<string, mixed>
	 */
	private function output_schema_for_module( AbilityModuleInterface $module ): array {
		if ( 'search' === $module->id() ) {
			return $this->canonical_search_output_schema();
		}

		if ( 'fetch' === $module->id() ) {
			return $this->canonical_fetch_output_schema();
		}

		if ( 'workflow_guides.list' === $module->id() ) {
			return $this->workflow_guides_list_output_schema();
		}

		if ( 'workflow_guides.get' === $module->id() ) {
			return $this->workflow_guides_get_output_schema();
		}

		if ( 'core_schema.discover' === $module->id() ) {
			return McpOutputSchemaCatalog::core_schema();
		}

		if ( str_starts_with( $module->id(), 'users.' ) ) {
			return $this->object_output_schema(
				array(
					'user_id'             => array( 'type' => 'integer' ),
					'roles'               => array( 'type' => 'array' ),
					'capabilities'        => array( 'type' => 'object' ),
					'blocked_unavailable' => array( 'type' => 'array' ),
					'items'               => array( 'type' => 'array' ),
					'total'               => array( 'type' => 'integer' ),
					'returned'            => array( 'type' => 'integer' ),
					'page'                => array( 'type' => 'integer' ),
					'per_page'            => array( 'type' => 'integer' ),
					'bounded'             => array( 'type' => 'boolean' ),
					'read_only'           => array( 'type' => 'boolean' ),
					'required_capability' => array( 'type' => 'string' ),
					'privacy'             => array( 'type' => 'object' ),
				)
			);
		}

		if ( 'intelligence.feedback.submit' === $module->id() ) {
			return $this->object_output_schema(
				array(
					'status'        => array(
						'type'        => 'string',
						'description' => 'queued when accepted for admin review, or rejected when required fields are missing.',
					),
					'message'       => array( 'type' => 'string' ),
					'error'         => array( 'type' => 'string' ),
					'suggestion'    => array( 'type' => 'object' ),
					'review_status' => array( 'type' => 'object' ),
				),
				array( 'status' )
			);
		}

		if ( 'plugin.incident.report' === $module->id() ) {
			return $this->object_output_schema(
				array(
					'status'                    => array(
						'type'        => 'string',
						'description' => 'stored_ready_for_client_submission when a local incident and public GitHub issue draft were prepared, or rejected when required fields are missing.',
					),
					'message'                   => array( 'type' => 'string' ),
					'error'                     => array( 'type' => 'string' ),
					'report_id'                 => array( 'type' => 'string' ),
					'correlation_id'            => array( 'type' => 'string' ),
					'repository'                => array( 'type' => 'string' ),
					'title'                     => array( 'type' => 'string' ),
					'body'                      => array( 'type' => 'string' ),
					'issue_url'                 => array( 'type' => 'string' ),
					'can_create_direct'         => array( 'type' => 'boolean' ),
					'incident'                  => array( 'type' => 'object' ),
					'next_actions'              => array( 'type' => 'array' ),
					'dry_run'                   => array( 'type' => 'boolean' ),
					'confirmation_required'     => array( 'type' => 'boolean' ),
					'confirmation_token'        => array( 'type' => 'string' ),
					'confirmation_expires_in'   => array( 'type' => 'integer' ),
					'confirmation_instructions' => array( 'type' => 'string' ),
					'action'                    => array( 'type' => 'string' ),
					'risk_level'                => array( 'type' => 'string' ),
					'preview'                   => array( 'type' => 'object' ),
					'replayed'                  => array( 'type' => 'boolean' ),
				),
				array( 'status' )
			);
		}

		if ( 'plugin.incident.list' === $module->id() ) {
			return $this->object_output_schema(
				array(
					'items'    => array( 'type' => 'array' ),
					'total'    => array( 'type' => 'integer' ),
					'page'     => array( 'type' => 'integer' ),
					'per_page' => array( 'type' => 'integer' ),
					'summary'  => array( 'type' => 'object' ),
				)
			);
		}

		if ( 'content_internal_link.policy' === $module->id() ) {
			return $this->object_output_schema(
				array(
					'type'         => array( 'type' => 'string' ),
					'policy'       => array( 'type' => 'object' ),
					'limits'       => array( 'type' => 'object' ),
					'guidance'     => array( 'type' => 'object' ),
					'capabilities' => array( 'type' => 'object' ),
				),
				array( 'type', 'policy', 'limits' )
			);
		}

		if ( 'content_audit.internal_links' === $module->id() ) {
			return $this->object_output_schema(
				array(
					'items'              => array( 'type' => 'array' ),
					'total'              => array( 'type' => 'integer' ),
					'page'               => array( 'type' => 'integer' ),
					'per_page'           => array( 'type' => 'integer' ),
					'index'              => array( 'type' => 'object' ),
					'summary'            => array( 'type' => 'object' ),
					'health_summary'     => array( 'type' => 'object' ),
					'action_queue'       => array( 'type' => 'object' ),
					'filtered_by_access' => array( 'type' => 'boolean' ),
					'next_actions'       => array( 'type' => 'array' ),
				)
			);
		}

		if ( 'content_internal_link.suggestions_list' === $module->id() ) {
			return $this->object_output_schema(
				array(
					'items'              => array( 'type' => 'array' ),
					'total'              => array( 'type' => 'integer' ),
					'visible_total'      => array( 'type' => 'integer' ),
					'page'               => array( 'type' => 'integer' ),
					'per_page'           => array( 'type' => 'integer' ),
					'status'             => array( 'type' => 'string' ),
					'filtered_by_access' => array( 'type' => 'boolean' ),
					'capabilities'       => array( 'type' => 'object' ),
					'usage'              => array( 'type' => 'object' ),
				)
			);
		}

		if ( 'content_internal_link.suggestions_create' === $module->id() || str_starts_with( $module->id(), 'content_internal_link.suggestion' ) ) {
			return $this->object_output_schema(
				array(
					'status'        => array( 'type' => 'string' ),
					'error'         => array( 'type' => 'string' ),
					'message'       => array( 'type' => 'string' ),
					'items'         => array( 'type' => 'array' ),
					'suggestion'    => array( 'type' => 'object' ),
					'duplicates'    => array( 'type' => 'array' ),
					'total_created' => array( 'type' => 'integer' ),
					'dry_run'       => array( 'type' => 'boolean' ),
					'target'        => array( 'type' => 'object' ),
					'changes'       => array( 'type' => 'array' ),
					'diff'          => array( 'type' => 'object' ),
					'warnings'      => array( 'type' => 'array' ),
					'capabilities'  => array( 'type' => 'object' ),
					'next_actions'  => array( 'type' => 'array' ),
				)
			);
		}

		if ( WorkflowAbilityOutputSchema::supports( $module->id() ) ) {
			return $this->object_output_schema( WorkflowAbilityOutputSchema::fields() );
		}

		if ( ! str_starts_with( $module->id(), 'intelligence.' ) ) {
			return $this->is_collection_module( $module )
				? $this->collection_output_schema()
				: $this->operational_output_schema();
		}

		return $this->object_output_schema(
			array(
				'type'                 => array( 'type' => 'string' ),
				'label'                => array( 'type' => 'string' ),
				'description'          => array( 'type' => 'string' ),
				'operations'           => array( 'type' => 'object' ),
				'regular_abilities'    => array( 'type' => 'array' ),
				'workflows'            => array( 'type' => 'object' ),
				'workflow_guides'      => array( 'type' => 'object' ),
				'intelligence'         => array( 'type' => 'object' ),
				'blocked_capabilities' => array( 'type' => 'object' ),
				'example_prompts'      => array( 'type' => 'array' ),
				'next_actions'         => array( 'type' => 'array' ),
				'guidance'             => array( 'type' => 'object' ),
				'learning_protocol'    => array( 'type' => 'object' ),
				'items'                => array( 'type' => 'array' ),
				'summary'              => array( 'type' => 'object' ),
			)
		);
	}

	/**
	 * Check whether an operational module returns a collection shape.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 */
	private function is_collection_module( AbilityModuleInterface $module ): bool {
		return in_array(
			$module->id(),
			array(
				'site.list_post_types',
				'workflow_guides.list',
				'content.list_items',
				'content_search.items',
				'content_search.chunks',
				'content_find.related',
				'content_find.internal_links',
				'content_audit.internal_links',
				'content_revisions.list',
				'navigation.list_menus',
				'navigation.list_locations',
				'navigation.list_items',
				'users.list_safe',
				'memory.list',
				'taxonomy.list_taxonomies',
				'taxonomy.list_terms',
				'media.list_items',
				'comments.list_items',
				'wp_abilities.discover',
			),
			true
		);
	}

	/**
	 * Return the canonical MCP search output schema used by retrieval clients.
	 *
	 * @return array<string, mixed>
	 */
	private function canonical_search_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'results' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'    => array( 'type' => 'string' ),
							'title' => array( 'type' => 'string' ),
							'url'   => array( 'type' => 'string' ),
						),
						'required'             => array( 'id', 'title', 'url' ),
						'additionalProperties' => false,
					),
				),
				'error'   => array( 'type' => 'string' ),
				'message' => array( 'type' => 'string' ),
			),
			'required'             => array( 'results' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Return workflow guide list output schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_guides_list_output_schema(): array {
		return $this->object_output_schema(
			array(
				'items'        => array( 'type' => 'array' ),
				'total'        => array( 'type' => 'integer' ),
				'context'      => array( 'type' => 'string' ),
				'bounded'      => array( 'type' => 'boolean' ),
				'max_guides'   => array( 'type' => 'integer' ),
				'next_actions' => array( 'type' => 'array' ),
				'error'        => array( 'type' => 'string' ),
				'message'      => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Return the canonical MCP fetch output schema used by retrieval clients.
	 *
	 * @return array<string, mixed>
	 */
	private function canonical_fetch_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'       => array( 'type' => 'string' ),
				'title'    => array( 'type' => 'string' ),
				'text'     => array( 'type' => 'string' ),
				'url'      => array( 'type' => 'string' ),
				'metadata' => array( 'type' => 'object' ),
				'status'   => array( 'type' => 'string' ),
				'error'    => array( 'type' => 'string' ),
				'message'  => array( 'type' => 'string' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Return workflow guide lookup output schema.
	 *
	 * @return array<string, mixed>
	 */
	private function workflow_guides_get_output_schema(): array {
		return $this->object_output_schema(
			array(
				'id'                          => array( 'type' => 'string' ),
				'title'                       => array( 'type' => 'string' ),
				'summary'                     => array( 'type' => 'string' ),
				'task_category'               => array( 'type' => 'string' ),
				'risk_level'                  => array( 'type' => 'string' ),
				'estimated_response_size'     => array( 'type' => 'string' ),
				'available'                   => array( 'type' => 'boolean' ),
				'required_operations'         => array( 'type' => 'array' ),
				'optional_operations'         => array( 'type' => 'array' ),
				'missing_required_operations' => array( 'type' => 'array' ),
				'steps'                       => array( 'type' => 'array' ),
				'next_actions'                => array( 'type' => 'array' ),
				'status'                      => array( 'type' => 'string' ),
				'error'                       => array( 'type' => 'string' ),
				'message'                     => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Return a common output schema for paginated and item collections.
	 *
	 * @return array<string, mixed>
	 */
	private function collection_output_schema(): array {
		return $this->object_output_schema(
			array(
				'items'              => array( 'type' => 'array' ),
				'total'              => array( 'type' => 'integer' ),
				'visible_total'      => array( 'type' => 'integer' ),
				'page'               => array( 'type' => 'integer' ),
				'per_page'           => array( 'type' => 'integer' ),
				'has_more'           => array( 'type' => 'boolean' ),
				'post_id'            => array( 'type' => 'integer' ),
				'parent'             => array( 'type' => 'object' ),
				'preview'            => array( 'type' => 'object' ),
				'read_only'          => array( 'type' => 'boolean' ),
				'capabilities'       => array( 'type' => 'object' ),
				'context'            => array( 'type' => 'string' ),
				'index'              => array( 'type' => 'object' ),
				'filtered_by_access' => array( 'type' => 'boolean' ),
				'total_is_estimated' => array( 'type' => 'boolean' ),
				'degraded'           => array(
					'type'        => 'boolean',
					'description' => 'True when results came from a live WordPress query because the intelligence index could not answer. Queue content_index_refresh_batch and retry for indexed results.',
				),
				'degraded_reason'    => array( 'type' => 'string' ),
				'quality_summary'    => array( 'type' => 'object' ),
				'error'              => array( 'type' => 'string' ),
				'message'            => array( 'type' => 'string' ),
			)
		);
	}

	/**
	 * Return a common output schema for operational and workflow tools.
	 *
	 * @return array<string, mixed>
	 */
	private function operational_output_schema(): array {
		return $this->object_output_schema(
			array(
				'status'                   => array( 'type' => 'string' ),
				'error'                    => array( 'type' => 'string' ),
				'message'                  => array( 'type' => 'string' ),
				'action'                   => array( 'type' => 'string' ),
				'risk_level'               => array( 'type' => 'string' ),
				'target'                   => array( 'type' => 'object' ),
				'workflow'                 => array( 'type' => 'string' ),
				'workflow_session'         => array( 'type' => 'object' ),
				'workflow_loop'            => array( 'type' => 'object' ),
				'workflow_session_plan'    => array( 'type' => 'object' ),
				'workflow_guide_id'        => array( 'type' => 'string' ),
				'workflow_guide'           => array( 'type' => 'object' ),
				'intent'                   => array( 'type' => 'string' ),
				'content_mode'             => array( 'type' => 'string' ),
				'confidence'               => array( 'type' => 'string' ),
				'next_tool'                => array( 'type' => 'string' ),
				'next_tool_arguments'      => array( 'type' => 'object' ),
				'recommended_sequence'     => array( 'type' => 'array' ),
				'required_operations'      => array( 'type' => 'array' ),
				'blocked_operations'       => array( 'type' => 'array' ),
				'post_id'                  => array( 'type' => 'integer' ),
				'parent'                   => array( 'type' => 'object' ),
				'has_autosave'             => array( 'type' => 'boolean' ),
				'autosave'                 => array( 'type' => 'object' ),
				'preview'                  => array( 'type' => 'object' ),
				'read_only'                => array( 'type' => 'boolean' ),
				'post_type'                => array( 'type' => 'string' ),
				'intelligence_context'     => array( 'type' => 'object' ),
				'edit_url'                 => array( 'type' => 'string' ),
				'permalink'                => array( 'type' => 'string' ),
				'fields'                   => array( 'type' => 'object' ),
				'items'                    => array( 'type' => 'array' ),
				'items_to_process'         => array( 'type' => 'array' ),
				'active_item'              => array( 'type' => 'object' ),
				'total'                    => array( 'type' => 'integer' ),
				'filters'                  => array( 'type' => 'object' ),
				'insights'                 => array( 'type' => 'array' ),
				'job'                      => array( 'type' => 'object' ),
				'index'                    => array( 'type' => 'object' ),
				'summary'                  => array( 'type' => 'object' ),
				'findings'                 => array( 'type' => 'array' ),
				'operation_entries'        => array( 'type' => 'object' ),
				'changes'                  => array( 'type' => 'array' ),
				'skipped'                  => array( 'type' => 'array' ),
				'warnings'                 => array( 'type' => 'array' ),
				'next_actions'             => array( 'type' => 'array' ),
				'review_status'            => array( 'type' => 'object' ),
				'block_validation'         => array( 'type' => 'object' ),
				'seo'                      => array( 'type' => 'object' ),
				'dry_run'                  => array( 'type' => 'boolean' ),
				'confirmation_required'    => array( 'type' => 'boolean' ),
				'confirmation_policy'      => array( 'type' => 'string' ),
				'confirmation_token'       => array( 'type' => 'string' ),
				'write_permission_enabled' => array( 'type' => 'boolean' ),
				'access_level'             => array( 'type' => 'string' ),
				'repository'               => array( 'type' => 'string' ),
				'issue_url'                => array( 'type' => 'string' ),
				'can_create_direct'        => array( 'type' => 'boolean' ),
				'replayed'                 => array(
					'type'        => 'boolean',
					'description' => 'True when this result was replayed from a previous successful call with the same idempotency_key or confirmation_token; the write did not execute again.',
				),
			)
		);
	}

	/**
	 * Build a client-safe object output schema.
	 *
	 * @param array<string, mixed> $properties Schema properties.
	 * @param string[]             $required   Required property names.
	 * @return array<string, mixed>
	 */
	private function object_output_schema( array $properties, array $required = array() ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => true,
		);

		if ( array() !== $required ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Return short OpenAI tool invocation status text.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @param string                 $prefix Status prefix.
	 */
	private function tool_invocation_status( AbilityModuleInterface $module, string $prefix ): string {
		$status = sprintf( '%s %s', $prefix, $module->title() );

		return strlen( $status ) > 64 ? substr( $status, 0, 61 ) . '...' : $status;
	}

	/**
	 * Record one MCP session timeline event without affecting protocol success.
	 *
	 * @param string               $event    Timeline event type.
	 * @param array<string, mixed> $metadata Support-safe metadata.
	 * @param array<string, mixed> $auth     OAuth token context.
	 */
	private function record_timeline_event( string $event, array $metadata, array $auth ): void {
		try {
			( new ActivityLogger() )->record_timeline_event( $event, $metadata, $auth );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}

	/**
	 * Return elapsed milliseconds from a monotonic-enough request timestamp.
	 *
	 * @param float $started_at Request start timestamp.
	 */
	private function duration_ms( float $started_at ): int {
		return max( 0, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );
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
	 * Return the scopes requested during the first OAuth challenge.
	 */
	private function initial_auth_scope(): string {
		return implode( ' ', Helpers::supported_scopes() );
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
				'authorization/challenge',
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
		$response->header( 'MCP-Protocol-Version', $this->request_protocol_version );

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
			'tools/call',
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
	 * Adapt a policy-preserving execution outcome to the negotiated MCP response.
	 *
	 * The gateway owns every decision and side effect. This transport boundary
	 * only selects the already-established legacy/current response encoding.
	 *
	 * @param string|int|null         $id      JSON-RPC request ID.
	 * @param AbilityExecutionOutcome $outcome Gateway outcome.
	 * @return array<string, mixed>|WP_REST_Response
	 */
	private function adapt_tool_execution_outcome( string|int|null $id, AbilityExecutionOutcome $outcome ): array|WP_REST_Response {
		$data = $outcome->data;

		return match ( $outcome->type ) {
			AbilityExecutionGateway::OUTCOME_INVALID_PARAMS => $this->rpc_error(
				$id,
				-32602,
				'Invalid params',
				array(
					'code'    => (string) ( $data['code'] ?? 'invalid_argument_type' ),
					'message' => (string) ( $data['message'] ?? 'Tool arguments must be a JSON object.' ),
				)
			),
			AbilityExecutionGateway::OUTCOME_UNKNOWN_TOOL => self::PROTOCOL_VERSION_CURRENT === $this->request_protocol_version
				? $this->rpc_error( $id, -32602, 'Invalid params', array( 'code' => 'unknown_tool' ) )
				: $this->tool_error_result( $id, 'Unknown tool.' ),
			AbilityExecutionGateway::OUTCOME_TOOL_ERROR => $this->tool_error_result( $id, (string) ( $data['message'] ?? 'Tool execution is not available.' ) ),
			AbilityExecutionGateway::OUTCOME_AUTH_CHALLENGE => $this->auth_challenge_response(
				$id,
				implode( ' ', (array) ( $data['required_scopes'] ?? array() ) ),
				403,
				'insufficient_scope'
			),
			AbilityExecutionGateway::OUTCOME_SUCCESS => $this->rpc_result(
				$id,
				'tools/call',
				array(
					'content'           => array(
						array(
							'type' => 'text',
							'text' => (string) wp_json_encode( (array) ( $data['result'] ?? array() ) ),
						),
					),
					'structuredContent' => (array) ( $data['result'] ?? array() ),
				)
			),
			default => $this->rpc_error( $id, -32603, 'Internal error' ),
		};
	}

	/**
	 * Wrap a JSON-RPC result.
	 *
	 * @param string|int|null     $id                        JSON-RPC request ID.
	 * @param string              $method                    JSON-RPC method.
	 * @param array<string,mixed> $result                    Result payload.
	 * @param bool                $authorization_independent Whether the result is public and user independent.
	 * @return array<string, mixed>
	 */
	private function rpc_result( string|int|null $id, string $method, array $result, bool $authorization_independent = false ): array {
		$result = ( new McpResultPolicy() )->shape( $this->request_protocol_version, $method, $result, $authorization_independent );
		if ( self::PROTOCOL_VERSION_CURRENT === $this->request_protocol_version ) {
			$meta                                       = isset( $result['_meta'] ) && is_array( $result['_meta'] ) ? $result['_meta'] : array();
			$meta['io.modelcontextprotocol/serverInfo'] = $this->server_info();
			$result['_meta']                            = $meta;
		}

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

		$response = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
		if ( self::PROTOCOL_VERSION_CURRENT === $this->request_protocol_version ) {
			$response['_meta'] = array( 'io.modelcontextprotocol/serverInfo' => $this->server_info() );
		}

		return $response;
	}

	/**
	 * Return bounded server identity metadata for current-protocol responses.
	 *
	 * @return array{name: string, version: string}
	 */
	private function server_info(): array {
		return array(
			'name'    => 'Aculect AI Companion MCP',
			'version' => ACULECT_AI_COMPANION_VERSION,
		);
	}
}
