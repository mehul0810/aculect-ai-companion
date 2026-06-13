<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;

/**
 * Builds support-safe MCP tool manifest exports and summaries.
 */
final class McpToolManifest {

	private const CLAUDE_SAFE_TOOL_NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';

	/**
	 * Return the exact tools/list result payload for the current WordPress user.
	 *
	 * @return array{tools: list<array<string, mixed>>}
	 */
	public function tools_list_payload_for_current_user(): array {
		return ( new McpController() )->tool_manifest_for_current_user();
	}

	/**
	 * Return the exact initialize result payload for the current server.
	 *
	 * @return array<string, mixed>
	 */
	public function initialize_payload(): array {
		return ( new McpController() )->initialize_payload_for_diagnostics();
	}

	/**
	 * Build a full admin export for the current WordPress user.
	 *
	 * @param array<string, mixed> $session Optional active connector session context.
	 * @return array<string, mixed>
	 */
	public function export_for_current_user( array $session = array() ): array {
		$generated_at = gmdate( 'c' );
		$payload      = $this->tools_list_payload_for_current_user();
		$initialize   = $this->initialize_payload();

		return array(
			'generated_at'        => $generated_at,
			'connection_url'      => Helpers::mcp_resource(),
			'metadata'            => $this->metadata_context( $payload, $initialize, $generated_at ),
			'user'                => $this->current_user_context(),
			'session'             => $this->session_context( $session ),
			'summary'             => $this->summary( $payload, $initialize ),
			'ability_policy'      => $this->ability_policy_context(),
			'initialize_payload'  => $initialize,
			'tools_list_payload'  => $payload,
			'json_rpc_method'     => 'tools/list',
			'json_rpc_result_key' => 'result',
		);
	}

	/**
	 * Build a full admin export for the WordPress user attached to a connector session.
	 *
	 * @param int                  $user_id WordPress user ID.
	 * @param array<string, mixed> $session Active connector session context.
	 * @return array<string, mixed>
	 */
	public function export_for_user( int $user_id, array $session = array() ): array {
		return $this->with_user( $user_id, fn (): array => $this->export_for_current_user( $session ) );
	}

	/**
	 * Return a compact manifest summary for diagnostics.
	 *
	 * @return array<string, mixed>
	 */
	public function summary_for_current_user(): array {
		return $this->summary( $this->tools_list_payload_for_current_user(), $this->initialize_payload() );
	}

	/**
	 * Return role/global ability policy context for the current WordPress user.
	 *
	 * @return array<string, mixed>
	 */
	public function ability_policy_context(): array {
		return ( new McpToolAvailability() )->ability_policy_for_current_user();
	}

	/**
	 * Build a compact validation summary from a tools/list payload.
	 *
	 * @param array<string, mixed> $payload            MCP tools/list result payload.
	 * @param array<string, mixed> $initialize_payload MCP initialize result payload.
	 * @return array<string, mixed>
	 */
	public function summary( array $payload, array $initialize_payload = array() ): array {
		$tools       = isset( $payload['tools'] ) && is_array( $payload['tools'] ) ? array_values( $payload['tools'] ) : array();
		$names       = array();
		$seen        = array();
		$duplicates  = array();
		$invalid     = array();
		$read_only   = 0;
		$write_tools = 0;

		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			$name = (string) ( $tool['name'] ?? '' );
			if ( '' === $name ) {
				$invalid[] = $name;
				continue;
			}

			$names[] = $name;
			if ( isset( $seen[ $name ] ) ) {
				$duplicates[ $name ] = $name;
			}
			$seen[ $name ] = true;

			if ( 1 !== preg_match( self::CLAUDE_SAFE_TOOL_NAME_PATTERN, $name ) ) {
				$invalid[] = $name;
			}

			$annotations = isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ? $tool['annotations'] : array();
			if ( true === ( $annotations['readOnlyHint'] ?? null ) ) {
				++$read_only;
			} else {
				++$write_tools;
			}
		}

		$intelligence_count = count(
			array_filter(
				$names,
				static fn ( string $name ): bool => str_starts_with( $name, 'intelligence_' )
			)
		);

