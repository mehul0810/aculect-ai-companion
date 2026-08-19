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
	 * Create validated readiness evidence.
	 *
	 * @param string $plan_hash            Bound plan hash.
	 * @param array  $adapter_requirements Exact adapter requirements.
	 * @param array  $ability_requirements Exact ability requirements.
	 * @param array  $validation_rule_ids  Exact validation rules.
	 * @param bool   $requirements_checked Requirements checked successfully.
	 * @param bool   $validation_checked   Validation checked successfully.
	 * @param array  $missing_bindings     Missing exact binding tokens.
	 * @throws WorkflowPlanningException When evidence identity is malformed.
	 * @phpstan-param list<mixed> $adapter_requirements
	 * @phpstan-param list<string> $ability_requirements
	 * @phpstan-param list<string> $validation_rule_ids
	 * @phpstan-param list<string> $missing_bindings
	 */
	private function __construct(
		private string $plan_hash,
		private array $adapter_requirements,
		private array $ability_requirements,
		private array $validation_rule_ids,
		private bool $requirements_checked,
		private bool $validation_checked,
		private array $missing_bindings = array()
	) {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $plan_hash ) ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.readiness' );
		}
		$this->assert_missing_values( $missing_bindings );
		if ( $requirements_checked && array() !== $missing_bindings ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.readiness.requirements' );
		}
	}

	/**
	 * Create plan-bound evidence before requirement evaluation.
	 *
	 * Validation proof remains a separate caller-owned concern.
	 *
	 * @param WorkflowPlan $plan               Exact plan.
	 * @param bool         $validation_checked Whether separate validation completed.
	 */
	public static function unchecked( WorkflowPlan $plan, bool $validation_checked = false ): self {
		return self::create( $plan, false, $validation_checked, array() );
	}

	/**
	 * Create plan-bound evidence from the pure requirement evaluator.
	 *
	 * This internal factory deliberately accepts no requirements-checked flag.
	 * Completeness is derived only from the evaluator's missing requirement lists.
	 *
	 * @internal WorkflowPlanReadinessEvaluator is the sole production caller.
	 *
	 * @param WorkflowPlan $plan               Exact plan.
	 * @param array        $missing_bindings   Missing exact binding tokens.
	 * @param bool         $validation_checked Whether separate validation completed.
	 * @phpstan-param list<string> $missing_bindings
	 */
	public static function from_evaluation(
		WorkflowPlan $plan,
		array $missing_bindings,
		bool $validation_checked = false
	): self {
		return self::create(
			$plan,
			array() === $missing_bindings,
			$validation_checked,
			$missing_bindings
		);
	}

	/**
	 * Bind evidence to the plan's exact detached identity.
	 *
	 * @param WorkflowPlan $plan                 Exact plan.
	 * @param bool         $requirements_checked Derived requirement state.
	 * @param bool         $validation_checked   Separate validation state.
	 * @param array        $missing_bindings     Missing exact binding tokens.
	 * @phpstan-param list<string> $missing_bindings
	 */
	private static function create(
		WorkflowPlan $plan,
		bool $requirements_checked,
		bool $validation_checked,
		array $missing_bindings
	): self {
		$identity = $plan->identity();

		return new self(
			$plan->hash(),
			$identity['adapter_requirements'],
			$identity['ability_requirements'],
			$identity['validation_rule_ids'],
			$requirements_checked,
			$validation_checked,
			$missing_bindings
		);
	}

	/**
	 * Return sorted missing exact binding tokens.
	 *
	 * These tokens are the sole dependency-readiness authority.
	 *
	 * @return list<string>
	 */
	public function missing_bindings(): array {
		return array_values( $this->missing_bindings );
	}

	/**
	 * Return sorted missing exact adapter-version tokens.
	 *
	 * @return list<string>
	 */
	public function missing_adapters(): array {
		$adapters = array();
		foreach ( $this->missing_bindings as $token ) {
			$adapter              = (string) strstr( $token, '|', true );
			$adapters[ $adapter ] = true;
		}

		$tokens = array_keys( $adapters );
		sort( $tokens, SORT_STRING );

		return $tokens;
	}

	/**
	 * Return sorted missing exact ability IDs.
	 *
	 * @return list<string>
	 */
	public function missing_abilities(): array {
		$abilities = array();
		foreach ( $this->missing_bindings as $token ) {
			$parts                  = explode( '|', $token, 3 );
			$abilities[ $parts[1] ] = true;
		}

		$ability_ids = array_keys( $abilities );
		sort( $ability_ids, SORT_STRING );

		return $ability_ids;
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
	 * @param array $missing_bindings Missing exact binding tokens.
	 * @throws WorkflowPlanningException When missing details are malformed.
	 * @phpstan-param list<string> $missing_bindings
	 */
	private function assert_missing_values( array $missing_bindings ): void {
		if ( count( $missing_bindings ) > 50 ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.readiness.missing' );
		}

		foreach ( $missing_bindings as $token ) {
			$matches = array();
			if ( strlen( $token ) > 256 || 1 !== preg_match( '#^[a-z][a-z0-9_]{1,63}@([1-9][0-9]*)\|([a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*)\|(read|proposal|write)$#D', $token, $matches ) ) {
				throw new WorkflowPlanningException( 'invalid_request', '$.readiness.missing_bindings' );
			}

			$version = filter_var( $matches[1], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $version || (string) $version !== $matches[1] || strlen( $matches[2] ) > 128 ) {
				throw new WorkflowPlanningException( 'invalid_request', '$.readiness.missing_bindings' );
			}
		}

		$sorted_bindings = array_values( array_unique( $missing_bindings ) );
		sort( $sorted_bindings, SORT_STRING );
		if ( $missing_bindings !== $sorted_bindings ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.readiness.missing' );
		}
	}
}
