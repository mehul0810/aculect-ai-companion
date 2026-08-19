<?php
/**
 * Pure deterministic workflow transition guard.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Enforces the shared lifecycle contract without storage or execution.
 */
final class WorkflowTransitionGuard {

	/**
	 * Apply one transition or throw a bounded fail-closed exception.
	 *
	 * @param WorkflowStateSnapshot     $snapshot Current immutable state.
	 * @param WorkflowTransitionRequest $request Requested transition and evidence.
	 * @throws WorkflowPlanningException When the transition or evidence is invalid.
	 */
	public function transition( WorkflowStateSnapshot $snapshot, WorkflowTransitionRequest $request ): WorkflowTransitionResult {
		$this->assert_snapshot( $snapshot );

		if ( $snapshot->state()->is_terminal() ) {
			throw new WorkflowPlanningException( 'terminal_state', '$.state' );
		}

		$this->assert_plan_binding( $snapshot, $request );
		$this->assert_action_allowed( $snapshot->state(), $request->action() );

		return match ( $request->action() ) {
			WorkflowTransitionAction::PREPARE => $this->prepare( $request ),
			WorkflowTransitionAction::RESUME_WITH_INPUT => $this->resume_with_input( $snapshot, $request ),
			WorkflowTransitionAction::BUILD_DRY_RUN => $this->build_dry_run( $snapshot, $request ),
			WorkflowTransitionAction::REQUEST_APPROVAL => $this->request_approval( $snapshot, $request ),
			WorkflowTransitionAction::RESUME_WITH_APPROVAL,
			WorkflowTransitionAction::START => $this->start( $snapshot, $request ),
			WorkflowTransitionAction::COMPLETE => $this->finish_running( $snapshot, $request, 'completed' ),
			WorkflowTransitionAction::FAIL => $this->fail( $snapshot, $request ),
			WorkflowTransitionAction::CANCEL => $this->cancel( $snapshot, $request ),
		};
	}

	/**
	 * Validate snapshot shape before state-specific checks.
	 *
	 * @param WorkflowStateSnapshot $snapshot Current immutable state.
	 * @throws WorkflowPlanningException When the snapshot is internally inconsistent.
	 */
	private function assert_snapshot( WorkflowStateSnapshot $snapshot ): void {
		$requires_plan = in_array(
			$snapshot->state(),
			array(
				WorkflowRunState::PREPARED,
				WorkflowRunState::WAITING_FOR_INPUT,
				WorkflowRunState::DRY_RUN_READY,
				WorkflowRunState::WAITING_FOR_APPROVAL,
				WorkflowRunState::RUNNING,
			),
			true
		);
		if ( $requires_plan && null === $snapshot->plan() ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.plan' );
		}

		if ( in_array( $snapshot->state(), array( WorkflowRunState::DRY_RUN_READY, WorkflowRunState::WAITING_FOR_APPROVAL ), true ) && null === $snapshot->dry_run() ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.dry_run' );
		}
	}

	/**
	 * Enforce plan identity before action-specific readiness checks.
	 *
	 * @param WorkflowStateSnapshot     $snapshot Current immutable state.
	 * @param WorkflowTransitionRequest $request  Requested transition and evidence.
	 * @throws WorkflowPlanningException When supplied evidence is bound to another plan.
	 */
	private function assert_plan_binding( WorkflowStateSnapshot $snapshot, WorkflowTransitionRequest $request ): void {
		$current = $snapshot->plan();
		$next    = $request->plan();
		if ( null !== $current && null !== $next && ! hash_equals( $current->hash(), $next->hash() ) ) {
			if ( WorkflowRunState::WAITING_FOR_INPUT !== $snapshot->state()
				|| WorkflowTransitionAction::RESUME_WITH_INPUT !== $request->action()
				|| ! $this->same_definition_plan( $current, $next )
			) {
				throw new WorkflowPlanningException( 'plan_binding_mismatch', '$.plan_hash' );
			}
		}

		$dry_run = $request->dry_run();
		$plan    = $next ?? $current;
		if ( null !== $dry_run && ( null === $plan || ! hash_equals( $plan->hash(), $dry_run->plan_hash() ) ) ) {
			throw new WorkflowPlanningException( 'plan_binding_mismatch', '$.dry_run.plan_hash' );
		}
	}

