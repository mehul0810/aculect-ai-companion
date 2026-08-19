<?php
/**
 * Bounded workflow planning exception.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

use RuntimeException;

/**
 * Exposes stable codes and sanitized paths without raw values.
 */
final class WorkflowPlanningException extends RuntimeException {

	private const MAX_PATH_BYTES = 96;

	/**
	 * Create one bounded planning failure.
	 *
	 * @param string $error_code Stable machine-readable code.
	 * @param string $path       Bounded field path or identifier.
	 */
	public function __construct(
		private readonly string $error_code,
		private readonly string $path = '$'
	) {
		parent::__construct( $error_code );
	}

	/**
	 * Return the stable public-safe error code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Return a public-safe bounded path.
	 */
	public function path(): string {
		$path = preg_replace( '/[^A-Za-z0-9_$.[\]_-]/', '_', $this->path );
		$path = is_string( $path ) && '' !== $path ? $path : '$';

		return substr( $path, 0, self::MAX_PATH_BYTES );
	}
}
