<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;

/**
 * Carries request-local OAuth resource information during token issuance.
 */
final class RequestContext {

	private static string $resource = '';

	/**
	 * Set the resource currently being processed.
	 *
	 * @param string $resource Resource URL.
	 */
	public static function set_resource( string $resource ): void {
		self::$resource = Helpers::normalize_resource( $resource );
	}

	/**
	 * Return the current resource, falling back to the MCP resource.
	 */
	public static function resource(): string {
		return '' === self::$resource ? Helpers::mcp_resource() : self::$resource;
	}

	/**
	 * Reset request-local resource state after OAuth processing.
	 */
	public static function reset(): void {
		self::$resource = '';
	}
}
