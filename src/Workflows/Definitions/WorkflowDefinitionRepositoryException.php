<?php
/**
 * Workflow definition repository exception.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use RuntimeException;

/**
 * Reports a bounded storage or concurrency failure without exposing SQL or
 * definition content to callers.
 */
final class WorkflowDefinitionRepositoryException extends RuntimeException {

	private readonly string $error_code;

	/**
	 * Create a repository exception.
	 *
	 * @param string $error_code Stable machine-readable error code.
	 */
	public function __construct( string $error_code ) {
		$this->error_code = 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $error_code )
			? $error_code
			: 'repository_failed';

		parent::__construct( $this->error_code );
	}

	/**
	 * Return the stable machine-readable error code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}
}
