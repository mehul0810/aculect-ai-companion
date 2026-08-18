<?php
/**
 * Immutable workflow readiness evidence.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Binds dependency and validation checks to one exact plan.
 */
final readonly class WorkflowReadinessEvidence {

	/**
	 * Create readiness evidence.
	 *
	 * @param string $plan_hash            Bound plan hash.
	 * @param array  $adapter_requirements Exact adapter requirements.
	 * @param array  $ability_requirements Exact ability requirements.
	 * @param array  $validation_rule_ids  Exact validation rules.
	 * @param bool   $requirements_checked Requirements checked successfully.
	 * @param bool   $validation_checked   Validation checked successfully.
	 * @throws WorkflowPlanningException When evidence identity is malformed.
	 * @phpstan-param list<mixed> $adapter_requirements
	 * @phpstan-param list<string> $ability_requirements
	 * @phpstan-param list<string> $validation_rule_ids
	 */
	public function __construct(
		private string $plan_hash,
		private array $adapter_requirements,
		private array $ability_requirements,
		private array $validation_rule_ids,
		private bool $requirements_checked,
		private bool $validation_checked
	) {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $plan_hash ) ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.readiness' );
		}
	}

	/**
	 * Return a mismatch code or null when evidence is exact and complete.
	 *
	 * @param WorkflowPlan $plan Bound plan.
	 */
	public function binding_error_for( WorkflowPlan $plan ): ?string {
		$identity = $plan->identity();
		if ( ! hash_equals( $plan->hash(), $this->plan_hash )
			|| ! $this->values_equal( $identity['adapter_requirements'], $this->adapter_requirements )
			|| $identity['ability_requirements'] !== $this->ability_requirements
			|| $identity['validation_rule_ids'] !== $this->validation_rule_ids
		) {
			return 'evidence_mismatch';
		}

		return null;
	}

	/**
	 * Return validation readiness error when checks are deferred.
	 *
	 * @param WorkflowPlan $plan Bound plan.
	 */
	public function validation_error_for( WorkflowPlan $plan ): ?string {
		if ( $plan->requires_validation() && ! $this->validation_checked ) {
			return 'validation_unchecked';
		}

		return null;
	}

	/**
	 * Return dependency readiness error when requirements are unchecked.
	 */
	public function requirements_error(): ?string {

		if ( ! $this->requirements_checked ) {
			return 'requirements_unchecked';
		}

		return null;
	}

	/**
	 * Compare detached JSON-compatible values.
	 *
	 * @param mixed $left  First value.
	 * @param mixed $right Second value.
	 */
	private function values_equal( mixed $left, mixed $right ): bool {
		$canonicalizer = new WorkflowPlanningCanonicalizer();

		return $canonicalizer->normalize_and_encode( $left )['json'] === $canonicalizer->normalize_and_encode( $right )['json'];
	}
}
