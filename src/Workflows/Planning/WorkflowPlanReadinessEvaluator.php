<?php
/**
 * Pure workflow planned-requirement readiness evaluator.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

use stdClass;

/**
 * Compares one plan with a detached availability observation.
 *
 * Availability is intentionally neither authorization nor a capability grant.
 * Callers must apply policy and capability checks before creating the snapshot.
 */
final class WorkflowPlanReadinessEvaluator {

	/**
	 * Evaluate exact planned adapter and ability requirements.
	 *
	 * This method does not resolve registries, execute callbacks, or validate plan
	 * rules. It returns evidence with validation deferred when the plan has rules.
	 *
	 * @param WorkflowPlan                 $plan         Immutable plan to inspect.
	 * @param WorkflowAvailabilitySnapshot $availability Detached observed availability.
	 * @throws WorkflowPlanningException When an internal plan projection is invalid.
	 */
	public function evaluate( WorkflowPlan $plan, WorkflowAvailabilitySnapshot $availability ): WorkflowReadinessEvidence {
		$identity            = $plan->identity();
		$required_adapters   = $this->required_adapter_ids( $identity['adapter_requirements'] ?? null );
		$required_abilities  = $this->required_ability_ids( $identity['ability_requirements'] ?? null );
		$available_adapters  = array_fill_keys( $availability->adapter_ids(), true );
		$available_abilities = array_fill_keys( $availability->ability_ids(), true );

		$missing_adapters  = $this->missing_ids( $required_adapters, $available_adapters );
		$missing_abilities = $this->missing_ids( $required_abilities, $available_abilities );

		return WorkflowReadinessEvidence::for_plan(
			$plan,
			$missing_adapters,
			$missing_abilities,
			! $plan->requires_validation()
		);
	}

	/**
	 * Extract exact, sorted unique adapter IDs from plan requirements.
	 *
	 * @param mixed $requirements Candidate adapter-requirement list.
	 * @return list<string>
	 * @throws WorkflowPlanningException When the plan invariant is malformed.
	 */
	private function required_adapter_ids( mixed $requirements ): array {
		if ( ! is_array( $requirements ) || ! array_is_list( $requirements ) ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.plan.adapter_requirements' );
		}

		$ids = array();
		foreach ( $requirements as $index => $requirement ) {
			$map = $this->map( $requirement );
			$id  = $map['adapter_id'] ?? null;
			if ( ! is_string( $id ) || ! $this->valid_adapter_id( $id ) || isset( $ids[ $id ] ) ) {
				throw new WorkflowPlanningException( 'invalid_request', '$.plan.adapter_requirements[' . $index . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
			}

			$ids[ $id ] = true;
		}

		$ids = array_keys( $ids );
		sort( $ids, SORT_STRING );

		return $ids;
	}

	/**
	 * Extract exact, sorted unique ability IDs from plan requirements.
	 *
	 * @param mixed $requirements Candidate ability-requirement list.
	 * @return list<string>
	 * @throws WorkflowPlanningException When the plan invariant is malformed.
	 */
	private function required_ability_ids( mixed $requirements ): array {
		if ( ! is_array( $requirements ) || ! array_is_list( $requirements ) ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.plan.ability_requirements' );
		}

		$ids = array();
		foreach ( $requirements as $index => $id ) {
			if ( ! is_string( $id ) || ! $this->valid_ability_id( $id ) || isset( $ids[ $id ] ) ) {
				throw new WorkflowPlanningException( 'invalid_request', '$.plan.ability_requirements[' . $index . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
			}

			$ids[ $id ] = true;
		}

		$ids = array_keys( $ids );
		sort( $ids, SORT_STRING );

		return $ids;
	}

	/**
	 * Return sorted required IDs absent from the exact availability lookup.
	 *
	 * @param array               $required Exact required IDs.
	 * @param array<string, true> $available Exact available-ID lookup.
	 * @return list<string>
	 * @phpstan-param list<string> $required
	 */
	private function missing_ids( array $required, array $available ): array {
		$missing = array();
		foreach ( $required as $id ) {
			if ( ! isset( $available[ $id ] ) ) {
				$missing[] = $id;
			}
		}

		sort( $missing, SORT_STRING );

		return $missing;
	}

	/**
	 * Convert the canonical plan's object requirement to an associative map.
	 *
	 * @param mixed $value Canonical requirement value.
	 * @return array<string, mixed>
	 * @throws WorkflowPlanningException When the plan invariant is malformed.
	 */
	private function map( mixed $value ): array {
		if ( is_array( $value ) && ! array_is_list( $value ) ) {
			return $value;
		}

		if ( ! $value instanceof stdClass ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.plan.adapter_requirements' );
		}

		$map = array();
		// @phpstan-ignore-next-line -- Canonical object keys are preserved by native iteration.
		foreach ( $value as $key => $item ) {
			$map[ $key ] = $item;
		}

		return $map;
	}

	/**
	 * Validate the exact adapter-ID grammar used by workflow definitions.
	 *
	 * @param string $id Candidate adapter ID.
	 */
	private function valid_adapter_id( string $id ): bool {
		return strlen( $id ) >= 2
			&& strlen( $id ) <= 64
			&& 1 === preg_match( '/^[a-z][a-z0-9_]*$/D', $id );
	}

	/**
	 * Validate the exact ability-ID grammar used by workflow definitions.
	 *
	 * @param string $id Candidate ability ID.
	 */
	private function valid_ability_id( string $id ): bool {
		return strlen( $id ) <= 128
			&& 1 === preg_match( '#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*$#D', $id );
	}
}
