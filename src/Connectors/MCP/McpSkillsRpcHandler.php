<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Parses and serves the stable Skills extension RPC methods.
 */
final class McpSkillsRpcHandler {

	/**
	 * Handle one Skills extension method.
	 *
	 * @param string               $method          JSON-RPC method.
	 * @param mixed                $raw_params      JSON-RPC params.
	 * @param string               $protocol_version Current protocol revision.
	 * @param array<string, mixed> $auth            Authenticated connection context.
	 * @return array<string, mixed>
	 */
	public function handle( string $method, mixed $raw_params, string $protocol_version, array $auth ): array {
		if ( ! McpSkillsNegotiation::enabled_for_protocol( $protocol_version ) ) {
			return array( 'unsupported' => true );
		}

		$params    = is_array( $raw_params ) ? $raw_params : array();
		$available = $this->available_tool_ids( $auth );
		if ( 'skills/list' === $method ) {
			$cursor = $params['cursor'] ?? '';
			if ( ! is_string( $cursor ) ) {
				return $this->invalid_params( 'invalid_cursor' );
			}

			$result = ( new McpSkillsRegistry() )->list_skills( $available, $cursor );
			return isset( $result['error'] )
				? $this->invalid_params( (string) $result['error'], (string) ( $result['message'] ?? 'Invalid params' ) )
				: $result;
		}

		if ( 'skills/get' === $method ) {
			$uri = $params['uri'] ?? null;
			if ( ! is_string( $uri ) || '' === $uri ) {
				return $this->invalid_params( 'invalid_skill_uri' );
			}

			$result = ( new McpSkillsRegistry() )->get_skill( $uri, $available );
			return isset( $result['error'] )
				? $this->invalid_params( (string) $result['error'], (string) ( $result['message'] ?? 'Invalid params' ) )
				: $result;
		}

		return array( 'unsupported' => true );
	}

	/**
	 * Return the exact ability IDs exposed to an authenticated connection.
	 *
	 * @param array<string, mixed> $auth Authenticated connection context.
	 * @return list<string>
	 */
	public function available_tool_ids( array $auth ): array {
		$user_id        = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$granted_scopes = array_key_exists( 'scopes', $auth ) ? (array) $auth['scopes'] : null;
		$modules        = ( new McpToolAvailability() )->tool_modules_for_user(
			$user_id,
			null,
			null,
			$granted_scopes,
			AbilityExecutionGateway::profile_context_from_auth( $auth )
		);

		return array_values( array_map( 'strval', array_keys( $modules ) ) );
	}

	/**
	 * Build the common invalid-params result that the transport maps to JSON-RPC.
	 *
	 * @param string $code    Stable, non-sensitive validation reason.
	 * @param string $message Safe explanatory message.
	 * @return array{error_code:int,error_message:string,error_data:array{code:string}}
	 */
	private function invalid_params( string $code, string $message = 'Invalid params' ): array {
		return array(
			'error_code'    => -32602,
			'error_message' => $message,
			'error_data'    => array( 'code' => $code ),
		);
	}
}
