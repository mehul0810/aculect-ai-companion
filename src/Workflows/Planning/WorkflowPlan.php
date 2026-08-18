<?php
/**
 * Immutable deterministic workflow plan.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Binds one validated definition and normalized input to ordered requirements.
 */
final readonly class WorkflowPlan {

	/**
	 * Create an immutable plan.
	 *
	 * @param array<string, mixed> $identity      Canonical plan identity.
	 * @param array                $missing_paths Missing required input paths.
	 * @param array                $invalid_paths Invalid input paths.
	 * @param string               $canonical     Canonical plan JSON.
	 * @param string               $plan_hash     SHA-256 plan hash.
	 * @phpstan-param list<string> $missing_paths
	 * @phpstan-param list<string> $invalid_paths
	 */
	public function __construct(
		private array $identity,
		private array $missing_paths,
		private array $invalid_paths,
		private string $canonical,
		private string $plan_hash
	) {
	}

	/**
	 * Return a detached identity map.
	 *
	 * @return array<string, mixed>
	 */
	public function identity(): array {
		/**
		 * Detached identity data.
		 *
		 * @var array<string, mixed> $copy
		 */
		$copy = ( new WorkflowPlanningCanonicalizer() )->copy( $this->identity );

		return $copy;
	}

	/**
	 * Return canonical plan JSON.
	 */
	public function canonical_json(): string {
		return $this->canonical;
	}

	/**
	 * Return the deterministic plan hash.
	 */
	public function hash(): string {
		return $this->plan_hash;
	}

	/**
	 * Return the definition checksum.
	 */
	public function definition_checksum(): string {
		return (string) $this->identity['definition_checksum'];
	}

	/**
	 * Return the definition revision.
	 */
	public function definition_revision(): int {
		return (int) $this->identity['definition_revision'];
	}

	/**
	 * Return the normalized input hash.
	 */
	public function input_hash(): string {
		return (string) $this->identity['normalized_input_hash'];
	}

	/**
	 * Return ordered approval gate step IDs.
	 *
	 * @return list<string>
	 */
	public function approval_gate_step_ids(): array {
		/**
		 * Ordered approval gates.
		 *
		 * @var list<string> $gates
		 */
		$gates = $this->identity['approval_gate_step_ids'];

		return $gates;
	}

	/**
	 * Return sorted missing input paths.
	 *
	 * @return list<string>
	 */
	public function missing_paths(): array {
		return $this->missing_paths;
	}

	/**
	 * Return sorted invalid input paths.
	 *
	 * @return list<string>
	 */
	public function invalid_paths(): array {
		return $this->invalid_paths;
	}

	/**
	 * Whether input is complete and schema-valid.
	 */
	public function is_input_ready(): bool {
		return array() === $this->missing_paths && array() === $this->invalid_paths;
	}

	/**
	 * Whether declarative validations must be checked before execution.
	 */
	public function requires_validation(): bool {
		return array() !== ( $this->identity['validation_rule_ids'] ?? array() );
	}
}
