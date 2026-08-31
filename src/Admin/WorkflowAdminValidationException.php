<?php
/**
 * Guided workflow-admin validation failure.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use InvalidArgumentException;

/**
 * Carries bounded field errors without retaining submitted values.
 */
final class WorkflowAdminValidationException extends InvalidArgumentException {

	/**
	 * Create a bounded validation exception.
	 *
	 * @param array<string,string> $errors Field keys mapped to safe messages.
	 */
	public function __construct( private readonly array $errors ) {
		parent::__construct( 'workflow_admin_validation_failed' );
	}

	/**
	 * Return field errors for the admin form.
	 *
	 * @return array<string,string>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
