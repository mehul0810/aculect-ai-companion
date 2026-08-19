<?php
/**
 * Pure workflow transition guard tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Planning;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Planning\WorkflowApprovalEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowAvailabilitySnapshot;
use Aculect\AICompanion\Workflows\Planning\WorkflowDryRun;
use Aculect\AICompanion\Workflows\Planning\WorkflowExecutionEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanReadinessEvaluator;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanningException;
use Aculect\AICompanion\Workflows\Planning\WorkflowReadinessEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use Aculect\AICompanion\Workflows\Planning\WorkflowStateSnapshot;
use Aculect\AICompanion\Workflows\Planning\WorkflowTransitionAction;
use Aculect\AICompanion\Workflows\Planning\WorkflowTransitionGuard;
use Aculect\AICompanion\Workflows\Planning\WorkflowTransitionRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks the nine-state lifecycle, evidence binding, and error precedence.
 */
final class WorkflowTransitionGuardTest extends TestCase {

	private WorkflowTransitionGuard $guard;

	protected function setUp(): void {
		$this->guard = new WorkflowTransitionGuard();
	}

	public function test_complete_approval_lifecycle_reaches_terminal_state(): void {
		$plan     = $this->ordered_plan( '{"brief":"Lifecycle"}' );
		$snapshot = WorkflowStateSnapshot::created();

		$snapshot = $this->guard->transition(
			$snapshot,
			new WorkflowTransitionRequest( WorkflowTransitionAction::PREPARE, plan: $plan )
		)->snapshot();
		self::assertSame( WorkflowRunState::PREPARED, $snapshot->state() );

		$dry_run  = WorkflowDryRun::from_plan( $plan );
		$snapshot = $this->guard->transition(
			$snapshot,
			new WorkflowTransitionRequest( WorkflowTransitionAction::BUILD_DRY_RUN, plan: $plan, dry_run: $dry_run )
		)->snapshot();
		self::assertSame( WorkflowRunState::DRY_RUN_READY, $snapshot->state() );

		$snapshot = $this->guard->transition(
			$snapshot,
			new WorkflowTransitionRequest( WorkflowTransitionAction::REQUEST_APPROVAL, plan: $plan, dry_run: $dry_run )
		)->snapshot();
		self::assertSame( WorkflowRunState::WAITING_FOR_APPROVAL, $snapshot->state() );

		$snapshot = $this->guard->transition(
			$snapshot,
			new WorkflowTransitionRequest(
				WorkflowTransitionAction::RESUME_WITH_APPROVAL,
				plan: $plan,
				approval: $this->approval( $plan ),
				readiness: $this->readiness( $plan )
			)
		)->snapshot();
		self::assertSame( WorkflowRunState::RUNNING, $snapshot->state() );

		$snapshot = $this->guard->transition(
			$snapshot,
			new WorkflowTransitionRequest(
				WorkflowTransitionAction::COMPLETE,
				plan: $plan,
				execution: new WorkflowExecutionEvidence( $plan->hash(), 'completed', 'workflow_completed' )
			)
		)->snapshot();

		self::assertSame( WorkflowRunState::COMPLETED, $snapshot->state() );
		self::assertSame( 'workflow_completed', $snapshot->outcome_code() );

		$this->expect_code(
			'terminal_state',
			fn () => $this->guard->transition( $snapshot, new WorkflowTransitionRequest( WorkflowTransitionAction::CANCEL ) )
		);
	}

	public function test_missing_input_can_be_replaced_only_before_prepared(): void {
		$missing  = $this->ordered_plan( '{}' );
		$complete = $this->ordered_plan( '{"brief":"Ready"}' );
		$snapshot = $this->guard->transition(
			WorkflowStateSnapshot::created(),
			new WorkflowTransitionRequest( WorkflowTransitionAction::PREPARE, plan: $missing )
		)->snapshot();
		self::assertSame( WorkflowRunState::WAITING_FOR_INPUT, $snapshot->state() );

		$snapshot = $this->guard->transition(
			$snapshot,
			new WorkflowTransitionRequest( WorkflowTransitionAction::RESUME_WITH_INPUT, plan: $complete )
		)->snapshot();
		self::assertSame( WorkflowRunState::PREPARED, $snapshot->state() );

		$changed = $this->ordered_plan( '{"brief":"Different"}' );
		$this->expect_code(
			'plan_binding_mismatch',
			fn () => $this->guard->transition(
				$snapshot,
				new WorkflowTransitionRequest( WorkflowTransitionAction::BUILD_DRY_RUN, plan: $changed )
			)
		);
	}

