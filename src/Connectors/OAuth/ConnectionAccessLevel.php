<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

/**
 * Normalizes admin-managed connector access levels.
 */
final class ConnectionAccessLevel {

	public const READ            = 'read';
	public const WRITE           = 'write';
	public const SELECTIVE_READ  = 'selective_read';
	public const SELECTIVE_WRITE = 'selective_write';
	public const FULL_WRITE      = 'full_write';
	public const EXECUTE         = 'execute';
	public const DEFAULT         = self::READ;

	/**
	 * Normalize untrusted access-level input into the canonical two-state model.
	 *
	 * @param string $level Access level.
	 * @return string Normalized access level.
	 */
	public static function normalize( string $level ): string {
		$level = sanitize_key( $level );

		return match ( $level ) {
			self::WRITE,
			self::SELECTIVE_WRITE,
			self::FULL_WRITE,
			self::EXECUTE => self::WRITE,
			self::READ,
			self::SELECTIVE_READ => self::READ,
			default => self::DEFAULT,
		};
	}

	/**
	 * Return all canonical access-level values.
	 *
	 * @return string[]
	 */
	public static function values(): array {
		return array(
			self::READ,
			self::WRITE,
		);
	}

	/**
	 * Return whether the level skips write confirmation prompts.
	 *
	 * @param string $level Access level.
	 * @return bool Whether the level skips write confirmation prompts.
	 */
	public static function allows_direct_write( string $level ): bool {
		return self::WRITE === self::normalize( $level );
	}

	/**
	 * Convert the legacy write-permission flag into the closest access level.
	 *
	 * @param bool $enabled Legacy write-permission flag.
	 * @return string Access level.
	 */
	public static function from_write_permission( bool $enabled ): string {
		return $enabled ? self::WRITE : self::READ;
	}
}
