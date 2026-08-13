<?php
/**
 * Test-only workflow definition fixture exception.
 *
 * @package Aculect\AICompanion\Tests\Support
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Support;

use RuntimeException;

/**
 * Reports one stable fixture-loader failure code.
 */
final class WorkflowDefinitionFixtureException extends RuntimeException {

	/**
	 * Create a fixture exception.
	 *
	 * @param string $error_code Stable fixture error code.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- The stable code is fixture-test API.
	public function __construct( private readonly string $error_code ) {
		parent::__construct( $error_code );
	}

	/**
	 * Return the stable fixture error code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}
}
