<?php
/**
 * Durable custom workflow runner.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionCommentThrowTag.Missing, WordPress.Security.EscapeOutput.ExceptionNotEscaped

namespace Aculect\AICompanion\Workflows\Execution;

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterRegistry;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRecord;
use Aculect\AICompanion\Workflows\Planning\WorkflowApprovalEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowDryRun;
use Aculect\AICompanion\Workflows\Planning\WorkflowExecutionEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowReadinessEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use Aculect\AICompanion\Workflows\Planning\WorkflowStateSnapshot;
use Aculect\AICompanion\Workflows\Planning\WorkflowTransitionAction;
use Aculect\AICompanion\Workflows\Planning\WorkflowTransitionGuard;
use Aculect\AICompanion\Workflows\Planning\WorkflowTransitionRequest;
use Closure;
use Throwable;
use stdClass;

/**
 * Orchestrates preparation, approval, gateway-only step dispatch, and durable
 * state transitions. One call executes at most one step, keeping retries and
 * cancellation observable and bounded.
 */
final class WorkflowRunner {

	private const WAITING_SECONDS = 604800;

	public function __construct(
		private WorkflowRunStoreInterface $store,
		private WorkflowAdapterRegistry $adapters = new WorkflowAdapterRegistry(),
		private WorkflowTransitionGuard $guard = new WorkflowTransitionGuard(),
		private WorkflowAuditStoreInterface $audit = new NullWorkflowAuditStore(),
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Controlled UTC clock used for waiting and lease expiry decisions.
	 *
	 * @var Closure(): int
	 */
	private Closure $clock;

	/**
	 * Create a durable run pinned to one exact definition and plan.
	 *
	 * @param WorkflowDefinitionRecord $definition Definition catalog record.
	 * @param WorkflowPlan             $plan       Exact normalized plan.
	 * @param WorkflowInputContract    $input      Normalized input.
	 * @param int                      $actor_id   Authenticated actor.
	 * @param WorkflowDryRun|null      $dry_run    Optional already-built dry run.
	 * @param string|null              $run_id     Optional caller idempotency ID.
	 */
	public function create( WorkflowDefinitionRecord $definition, WorkflowPlan $plan, WorkflowInputContract $input, int $actor_id, ?WorkflowDryRun $dry_run = null, ?string $run_id = null ): WorkflowRunRecord {
		$this->assert_definition_plan( $definition, $plan );
		if ( $actor_id < 1 || array() !== $plan->invalid_paths() ) {
			throw new WorkflowRunnerException( 'input_invalid' );
		}
		$this->assert_input_plan( $plan, $input );
		if ( null !== $dry_run && ! hash_equals( $plan->hash(), $dry_run->plan_hash() ) ) {
			throw new WorkflowRunnerException( 'dry_run_mismatch' );
		}
		if ( null !== $dry_run && ! $plan->is_input_ready() ) {
			throw new WorkflowRunnerException( 'dry_run_input_incomplete' );
		}

		$state = array() !== $plan->missing_paths()
			? WorkflowRunState::WAITING_FOR_INPUT
			: ( null !== $dry_run ? WorkflowRunState::DRY_RUN_READY : WorkflowRunState::PREPARED );
		$id    = $run_id ?? $this->new_run_id();
		$until = WorkflowRunState::WAITING_FOR_INPUT === $state ? $this->waiting_expiry() : null;

		try {
			$created = $this->store->create(
				$id,
				$definition->workflow_id(),
				$definition->definition()->to_array()['workflow_version'],
				$definition->definition()->checksum(),
				$plan,
				$input,
				$state,
				$actor_id,
				$dry_run,
				$until
			);
			$this->audit_event( $created, 'run_created', $actor_id );

			return $created;
		} catch ( WorkflowRunStoreException $exception ) {
			throw new WorkflowRunnerException( $exception->error_code() );
		}
	}

	/** Build and persist a deterministic dry run. */
	public function build_dry_run( string $run_id, WorkflowPlan $plan, int $actor_id ): WorkflowRunRecord {
		$record = $this->require_run( $run_id );
		$this->assert_record_plan( $record, $plan );
		if ( WorkflowRunState::PREPARED !== $record->state() && WorkflowRunState::DRY_RUN_READY !== $record->state() ) {
			throw new WorkflowRunnerException( 'invalid_state' );
		}
		$dry_run = WorkflowDryRun::from_plan( $plan );
		$this->guard->transition(
			$this->snapshot( $record, $plan ),
			new WorkflowTransitionRequest( WorkflowTransitionAction::BUILD_DRY_RUN, plan: $plan, dry_run: $dry_run )
		);

		if ( WorkflowRunState::DRY_RUN_READY === $record->state() ) {
			return $record;
		}

		$updated = $this->persist_transition( $record, WorkflowRunState::DRY_RUN_READY, $actor_id );
		$this->audit_event( $updated, 'run_dry_run_ready', $actor_id );

		return $updated;
	}

	/** Move an exact dry run into approval waiting. */
	public function request_approval( string $run_id, WorkflowPlan $plan, int $actor_id ): WorkflowRunRecord {
		$record = $this->require_run( $run_id );
		$this->assert_record_plan( $record, $plan );
		$dry_run = WorkflowDryRun::from_plan( $plan );
		$result  = $this->guard->transition(
			$this->snapshot( $record, $plan ),
			new WorkflowTransitionRequest( WorkflowTransitionAction::REQUEST_APPROVAL, plan: $plan, dry_run: $dry_run )
		);

		$updated = $this->persist_transition( $record, $result->snapshot()->state(), $actor_id, null, $this->waiting_expiry() );
		$this->audit_event( $updated, 'approval_requested', $actor_id );

		return $updated;
	}

	/** Replace incomplete input while retaining the exact definition version. */
	public function resume_with_input( string $run_id, WorkflowPlan $plan, WorkflowInputContract $input, int $actor_id ): WorkflowRunRecord {
		$record = $this->require_run( $run_id );
		$this->assert_record_definition_plan( $record, $plan );
		if ( WorkflowRunState::WAITING_FOR_INPUT !== $record->state() ) {
			throw new WorkflowRunnerException( 'invalid_state' );
		}
		$this->assert_waiting_not_expired( $record );
		if ( ! $plan->is_input_ready() ) {
			throw new WorkflowRunnerException( 'input_incomplete' );
		}
		$this->assert_input_plan( $plan, $input );

		try {
			$updated = $this->store->replace_plan( $run_id, $record->state_version(), $plan, $input, $actor_id );
		} catch ( WorkflowRunStoreException $exception ) {
			throw new WorkflowRunnerException( $exception->error_code() );
		}
		if ( null === $updated ) {
			throw new WorkflowRunnerException( 'state_conflict' );
		}

		$this->audit_event( $updated, 'run_input_resumed', $actor_id );

		return $updated;
	}

	/** Start execution after exact readiness and approval evidence. */
	public function start( string $run_id, WorkflowPlan $plan, WorkflowReadinessEvidence $readiness, int $actor_id, ?WorkflowApprovalEvidence $approval = null ): WorkflowRunRecord {
		$record = $this->require_run( $run_id );
		$this->assert_record_plan( $record, $plan );
		$this->assert_waiting_not_expired( $record );
		$request = new WorkflowTransitionRequest( WorkflowTransitionAction::START, plan: $plan, approval: $approval, readiness: $readiness );
		$result  = $this->guard->transition( $this->snapshot( $record, $plan ), $request );

		$updated = $this->persist_transition( $record, $result->snapshot()->state(), $actor_id );
		$this->audit_event( $updated, 'run_started', $actor_id, null, $approval );

		return $updated;
	}

	/**
	 * Execute at most one dependency-ready step.
	 *
	 * @param WorkflowDefinitionRecord $definition Definition that owns arguments.
	 * @param string                   $run_id     Durable run ID.
	 * @param WorkflowPlan             $plan       Exact pinned plan.
	 * @param array<string, mixed>     $auth       Authenticated gateway context.
	 * @param int                      $actor_id   Current actor.
	 */
	public function execute_next( WorkflowDefinitionRecord $definition, string $run_id, WorkflowPlan $plan, array $auth, int $actor_id ): WorkflowRunExecutionResult {
		$record = $this->require_run( $run_id );
		$this->assert_definition_plan( $definition, $plan );
		$this->assert_record_plan( $record, $plan );
		if ( $record->state()->is_terminal() ) {
			return new WorkflowRunExecutionResult( $record, null, null, false );
		}
		if ( WorkflowRunState::RUNNING !== $record->state() ) {
			throw new WorkflowRunnerException( 'run_not_started' );
		}

		$steps     = $this->store->steps( $run_id );
		$uncertain = $this->uncertain_step( $steps );
		if ( null !== $uncertain ) {
			// A worker may observe a durable uncertainty marker after another
			// worker fenced the adapter result but before it fenced the parent
			// run. Fail the parent deterministically instead of reporting a
			// misleading dependency deadlock.
			return $this->fail_run( $record, $plan, 'execution_uncertain', $actor_id );
		}
		$next = $this->next_step( $plan, $steps );
		if ( null === $next ) {
			if ( $this->all_steps_complete( $steps ) ) {
				$evidence = new WorkflowExecutionEvidence( $plan->hash(), 'completed', 'completed' );
				$this->guard->transition( $this->snapshot( $record, $plan ), new WorkflowTransitionRequest( WorkflowTransitionAction::COMPLETE, plan: $plan, execution: $evidence ) );
				$completed = $this->persist_transition( $record, WorkflowRunState::COMPLETED, $actor_id, 'completed' );
				$this->audit_event( $completed, 'run_completed', $actor_id, null, null, 'completed' );

				return new WorkflowRunExecutionResult( $completed, null, null, true );
			}

			$running = $this->running_step( $steps );
			if ( null !== $running ) {
				if ( $this->lease_expired( $running ) ) {
					$uncertain = $this->store->fail_step( $run_id, $running->step_id(), $running->fence(), 'execution_uncertain', $actor_id );
					if ( null === $uncertain ) {
						throw new WorkflowRunnerException( 'step_claim_lost' );
					}

					$current = $this->require_run( $run_id );
					$this->audit_event( $current, 'step_failed', $actor_id, $uncertain, null, 'execution_uncertain' );

					return $this->fail_run( $current, $plan, 'execution_uncertain', $actor_id, $uncertain );
				}

				return new WorkflowRunExecutionResult( $record, $running, null, false );
			}

			return $this->fail_run( $record, $plan, 'step_dependency_deadlock', $actor_id );
		}

		$claimed = $this->store->claim_step( $run_id, $next->step_id(), $actor_id );
		if ( null === $claimed ) {
			$current = $this->store->steps( $run_id );
			return new WorkflowRunExecutionResult( $record, $this->find_step( $current, $next->step_id() ), null, false );
		}

		try {
			$arguments = $this->arguments_for_step( $definition, $next->step_id(), $plan, $steps );
		} catch ( WorkflowRunnerException $exception ) {
			$stored_step = $this->store->fail_step( $run_id, $next->step_id(), $claimed->fence(), $exception->error_code(), $actor_id );
			if ( null === $stored_step ) {
				throw new WorkflowRunnerException( 'step_claim_lost' );
			}

			return $this->fail_run( $record, $plan, $exception->error_code(), $actor_id, $stored_step );
		}
		try {
			$result = $this->adapters->execute( $plan, $next->step_id(), $arguments, $auth );
		} catch ( Throwable ) {
			$result = WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE );
		}
		if ( $this->lease_expired( $claimed ) ) {
			$uncertain = $this->store->fail_step( $run_id, $next->step_id(), $claimed->fence(), 'execution_uncertain', $actor_id );
			if ( null === $uncertain ) {
				throw new WorkflowRunnerException( 'step_claim_lost' );
			}

			$current = $this->require_run( $run_id );
			$this->audit_event( $current, 'step_failed', $actor_id, $uncertain, null, 'execution_uncertain' );

			return $this->fail_run( $current, $plan, 'execution_uncertain', $actor_id, $uncertain );
		}
		if ( $result->succeeded() ) {
			$stored_step = $this->store->complete_step( $run_id, $next->step_id(), $claimed->fence(), $result, $actor_id );
			if ( null === $stored_step ) {
				throw new WorkflowRunnerException( 'step_claim_lost' );
			}

			$current = $this->require_run( $run_id );
			$this->audit_event( $current, 'step_completed', $actor_id, $stored_step, null, $result->code(), array_keys( $arguments ) );
			return new WorkflowRunExecutionResult( $current, $stored_step, $result, true );
		}

		$stored_step = $this->store->fail_step( $run_id, $next->step_id(), $claimed->fence(), $result->code(), $actor_id );
		if ( null === $stored_step ) {
			throw new WorkflowRunnerException( 'step_claim_lost' );
		}

		return $this->fail_run( $record, $plan, $result->code(), $actor_id, $stored_step, $result, array_keys( $arguments ) );
	}

