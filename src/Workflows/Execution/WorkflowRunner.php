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
		?Closure $clock = null
	) {
		$this->clock = $clock ?? static fn (): int => time();
	}

	/**
	 * Controlled UTC clock used for waiting and lease deadlines.
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
			return $this->store->create(
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

		return $this->persist_transition( $record, WorkflowRunState::DRY_RUN_READY, $actor_id );
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

		return $this->persist_transition( $record, $result->snapshot()->state(), $actor_id, null, $this->waiting_expiry() );
	}

	/** Replace incomplete input while retaining the exact definition version. */
	public function resume_with_input( string $run_id, WorkflowPlan $plan, WorkflowInputContract $input, int $actor_id ): WorkflowRunRecord {
		$record = $this->require_run( $run_id );
		$this->assert_record_definition_plan( $record, $plan );
		if ( WorkflowRunState::WAITING_FOR_INPUT !== $record->state() ) {
			throw new WorkflowRunnerException( 'invalid_state' );
		}
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

		return $updated;
	}

	/** Start execution after exact readiness and approval evidence. */
	public function start( string $run_id, WorkflowPlan $plan, WorkflowReadinessEvidence $readiness, int $actor_id, ?WorkflowApprovalEvidence $approval = null ): WorkflowRunRecord {
		$record = $this->require_run( $run_id );
		$this->assert_record_plan( $record, $plan );
		$request = new WorkflowTransitionRequest( WorkflowTransitionAction::START, plan: $plan, approval: $approval, readiness: $readiness );
		$result  = $this->guard->transition( $this->snapshot( $record, $plan ), $request );

		return $this->persist_transition( $record, $result->snapshot()->state(), $actor_id );
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

		$steps   = $this->store->steps( $run_id );
		$running = $this->running_step( $steps );
		if ( null !== $running ) {
			if ( $this->lease_expired( $running ) ) {
				return $this->fail_run( $record, $plan, 'step_execution_uncertain', $actor_id, $running );
			}

			return new WorkflowRunExecutionResult( $record, $running, null, false );
		}

		$next = $this->next_step( $plan, $steps );
		if ( null === $next ) {
			if ( $this->all_steps_complete( $steps ) ) {
				$evidence = new WorkflowExecutionEvidence( $plan->hash(), 'completed', 'completed' );
				$this->guard->transition( $this->snapshot( $record, $plan ), new WorkflowTransitionRequest( WorkflowTransitionAction::COMPLETE, plan: $plan, execution: $evidence ) );
				$completed = $this->persist_transition( $record, WorkflowRunState::COMPLETED, $actor_id, 'completed' );

				return new WorkflowRunExecutionResult( $completed, null, null, true );
			}

			return $this->fail_run( $record, $plan, 'step_dependency_deadlock', $actor_id );
		}

		$claimed = $this->store->claim_step( $run_id, $next->step_id(), $actor_id );
		if ( null === $claimed ) {
			$current = $this->store->steps( $run_id );
			return new WorkflowRunExecutionResult( $record, $this->find_step( $current, $next->step_id() ), null, false );
		}

		$arguments = $this->arguments_for_step( $definition, $next->step_id() );
		try {
			$result = $this->adapters->execute( $plan, $next->step_id(), $arguments, $auth );
		} catch ( Throwable ) {
			$result = WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE );
		}
		if ( $result->succeeded() ) {
			$stored_step = $this->store->complete_step( $run_id, $next->step_id(), $claimed->fence(), $result, $actor_id );
			if ( null === $stored_step ) {
				throw new WorkflowRunnerException( 'step_claim_lost' );
			}

			$current = $this->require_run( $run_id );
			return new WorkflowRunExecutionResult( $current, $stored_step, $result, true );
		}

		$stored_step = $this->store->fail_step( $run_id, $next->step_id(), $claimed->fence(), $result->code(), $actor_id );
		if ( null === $stored_step ) {
			throw new WorkflowRunnerException( 'step_claim_lost' );
		}

		return $this->fail_run( $record, $plan, $result->code(), $actor_id, $stored_step, $result );
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

		return $this->persist_transition( $record, WorkflowRunState::CANCELLED, $actor_id, $result->snapshot()->outcome_code() );
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

	/** Fail the run with exact execution evidence. */
	private function fail_run( WorkflowRunRecord $record, WorkflowPlan $plan, string $code, int $actor_id, ?WorkflowStepRecord $step = null, ?WorkflowAdapterResult $result = null ): WorkflowRunExecutionResult {
		$evidence = new WorkflowExecutionEvidence( $plan->hash(), 'failed', $code );
		$this->guard->transition( $this->snapshot( $record, $plan ), new WorkflowTransitionRequest( WorkflowTransitionAction::FAIL, plan: $plan, execution: $evidence ) );
		$failed = $this->persist_transition( $record, WorkflowRunState::FAILED, $actor_id, $code );

		return new WorkflowRunExecutionResult( $failed, $step, $result, true );
	}

	/**
	 * Extract one validated static argument object from the immutable definition.
	 *
	 * @return array<string, mixed>
	 */
	private function arguments_for_step( WorkflowDefinitionRecord $definition, string $step_id ): array {
		foreach ( $definition->definition()->to_array()['steps'] as $raw_step ) {
			$step = $raw_step instanceof stdClass ? get_object_vars( $raw_step ) : $raw_step;
			if ( ! is_array( $step ) || (string) ( $step['step_id'] ?? '' ) !== $step_id ) {
				continue;
			}
			$arguments = $step['arguments'] ?? array();
			if ( $arguments instanceof stdClass ) {
				return get_object_vars( $arguments );
			}
			if ( is_array( $arguments ) && array() === $arguments ) {
				// An empty JSON object is represented as an empty PHP array by
				// normalized workflow definitions. Preserve that object semantics;
				// non-empty list roots remain invalid below.
				return array();
			}
			if ( is_array( $arguments ) && ! array_is_list( $arguments ) ) {
				return $arguments;
			}
		}

		throw new WorkflowRunnerException( 'step_arguments_invalid' );
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

	/** Return whether a claimed step has crossed its authoritative lease. */
	private function lease_expired( WorkflowStepRecord $step ): bool {
		$expires_at = $step->lease_expires_at();
		if ( null === $expires_at || '' === $expires_at ) {
			return true;
		}

		return $expires_at <= gmdate( 'Y-m-d H:i:s', ( $this->clock )() );
	}

	/** Create a collision-resistant bounded run identifier. */
	private function new_run_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}

		return 'run_' . bin2hex( random_bytes( 24 ) );
	}
}