		return array(
			'tool_count'              => count( $tools ),
			'operation_tool_count'    => count( $names ) - $intelligence_count,
			'intelligence_tool_count' => $intelligence_count,
			'read_only_tool_count'    => $read_only,
			'write_tool_count'        => $write_tools,
			'duplicate_tool_names'    => array_values( $duplicates ),
			'invalid_tool_names'      => array_values( array_unique( $invalid ) ),
			'first_tool_names'        => array_slice( $names, 0, 10 ),
			'last_tool_names'         => array_slice( $names, -5 ),
			'metadata_fingerprint'    => $this->metadata_fingerprint( $payload, $initialize_payload ),
			'fingerprint_algorithm'   => 'sha256:canonical-json:mcp-metadata-v1',
			'claude_name_pattern'     => '^[a-zA-Z0-9_-]{1,64}$',
		);
	}

	/**
	 * Build deterministic MCP metadata context for diagnostics.
	 *
	 * @param array<string, mixed> $tools_payload      MCP tools/list result payload.
	 * @param array<string, mixed> $initialize_payload MCP initialize result payload.
	 * @param string               $generated_at       Export or diagnostics timestamp.
	 * @return array<string, mixed>
	 */
	public function metadata_context( array $tools_payload, array $initialize_payload, string $generated_at ): array {
		return array(
			'fingerprint'           => $this->metadata_fingerprint( $tools_payload, $initialize_payload ),
			'fingerprint_algorithm' => 'sha256:canonical-json:mcp-metadata-v1',
			'generated_at'          => $generated_at,
			'covers'                => array(
				'initialize.protocolVersion',
				'initialize.serverInfo',
				'initialize.instructions',
				'initialize.capabilities',
				'tools.name',
				'tools.title',
				'tools.description',
				'tools.inputSchema',
				'tools.outputSchema',
				'tools.annotations',
				'tools.securitySchemes',
				'tools._meta',
			),
			'refresh_guidance'      => $this->metadata_refresh_guidance(),
		);
	}

	/**
	 * Return a deterministic fingerprint for MCP metadata snapshots.
	 *
	 * @param array<string, mixed> $tools_payload      MCP tools/list result payload.
	 * @param array<string, mixed> $initialize_payload MCP initialize result payload.
	 */
	public function metadata_fingerprint( array $tools_payload, array $initialize_payload = array() ): string {
		$snapshot = array(
			'initialize' => $initialize_payload,
			'tools_list' => $tools_payload,
		);

		$json = wp_json_encode( $this->canonicalize( $snapshot ), JSON_UNESCAPED_SLASHES );

		return hash( 'sha256', false === $json ? '' : $json );
	}

	/**
	 * Return provider-specific cache refresh guidance for diagnostics exports.
	 *
	 * @return array<string, string>
	 */
	public function metadata_refresh_guidance(): array {
		return array(
			'chatgpt_app'        => 'After tool names, schemas, annotations, security schemes, _meta, or instructions change, reconnect or rescan the ChatGPT connector/app version and compare this fingerprint.',
			'openai_api'         => 'Start a fresh conversation or invalidate cached MCP tools so old mcp_list_tools context is not reused.',
			'codex_agents_sdk'   => 'If tool-list caching is enabled, call the client cache invalidation path before retrying tool discovery.',
			'claude_connector'   => 'Remove and re-add or re-authenticate the Claude connector when it reports fewer tools than this manifest export.',
			'gemini_cli'         => 'Run gemini mcp list or reconnect the MCP server after plugin updates, ability policy changes, or schema changes.',
			'generic_mcp_client' => 'Refresh OAuth and tools/list metadata after plugin updates, ability policy changes, or schema changes.',
		);
	}

	/**
	 * Run a callback under another current user, then restore the previous user.
	 *
	 * @param int      $user_id  WordPress user ID.
	 * @param callable $callback Export callback.
	 * @return array<string, mixed>
	 */
	private function with_user( int $user_id, callable $callback ): array {
		$previous_user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;

		$this->set_current_user_id( $user_id );

		try {
			$result = $callback();
		} finally {
			$this->set_current_user_id( $previous_user_id );
		}

		return is_array( $result ) ? $result : array();
	}

	/**
	 * Switch the current user in WordPress or the lightweight unit-test runtime.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	private function set_current_user_id( int $user_id ): void {
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $user_id );
			return;
		}

		$GLOBALS['aculect_ai_companion_test_current_user_id'] = $user_id;
	}

	/**
	 * Return safe context for the current WordPress user.
	 *
	 * @return array<string, mixed>
	 */
	private function current_user_context(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$user    = function_exists( 'get_user_by' ) ? get_user_by( 'id', $user_id ) : false;

		return array(
			'id'    => $user_id,
			'roles' => is_object( $user ) ? array_values( array_map( 'strval', (array) $user->roles ) ) : array(),
		);
	}

	/**
	 * Return safe active-session metadata without OAuth material.
	 *
	 * @param array<string, mixed> $session Active connector session context.
	 * @return array<string, mixed>
	 */
	private function session_context( array $session ): array {
		if ( array() === $session ) {
			return array();
		}

		return array(
			'id'                       => absint( $session['id'] ?? 0 ),
			'provider'                 => sanitize_text_field( (string) ( $session['provider'] ?? '' ) ),
			'client_name'              => sanitize_text_field( (string) ( $session['client_name'] ?? '' ) ),
			'user_id'                  => absint( $session['user_id'] ?? 0 ),
			'user_roles'               => array_values( array_map( 'sanitize_text_field', array_map( 'strval', is_array( $session['user_roles'] ?? null ) ? $session['user_roles'] : array() ) ) ),
			'scopes'                   => array_values( array_map( 'sanitize_text_field', array_map( 'strval', is_array( $session['scopes'] ?? null ) ? $session['scopes'] : array() ) ) ),
			'resource'                 => esc_url_raw( (string) ( $session['resource'] ?? '' ) ),
			'status'                   => sanitize_key( (string) ( $session['status'] ?? '' ) ),
			'access_level'             => sanitize_key( (string) ( $session['access_level'] ?? '' ) ),
			'write_permission_enabled' => true === ( $session['write_permission_enabled'] ?? false ),
		);
	}

	/**
	 * Recursively sort associative keys while preserving list order.
	 *
	 * @param mixed $value Raw metadata value.
	 * @return mixed
	 */
	private function canonicalize( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$properties = get_object_vars( $value );
			if ( array() === $properties ) {
				return new \stdClass();
			}

			return $this->canonicalize( $properties );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_is_list( $value ) ) {
			return array_map( array( $this, 'canonicalize' ), $value );
		}

		ksort( $value );

		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize( $item );
		}

		return $value;
	}
}