	/**
	 * Enforce the closed state/action matrix.
	 *
	 * @param WorkflowRunState         $state  Current run state.
	 * @param WorkflowTransitionAction $action Requested action.
	 * @throws WorkflowPlanningException When the action is not valid for the state.
	 */
	private function assert_action_allowed( WorkflowRunState $state, WorkflowTransitionAction $action ): void {
		$allowed = match ( $state ) {
			WorkflowRunState::CREATED => array( WorkflowTransitionAction::PREPARE, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ),
			WorkflowRunState::WAITING_FOR_INPUT => array( WorkflowTransitionAction::RESUME_WITH_INPUT, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ),
			WorkflowRunState::PREPARED => array( WorkflowTransitionAction::BUILD_DRY_RUN, WorkflowTransitionAction::START, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ),
			WorkflowRunState::DRY_RUN_READY => array( WorkflowTransitionAction::BUILD_DRY_RUN, WorkflowTransitionAction::REQUEST_APPROVAL, WorkflowTransitionAction::START, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ),
			WorkflowRunState::WAITING_FOR_APPROVAL => array( WorkflowTransitionAction::RESUME_WITH_APPROVAL, WorkflowTransitionAction::START, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ),
			WorkflowRunState::RUNNING => array( WorkflowTransitionAction::COMPLETE, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ),
			default => array(),
		};

		if ( ! in_array( $action, $allowed, true ) ) {
			throw new WorkflowPlanningException( 'invalid_transition', '$.action' );
		}
	}

	/**
	 * Prepare a new plan.
	 *
	 * @param WorkflowTransitionRequest $request Requested transition and plan.
	 * @throws WorkflowPlanningException When the plan is absent or invalid.
	 */
	private function prepare( WorkflowTransitionRequest $request ): WorkflowTransitionResult {
		$plan  = $this->require_plan( $request );
		$state = $plan->missing_paths() ? WorkflowRunState::WAITING_FOR_INPUT : WorkflowRunState::PREPARED;
		if ( WorkflowRunState::PREPARED === $state ) {
			$this->assert_input_valid( $plan );
		}

		return new WorkflowTransitionResult( new WorkflowStateSnapshot( $state, $plan ), true );
	}

	/**
	 * Replace incomplete input before the plan is prepared.
	 *
	 * @param WorkflowStateSnapshot     $snapshot Current immutable state.
	 * @param WorkflowTransitionRequest $request  Requested transition and replacement plan.
	 * @throws WorkflowPlanningException When the replacement plan is absent or invalid.
	 */
	private function resume_with_input( WorkflowStateSnapshot $snapshot, WorkflowTransitionRequest $request ): WorkflowTransitionResult {
		$plan  = $this->require_plan( $request );
		$state = $plan->missing_paths() ? WorkflowRunState::WAITING_FOR_INPUT : WorkflowRunState::PREPARED;
		if ( WorkflowRunState::PREPARED === $state ) {
			$this->assert_input_valid( $plan );
		}
		$changed = null === $snapshot->plan() || ! hash_equals( $snapshot->plan()->hash(), $plan->hash() );

		return new WorkflowTransitionResult( new WorkflowStateSnapshot( $state, $plan ), $changed || WorkflowRunState::PREPARED === $state );
	}

	/**
	 * Build or re-evaluate an exact deterministic dry-run.
	 *
	 * @param WorkflowStateSnapshot     $snapshot Current immutable state.
	 * @param WorkflowTransitionRequest $request  Requested transition and optional dry-run.
	 * @throws WorkflowPlanningException When the plan is incomplete or evidence is invalid.
	 */
	private function build_dry_run( WorkflowStateSnapshot $snapshot, WorkflowTransitionRequest $request ): WorkflowTransitionResult {
		$plan = $request->plan() ?? $snapshot->plan();
		if ( null === $plan ) {
			throw new WorkflowPlanningException( 'plan_required', '$.plan' );
		}
		$this->assert_input_ready( $plan );
		$dry_run = $request->dry_run() ?? WorkflowDryRun::from_plan( $plan );

		if ( WorkflowRunState::DRY_RUN_READY === $snapshot->state()
			&& null !== $snapshot->dry_run()
			&& hash_equals( $snapshot->dry_run()->canonical_json(), $dry_run->canonical_json() )
		) {
			return new WorkflowTransitionResult( $snapshot, false );
		}

		return new WorkflowTransitionResult( new WorkflowStateSnapshot( WorkflowRunState::DRY_RUN_READY, $plan, $dry_run ), true );
	}