	public function test_same_plan_repeated_dry_run_is_an_idempotent_noop(): void {
		$plan     = $this->proposal_plan();
		$prepared = new WorkflowStateSnapshot( WorkflowRunState::PREPARED, $plan );
		$first    = $this->guard->transition(
			$prepared,
			new WorkflowTransitionRequest( WorkflowTransitionAction::BUILD_DRY_RUN, plan: $plan )
		);
		$second   = $this->guard->transition(
			$first->snapshot(),
			new WorkflowTransitionRequest( WorkflowTransitionAction::BUILD_DRY_RUN, plan: $plan )
		);

		self::assertTrue( $first->changed() );
		self::assertFalse( $second->changed() );
		self::assertSame( $first->snapshot(), $second->snapshot() );
	}

	public function test_start_error_precedence_is_validation_then_approval_then_requirements(): void {
		$plan     = $this->ordered_plan( '{"brief":"Precedence"}' );
		$dry_run  = WorkflowDryRun::from_plan( $plan );
		$snapshot = new WorkflowStateSnapshot( WorkflowRunState::WAITING_FOR_APPROVAL, $plan, $dry_run );

		$this->expect_code(
			'validation_unchecked',
			fn () => $this->guard->transition( $snapshot, new WorkflowTransitionRequest( WorkflowTransitionAction::START, plan: $plan ) )
		);

		$validation_only = WorkflowReadinessEvidence::unchecked( $plan, true );
		$this->expect_code(
			'approval_required',
			fn () => $this->guard->transition(
				$snapshot,
				new WorkflowTransitionRequest( WorkflowTransitionAction::START, plan: $plan, readiness: $validation_only )
			)
		);

		$this->expect_code(
			'requirements_unchecked',
			fn () => $this->guard->transition(
				$snapshot,
				new WorkflowTransitionRequest(
					WorkflowTransitionAction::START,
					plan: $plan,
					approval: $this->approval( $plan ),
					readiness: $validation_only
				)
			)
		);
	}

	public function test_missing_input_precedes_invalid_input_and_gated_plans_cannot_skip_dry_run_approval(): void {
		$incomplete = $this->ordered_plan( '{"extra":true}' );
		$prepared   = $this->guard->transition(
			WorkflowStateSnapshot::created(),
			new WorkflowTransitionRequest( WorkflowTransitionAction::PREPARE, plan: $incomplete )
		)->snapshot();

		self::assertSame( WorkflowRunState::WAITING_FOR_INPUT, $prepared->state() );
		self::assertSame( array( '$.brief' ), $incomplete->missing_paths() );
		self::assertSame( array( '$.extra' ), $incomplete->invalid_paths() );

		$plan = $this->ordered_plan( '{"brief":"Cannot skip approval"}' );
		$this->expect_code(
			'approval_required',
			fn () => $this->guard->transition(
				new WorkflowStateSnapshot( WorkflowRunState::PREPARED, $plan ),
				new WorkflowTransitionRequest(
					WorkflowTransitionAction::START,
					plan: $plan,
					approval: $this->approval( $plan ),
					readiness: $this->readiness( $plan )
				)
			)
		);
	}

	public function test_evidence_binding_mismatch_precedes_approval_and_readiness(): void {
		$plan      = $this->ordered_plan( '{"brief":"First"}' );
		$other     = $this->ordered_plan( '{"brief":"Other"}' );
		$dry_run   = WorkflowDryRun::from_plan( $plan );
		$snapshot  = new WorkflowStateSnapshot( WorkflowRunState::WAITING_FOR_APPROVAL, $plan, $dry_run );
		$readiness = $this->readiness( $other );

		$this->expect_code(
			'evidence_mismatch',
			fn () => $this->guard->transition(
				$snapshot,
				new WorkflowTransitionRequest(
					WorkflowTransitionAction::START,
					plan: $plan,
					approval: $this->approval( $other ),
					readiness: $readiness
				)
			)
		);
	}

