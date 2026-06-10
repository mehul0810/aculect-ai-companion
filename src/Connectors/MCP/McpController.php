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
	 * OAuth context resolved by the permission callback for the current request.
	 *
	 * @var array<string, mixed>
	 */
	private array $request_auth = array();

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
	 * JSON-RPC notifications are auth-exempt per the MCP streamable HTTP
	 * transport: they carry no id and receive an empty 202 acknowledgement.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return true|\WP_Error
	 */
	public function check_mcp_permission( WP_REST_Request $request ): bool|\WP_Error {
		$this->request_auth = array();

		if ( $this->is_auth_exempt_notification( $request ) ) {
			return true;
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
	 * Check whether the request is an auth-exempt JSON-RPC notification.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function is_auth_exempt_notification( WP_REST_Request $request ): bool {
		if ( 'POST' !== $request->get_method() ) {
			return false;
		}

		$body = $request->get_json_params();

		return is_array( $body )
			&& ! array_key_exists( 'id', $body )
			&& str_starts_with( (string) ( $body['method'] ?? '' ), 'notifications/' );
	}

	/**
	 * Read the JSON-RPC method for diagnostics without trusting the payload.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function rpc_method_from_request( WP_REST_Request $request ): string {
		$body = $request->get_json_params();

		return is_array( $body ) ? (string) ( $body['method'] ?? '' ) : '';
	}

	/**
	 * Read the JSON-RPC request ID for challenge responses.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function rpc_id_from_request( WP_REST_Request $request ): string|int|null {
		$body = $request->get_json_params();
		$id   = is_array( $body ) ? ( $body['id'] ?? null ) : null;

		return is_string( $id ) || is_int( $id ) ? $id : null;
	}

	/**
	 * Describe the authenticated MCP endpoint.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|array<string, mixed>
	 */
	public function describe( WP_REST_Request $request ): WP_REST_Response|array {
		if ( array() === $this->request_auth ) {
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

		$auth = $this->request_auth;
		if ( array() === $auth ) {
			return $this->auth_challenge_response( $id, $this->initial_auth_scope(), 401, 'invalid_token' );
		}

		wp_set_current_user( (int) $auth['user_id'] );

		switch ( $method ) {
			case 'initialize':
				return $this->rpc_result( $id, $this->initialize_payload() );

			case 'tools/list':
				return $this->rpc_result( $id, $this->list_tools() );

			case 'tools/call':
				$registry             = new AbilitiesRegistry();
				$intelligence         = new IntelligenceRegistry();
				$requested_tool       = (string) ( $body['params']['name'] ?? '' );
				$tool                 = $intelligence->internal_id( $requested_tool );
				$is_intelligence_tool = $intelligence->is_known( $tool );
				if ( ! $is_intelligence_tool ) {
					$tool = $registry->internal_id( $requested_tool );
				}

				$args  = (array) ( $body['params']['arguments'] ?? array() );
				$error = $is_intelligence_tool ? '' : $this->tool_call_error( $tool, $registry, (int) ( $auth['user_id'] ?? 0 ) );
				if ( 'unknown_tool' === $error ) {
					$this->record_tool_activity(
						$tool,
						$args,
						array(
							'status'  => 'error',
							'error'   => 'unknown_tool',
							'message' => 'Unknown tool.',
						),
						$auth
					);
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
					$this->record_tool_activity(
						$tool,
						$args,
						array(
							'status'  => 'error',
							'error'   => 'tool_disabled',
							'message' => 'This ability is disabled in Aculect AI Companion settings.',
						),
						$auth
					);
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
					$this->record_tool_activity(
						$tool,
						$args,
						array(
							'status'  => 'error',
							'error'   => 'tool_forbidden_for_role',
							'message' => 'This ability is not available for the connected WordPress role.',
						),
						$auth
					);
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
					$this->record_tool_activity(
						$tool,
						$args,
						array(
							'status'  => 'error',
							'error'   => 'access_paused',
							'message' => 'AI access is paused in Aculect AI Companion settings.',
						),
						$auth
					);
					( new Logger() )->warning(
						'mcp.access_paused',
						'MCP tool call was blocked because AI access is paused.',
						$this->log_context( $method, (string) ( $auth['provider'] ?? '' ), 'access_paused' ),
						$request,
						423
					);
					return $this->tool_error_result( $id, 'AI access is paused in Aculect AI Companion settings.' );
				}

				$required = $is_intelligence_tool ? $intelligence->required_scopes( $tool ) : $registry->required_scopes( $tool );
				if ( ! $this->has_scopes( (array) ( $auth['scopes'] ?? array() ), $required ) ) {
					$this->record_tool_activity(
						$tool,
						$args,
						array(
							'status'          => 'error',
							'error'           => 'insufficient_scope',
							'message'         => 'The connection token does not include every required OAuth scope.',
							'required_scopes' => $required,
						),
						$auth
					);
					( new Logger() )->warning(
						'mcp.insufficient_scope',
						'MCP tool call did not include every required OAuth scope.',
						$this->log_context( $method, (string) ( $auth['provider'] ?? '' ), 'insufficient_scope', $tool, $required ),
						$request,
						403
					);
					return $this->auth_challenge_response( $id, implode( ' ', $required ), 403, 'insufficient_scope' );
				}

				$safety                     = new ToolSafety();
				$is_write_tool              = ! $is_intelligence_tool && ! $registry->is_read_only( $tool );
				$is_dry_run                 = $is_write_tool && $safety->is_dry_run( $args );
				$write_permission_unblocked = $is_write_tool && $this->write_permission_unblocks_tool( $tool, $registry, $auth );
				$is_confirmation_required   = false;
				$needs_confirmation_gate    = $is_write_tool
					&& ! $is_dry_run
					&& ! $write_permission_unblocked
					&& $safety->requires_confirmation( $tool, $args )
					&& ! $safety->consume_confirmation_token( $tool, $args, $auth );
				if ( $is_dry_run ) {
					$result = $this->execute_tool( $tool, $args, $registry, $intelligence, $is_intelligence_tool, $auth );
					if ( ! isset( $result['error'] ) ) {
						if ( $write_permission_unblocked ) {
							$result = $this->write_permission_preview_payload( $result );
						} elseif ( $safety->requires_confirmation( $tool, $args ) ) {
							$result = $this->add_confirmation_metadata( $result, $tool, $args, $auth, $safety );
						}
					}
				} elseif ( $needs_confirmation_gate ) {
					$preview_args             = $safety->strip_control_args( $args );
					$preview_args['dry_run']  = true;
					$preview                  = $this->execute_tool( $tool, $preview_args, $registry, $intelligence, $is_intelligence_tool, $auth );
					$is_confirmation_required = ! isset( $preview['error'] );
					$result                   = isset( $preview['error'] )
						? $preview
						: $this->confirmation_required_payload( $tool, $preview_args, $auth, $preview, $safety );
				} else {
					$args   = $is_write_tool ? $safety->strip_control_args( $args ) : $args;
					$result = $this->execute_tool( $tool, $args, $registry, $intelligence, $is_intelligence_tool, $auth );
				}

				$this->record_tool_activity( $tool, $args, $result, $auth );

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
	 * Build the MCP tools/list payload from internal intelligence and enabled abilities.
	 *
	 * @return array{tools: list<array<string, mixed>>}
	 */
	public function tool_manifest_for_current_user(): array {
		return $this->list_tools();
	}

	/**
	 * Build the MCP tools/list payload from internal intelligence and enabled abilities.
	 *
	 * @return array{tools: list<array<string, mixed>>}
	 */
	private function list_tools(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$modules = ( new McpToolAvailability() )->tool_modules_for_user( (int) $user_id );

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
		$meta     = array(
			'securitySchemes'                => $security,
			'openai/toolInvocation/invoking' => $this->tool_invocation_status( $module, 'Running' ),
			'openai/toolInvocation/invoked'  => $this->tool_invocation_status( $module, 'Finished' ),
		);

		$descriptor = array(
			'name'            => $registry->tool_name( $module->id() ),
			'title'           => $module->title(),
			'description'     => $module->description(),
			'inputSchema'     => $module->input_schema(),
			'securitySchemes' => $security,
			'_meta'           => $meta,
			'annotations'     => $this->tool_annotations( $module ),
		);

		$output_schema = $this->output_schema_for_module( $module );
		if ( array() !== $output_schema ) {
			$descriptor['outputSchema'] = $output_schema;
		}

		return $descriptor;
	}

	/**
	 * Return provider-facing tool annotations.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @return array<string, bool>
	 */
	private function tool_annotations( AbilityModuleInterface $module ): array {
		$risk = ( new ToolSafety() )->risk_level( $module->id(), array() );

		return array(
			'readOnlyHint'    => $module->is_read_only(),
			'destructiveHint' => in_array( $risk, array( 'destructive', 'system' ), true ),
			'idempotentHint'  => in_array( $module->id(), array( 'content_index.refresh_batch', 'memory.save' ), true ),
			'openWorldHint'   => in_array(
				$module->id(),
				array(
					'content.create_item',
					'content.update_item',
					'comments.create_item',
					'comments.update_item',
					'comments.bulk_update',
					'wp_abilities.run',
				),
				true
			),
		);
	}

	/**
	 * Build the MCP initialize payload.
	 *
	 * @return array<string, mixed>
	 */
	private function initialize_payload(): array {
		return array(
			'protocolVersion' => '2025-06-18',
			'serverInfo'      => array(
				'name'    => 'Aculect AI Companion MCP',
				'version' => ACULECT_AI_COMPANION_VERSION,
			),
			'instructions'    => $this->mcp_instructions(),
			'capabilities'    => array(
				'tools' => new \stdClass(),
			),
		);
	}

	/**
	 * Return server-wide workflow guidance for MCP clients.
	 */
	private function mcp_instructions(): string {
		return implode(
			' ',
			array(
				'Aculect AI Companion is a WordPress MCP server with read-only Aculect Intelligence context tools and separately governed operational tools.',
				'Before planning site, content, brand, or developer work, call the relevant context tool: intelligence_site_get_context, intelligence_content_get_context, intelligence_developer_get_context, or intelligence_brand_get_context.',
				'Use the returned operations manifest to choose only available operational tools; unavailable operations explain global ability, role policy, or OAuth scope blockers.',
				'For fast content discovery, prefer content_search_items, content_search_chunks, content_find_related, and content_find_internal_links before reading full posts; refresh stale index rows with content_index_refresh_batch when available.',
				'Use memory_list for durable Aculect Intelligence guidance; do not require ChatGPT or Claude saved memory to understand the site.',
				'For normal WordPress content creation or editing, call content_workflow_prepare_post first, then prefer content_workflow_create_draft, content_workflow_update_post, or seo_workflow_update_rankmath when available.',
				'Use atomic content, taxonomy, media, and SEO tools only when a workflow tool is unavailable or the user asks for a narrow direct operation.',
				'If intelligence is incomplete, stale, or causes poor results, call intelligence_feedback_submit with a bounded learning suggestion for admin review.',
				'Never use raw Custom HTML blocks or core/html; use registered WordPress blocks and patterns, and validate block content before write operations.',
			)
		);
	}

	/**
	 * Return a top-level output schema for modules that publish structured content.
	 *
	 * @param AbilityModuleInterface $module Ability module.
	 * @return array<string, mixed>
	 */
	private function output_schema_for_module( AbilityModuleInterface $module ): array {
		if ( ! str_starts_with( $module->id(), 'intelligence.' ) ) {
			return $this->is_collection_module( $module )
				? $this->collection_output_schema()
				: $this->operational_output_schema();
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

		return $this->object_output_schema(
			array(
				'type'              => array( 'type' => 'string' ),
				'label'             => array( 'type' => 'string' ),
				'description'       => array( 'type' => 'string' ),
				'operations'        => array( 'type' => 'object' ),
				'guidance'          => array( 'type' => 'object' ),
				'learning_protocol' => array( 'type' => 'object' ),
				'items'             => array( 'type' => 'array' ),
				'summary'           => array( 'type' => 'object' ),
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
				'content.list_items',
				'content_search.items',
				'content_search.chunks',
				'content_find.related',
				'content_find.internal_links',
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
				'context'            => array( 'type' => 'string' ),
				'index'              => array( 'type' => 'object' ),
				'filtered_by_access' => array( 'type' => 'boolean' ),
				'total_is_estimated' => array( 'type' => 'boolean' ),
				'degraded'           => array(
					'type'        => 'boolean',
					'description' => 'True when results came from a live WordPress query because the intelligence index could not answer. Queue content_index_refresh_batch and retry for indexed results.',
				),
				'degraded_reason'    => array( 'type' => 'string' ),
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
				'status'                => array( 'type' => 'string' ),
				'error'                 => array( 'type' => 'string' ),
				'message'               => array( 'type' => 'string' ),
				'workflow'              => array( 'type' => 'string' ),
				'post_id'               => array( 'type' => 'integer' ),
				'post_type'             => array( 'type' => 'string' ),
				'intelligence_context'  => array( 'type' => 'object' ),
				'edit_url'              => array( 'type' => 'string' ),
				'permalink'             => array( 'type' => 'string' ),
				'fields'                => array( 'type' => 'object' ),
				'items'                 => array( 'type' => 'array' ),
				'job'                   => array( 'type' => 'object' ),
				'index'                 => array( 'type' => 'object' ),
				'changes'               => array( 'type' => 'array' ),
				'warnings'              => array( 'type' => 'array' ),
				'next_actions'          => array( 'type' => 'array' ),
				'block_validation'      => array( 'type' => 'object' ),
				'seo'                   => array( 'type' => 'object' ),
				'dry_run'               => array( 'type' => 'boolean' ),
				'confirmation_required' => array( 'type' => 'boolean' ),
				'confirmation_token'    => array( 'type' => 'string' ),
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
	 * Execute an MCP tool from either the internal intelligence or ability registry.
	 *
	 * @param string               $tool                 Internal tool ID.
	 * @param array<string, mixed> $args                 Tool arguments.
	 * @param AbilitiesRegistry    $registry             User-managed ability registry.
	 * @param IntelligenceRegistry $intelligence         Internal intelligence registry.
	 * @param bool                 $is_intelligence_tool Whether the tool is internal intelligence.
	 * @param array<string, mixed> $auth                 OAuth token context.
	 * @return array<string, mixed>
	 */
	private function execute_tool( string $tool, array $args, AbilitiesRegistry $registry, IntelligenceRegistry $intelligence, bool $is_intelligence_tool, array $auth = array() ): array {
		return $is_intelligence_tool ? $intelligence->execute( $tool, $args, $this->intelligence_source_from_auth( $auth ) ) : $registry->execute( $tool, $args );
	}

	/**
	 * Record one MCP tool event without making activity storage part of request success.
	 *
	 * @param string               $tool   Internal tool ID.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @param array<string, mixed> $result Tool result or error payload.
	 * @param array<string, mixed> $auth   OAuth token context.
	 */
	private function record_tool_activity( string $tool, array $args, array $result, array $auth ): void {
		try {
			( new ActivityLogger() )->record_tool_call( $tool, $args, $result, $auth );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}

	/**
	 * Return bounded source metadata for intelligence feedback suggestions.
	 *
	 * @param array<string, mixed> $auth OAuth token context.
	 * @return array<string, mixed>
	 */
	private function intelligence_source_from_auth( array $auth ): array {
		return array(
			'provider'    => (string) ( $auth['provider'] ?? 'mcp' ),
			'client_id'   => (string) ( $auth['client_id'] ?? '' ),
			'client_name' => (string) ( $auth['client_name'] ?? '' ),
			'user_id'     => (int) ( $auth['user_id'] ?? 0 ),
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
	 * Determine whether a connection can execute write tools without confirmation blockers.
	 *
	 * This does not bypass OAuth scopes, disabled abilities, role policy, global
	 * pauses, or WordPress capability checks inside the tool implementation.
	 *
	 * @param string               $tool     Internal ability ID.
	 * @param AbilitiesRegistry    $registry Ability registry.
	 * @param array<string, mixed> $auth     OAuth context.
	 */
	private function write_permission_unblocks_tool( string $tool, AbilitiesRegistry $registry, array $auth ): bool {
		$enabled = $auth['write_permission_enabled'] ?? false;

		return ! $registry->is_read_only( $tool ) && in_array( $enabled, array( true, 1, '1' ), true );
	}

	/**
	 * Mark a dry-run preview as directly executable for trusted write connections.
	 *
	 * @param array<string, mixed> $result Preview result.
	 * @return array<string, mixed>
	 */
	private function write_permission_preview_payload( array $result ): array {
		$result['confirmation_required']    = false;
		$result['write_permission_enabled'] = true;
		unset(
			$result['confirmation_token'],
			$result['confirmation_expires_in'],
			$result['confirmation_instructions']
		);

		return $result;
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

		foreach ( $registry->dependency_ids( $tool ) as $dependency_id ) {
			if ( ! $registry->is_enabled( $dependency_id ) ) {
				return 'tool_disabled';
			}

			if ( ! ( new RoleAbilitiesPolicy() )->is_allowed_for_user( $dependency_id, $user_id, $registry ) ) {
				return 'tool_forbidden_for_role';
			}
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
