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
			( new Logger() )->warning(
				'mcp.invalid_token',
				'MCP endpoint description request did not include a valid bearer token.',
				$this->log_context( 'describe', '', 'invalid_token' ),
				$request,
				401
			);
			return $this->auth_challenge_response( null, $this->initial_auth_scope(), 401, 'invalid_token' );
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
		if ( ! array_key_exists( 'id', $body ) && str_starts_with( $method, 'notifications/' ) ) {
			return new WP_REST_Response( null, 202 );
		}

		$auth = ( new TokenValidator() )->authenticate( $request );
		if ( array() === $auth ) {
			( new Logger() )->warning(
				'mcp.invalid_token',
				'MCP request did not include a valid bearer token.',
				$this->log_context( $method, '', 'invalid_token' ),
				$request,
				401
			);
			return $this->auth_challenge_response( $id, $this->initial_auth_scope(), 401, 'invalid_token' );
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
				$args     = (array) ( $body['params']['arguments'] ?? array() );
				$error    = $this->tool_call_error( $tool, $registry, (int) ( $auth['user_id'] ?? 0 ) );
				if ( 'unknown_tool' === $error ) {
					( new Logger() )->warning(
						'mcp.unknown_tool',
						'MCP tool call referenced an unknown tool.',
						$this->log_context( $method, (string) ( $auth['provider'] ?? '' ), 'unknown_tool', $tool ),
						$request,
						200
					);
					return $this->tool_error_result( $id, 'Unknown tool.' );
				}

				if ( 'tool_disabled' === $error ) {
					( new Logger() )->warning(
						'mcp.tool_disabled',
						'MCP tool call referenced a disabled tool.',
						$this->log_context( $method, (string) ( $auth['provider'] ?? '' ), 'tool_disabled', $tool ),
						$request,
						200
					);
					return $this->tool_error_result( $id, 'This ability is disabled in Aculect AI Companion settings.' );
				}

				if ( 'tool_forbidden_for_role' === $error ) {
					( new Logger() )->warning(
						'mcp.tool_forbidden_for_role',
						'MCP tool call was blocked by role ability policy.',
						$this->log_context( $method, (string) ( $auth['provider'] ?? '' ), 'tool_forbidden_for_role', $tool ),
						$request,
						200
					);
					return $this->tool_error_result( $id, 'This ability is not available for the connected WordPress role.' );
				}

				if ( $this->is_access_paused( (int) ( $auth['user_id'] ?? 0 ) ) ) {
					( new Logger() )->warning(
						'mcp.access_paused',
						'MCP tool call was blocked because AI access is paused.',
						$this->log_context( $method, (string) ( $auth['provider'] ?? '' ), 'access_paused' ),
						$request,
						423
					);
					return $this->tool_error_result( $id, 'AI access is paused in Aculect AI Companion settings.' );
				}

				$required = $registry->required_scopes( $tool );
				if ( ! $this->has_scopes( (array) ( $auth['scopes'] ?? array() ), $required ) ) {
					( new Logger() )->warning(
						'mcp.insufficient_scope',
						'MCP tool call did not include every required OAuth scope.',
						$this->log_context( $method, (string) ( $auth['provider'] ?? '' ), 'insufficient_scope', $tool, $required ),
						$request,
						403
					);
						return $this->auth_challenge_response( $id, implode( ' ', $required ), 403, 'insufficient_scope' );
				}

				$safety                   = new ToolSafety();
				$is_dry_run               = ! $registry->is_read_only( $tool ) && $safety->is_dry_run( $args );
				$is_confirmation_required = false;
				if ( $is_dry_run ) {
					$result = $registry->execute( $tool, $args );
					if ( ! isset( $result['error'] ) && $safety->requires_confirmation( $tool, $args ) ) {
						$result = $this->add_confirmation_metadata( $result, $tool, $args, $auth, $safety );
					}
				} elseif ( ! $registry->is_read_only( $tool ) && $safety->requires_confirmation( $tool, $args ) && ! $safety->consume_confirmation_token( $tool, $args, $auth ) ) {
					$preview_args             = $safety->strip_control_args( $args );
					$preview_args['dry_run']  = true;
					$preview                  = $registry->execute( $tool, $preview_args );
					$is_confirmation_required = ! isset( $preview['error'] );
					$result                   = isset( $preview['error'] )
						? $preview
						: $this->confirmation_required_payload( $tool, $preview_args, $auth, $preview, $safety );
				} else {
					$args   = $registry->is_read_only( $tool ) ? $args : $safety->strip_control_args( $args );
					$result = $registry->execute( $tool, $args );
				}

				if ( ! $is_dry_run && ! $is_confirmation_required && ! $registry->is_read_only( $tool ) ) {
					( new ActivityLogger() )->record_tool_call(
						$tool,
						$args,
						$result,
						$auth
					);
				}

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

		( new Logger() )->warning(
			'mcp.method_not_found',
			'MCP request used an unsupported JSON-RPC method.',
			$this->log_context( $method, (string) ( $auth['provider'] ?? '' ), 'method_not_found' ),
			$request,
			200
		);
		return $this->rpc_error( $id, -32601, 'Method not found' );
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
		$user_id  = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$modules  = ( new RoleAbilitiesPolicy() )->enabled_modules_for_user( (int) $user_id, $registry );

		return array(
			'tools' => array_values( array_map( array( $this, 'tool_from_module' ), $modules ) ),
		);
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

		return array(
			'name'            => $registry->tool_name( $module->id() ),
			'title'           => $module->title(),
			'description'     => $module->description(),
			'inputSchema'     => $module->input_schema(),
			'securitySchemes' => $security,
			'_meta'           => array( 'securitySchemes' => $security ),
			'annotations'     => array( 'readOnlyHint' => $module->is_read_only() ),
		);
	}

	/**
	 * Add confirmation metadata to a dry-run preview.
	 *
	 * @param array<string,mixed>  $result Preview result.
	 * @param string               $tool   Internal ability ID.
	 * @param array<mixed>         $args   Tool arguments.
	 * @param array<string, mixed> $auth   OAuth context.
	 * @param ToolSafety           $safety Safety helper.
	 * @return array<string,mixed>
	 */
	private function add_confirmation_metadata( array $result, string $tool, array $args, array $auth, ToolSafety $safety ): array {
		$result['confirmation_required']     = true;
		$result['confirmation_token']        = $safety->issue_confirmation_token( $tool, $args, $auth );
		$result['confirmation_expires_in']   = $safety->confirmation_ttl();
		$result['confirmation_instructions'] = 'Repeat the same tool call with confirmation_token before it expires to apply these changes.';

		return $result;
	}

	/**
	 * Build a confirmation-required response without applying the action.
	 *
	 * @param string               $tool    Internal ability ID.
	 * @param array<mixed>         $args    Preview arguments.
	 * @param array<string, mixed> $auth    OAuth context.
	 * @param array<string, mixed> $preview Dry-run preview.
	 * @param ToolSafety           $safety  Safety helper.
	 * @return array<string,mixed>
	 */
	private function confirmation_required_payload( string $tool, array $args, array $auth, array $preview, ToolSafety $safety ): array {
		return array(
			'status'                    => 'confirmation_required',
			'confirmation_required'     => true,
			'confirmation_token'        => $safety->issue_confirmation_token( $tool, $args, $auth ),
			'confirmation_expires_in'   => $safety->confirmation_ttl(),
			'confirmation_instructions' => 'Repeat the same tool call with confirmation_token before it expires to apply these changes.',
			'action'                    => $tool,
			'risk_level'                => $safety->risk_level( $tool, $args ),
			'preview'                   => $preview,
		);
	}

	/**
	 * Determine whether MCP tool calls are paused globally or for one user.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function is_access_paused( int $user_id = 0 ): bool {
		return AccessLockdown::is_paused() || UserAccessControl::is_paused( $user_id );
	}

	/**
	 * Return a tool-call block reason before dispatch, or an empty string if callable.
	 *
	 * @param string            $tool     Internal ability ID.
	 * @param AbilitiesRegistry $registry Ability registry.
	 * @param int               $user_id  WordPress user ID.
	 */
	private function tool_call_error( string $tool, AbilitiesRegistry $registry, int $user_id = 0 ): string {
		if ( ! $registry->is_known( $tool ) ) {
			return 'unknown_tool';
		}

		if ( ! $registry->is_enabled( $tool ) ) {
			return 'tool_disabled';
		}

		if ( ! ( new RoleAbilitiesPolicy() )->is_allowed_for_user( $tool, $user_id, $registry ) ) {
			return 'tool_forbidden_for_role';
		}

		return '';
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
