<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * MCP protocol versions understood by internal version-aware policies.
 *
 * Defining a version here does not advertise or activate it. Transport
 * negotiation remains owned by McpController.
 */
final class McpProtocolVersion {

	public const LEGACY  = '2025-06-18';
	public const CURRENT = '2026-07-28';

	/**
	 * Check whether a version is known to the internal policy layer.
	 *
	 * @param string $version Protocol version.
	 */
	public static function is_known( string $version ): bool {
		return in_array( $version, array( self::LEGACY, self::CURRENT ), true );
	}
}