	/** Cancel a run, requiring a safe execution boundary when running. */
	public function cancel( string $run_id, WorkflowPlan $plan, int $actor_id, ?WorkflowExecutionEvidence $execution = null ): WorkflowRunRecord {
		$record = $this->require_run( $run_id );
		$this->assert_record_plan( $record, $plan );
		if ( $record->state()->is_terminal() ) {
			return $record;
		}
		$request = new WorkflowTransitionRequest( WorkflowTransitionAction::CANCEL, plan: $plan, execution: $execution );
		$result  = $this->guard->transition( $this->snapshot( $record, $plan ), $request );

		$cancelled = $this->persist_transition( $record, WorkflowRunState::CANCELLED, $actor_id, $result->snapshot()->outcome_code() );
		$this->audit_event( $cancelled, 'run_cancelled', $actor_id, null, null, $result->snapshot()->outcome_code() );

		return $cancelled;
	}

	/** Return a persisted run or throw a bounded error. */
	private function require_run( string $run_id ): WorkflowRunRecord {
		try {
			$record = $this->store->get( $run_id );
		} catch ( WorkflowRunStoreException $exception ) {
			throw new WorkflowRunnerException( $exception->error_code() );
		}
		if ( null === $record ) {
			throw new WorkflowRunnerException( 'run_not_found' );
		}

		return $record;
	}

