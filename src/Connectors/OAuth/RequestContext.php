<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth;

use Quark\Connectors\Helpers;

final class RequestContext {

	private static string $resource = '';

	public static function set_resource( string $resource ): void {
		self::$resource = Helpers::normalize_resource( $resource );
	}

	public static function resource(): string {
		return '' === self::$resource ? Helpers::mcp_resource() : self::$resource;
	}

	public static function reset(): void {
		self::$resource = '';
	}
}
