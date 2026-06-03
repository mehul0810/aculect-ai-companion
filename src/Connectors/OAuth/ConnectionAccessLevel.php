<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

/**
 * Normalizes admin-managed connector access levels.
 */
final class ConnectionAccessLevel {

	public const READ            = 'read';
	public const SELECTIVE_READ  = 'selective_read';
	public const SELECTIVE_WRITE = 'selective_write';
	public const FULL_WRITE      = 'full_write';
	public const EXECUTE         = 'execute';
	public const DEFAULT         = self::SELECTIVE_READ;

	private const WRITE_CAPABLE_LEVELS = array(
		self::SELECTIVE_WRITE,
		self::FULL_WRITE,
		self::EXECUTE,
	);

	/**
	 * Normalize untrusted access-level input.
	 *
	 * @param string $level Access level.
	 * @return string Normalized access level.
	 */
	public static function normalize( string $level ): string {
		$level = sanitize_key( $level );

		return in_array( $level, self::values(), true ) ? $level : self::DEFAULT;
	}

	/**
	 * Return all supported access-level values.
	 *
	 * @return string[]
	 */
	public static function values(): array {
		return array(
			self::READ,
			self::SELECTIVE_READ,
			self::SELECTIVE_WRITE,
			self::FULL_WRITE,
			self::EXECUTE,
		);
	}

	/**
	 * Return whether the level skips write confirmation prompts.
	 *
	 * @param string $level Access level.
	 * @return bool Whether the level skips write confirmation prompts.
	 */
	public static function allows_direct_write( string $level ): bool {
		return in_array( self::normalize( $level ), self::WRITE_CAPABLE_LEVELS, true );
	}

	/**
	 * Convert the legacy write-permission flag into the closest access level.
	 *
	 * @param bool $enabled Legacy write-permission flag.
	 * @return string Access level.
	 */
	public static function from_write_permission( bool $enabled ): string {
		return $enabled ? self::SELECTIVE_WRITE : self::DEFAULT;
	}
}