	/** Verify a definition and plan are bound to the same immutable snapshot. */
	private function assert_definition_plan( WorkflowDefinitionRecord $definition, WorkflowPlan $plan ): void {
		$value = $definition->definition()->to_array();
		if ( $definition->workflow_id() !== $plan->identity()['workflow_id'] || (int) $value['workflow_version'] !== $plan->definition_revision() || $definition->definition()->checksum() !== $plan->definition_checksum() ) {
			throw new WorkflowRunnerException( 'plan_definition_mismatch' );
		}
	}

	/** Verify a durable record and plan have identical binding identity. */
	private function assert_record_plan( WorkflowRunRecord $record, WorkflowPlan $plan ): void {
		if ( $record->plan_hash() !== $plan->hash() || $record->workflow_id() !== (string) $plan->identity()['workflow_id'] || $record->workflow_version() !== $plan->definition_revision() || $record->definition_checksum() !== $plan->definition_checksum() ) {
			throw new WorkflowRunnerException( 'plan_binding_mismatch' );
		}
	}

	/**
	 * Verify a durable record and replacement plan share the same definition.
	 *
	 * Waiting-for-input runs intentionally have an incomplete plan hash, so
	 * replacing their normalized input is the one lifecycle operation allowed
	 * to change the plan hash while retaining the immutable definition revision.
	 */
	private function assert_record_definition_plan( WorkflowRunRecord $record, WorkflowPlan $plan ): void {
		if ( $record->workflow_id() !== (string) $plan->identity()['workflow_id'] || $record->workflow_version() !== $plan->definition_revision() || $record->definition_checksum() !== $plan->definition_checksum() ) {
			throw new WorkflowRunnerException( 'plan_definition_mismatch' );
		}
	}

