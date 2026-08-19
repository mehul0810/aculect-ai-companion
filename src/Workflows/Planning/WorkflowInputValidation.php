<?php
/**
 * Workflow input validation result.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Holds sorted, bounded missing and invalid paths.
 */
final readonly class WorkflowInputValidation {

	/**
	 * Create a validation result.
	 *
	 * @param array $missing_paths Missing required paths.
	 * @param array $invalid_paths Invalid value paths.
	 * @phpstan-param list<string> $missing_paths
	 * @phpstan-param list<string> $invalid_paths
	 */
	public function __construct(
		private array $missing_paths,
		private array $invalid_paths
	) {
	}

	/**
	 * Return sorted missing paths.
	 *
	 * @return list<string>
	 */
	public function missing_paths(): array {
		return $this->missing_paths;
	}

	/**
	 * Return sorted invalid paths.
	 *
	 * @return list<string>
	 */
	public function invalid_paths(): array {
		return $this->invalid_paths;
	}

	/**
	 * Whether required input is missing.
	 */
	public function has_missing(): bool {
		return array() !== $this->missing_paths;
	}

	/**
	 * Whether supplied input violates the schema.
	 */
	public function has_invalid(): bool {
		return array() !== $this->invalid_paths;
	}
}
