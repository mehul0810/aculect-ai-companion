<?php
/**
 * Immutable workflow state snapshot.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Carries pure state and plan evidence without persistence or clocks.
 */
final readonly class WorkflowStateSnapshot {

	/**
	 * Create one state snapshot.
	 *
	 * @param WorkflowRunState    $state       Current state.
	 * @param WorkflowPlan|null   $plan        Bound plan when present.
	 * @param WorkflowDryRun|null $dry_run     Bound dry-run when present.
	 * @param string|null         $outcome_code Bounded terminal outcome code.
	 * @throws WorkflowPlanningException When snapshot evidence is inconsistent.
	 */
	public function __construct(
		private WorkflowRunState $state,
		private ?WorkflowPlan $plan = null,
		private ?WorkflowDryRun $dry_run = null,
		private ?string $outcome_code = null
	) {
		if ( null !== $dry_run && ( null === $plan || ! hash_equals( $plan->hash(), $dry_run->plan_hash() ) ) ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.dry_run' );
		}

		if ( null !== $outcome_code && 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $outcome_code ) ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.outcome_code' );
		}

		$requires_plan = in_array(
			$state,
			array(
				WorkflowRunState::PREPARED,
				WorkflowRunState::WAITING_FOR_INPUT,
				WorkflowRunState::DRY_RUN_READY,
				WorkflowRunState::WAITING_FOR_APPROVAL,
				WorkflowRunState::RUNNING,
				WorkflowRunState::COMPLETED,
			),
			true
		);
		if ( $requires_plan && null === $plan ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.plan' );
		}

		if ( WorkflowRunState::CREATED === $state && ( null !== $plan || null !== $dry_run || null !== $outcome_code ) ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.state' );
		}

		if ( in_array( $state, array( WorkflowRunState::PREPARED, WorkflowRunState::WAITING_FOR_INPUT ), true ) && ( null !== $dry_run || null !== $outcome_code ) ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.state' );
		}

		if ( WorkflowRunState::WAITING_FOR_INPUT === $state && null !== $plan && array() === $plan->missing_paths() ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.state' );
		}

		if ( in_array( $state, array( WorkflowRunState::PREPARED, WorkflowRunState::DRY_RUN_READY, WorkflowRunState::WAITING_FOR_APPROVAL, WorkflowRunState::RUNNING, WorkflowRunState::COMPLETED ), true )
			&& null !== $plan
			&& ! $plan->is_input_ready()
		) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.plan' );
		}

		if ( in_array( $state, array( WorkflowRunState::DRY_RUN_READY, WorkflowRunState::WAITING_FOR_APPROVAL ), true ) && ( null === $dry_run || null !== $outcome_code ) ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.dry_run' );
		}

		if ( WorkflowRunState::WAITING_FOR_APPROVAL === $state && null !== $plan && array() === $plan->approval_gate_step_ids() ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.approval_gates' );
		}

		if ( WorkflowRunState::RUNNING === $state && ( null !== $outcome_code || ( null !== $plan && array() !== $plan->approval_gate_step_ids() && null === $dry_run ) ) ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.state' );
		}

		if ( $state->is_terminal() && null === $outcome_code ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.outcome_code' );
		}
	}

	/**
	 * Return a fresh created snapshot.
	 */
	public static function created(): self {
		return new self( WorkflowRunState::CREATED );
	}

	/**
	 * Return current state.
	 */
	public function state(): WorkflowRunState {
		return $this->state;
	}

	/**
	 * Return bound plan when present.
	 */
	public function plan(): ?WorkflowPlan {
		return $this->plan;
	}

	/**
	 * Return bound dry-run when present.
	 */
	public function dry_run(): ?WorkflowDryRun {
		return $this->dry_run;
	}

	/**
	 * Return bounded terminal outcome code when present.
	 */
	public function outcome_code(): ?string {
		return $this->outcome_code;
	}
}