	/** Rebuild a pure snapshot from durable state and an exact plan. */
	private function snapshot( WorkflowRunRecord $record, WorkflowPlan $plan ): WorkflowStateSnapshot {
		$dry_run = in_array( $record->state(), array( WorkflowRunState::DRY_RUN_READY, WorkflowRunState::WAITING_FOR_APPROVAL ), true )
			|| ( WorkflowRunState::RUNNING === $record->state() && array() !== $plan->approval_gate_step_ids() )
			? WorkflowDryRun::from_plan( $plan )
			: null;

		return new WorkflowStateSnapshot( $record->state(), $plan, $dry_run );
	}

	/** Verify normalized input and plan hashes are the same immutable value. */
	private function assert_input_plan( WorkflowPlan $plan, WorkflowInputContract $input ): void {
		if ( $input->hash() !== $plan->input_hash() ) {
			throw new WorkflowRunnerException( 'input_plan_mismatch' );
		}
	}

	/** Persist a guarded lifecycle transition through the run CAS fence. */
	private function persist_transition( WorkflowRunRecord $record, WorkflowRunState $next, int $actor_id, ?string $outcome_code = null, ?string $waiting_expires_at = null ): WorkflowRunRecord {
		try {
			$updated = $this->store->transition( $record->run_id(), $record->state(), $record->state_version(), $next, $actor_id, $outcome_code, $waiting_expires_at );
		} catch ( WorkflowRunStoreException $exception ) {
			throw new WorkflowRunnerException( $exception->error_code() );
		}
		if ( null === $updated ) {
			throw new WorkflowRunnerException( 'state_conflict' );
		}

		return $updated;
	}

