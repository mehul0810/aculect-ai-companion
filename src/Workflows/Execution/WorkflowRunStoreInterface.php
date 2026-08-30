<?php
/**
 * Durable workflow run storage contract.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionCommentThrowTag.Missing

namespace Aculect\AICompanion\Workflows\Execution;

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Planning\WorkflowDryRun;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;

/**
 * Defines the CAS and encrypted-payload boundary used by the runner.
 *
 * @internal This is an internal composition contract; it is not a public API.
 */
interface WorkflowRunStoreInterface {

	/**
	 * Create a run pinned to one immutable plan and definition snapshot.
	 *
	 * @throws WorkflowRunStoreException When persistence or encryption fails.
	 */
	public function create(
		string $run_id,
		string $workflow_id,
		int $workflow_version,
		string $definition_checksum,
		WorkflowPlan $plan,
		WorkflowInputContract $input,
		WorkflowRunState $state,
		int $actor_id,
		?WorkflowDryRun $dry_run = null,
		?string $waiting_expires_at = null
	): WorkflowRunRecord;

	/** Return one run by stable ID. */
	public function get( string $run_id ): ?WorkflowRunRecord;

	/**
	 * Return all ordered steps for one run.
	 *
	 * @return list<WorkflowStepRecord>
	 */
	public function steps( string $run_id ): array;

	/**
	 * Compare-and-set one run lifecycle transition.
	 *
	 * @return WorkflowRunRecord|null Null when the expected state/fence lost a race.
	 */
	public function transition(
		string $run_id,
		WorkflowRunState $expected_state,
		int $expected_version,
		WorkflowRunState $next_state,
		int $actor_id,
		?string $outcome_code = null,
		?string $waiting_expires_at = null
	): ?WorkflowRunRecord;

	/**
	 * Replace incomplete input while preserving the pinned definition snapshot.
	 *
	 * @return WorkflowRunRecord|null Null when the expected state/fence lost a race.
	 */
	public function replace_plan(
		string $run_id,
		int $expected_version,
		WorkflowPlan $plan,
		WorkflowInputContract $input,
		int $actor_id
	): ?WorkflowRunRecord;

	/**
	 * Atomically claim one pending or expired-running step.
	 *
	 * @return WorkflowStepRecord|null Null when another worker owns the step.
	 */
	public function claim_step( string $run_id, string $step_id, int $actor_id ): ?WorkflowStepRecord;

	/** Persist one successful adapter result owned by the current claim. */
	public function complete_step( string $run_id, string $step_id, int $fence, WorkflowAdapterResult $result, int $actor_id ): ?WorkflowStepRecord;

	/** Persist one failed adapter result owned by the current claim. */
	public function fail_step( string $run_id, string $step_id, int $fence, string $error_code, int $actor_id ): ?WorkflowStepRecord;
}