	public function test_requirement_gate_and_execution_evidence_tampering_fails_closed(): void {
		$plan     = $this->ordered_plan( '{"brief":"Bound evidence"}' );
		$dry_run  = WorkflowDryRun::from_plan( $plan );
		$snapshot = new WorkflowStateSnapshot( WorkflowRunState::WAITING_FOR_APPROVAL, $plan, $dry_run );

		$this->expect_code(
			'approval_mismatch',
			fn () => $this->guard->transition(
				$snapshot,
				new WorkflowTransitionRequest(
					WorkflowTransitionAction::START,
					plan: $plan,
					approval: new WorkflowApprovalEvidence( $plan->hash(), array( 'prepare_content' ), 'tampered-gate', true ),
					readiness: $this->readiness( $plan )
				)
			)
		);

		$other = $this->ordered_plan( '{"brief":"Different execution"}' );
		$this->expect_code(
			'evidence_mismatch',
			fn () => $this->guard->transition(
				new WorkflowStateSnapshot( WorkflowRunState::RUNNING, $plan, $dry_run ),
				new WorkflowTransitionRequest(
					WorkflowTransitionAction::COMPLETE,
					plan: $plan,
					execution: new WorkflowExecutionEvidence( $other->hash(), 'completed', 'completed' )
				)
			)
		);
	}

	public function test_malformed_state_snapshots_fail_closed(): void {
		$complete = $this->ordered_plan( '{"brief":"Snapshot"}' );
		$missing  = $this->ordered_plan( '{}' );
		$proposal = $this->proposal_plan();
		$dry_run  = WorkflowDryRun::from_plan( $complete );

		$invalid = array(
			fn () => new WorkflowStateSnapshot( WorkflowRunState::CREATED, $complete ),
			fn () => new WorkflowStateSnapshot( WorkflowRunState::PREPARED ),
			fn () => new WorkflowStateSnapshot( WorkflowRunState::PREPARED, $missing ),
			fn () => new WorkflowStateSnapshot( WorkflowRunState::WAITING_FOR_INPUT, $complete ),
			fn () => new WorkflowStateSnapshot( WorkflowRunState::DRY_RUN_READY, $complete ),
			fn () => new WorkflowStateSnapshot( WorkflowRunState::WAITING_FOR_APPROVAL, $proposal, WorkflowDryRun::from_plan( $proposal ) ),
			fn () => new WorkflowStateSnapshot( WorkflowRunState::RUNNING, $complete ),
			fn () => new WorkflowStateSnapshot( WorkflowRunState::COMPLETED, $complete, $dry_run ),
			fn () => new WorkflowStateSnapshot( WorkflowRunState::FAILED ),
		);

		foreach ( $invalid as $factory ) {
			$this->expect_code( 'invalid_snapshot', $factory );
		}
	}

	public function test_running_cancel_requires_safe_bound_exact_plan_evidence(): void {
		$plan    = $this->proposal_plan();
		$running = new WorkflowStateSnapshot( WorkflowRunState::RUNNING, $plan );

		$this->expect_code(
			'cancel_not_allowed',
			fn () => $this->guard->transition( $running, new WorkflowTransitionRequest( WorkflowTransitionAction::CANCEL, plan: $plan ) )
		);

		$this->expect_code(
			'cancel_not_allowed',
			fn () => $this->guard->transition(
				$running,
				new WorkflowTransitionRequest(
					WorkflowTransitionAction::CANCEL,
					plan: $plan,
					execution: new WorkflowExecutionEvidence( $plan->hash(), 'cancelled', 'cancelled_by_owner', false )
				)
			)
		);

		$result = $this->guard->transition(
			$running,
			new WorkflowTransitionRequest(
				WorkflowTransitionAction::CANCEL,
				plan: $plan,
				execution: new WorkflowExecutionEvidence( $plan->hash(), 'cancelled', 'cancelled_at_safe_boundary', true )
			)
		);

		self::assertSame( WorkflowRunState::CANCELLED, $result->snapshot()->state() );
	}

