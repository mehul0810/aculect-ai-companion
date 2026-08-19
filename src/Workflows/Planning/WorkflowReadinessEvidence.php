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
	 * @param array  $missing_adapters     Missing exact adapter-version tokens.
	 * @param array  $missing_abilities    Missing exact ability IDs.
	 * @throws WorkflowPlanningException When evidence identity is malformed.
	 * @phpstan-param list<mixed> $adapter_requirements
	 * @phpstan-param list<string> $ability_requirements
	 * @phpstan-param list<string> $validation_rule_ids
	 * @phpstan-param list<string> $missing_adapters
	 * @phpstan-param list<string> $missing_abilities
	 */
	public function __construct(
		private string $plan_hash,
		private array $adapter_requirements,
		private array $ability_requirements,
		private array $validation_rule_ids,
		private bool $requirements_checked,
		private bool $validation_checked,
		private array $missing_adapters = array(),
		private array $missing_abilities = array()
	) {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $plan_hash ) ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.readiness' );
		}
		$this->assert_missing_values( $missing_adapters, $missing_abilities );
		if ( $requirements_checked && ( array() !== $missing_adapters || array() !== $missing_abilities ) ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.readiness.requirements' );
		}
	}

	/**
	 * Return sorted missing exact adapter-version tokens.
	 *
	 * @return list<string>
	 */
	public function missing_adapters(): array {
		return array_values( $this->missing_adapters );
	}

	/**
	 * Return sorted missing exact ability IDs.
	 *
	 * @return list<string>
	 */
	public function missing_abilities(): array {
		return array_values( $this->missing_abilities );
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

	/**
	 * Validate deterministic, public-safe missing requirement details.
	 *
	 * @param array $missing_adapters  Missing adapter-version tokens.
	 * @param array $missing_abilities Missing ability IDs.
	 * @throws WorkflowPlanningException When missing details are malformed.
	 * @phpstan-param list<string> $missing_adapters
	 * @phpstan-param list<string> $missing_abilities
	 */
	private function assert_missing_values( array $missing_adapters, array $missing_abilities ): void {
		if ( count( $missing_adapters ) > 50 || count( $missing_abilities ) > 50 ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.readiness.missing' );
		}

		foreach ( $missing_adapters as $token ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{1,63}@[1-9][0-9]*$/D', $token ) ) {
				throw new WorkflowPlanningException( 'invalid_request', '$.readiness.missing_adapters' );
			}
		}
		foreach ( $missing_abilities as $ability_id ) {
			if ( strlen( $ability_id ) > 128 || 1 !== preg_match( '#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*$#D', $ability_id ) ) {
				throw new WorkflowPlanningException( 'invalid_request', '$.readiness.missing_abilities' );
			}
		}

		$sorted_adapters  = array_values( array_unique( $missing_adapters ) );
		$sorted_abilities = array_values( array_unique( $missing_abilities ) );
		sort( $sorted_adapters, SORT_STRING );
		sort( $sorted_abilities, SORT_STRING );
		if ( $missing_adapters !== $sorted_adapters || $missing_abilities !== $sorted_abilities ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.readiness.missing' );
		}
	}
}
