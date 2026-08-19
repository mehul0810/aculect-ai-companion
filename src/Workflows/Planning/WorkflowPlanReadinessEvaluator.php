<?php
/**
 * Pure workflow plan requirement readiness evaluator.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

use stdClass;

/**
 * Compares exact planned requirements with detached availability evidence.
 */
final class WorkflowPlanReadinessEvaluator {

	/**
	 * Evaluate exact adapter, ability, and kind binding availability for one plan.
	 *
	 * Availability is not authorization. Runtime callers must perform policy,
	 * capability, scope, and confirmation checks before constructing it.
	 *
	 * @param WorkflowPlan                 $plan         Immutable plan.
	 * @param WorkflowAvailabilitySnapshot $availability Detached availability.
	 * @param bool                         $validation_checked Separate validation proof.
	 */
	public function evaluate(
		WorkflowPlan $plan,
		WorkflowAvailabilitySnapshot $availability,
		bool $validation_checked = false
	): WorkflowReadinessEvidence {
		$identity           = $plan->identity();
		$available_bindings = array();
		foreach ( $availability->bindings() as $binding ) {
			$available_bindings[ $this->binding_token( $binding ) ] = true;
		}

		$required_bindings = array();
		foreach ( $identity['steps'] as $step_value ) {
			$step                        = $step_value instanceof stdClass ? get_object_vars( $step_value ) : $step_value;
			$token                       = $this->binding_token( $step );
			$required_bindings[ $token ] = true;
		}

		$missing_bindings = array();
		foreach ( array_keys( $required_bindings ) as $token ) {
			if ( ! isset( $available_bindings[ $token ] ) ) {
				$missing_bindings[] = $token;
			}
		}
		sort( $missing_bindings, SORT_STRING );

		return WorkflowReadinessEvidence::from_evaluation(
			$plan,
			$missing_bindings,
			$validation_checked || ! $plan->requires_validation()
		);
	}

	/**
	 * Build the deterministic canonical token for one exact binding.
	 *
	 * @param array $binding Exact binding or planned step.
	 * @phpstan-param array{adapter_id:string,adapter_version:int,ability_id:string,kind:string} $binding
	 */
	private function binding_token( array $binding ): string {
		return $binding['adapter_id'] . '@' . $binding['adapter_version'] . '|' . $binding['ability_id'] . '|' . $binding['kind'];
	}
}