	public function test_non_running_fail_and_cancel_do_not_require_readiness(): void {
		$failed    = $this->guard->transition(
			WorkflowStateSnapshot::created(),
			new WorkflowTransitionRequest( WorkflowTransitionAction::FAIL, failure_code: 'preparation_failed' )
		);
		$cancelled = $this->guard->transition(
			WorkflowStateSnapshot::created(),
			new WorkflowTransitionRequest( WorkflowTransitionAction::CANCEL )
		);

		self::assertSame( WorkflowRunState::FAILED, $failed->snapshot()->state() );
		self::assertSame( 'preparation_failed', $failed->snapshot()->outcome_code() );
		self::assertSame( WorkflowRunState::CANCELLED, $cancelled->snapshot()->state() );
	}

	/**
	 * Verify every state recognizes only its declared actions.
	 *
	 * @param WorkflowRunState $state   State to inspect.
	 * @param array            $allowed Allowed actions.
	 * @phpstan-param list<WorkflowTransitionAction> $allowed
	 */
	#[DataProvider( 'transition_matrix_provider' )]
	public function test_transition_matrix_rejects_exactly_disallowed_actions( WorkflowRunState $state, array $allowed ): void {
		$snapshot = $this->snapshot_for( $state );
		foreach ( WorkflowTransitionAction::cases() as $action ) {
			try {
				$this->guard->transition( $snapshot, $this->request_for( $action, $snapshot ) );
				self::assertContains( $action, $allowed, $state->value . ' unexpectedly allowed ' . $action->value );
			} catch ( WorkflowPlanningException $exception ) {
				if ( $state->is_terminal() ) {
					self::assertSame( 'terminal_state', $exception->error_code() );
					continue;
				}
				if ( in_array( $action, $allowed, true ) ) {
					self::assertNotSame( 'invalid_transition', $exception->error_code(), $state->value . ' must recognize ' . $action->value );
				} else {
					self::assertSame( 'invalid_transition', $exception->error_code(), $state->value . ' unexpectedly recognized ' . $action->value );
				}
			}
		}
	}

	/**
	 * Return the exhaustive state/action allowlist.
	 *
	 * @return iterable<string, array{0:WorkflowRunState,1:list<WorkflowTransitionAction>}>
	 */
	public static function transition_matrix_provider(): iterable {
		yield 'created' => array( WorkflowRunState::CREATED, array( WorkflowTransitionAction::PREPARE, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ) );
		yield 'waiting input' => array( WorkflowRunState::WAITING_FOR_INPUT, array( WorkflowTransitionAction::RESUME_WITH_INPUT, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ) );
		yield 'prepared' => array( WorkflowRunState::PREPARED, array( WorkflowTransitionAction::BUILD_DRY_RUN, WorkflowTransitionAction::START, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ) );
		yield 'dry run ready' => array( WorkflowRunState::DRY_RUN_READY, array( WorkflowTransitionAction::BUILD_DRY_RUN, WorkflowTransitionAction::REQUEST_APPROVAL, WorkflowTransitionAction::START, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ) );
		yield 'waiting approval' => array( WorkflowRunState::WAITING_FOR_APPROVAL, array( WorkflowTransitionAction::RESUME_WITH_APPROVAL, WorkflowTransitionAction::START, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ) );
		yield 'running' => array( WorkflowRunState::RUNNING, array( WorkflowTransitionAction::COMPLETE, WorkflowTransitionAction::FAIL, WorkflowTransitionAction::CANCEL ) );
		yield 'completed terminal' => array( WorkflowRunState::COMPLETED, array() );
		yield 'failed terminal' => array( WorkflowRunState::FAILED, array() );
		yield 'cancelled terminal' => array( WorkflowRunState::CANCELLED, array() );
	}

	private function snapshot_for( WorkflowRunState $state ): WorkflowStateSnapshot {
		$plan    = WorkflowRunState::WAITING_FOR_INPUT === $state ? $this->ordered_plan( '{}' ) : $this->ordered_plan( '{"brief":"Matrix"}' );
		$dry_run = WorkflowDryRun::from_plan( $plan );

		return match ( $state ) {
			WorkflowRunState::CREATED => WorkflowStateSnapshot::created(),
			WorkflowRunState::PREPARED, WorkflowRunState::WAITING_FOR_INPUT => new WorkflowStateSnapshot( $state, $plan ),
			WorkflowRunState::RUNNING => new WorkflowStateSnapshot( $state, $plan, $dry_run ),
			WorkflowRunState::DRY_RUN_READY, WorkflowRunState::WAITING_FOR_APPROVAL => new WorkflowStateSnapshot( $state, $plan, $dry_run ),
			default => new WorkflowStateSnapshot( $state, $plan, $dry_run, 'terminal_fixture' ),
		};
	}

