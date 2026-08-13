<?php
/**
 * Workflow definition validation exception.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use InvalidArgumentException;

/**
 * Reports a bounded validation code and structural path without input values.
 */
final class WorkflowDefinitionValidationException extends InvalidArgumentException {
	private readonly string $error_code;
	private readonly string $error_path;

	/**
	 * Create a bounded validation exception.
	 *
	 * @param string $error_code Stable machine-readable error code.
	 * @param string $error_path Bounded structural path.
	 */
	public function __construct( string $error_code, string $error_path ) {
		$this->error_code = 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $error_code ) ? $error_code : 'validation_failed';
		$this->error_path = self::bounded_path( $error_path );

		parent::__construct( $this->error_code . ' at ' . $this->error_path );
	}

	/**
	 * Return the stable machine-readable error code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Return the structural path that failed validation.
	 */
	public function error_path(): string {
		return $this->error_path;
	}

	/**
	 * Sanitize and deterministically bound a structural path to 64 bytes.
	 *
	 * @param string $path Raw structural path.
	 */
	private static function bounded_path( string $path ): string {
		$path = (string) preg_replace( '/[^\x20-\x7E]/', '?', $path );
		if ( '' === $path || '$' !== $path[0] ) {
			$path = '$';
		}
		if ( strlen( $path ) <= 64 ) {
			return $path;
		}

		return substr( $path, 0, 54 ) . '#' . substr( hash( 'sha256', $path ), 0, 9 );
	}
}
