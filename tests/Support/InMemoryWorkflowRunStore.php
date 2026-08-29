<?php
/**
 * In-memory workflow run store for runner lifecycle tests.
 *
 * @package Aculect\AICompanion\Tests\Support
 */

declare(strict_types=1);

// phpcs:disable Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.MissingParamComment, Squiz.Commenting.FunctionComment.IncorrectTypeHint -- Test double methods mirror an internal interface and stay intentionally compact.

namespace Aculect\AICompanion\Tests\Support;

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStoreException;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStoreInterface;
use Aculect\AICompanion\Workflows\Execution\WorkflowStepRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowStepState;
use Aculect\AICompanion\Workflows\Planning\WorkflowDryRun;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use stdClass;

/**
 * Deliberately small stateful double that preserves CAS and fencing semantics.
 */
final class InMemoryWorkflowRunStore implements WorkflowRunStoreInterface {

	/**
	 * @var array<string, WorkflowRunRecord>
	 */
	private array $runs = array();

	/**
	 * @var array<string, list<WorkflowStepRecord>>
	 */
	private array $steps = array();

	private int $next_run_id  = 1;
	private int $next_step_id = 1;

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
	): WorkflowRunRecord {
		unset( $dry_run, $input );
		if ( isset( $this->runs[ $run_id ] ) ) {
			throw new WorkflowRunStoreException( 'run_create_failed' );
		}

		$record                 = new WorkflowRunRecord(
			$this->next_run_id++,
			$run_id,
			$workflow_id,
			$workflow_version,
			$definition_checksum,
			$plan->hash(),
			$plan->input_hash(),
			$state,
			1,
			null,
			$waiting_expires_at,
			null,
			$actor_id,
			$actor_id,
			'2026-08-29 00:00:00',
			'2026-08-29 00:00:00'
		);
		$this->runs[ $run_id ]  = $record;
		$this->steps[ $run_id ] = $this->make_steps( $run_id, $plan );

		return $record;
	}

	public function get( string $run_id ): ?WorkflowRunRecord {
		return $this->runs[ $run_id ] ?? null;
	}

	/**
	 * @return list<WorkflowStepRecord>
	 */
	public function steps( string $run_id ): array {
		return $this->steps[ $run_id ] ?? array();
	}

	public function transition(
		string $run_id,
		WorkflowRunState $expected_state,
		int $expected_version,
		WorkflowRunState $next_state,
		int $actor_id,
		?string $outcome_code = null,
		?string $waiting_expires_at = null,
		?string $approval_reference_hash = null
	): ?WorkflowRunRecord {
		$record = $this->runs[ $run_id ] ?? null;
		if ( null === $record || $record->state() !== $expected_state || $record->state_version() !== $expected_version ) {
			return null;
		}
		if ( WorkflowRunState::RUNNING === $expected_state && WorkflowRunState::CANCELLED === $next_state ) {
			foreach ( $this->steps[ $run_id ] ?? array() as $step ) {
				if ( WorkflowStepState::RUNNING === $step->state() || ( WorkflowStepState::FAILED === $step->state() && 'execution_uncertain' === $step->error_code() ) ) {
					return null;
				}
			}
		}

		$updated               = new WorkflowRunRecord(
			$record->id(),
			$record->run_id(),
			$record->workflow_id(),
			$record->workflow_version(),
			$record->definition_checksum(),
			$record->plan_hash(),
			$record->input_hash(),
			$next_state,
			$expected_version + 1,
			$outcome_code,
			$waiting_expires_at,
			null !== $approval_reference_hash ? $approval_reference_hash : $record->approval_reference_hash(),
			$record->created_by(),
			$actor_id,
			$record->created_at(),
			'2026-08-29 00:00:01'
		);
		$this->runs[ $run_id ] = $updated;

		return $updated;
	}

	public function replace_plan(
		string $run_id,
		int $expected_version,
		WorkflowPlan $plan,
		WorkflowInputContract $input,
		int $actor_id
	): ?WorkflowRunRecord {
		$record = $this->runs[ $run_id ] ?? null;
		if ( null === $record || WorkflowRunState::WAITING_FOR_INPUT !== $record->state() || $record->state_version() !== $expected_version ) {
			return null;
		}

		$updated               = new WorkflowRunRecord(
			$record->id(),
			$record->run_id(),
			$record->workflow_id(),
			$record->workflow_version(),
			$record->definition_checksum(),
			$plan->hash(),
			$input->hash(),
			WorkflowRunState::PREPARED,
			$expected_version + 1,
			null,
			null,
			$record->approval_reference_hash(),
			$record->created_by(),
			$actor_id,
			$record->created_at(),
			'2026-08-29 00:00:01'
		);
		$this->runs[ $run_id ] = $updated;

		return $updated;
	}

	public function claim_step( string $run_id, string $step_id, int $actor_id ): ?WorkflowStepRecord {
		unset( $actor_id );
		$run = $this->runs[ $run_id ] ?? null;
		if ( null === $run || WorkflowRunState::RUNNING !== $run->state() ) {
			return null;
		}

		foreach ( $this->steps[ $run_id ] ?? array() as $index => $step ) {
			if ( $step->step_id() !== $step_id || WorkflowStepState::PENDING !== $step->state() ) {
				continue;
			}

			$claimed                          = $this->copy_step( $step, WorkflowStepState::RUNNING, $step->attempt() + 1, $step->fence() + 1, '', null, null, null );
			$this->steps[ $run_id ][ $index ] = $claimed;

			return $claimed;
		}

		return null;
	}

	public function complete_step( string $run_id, string $step_id, int $fence, WorkflowAdapterResult $result, int $actor_id ): ?WorkflowStepRecord {
		unset( $actor_id );
		return $this->finish( $run_id, $step_id, $fence, WorkflowStepState::COMPLETED, $result->code(), null, $result->output() );
	}

	public function fail_step( string $run_id, string $step_id, int $fence, string $error_code, int $actor_id ): ?WorkflowStepRecord {
		unset( $actor_id );
		return $this->finish( $run_id, $step_id, $fence, WorkflowStepState::FAILED, '', $error_code, null );
	}

	/**
	 * @return list<WorkflowStepRecord>
	 */
	private function make_steps( string $run_id, WorkflowPlan $plan ): array {
		$steps = array();
		foreach ( $plan->identity()['steps'] as $position => $raw_step ) {
			$step = $raw_step instanceof stdClass ? get_object_vars( $raw_step ) : $raw_step;
			if ( ! is_array( $step ) ) {
				continue;
			}
			$steps[] = new WorkflowStepRecord(
				$this->next_step_id++,
				$run_id,
				(string) $step['step_id'],
				(int) $position,
				(string) $step['adapter_id'],
				(int) $step['adapter_version'],
				(string) $step['ability_id'],
				(string) $step['kind'],
				WorkflowStepState::PENDING,
				0,
				1,
				'',
				null,
				null,
				null,
				null,
				'2026-08-29 00:00:00'
			);
		}

		return $steps;
	}

	private function finish( string $run_id, string $step_id, int $fence, WorkflowStepState $state, string $result_code, ?string $error_code, ?stdClass $output ): ?WorkflowStepRecord {
		$run = $this->runs[ $run_id ] ?? null;
		if ( null === $run || WorkflowRunState::RUNNING !== $run->state() ) {
			return null;
		}
		foreach ( $this->steps[ $run_id ] ?? array() as $index => $step ) {
			if ( $step->step_id() !== $step_id || WorkflowStepState::RUNNING !== $step->state() || $step->fence() !== $fence ) {
				continue;
			}
			$json                             = null === $output ? null : (string) wp_json_encode( $output );
			$finished                         = $this->copy_step( $step, $state, $step->attempt(), $step->fence(), $result_code, $error_code, $json, '2026-08-29 00:00:02' );
			$this->steps[ $run_id ][ $index ] = $finished;

			return $finished;
		}

		return null;
	}

	private function copy_step( WorkflowStepRecord $step, WorkflowStepState $state, int $attempt, int $fence, string $result_code, ?string $error_code, ?string $output_json, ?string $completed_at ): WorkflowStepRecord {
		return new WorkflowStepRecord(
			$step->id(),
			$step->run_id(),
			$step->step_id(),
			$step->position(),
			$step->adapter_id(),
			$step->adapter_version(),
			$step->ability_id(),
			$step->kind(),
			$state,
			$attempt,
			$fence,
			$result_code,
			$error_code,
			$output_json,
			$step->started_at() ?? '2026-08-29 00:00:01',
			$completed_at,
			'2026-08-29 00:00:02'
		);
	}
}