	private function request_for( WorkflowTransitionAction $action, WorkflowStateSnapshot $snapshot ): WorkflowTransitionRequest {
		$plan = $snapshot->plan() ?? $this->ordered_plan( '{"brief":"Matrix"}' );

		return match ( $action ) {
			WorkflowTransitionAction::PREPARE,
			WorkflowTransitionAction::RESUME_WITH_INPUT => new WorkflowTransitionRequest( $action, plan: $plan ),
			WorkflowTransitionAction::BUILD_DRY_RUN => new WorkflowTransitionRequest( $action, plan: $plan, dry_run: WorkflowDryRun::from_plan( $plan ) ),
			WorkflowTransitionAction::REQUEST_APPROVAL => new WorkflowTransitionRequest( $action, plan: $plan, dry_run: WorkflowDryRun::from_plan( $plan ) ),
			WorkflowTransitionAction::RESUME_WITH_APPROVAL,
			WorkflowTransitionAction::START => new WorkflowTransitionRequest( $action, plan: $plan, approval: $this->approval( $plan ), readiness: $this->readiness( $plan ) ),
			WorkflowTransitionAction::COMPLETE => new WorkflowTransitionRequest( $action, plan: $plan, execution: new WorkflowExecutionEvidence( $plan->hash(), 'completed', 'completed' ) ),
			WorkflowTransitionAction::FAIL => WorkflowRunState::RUNNING === $snapshot->state()
				? new WorkflowTransitionRequest( $action, plan: $plan, execution: new WorkflowExecutionEvidence( $plan->hash(), 'failed', 'failed' ) )
				: new WorkflowTransitionRequest( $action, plan: $plan, failure_code: 'failed' ),
			WorkflowTransitionAction::CANCEL => WorkflowRunState::RUNNING === $snapshot->state()
				? new WorkflowTransitionRequest( $action, plan: $plan, execution: new WorkflowExecutionEvidence( $plan->hash(), 'cancelled', 'cancelled', true ) )
				: new WorkflowTransitionRequest( $action, plan: $plan ),
		};
	}

	private function approval( WorkflowPlan $plan ): WorkflowApprovalEvidence {
		$evidence = new WorkflowApprovalEvidence( $plan->hash(), $plan->approval_gate_step_ids(), 'approval-fixture', true );
		self::assertSame( 'approval-fixture', $evidence->reference() );

		return $evidence;
	}

	private function readiness( WorkflowPlan $plan ): WorkflowReadinessEvidence {
		$identity = $plan->identity();

		return ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$plan,
			WorkflowAvailabilitySnapshot::from_value(
				array(
					'adapters'  => $identity['adapter_requirements'],
					'abilities' => $identity['ability_requirements'],
				)
			),
			true
		);
	}

	private function ordered_plan( string $input_json ): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build(
			$this->fixture( 'ordered-multi-step-v1.json' ),
			WorkflowInputContract::from_json( $input_json )
		);
	}

	private function proposal_plan(): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build(
			$this->fixture( 'proposal-only-v1.json' ),
			WorkflowInputContract::from_json( '{"post_id":5}' )
		);
	}

	private function fixture( string $name ): WorkflowDefinition {
		$json = file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned local fixture.
		self::assertIsString( $json );

		return WorkflowDefinition::from_json( $json );
	}

	/**
	 * Assert a closure throws one stable planning code.
	 *
	 * @param string   $code     Expected code.
	 * @param callable $callback Operation.
	 */
	private function expect_code( string $code, callable $callback ): void {
		try {
			$callback();
			self::fail( 'Expected planning failure: ' . $code );
		} catch ( WorkflowPlanningException $exception ) {
			self::assertSame( $code, $exception->error_code() );
		}
	}
}
