<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

use Aculect\AICompanion\Connectors\MCP\McpProtocolVersion;
use Aculect\AICompanion\Settings\McpAppsSettings;

/**
 * Distinguishes site configuration from observations of an authenticated client.
 */
final class McpFeatureReadiness {

	/**
	 * Build bounded configuration evidence without inferring a client's capabilities.
	 *
	 * @return array<string,mixed>
	 */
	public function item(): array {
		return array(
			'id'       => 'mcp_features',
			'status'   => 'warning',
			'message'  => 'Site configuration does not prove client negotiation or rendering. With diagnostic logging enabled, inspect mcp.capabilities events from authenticated tool or resource requests. Skills require protocol ' . McpProtocolVersion::CURRENT . '; text results remain available.',
			'evidence' => array(
				'appsSavedEnabled'          => McpAppsSettings::saved_enabled(),
				'appsEffectiveEnabled'      => McpAppsSettings::is_enabled(),
				'skillsProtocolAvailable'   => true,
				'textFallbackAvailable'     => true,
				'clientNegotiationVerified' => false,
				'clientRenderingVerified'   => false,
				'diagnosticLoggingEnabled'  => LogSettings::is_enabled(),
			),
		);
	}
}