	/**
	 * Move a dry-run with gates to approval waiting.
	 *
	 * @param WorkflowStateSnapshot     $snapshot Current immutable state.
	 * @param WorkflowTransitionRequest $request  Requested transition and optional dry-run.
	 * @throws WorkflowPlanningException When no gate or dry-run is available.
	 */
	private function request_approval( WorkflowStateSnapshot $snapshot, WorkflowTransitionRequest $request ): WorkflowTransitionResult {
		$plan = $snapshot->plan();
		if ( null === $plan || array() === $plan->approval_gate_step_ids() ) {
			throw new WorkflowPlanningException( 'invalid_transition', '$.approval_gates' );
		}

		$dry_run = $request->dry_run() ?? $snapshot->dry_run();
		if ( null === $dry_run ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.dry_run' );
		}

		return new WorkflowTransitionResult( new WorkflowStateSnapshot( WorkflowRunState::WAITING_FOR_APPROVAL, $plan, $dry_run ), true );
	}

	/**
	 * Start execution only with exact approval and readiness evidence.
	 *
	 * @param WorkflowStateSnapshot     $snapshot Current immutable state.
	 * @param WorkflowTransitionRequest $request  Requested transition and evidence.
	 * @throws WorkflowPlanningException When approval, validation, or requirements are not ready.
	 */
	private function start( WorkflowStateSnapshot $snapshot, WorkflowTransitionRequest $request ): WorkflowTransitionResult {
		$plan = $snapshot->plan();
		if ( null === $plan ) {
			throw new WorkflowPlanningException( 'plan_required', '$.plan' );
		}
		$this->assert_input_ready( $plan );

		$gates     = $plan->approval_gate_step_ids();
		$approval  = $request->approval();
		$readiness = $request->readiness();
		if ( null === $readiness ) {
			if ( $plan->requires_validation() ) {
				throw new WorkflowPlanningException( 'validation_unchecked', '$.readiness' );
			}
			if ( array() !== $gates && ( null === $approval || ! $approval->matches( $plan ) ) ) {
				throw new WorkflowPlanningException( null === $approval ? 'approval_required' : 'approval_mismatch', '$.approval' );
			}
			throw new WorkflowPlanningException( 'requirements_unchecked', '$.readiness' );
		}

		$error = $readiness->binding_error_for( $plan );
		if ( null !== $error ) {
			throw new WorkflowPlanningException( $error, '$.readiness' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal evidence code.
		}
		$error = $readiness->validation_error_for( $plan );
		if ( null !== $error ) {
			throw new WorkflowPlanningException( $error, '$.readiness' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal evidence code.
		}

		if ( array() !== $gates && WorkflowRunState::WAITING_FOR_APPROVAL !== $snapshot->state() ) {
			throw new WorkflowPlanningException( 'approval_required', '$.approval' );
		}

		if ( array() !== $gates && ( null === $approval || ! $approval->matches( $plan ) ) ) {
			throw new WorkflowPlanningException( null === $approval ? 'approval_required' : 'approval_mismatch', '$.approval' );
		}

		$error = $readiness->requirements_error();
		if ( null !== $error ) {
			throw new WorkflowPlanningException( $error, '$.readiness' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal evidence code.
		}

		return new WorkflowTransitionResult( new WorkflowStateSnapshot( WorkflowRunState::RUNNING, $plan, $snapshot->dry_run() ), true );
	}

	/**
	 * Complete or fail a running plan using bound evidence.
	 *
	 * @param WorkflowStateSnapshot     $snapshot Current immutable state.
	 * @param WorkflowTransitionRequest $request  Requested transition and execution evidence.
	 * @param string                    $outcome  Expected terminal outcome.
	 * @throws WorkflowPlanningException When the execution evidence does not match.
	 */
	private function finish_running( WorkflowStateSnapshot $snapshot, WorkflowTransitionRequest $request, string $outcome ): WorkflowTransitionResult {
		$plan      = $snapshot->plan();
		$execution = $request->execution();
		if ( null === $plan || null === $execution || ! $execution->matches( $plan ) || $outcome !== $execution->outcome() ) {
			throw new WorkflowPlanningException( 'evidence_mismatch', '$.execution_evidence' );
		}

		$state = 'completed' === $outcome ? WorkflowRunState::COMPLETED : WorkflowRunState::FAILED;

		return new WorkflowTransitionResult( new WorkflowStateSnapshot( $state, $plan, $snapshot->dry_run(), $execution->code() ), true );
	}

	/**
	 * Fail a non-terminal state with bounded evidence.
	 *
	 * @param WorkflowStateSnapshot     $snapshot Current immutable state.
	 * @param WorkflowTransitionRequest $request  Requested transition and failure evidence.
	 * @throws WorkflowPlanningException When required failure evidence is absent.
	 */
	private function fail( WorkflowStateSnapshot $snapshot, WorkflowTransitionRequest $request ): WorkflowTransitionResult {
		if ( WorkflowRunState::RUNNING === $snapshot->state() ) {
			return $this->finish_running( $snapshot, $request, 'failed' );
		}

		$code = $request->failure_code();
		if ( null === $code ) {
			throw new WorkflowPlanningException( 'failure_code_invalid', '$.failure_code' );
		}

		return new WorkflowTransitionResult(
			new WorkflowStateSnapshot( WorkflowRunState::FAILED, $snapshot->plan(), $snapshot->dry_run(), $code ),
			true
		);
	}

	/**
	 * Cancel only before execution or at an externally proven running safe boundary.
	 *
	 * @param WorkflowStateSnapshot     $snapshot Current immutable state.
	 * @param WorkflowTransitionRequest $request  Requested transition and optional evidence.
	 * @throws WorkflowPlanningException When running work is not at a proven safe boundary.
	 */
	private function cancel( WorkflowStateSnapshot $snapshot, WorkflowTransitionRequest $request ): WorkflowTransitionResult {
		$code = 'cancelled';
		if ( WorkflowRunState::RUNNING === $snapshot->state() ) {
			$plan      = $snapshot->plan();
			$execution = $request->execution();
			if ( null === $plan
				|| null === $execution
				|| ! $execution->matches( $plan )
				|| 'cancelled' !== $execution->outcome()
				|| ! $execution->safe_boundary()
			) {
				throw new WorkflowPlanningException( 'cancel_not_allowed', '$.execution_evidence' );
			}
			$code = $execution->code();
		}

		return new WorkflowTransitionResult(
			new WorkflowStateSnapshot( WorkflowRunState::CANCELLED, $snapshot->plan(), $snapshot->dry_run(), $code ),
			true
		);
	}

	/**
	 * Require a request plan.
	 *
	 * @param WorkflowTransitionRequest $request Requested transition.
	 * @throws WorkflowPlanningException When no plan is supplied.
	 */
	private function require_plan( WorkflowTransitionRequest $request ): WorkflowPlan {
		$plan = $request->plan();
		if ( null === $plan ) {
			throw new WorkflowPlanningException( 'plan_required', '$.plan' );
		}

		return $plan;
	}

	/**
	 * Reject schema-invalid supplied input after missing-path classification.
	 *
	 * @param WorkflowPlan $plan Candidate plan.
	 * @throws WorkflowPlanningException When input violates the definition schema.
	 */
	private function assert_input_valid( WorkflowPlan $plan ): void {
		if ( array() !== $plan->invalid_paths() ) {
			throw new WorkflowPlanningException( 'input_invalid', $plan->invalid_paths()[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
		}
	}

	/**
	 * Require complete and schema-valid input.
	 *
	 * @param WorkflowPlan $plan Candidate plan.
	 * @throws WorkflowPlanningException When input is invalid or incomplete.
	 */
	private function assert_input_ready( WorkflowPlan $plan ): void {
		if ( array() !== $plan->missing_paths() ) {
			throw new WorkflowPlanningException( 'missing_input', $plan->missing_paths()[0] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
		}
		$this->assert_input_valid( $plan );
	}

	/**
	 * Whether two plans differ only by normalized input identity.
	 *
	 * @param WorkflowPlan $left  Existing plan.
	 * @param WorkflowPlan $right Replacement plan.
	 */
	private function same_definition_plan( WorkflowPlan $left, WorkflowPlan $right ): bool {
		$left_identity  = $left->identity();
		$right_identity = $right->identity();
		unset( $left_identity['normalized_input_hash'], $right_identity['normalized_input_hash'] );

		$canonicalizer = new WorkflowPlanningCanonicalizer();

		return $canonicalizer->normalize_and_encode( $left_identity )['json'] === $canonicalizer->normalize_and_encode( $right_identity )['json'];
	}
}
