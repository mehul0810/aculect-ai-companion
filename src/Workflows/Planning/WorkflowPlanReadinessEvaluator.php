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
	 * Evaluate adapter and ability availability for one exact plan.
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
		$available_adapters = array();
		foreach ( $availability->adapters() as $adapter ) {
			$available_adapters[ $adapter['adapter_id'] ] = array_fill_keys( $adapter['adapter_versions'], true );
		}

		$required_adapters = array();
		foreach ( $identity['steps'] as $step_value ) {
			$step = $step_value instanceof stdClass ? get_object_vars( $step_value ) : $step_value;
			$required_adapters[ $step['adapter_id'] . '@' . $step['adapter_version'] ] = array(
				'adapter_id'      => $step['adapter_id'],
				'adapter_version' => $step['adapter_version'],
			);
		}

		$missing_adapters = array();
		foreach ( $required_adapters as $token => $requirement ) {
			$available_versions = $available_adapters[ $requirement['adapter_id'] ] ?? array();
			if ( ! isset( $available_versions[ $requirement['adapter_version'] ] ) ) {
				$missing_adapters[] = $token;
			}
		}
		sort( $missing_adapters, SORT_STRING );

		$available_abilities = array_fill_keys( $availability->abilities(), true );
		$missing_abilities   = array();
		foreach ( $identity['ability_requirements'] as $ability_id ) {
			if ( ! isset( $available_abilities[ $ability_id ] ) ) {
				$missing_abilities[] = $ability_id;
			}
		}
		sort( $missing_abilities, SORT_STRING );

		return WorkflowReadinessEvidence::from_evaluation(
			$plan,
			$missing_adapters,
			$missing_abilities,
			$validation_checked || ! $plan->requires_validation()
		);
	}
}
