<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Resolve per-request Skills visibility before reading an MCP resource.
 */
final class McpNegotiatedResourceReader {
	/**
	 * Read one resource using this request's Apps and Skills visibility.
	 *
	 * @param mixed                $params JSON-RPC params.
	 * @param bool                 $apps_enabled Whether this request negotiated Apps.
	 * @param string               $protocol_version Negotiated MCP protocol version.
	 * @param array<string, mixed> $auth Authenticated actor context.
	 * @return array<string, mixed>
	 */
	public function read( mixed $params, bool $apps_enabled, string $protocol_version, array $auth ): array {
		$params   = is_array( $params ) ? $params : array();
		$uri      = is_string( $params['uri'] ?? null ) ? $params['uri'] : '';
		$user_id  = (int) ( $auth['user_id'] ?? 0 );
		$previous = get_current_user_id();
		try {
			wp_set_current_user( $user_id );
			$result = match ( true ) {
				$user_id <= 0 || get_current_user_id() !== $user_id => array( 'error' => 'forbidden' ),
				AccessLockdown::is_paused() || UserAccessControl::is_paused( $user_id ) => array( 'error' => 'access_paused' ),
				str_starts_with( $uri, 'aculect://' ) && ! in_array( 'content:read', (array) ( $auth['scopes'] ?? array() ), true ) => array( 'error' => 'insufficient_scope' ),
				default => $this->read_authorized( $params, $apps_enabled, $protocol_version, $auth ),
			};
			PolicyActivity::resource_read( $uri, $result, $auth );
			return $result;
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Resolve negotiated resources after authenticating the current actor.
	 *
	 * @param array<string, mixed> $params Resource params.
	 * @param bool                 $apps_enabled Apps negotiation.
	 * @param string               $protocol_version MCP version.
	 * @param array<string, mixed> $auth Connection context.
	 * @return array<string, mixed>
	 */
	private function read_authorized( array $params, bool $apps_enabled, string $protocol_version, array $auth ): array {
		$skills_enabled = McpSkillsNegotiation::enabled_for_protocol( $protocol_version );
		$uri            = $params['uri'] ?? null;
		$available      = $skills_enabled && is_string( $uri ) && str_starts_with( $uri, 'skill://' )
			? ( new McpSkillsRpcHandler() )->available_tool_ids( $auth )
			: array();

		return ( new McpResourceRegistry() )->read_resource( $params, $apps_enabled, $skills_enabled, $available );
	}
}