	/**
	 * Return the first pending step whose dependencies are complete.
	 *
	 * @param WorkflowPlan $plan  Immutable plan.
	 * @param array        $steps Persisted steps.
	 * @phpstan-param list<WorkflowStepRecord> $steps
	 */
	private function next_step( WorkflowPlan $plan, array $steps ): ?WorkflowStepRecord {
		$by_id = array();
		foreach ( $steps as $step ) {
			$by_id[ $step->step_id() ] = $step;
		}
		$identity = $plan->identity();
		foreach ( $identity['steps'] as $raw_step ) {
			$step = $raw_step instanceof stdClass ? get_object_vars( $raw_step ) : $raw_step;
			if ( ! is_array( $step ) ) {
				continue;
			}
			$stored = $by_id[ (string) $step['step_id'] ] ?? null;
			if ( null === $stored || WorkflowStepState::PENDING !== $stored->state() ) {
				continue;
			}
			$ready = true;
			foreach ( $step['depends_on'] as $dependency ) {
				if ( ! isset( $by_id[ $dependency ] ) || WorkflowStepState::COMPLETED !== $by_id[ $dependency ]->state() ) {
					$ready = false;
					break;
				}
			}
			if ( $ready ) {
				return $stored;
			}
		}

		return null;
	}

	/**
	 * Whether every persisted step is terminal-success.
	 *
	 * @param array $steps Persisted steps.
	 * @phpstan-param list<WorkflowStepRecord> $steps
	 */
	private function all_steps_complete( array $steps ): bool {
		if ( array() === $steps ) {
			return false;
		}
		foreach ( $steps as $step ) {
			if ( WorkflowStepState::COMPLETED !== $step->state() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Return one currently leased step, if any.
	 *
	 * @param array $steps Persisted steps.
	 * @phpstan-param list<WorkflowStepRecord> $steps
	 */
	private function running_step( array $steps ): ?WorkflowStepRecord {
		foreach ( $steps as $step ) {
			if ( WorkflowStepState::RUNNING === $step->state() ) {
				return $step;
			}
		}

		return null;
	}

	/**
	 * Return a durable step whose adapter result cannot be safely inferred.
	 *
	 * @param array $steps Persisted steps.
	 * @phpstan-param list<WorkflowStepRecord> $steps
	 */
	private function uncertain_step( array $steps ): ?WorkflowStepRecord {
		foreach ( $steps as $step ) {
			if ( WorkflowStepState::FAILED === $step->state() && 'execution_uncertain' === $step->error_code() ) {
				return $step;
			}
		}

		return null;
	}

	/** Return whether a durable step lease has expired. */
	private function lease_expired( WorkflowStepRecord $step ): bool {
		$expires = $step->lease_expires_at();
		if ( null === $expires || '' === $expires ) {
			return false;
		}

		$date = strtotime( $expires . ' UTC' );

		return false !== $date && $date <= ( $this->clock )();
	}

	/** Reject input or approval continuation after its durable deadline. */
	private function assert_waiting_not_expired( WorkflowRunRecord $record ): void {
		if ( ! in_array( $record->state(), array( WorkflowRunState::WAITING_FOR_INPUT, WorkflowRunState::WAITING_FOR_APPROVAL ), true ) ) {
			return;
		}

		$expires = $record->waiting_expires_at();
		$until   = null === $expires || '' === $expires ? false : strtotime( $expires . ' UTC' );
		if ( false === $until || $until <= ( $this->clock )() ) {
			throw new WorkflowRunnerException( 'waiting_expired' );
		}
	}

	/**
	 * Fail the run with exact execution evidence.
	 *
	 * @param WorkflowRunRecord          $record      Durable run record.
	 * @param WorkflowPlan               $plan        Exact workflow plan.
	 * @param string                     $code        Closed failure code.
	 * @param int                        $actor_id    Current actor.
	 * @param WorkflowStepRecord|null    $step        Optional failed step.
	 * @param WorkflowAdapterResult|null $result     Optional adapter result.
	 * @param array|null                 $field_names Names of arguments requested by a write step.
	 * @phpstan-param list<string>|null $field_names
	 */
	private function fail_run( WorkflowRunRecord $record, WorkflowPlan $plan, string $code, int $actor_id, ?WorkflowStepRecord $step = null, ?WorkflowAdapterResult $result = null, ?array $field_names = null ): WorkflowRunExecutionResult {
		$evidence = new WorkflowExecutionEvidence( $plan->hash(), 'failed', $code );
		$this->guard->transition( $this->snapshot( $record, $plan ), new WorkflowTransitionRequest( WorkflowTransitionAction::FAIL, plan: $plan, execution: $evidence ) );
		$failed = $this->persist_transition( $record, WorkflowRunState::FAILED, $actor_id, $code );
		if ( null !== $step ) {
			$this->audit_event( $record, 'step_failed', $actor_id, $step, null, $code, $field_names );
		}
		$this->audit_event( $failed, 'run_failed', $actor_id, $step, null, $code, $field_names );

		return new WorkflowRunExecutionResult( $failed, $step, $result, true );
	}

	/**
	 * Append one bounded audit event without allowing observability to leak raw data.
	 *
	 * Audit persistence is best effort after the guarded state transition. A
	 * storage outage must not turn a completed write into an ambiguous retry.
	 *
	 * @param WorkflowRunRecord             $run       Durable run metadata.
	 * @param string                        $event_type Closed event type.
	 * @param int                           $actor_id  Current actor.
	 * @param WorkflowStepRecord|null       $step      Optional step summary.
	 * @param WorkflowApprovalEvidence|null $approval  Optional approval evidence.
	 * @param string|null                   $outcome   Optional outcome code.
	 * @param array|null                    $field_names Names of arguments requested by a write step.
	 * @phpstan-param list<string>|null $field_names
	 */
	private function audit_event( WorkflowRunRecord $run, string $event_type, int $actor_id, ?WorkflowStepRecord $step = null, ?WorkflowApprovalEvidence $approval = null, ?string $outcome = null, ?array $field_names = null ): void {
		$changed_fields = array();
		$rollback_note  = '';
		$step_id        = '';
		if ( null !== $step ) {
			$step_id = $step->step_id();
			if ( 'write' === $step->kind() ) {
				$changed_fields = $this->write_changed_fields( $step, $field_names );
				$rollback_note  = 'Use the WordPress revision or the workflow rollback procedure to restore this change.';
			}
		}

		$approval_hash = null;
		if ( null !== $approval ) {
			$approval_hash = hash( 'sha256', $approval->reference() );
		}

		try {
			$this->audit->append(
				new WorkflowAuditRecord(
					$run->run_id(),
					$run->workflow_id(),
					$run->workflow_version(),
					$run->definition_checksum(),
					$event_type,
					$step_id,
					$actor_id,
					$outcome,
					$approval_hash,
					$changed_fields,
					$rollback_note,
					gmdate( 'Y-m-d H:i:s' )
				)
			);
		} catch ( Throwable $exception ) {
			unset( $exception );
			// The durable run state remains authoritative when the audit sink is unavailable.
		}
	}

	/**
	 * Return names-only evidence for one write step.
	 *
	 * Argument keys describe the requested mutation without persisting values.
	 * When a step has no arguments (or the keys are not bounded identifiers),
	 * the ability identity remains a useful, stable fallback.
	 *
	 * @param WorkflowStepRecord $step        Durable step summary.
	 * @param array|null         $field_names  Requested argument names.
	 * @phpstan-param list<string>|null $field_names
	 * @return list<string>
	 */
	private function write_changed_fields( WorkflowStepRecord $step, ?array $field_names ): array {
		$changed = array();
		foreach ( $field_names ?? array() as $field_name ) {
			if ( ! is_string( $field_name ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $field_name ) ) {
				continue;
			}
			$changed[] = 'field.' . $field_name;
		}

		if ( array() !== $changed ) {
			return array_values( array_unique( array_slice( $changed, 0, 32 ) ) );
		}

		$ability = preg_replace( '/[^A-Za-z0-9_.-]+/', '.', $step->ability_id() );
		$ability = is_string( $ability ) ? trim( $ability, '.' ) : '';

		return array( 'ability.' . ( '' === $ability ? 'unknown' : substr( $ability, 0, 88 ) ) );
	}

	/**
	 * Resolve one validated argument object from the immutable definition,
	 * normalized workflow input, and completed step outputs.
	 *
	 * @param WorkflowDefinitionRecord $definition Definition that owns arguments.
	 * @param string                   $step_id     Exact step ID.
	 * @param WorkflowPlan             $plan        Exact normalized plan.
	 * @param array                    $steps       Persisted step records.
	 * @phpstan-param list<WorkflowStepRecord> $steps
	 * @return array<string, mixed>
	 */
	private function arguments_for_step( WorkflowDefinitionRecord $definition, string $step_id, WorkflowPlan $plan, array $steps ): array {
		$arguments = null;
		foreach ( $definition->definition()->to_array()['steps'] as $raw_step ) {
			$step = $raw_step instanceof stdClass ? get_object_vars( $raw_step ) : $raw_step;
			if ( ! is_array( $step ) || (string) ( $step['step_id'] ?? '' ) !== $step_id ) {
				continue;
			}
			$arguments = $step['arguments'] ?? array();
			if ( $arguments instanceof stdClass ) {
				$arguments = get_object_vars( $arguments );
				break;
			}
			if ( is_array( $arguments ) && ( array() === $arguments || ! array_is_list( $arguments ) ) ) {
				break;
			}
		}

		if ( ! is_array( $arguments ) || ( array() !== $arguments && array_is_list( $arguments ) ) ) {
			throw new WorkflowRunnerException( 'step_arguments_invalid' );
		}

		return $this->resolve_argument_value( $arguments, $plan, $steps );
	}

	/**
	 * Resolve exact, typed bindings without interpolating untrusted strings.
	 *
	 * Supported forms are {{input.field}} and
	 * {{steps.step_id.output.field}}. Missing or unavailable bindings fail
	 * closed so a write adapter never receives an accidental empty argument.
	 *
	 * @param mixed        $value Candidate argument value.
	 * @param WorkflowPlan $plan  Exact normalized plan.
	 * @param array        $steps Persisted step records.
	 * @phpstan-param list<WorkflowStepRecord> $steps
	 * @return mixed
	 */
	private function resolve_argument_value( mixed $value, WorkflowPlan $plan, array $steps ): mixed {
		if ( $value instanceof stdClass ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			$resolved = array();
			foreach ( $value as $key => $item ) {
				$resolved[ $key ] = $this->resolve_argument_value( $item, $plan, $steps );
			}

			return $resolved;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}

		if ( 1 === preg_match( '/^\{\{input\.([a-z][a-z0-9_.]{0,127})\}\}$/D', $value, $matches ) ) {
			[ $found, $resolved ] = $this->lookup_path( $plan->input_contract()->value(), explode( '.', $matches[1] ) );
			if ( ! $found ) {
				throw new WorkflowRunnerException( 'step_arguments_invalid' );
			}

			return $resolved;
		}

		if ( 1 === preg_match( '/^\{\{steps\.([a-z][a-z0-9_]{0,63})\.output\.([a-z][a-z0-9_.-]{0,127})\}\}$/D', $value, $matches ) ) {
			$step = $this->find_step( $steps, $matches[1] );
			if ( null === $step || WorkflowStepState::COMPLETED !== $step->state() || null === $step->output_json() ) {
				throw new WorkflowRunnerException( 'step_arguments_invalid' );
			}
			try {
				$output = json_decode( $step->output_json(), true, WorkflowInputContract::MAX_DEPTH, JSON_THROW_ON_ERROR );
			} catch ( Throwable ) {
				throw new WorkflowRunnerException( 'step_arguments_invalid' );
			}
			[ $found, $resolved ] = $this->lookup_path( $output, explode( '.', $matches[2] ) );
			if ( ! $found ) {
				throw new WorkflowRunnerException( 'step_arguments_invalid' );
			}

			return $resolved;
		}

		return $value;
	}

	/**
	 * Look up a bounded dotted path in an object/array map.
	 *
	 * @param mixed $value Root value.
	 * @param array $path Path segments.
	 * @phpstan-param list<string> $path
	 * @return array{0:bool,1:mixed}
	 */
	private function lookup_path( mixed $value, array $path ): array {
		$current = $value;
		foreach ( $path as $segment ) {
			if ( $current instanceof stdClass ) {
				$current = get_object_vars( $current );
			}
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return array( false, null );
			}
			$current = $current[ $segment ];
		}

		return array( true, $current );
	}

	/**
	 * Find one detached step by ID.
	 *
	 * @param array $steps Persisted steps.
	 * @phpstan-param list<WorkflowStepRecord> $steps
	 */
	private function find_step( array $steps, string $step_id ): ?WorkflowStepRecord {
		foreach ( $steps as $step ) {
			if ( $step->step_id() === $step_id ) {
				return $step;
			}
		}

		return null;
	}

	/** Return a bounded waiting expiry seven days from now. */
	private function waiting_expiry(): string {
		return gmdate( 'Y-m-d H:i:s', ( $this->clock )() + self::WAITING_SECONDS );
	}

	/** Create a collision-resistant bounded run identifier. */
	private function new_run_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}

		return 'run_' . bin2hex( random_bytes( 24 ) );
	}
}
