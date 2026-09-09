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

	public const INITIAL      = '2025-03-26';
	public const LEGACY       = '2025-06-18';
	public const TRANSITIONAL = '2025-11-25';
	public const CURRENT      = '2026-07-28';

	/**
	 * Check whether a version is known to the internal policy layer.
	 *
	 * @param string $version Protocol version.
	 */
	public static function is_known( string $version ): bool {
		return in_array( $version, array( self::INITIAL, self::LEGACY, self::TRANSITIONAL, self::CURRENT ), true );
	}

	/**
	 * Return whether the protocol revision uses the initialize lifecycle.
	 *
	 * The 2026 stateless discovery revision intentionally removed initialize,
	 * while the preceding supported revisions retain that lifecycle.
	 *
	 * @param string $version Protocol version.
	 */
	public static function uses_initialize( string $version ): bool {
		return in_array( $version, array( self::INITIAL, self::LEGACY, self::TRANSITIONAL ), true );
	}

	/**
	 * Return whether the revision retains the legacy GET/SSE transport.
	 *
	 * MCP 2026-07-28 is POST-only. Earlier revisions remain available for
	 * clients that still use the HTTP+SSE listener.
	 *
	 * @param string $version Protocol version.
	 */
	public static function uses_get_transport( string $version ): bool {
		return self::is_known( $version ) && self::CURRENT !== $version;
	}
}
