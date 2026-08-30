<?php
/**
 * Bounded durable workflow run storage failure.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

use RuntimeException;

/** Closed machine-readable storage error. */
final class WorkflowRunStoreException extends RuntimeException {

	/**
	 * Create a bounded storage error.
	 *
	 * @param string $error_code Stable machine code.
	 */
	public function __construct( private string $error_code ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- The bounded accessor retains a stable machine code.
		parent::__construct( $error_code );
	}

	/** Return the stable machine code. */
	public function error_code(): string {
		return $this->error_code;
	}
}
