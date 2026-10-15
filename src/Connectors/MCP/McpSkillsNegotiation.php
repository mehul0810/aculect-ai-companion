<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Gates the stable MCP Skills extension to its required protocol revision.
 */
final class McpSkillsNegotiation {

	public const EXTENSION = 'io.modelcontextprotocol/skills';

	/**
	 * Check whether the request uses the base protocol revision required by Skills.
	 *
	 * @param string $protocol_version Resolved MCP protocol version.
	 */
	public static function enabled_for_protocol( string $protocol_version ): bool {
		return McpProtocolVersion::CURRENT === $protocol_version;
	}

	/**
	 * Add the Skills extension declaration only to compatible discovery responses.
	 *
	 * @param array<string, mixed> $discovery Stateless discovery response.
	 * @param string               $protocol_version Resolved MCP protocol version.
	 * @return array<string, mixed>
	 */
	public static function add_discovery_capability( array $discovery, string $protocol_version ): array {
		if ( ! self::enabled_for_protocol( $protocol_version ) ) {
			return $discovery;
		}

		$capabilities                  = is_array( $discovery['capabilities'] ?? null ) ? $discovery['capabilities'] : array();
		$extensions                    = is_array( $capabilities['extensions'] ?? null ) ? $capabilities['extensions'] : array();
		$extensions[ self::EXTENSION ] = new \stdClass();
		$capabilities['extensions']    = $extensions;
		$capabilities['resources']     = is_array( $capabilities['resources'] ?? null ) ? $capabilities['resources'] : array();
		$discovery['capabilities']     = $capabilities;

		return $discovery;
	}
}
