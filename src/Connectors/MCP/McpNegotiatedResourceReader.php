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
		$params         = is_array( $params ) ? $params : array();
		$skills_enabled = McpSkillsNegotiation::enabled_for_protocol( $protocol_version );
		$uri            = $params['uri'] ?? null;
		$available      = $skills_enabled && is_string( $uri ) && str_starts_with( $uri, 'skill://' )
			? ( new McpSkillsRpcHandler() )->available_tool_ids( $auth )
			: array();

		return ( new McpResourceRegistry() )->read_resource( $params, $apps_enabled, $skills_enabled, $available );
	}
}
