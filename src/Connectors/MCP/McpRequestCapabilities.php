<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Diagnostics\Logger;
use WP_REST_Request;

/**
 * Adds opt-in, credential-free observations to the existing negotiation path.
 */
final class McpRequestCapabilities {

	/**
	 * Resolve Apps as before and observe authenticated discovery/use requests only.
	 *
	 * @param string              $method RPC method.
	 * @param array<string,mixed> $body Request envelope, never copied into logs.
	 * @param string              $version Resolved protocol version.
	 * @param array<string,mixed> $auth Authenticated actor, never copied into logs.
	 * @param WP_REST_Request     $request Transport request.
	 * @param Logger|null         $logger Optional diagnostic sink.
	 */
	public static function apps_enabled( string $method, array $body, string $version, array $auth, WP_REST_Request $request, ?Logger $logger = null ): bool {
		$enabled = McpAppsNegotiation::enabled_for_request( $method, $body, $version, $auth, (string) $request->get_header( 'mcp-session-id' ) );
		if ( 0 >= (int) ( $auth['user_id'] ?? 0 ) || ! in_array( $method, array( 'server/discover', 'tools/list', 'tools/call', 'resources/list', 'resources/read' ), true ) ) {
			return $enabled;
		}

		try {
			( $logger ?? new Logger() )->info(
				'mcp.capabilities',
				'Capabilities resolved for this authenticated request; host rendering is not verified.',
				array(
					'rpc_method'                => $method,
					'protocol_version'          => $version,
					'apps_enabled'              => McpAppsNegotiation::enabled(),
					'apps_negotiated'           => $enabled,
					'skills_protocol_supported' => McpSkillsNegotiation::enabled_for_protocol( $version ),
					'text_fallback'             => true,
					'rendering_verified'        => false,
				),
				$request
			);
		} catch ( \Throwable ) {
			// Observability must never change execution or negotiation outcomes.
			return $enabled;
		}
		return $enabled;
	}
}
