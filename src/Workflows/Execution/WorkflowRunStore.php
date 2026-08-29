<?php
/**
 * Transactional durable workflow run store.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionCommentThrowTag.Missing

namespace Aculect\AICompanion\Workflows\Execution;

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Database\RunInstaller;
use Aculect\AICompanion\Workflows\Planning\WorkflowDryRun;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use Closure;
use Throwable;
use stdClass;

/**
 * Stores encrypted input, exact plan identity, and monotonic run/step state.
 *
 * All writes use compare-and-set predicates. A stale worker can therefore
 * never overwrite a later claim, result, or lifecycle transition.
 */
final class WorkflowRunStore implements WorkflowRunStoreInterface {

	private const STEP_LEASE_SECONDS = 30;
	private const RETENTION_SECONDS  = 2592000;
	private const PRUNE_LIMIT        = 25;

	/**
	 * Controlled UTC clock.
	 *
	 * @var Closure(): int
	 */
	private Closure $clock;
	private WorkflowRunRecordMapper $mapper;

	public function __construct( ?WorkflowPayloadVault $vault = null, ?Closure $clock = null ) {
		$this->vault  = $vault ?? new WorkflowPayloadVault();
		$this->mapper = new WorkflowRunRecordMapper( $this->vault );
		$this->clock  = $clock ?? static fn (): int => time();
	}

	private WorkflowPayloadVault $vault;

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
		unset( $dry_run );
		$this->ensure_storage();
		$this->assert_identity( $run_id, $workflow_id, $workflow_version, $definition_checksum, $plan );
		if ( $actor_id < 1 || ! in_array( $state, array( WorkflowRunState::WAITING_FOR_INPUT, WorkflowRunState::PREPARED, WorkflowRunState::DRY_RUN_READY ), true ) ) {
			throw new WorkflowRunStoreException( 'invalid_run_state' );
		}
		if ( null !== $waiting_expires_at && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $waiting_expires_at ) ) {
			throw new WorkflowRunStoreException( 'invalid_waiting_expiry' );
		}
		if ( $plan->definition_checksum() !== $definition_checksum ) {
			throw new WorkflowRunStoreException( 'plan_definition_mismatch' );
		}
		if ( $input->hash() !== $plan->input_hash() ) {
			throw new WorkflowRunStoreException( 'input_plan_mismatch' );
		}
		$this->prune_retention();

		$input_ciphertext = $this->seal( $input->canonical_json() );
		$now              = $this->now_sql();
		global $wpdb;
		$tables = RunInstaller::table_names();
		try {
			$this->begin();
			$inserted = $wpdb->insert(
				$tables['runs'],
				array(
					'run_id'              => $run_id,
					'workflow_id'         => $workflow_id,
					'workflow_version'    => $workflow_version,
					'definition_checksum' => $definition_checksum,
					'plan_hash'           => $plan->hash(),
					'input_hash'          => $input->hash(),
					'input_ciphertext'    => $input_ciphertext,
					'state'               => $state->value,
					'state_version'       => 1,
					'outcome_code'        => '',
					'waiting_expires_at'  => $waiting_expires_at,
					'created_by'          => $actor_id,
					'updated_by'          => $actor_id,
					'created_at'          => $now,
					'updated_at'          => $now,
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
			if ( false === $inserted ) {
				throw new WorkflowRunStoreException( 'run_create_failed' );
			}

			$run_pk = (int) ( $wpdb->insert_id ?? 0 );
			if ( $run_pk < 1 ) {
				throw new WorkflowRunStoreException( 'run_identity_missing' );
			}
			$this->insert_steps( $run_pk, $plan, $now );
			$this->commit();
		} catch ( WorkflowRunStoreException $exception ) {
			$this->rollback();
			throw $exception;
		} catch ( Throwable ) {
			$this->rollback();
			throw new WorkflowRunStoreException( 'run_create_failed' );
		}

		$record = $this->get( $run_id );
		if ( null === $record ) {
			throw new WorkflowRunStoreException( 'run_create_unreadable' );
		}

		return $record;
	}

	public function get( string $run_id ): ?WorkflowRunRecord {
		$this->ensure_storage();
		$row = $this->run_row( $run_id );

		return null === $row ? null : $this->mapper->run( $row );
	}

	public function steps( string $run_id ): array {
		$this->ensure_storage();
		$run = $this->run_row( $run_id );
		if ( null === $run ) {
			return array();
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, r.run_id FROM %i s INNER JOIN %i r ON r.id = s.run_pk WHERE s.run_pk = %d ORDER BY s.step_position ASC, s.id ASC',
				RunInstaller::table_names()['steps'],
				RunInstaller::table_names()['runs'],
				(int) $run['id']
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$steps = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$steps[] = $this->mapper->step( $row );
			}
		}

		return $steps;
	}

	public function transition(
		string $run_id,
		WorkflowRunState $expected_state,
		int $expected_version,
		WorkflowRunState $next_state,
		int $actor_id,
		?string $outcome_code = null,
		?string $waiting_expires_at = null
	): ?WorkflowRunRecord {
		$this->ensure_storage();
		if ( $actor_id < 1 || $expected_version < 1 || ( $next_state->is_terminal() && null === $outcome_code ) || ( ! $next_state->is_terminal() && null !== $outcome_code ) ) {
			throw new WorkflowRunStoreException( 'invalid_transition' );
		}
		if ( null !== $outcome_code && 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $outcome_code ) ) {
			throw new WorkflowRunStoreException( 'invalid_outcome_code' );
		}
		if ( null !== $waiting_expires_at && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $waiting_expires_at ) ) {
			throw new WorkflowRunStoreException( 'invalid_waiting_expiry' );
		}

		global $wpdb;
		$tables = RunInstaller::table_names();
		if ( WorkflowRunState::CANCELLED === $next_state && WorkflowRunState::RUNNING === $expected_state ) {
			$query = 'UPDATE %i AS r SET state = %s, state_version = %d, outcome_code = %s, waiting_expires_at = NULL, updated_by = %d, updated_at = %s WHERE r.run_id = %s AND r.state = %s AND r.state_version = %d AND NOT EXISTS (SELECT 1 FROM %i AS s WHERE s.run_pk = r.id AND (s.state = %s OR (s.state = %s AND s.error_code = %s)))';
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query shape is fixed and every value is supplied through placeholders.
			$updated = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query shape is fixed and every value is supplied through placeholders.
					$query,
					$tables['runs'],
					$next_state->value,
					$expected_version + 1,
					$outcome_code ?? '',
					$actor_id,
					$this->now_sql(),
					$run_id,
					$expected_state->value,
					$expected_version,
					$tables['steps'],
					WorkflowStepState::RUNNING->value,
					WorkflowStepState::FAILED->value,
					'execution_uncertain'
				)
			);
		} else {
			$updated = $wpdb->update(
				$tables['runs'],
				array(
					'state'              => $next_state->value,
					'state_version'      => $expected_version + 1,
					'outcome_code'       => $outcome_code ?? '',
					'waiting_expires_at' => $waiting_expires_at,
					'updated_by'         => $actor_id,
					'updated_at'         => $this->now_sql(),
				),
				array(
					'run_id'        => $run_id,
					'state'         => $expected_state->value,
					'state_version' => $expected_version,
				),
				array( '%s', '%d', '%s', '%s', '%d', '%s' ),
				array( '%s', '%s', '%d' )
			);
		}
		if ( 1 !== (int) $updated ) {
			return null;
		}

		return $this->get( $run_id );
	}

	public function replace_plan( string $run_id, int $expected_version, WorkflowPlan $plan, WorkflowInputContract $input, int $actor_id ): ?WorkflowRunRecord {
		$this->ensure_storage();
		if ( $expected_version < 1 || $actor_id < 1 || ! $plan->is_input_ready() ) {
			throw new WorkflowRunStoreException( 'invalid_replacement_plan' );
		}
		if ( $input->hash() !== $plan->input_hash() ) {
			throw new WorkflowRunStoreException( 'input_plan_mismatch' );
		}
		$run = $this->run_row( $run_id );
		if ( null === $run || WorkflowRunState::WAITING_FOR_INPUT->value !== (string) $run['state'] || $expected_version !== (int) $run['state_version'] ) {
			return null;
		}
		if ( (string) $run['workflow_id'] !== $plan->identity()['workflow_id'] || (int) $run['workflow_version'] !== $plan->definition_revision() || (string) $run['definition_checksum'] !== $plan->definition_checksum() ) {
			throw new WorkflowRunStoreException( 'plan_definition_mismatch' );
		}

		$input_ciphertext = $this->seal( $input->canonical_json() );
		global $wpdb;
		$updated = $wpdb->update(
			RunInstaller::table_names()['runs'],
			array(
				'plan_hash'        => $plan->hash(),
				'input_hash'       => $input->hash(),
				'input_ciphertext' => $input_ciphertext,
				'state'            => WorkflowRunState::PREPARED->value,
				'state_version'    => $expected_version + 1,
				'updated_by'       => $actor_id,
				'updated_at'       => $this->now_sql(),
			),
			array(
				'run_id'        => $run_id,
				'state'         => WorkflowRunState::WAITING_FOR_INPUT->value,
				'state_version' => $expected_version,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
			array( '%s', '%s', '%d' )
		);

		return 1 === (int) $updated ? $this->get( $run_id ) : null;
	}

	public function claim_step( string $run_id, string $step_id, int $actor_id ): ?WorkflowStepRecord {
		$this->ensure_storage();
		if ( $actor_id < 1 ) {
			throw new WorkflowRunStoreException( 'invalid_actor' );
		}
		$run = $this->run_row( $run_id );
		if ( null === $run || WorkflowRunState::RUNNING->value !== (string) $run['state'] ) {
			return null;
		}

		global $wpdb;
		$now       = ( $this->clock )();
		$now_sql   = gmdate( 'Y-m-d H:i:s', $now );
		$lease_sql = gmdate( 'Y-m-d H:i:s', $now + self::STEP_LEASE_SECONDS );
		$updated   = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i AS s SET state = 'running', attempt = attempt + 1, fence = fence + 1, lease_expires_at = %s, started_at = COALESCE(started_at, %s), updated_at = %s WHERE s.run_pk = %d AND s.step_id = %s AND (s.state = 'pending' OR (s.state = 'running' AND s.lease_expires_at IS NOT NULL AND s.lease_expires_at <= %s)) AND EXISTS (SELECT 1 FROM %i AS r WHERE r.id = s.run_pk AND r.state = %s)",
				RunInstaller::table_names()['steps'],
				$lease_sql,
				$now_sql,
				$now_sql,
				(int) $run['id'],
				$step_id,
				$now_sql,
				RunInstaller::table_names()['runs'],
				WorkflowRunState::RUNNING->value
			)
		);
		if ( 1 !== (int) $updated ) {
			return null;
		}

		return $this->step( $run_id, $step_id );
	}

	public function complete_step( string $run_id, string $step_id, int $fence, WorkflowAdapterResult $result, int $actor_id ): ?WorkflowStepRecord {
		$this->ensure_storage();
		if ( $actor_id < 1 || $fence < 1 || ! $result->succeeded() ) {
			throw new WorkflowRunStoreException( 'invalid_step_result' );
		}

		$output = $result->output();
		try {
			$json = wp_json_encode( $output );
			if ( ! is_string( $json ) ) {
				throw new WorkflowRunStoreException( 'output_encoding_failed' );
			}
			$ciphertext = $this->seal( $json );
		} catch ( WorkflowRunStoreException $exception ) {
			throw $exception;
		} catch ( Throwable ) {
			throw new WorkflowRunStoreException( 'output_encoding_failed' );
		}

		return $this->finish_step( $run_id, $step_id, $fence, $actor_id, WorkflowStepState::COMPLETED, $result->code(), '', $ciphertext, hash( 'sha256', $json ) );
	}

	public function fail_step( string $run_id, string $step_id, int $fence, string $error_code, int $actor_id ): ?WorkflowStepRecord {
		$this->ensure_storage();
		if ( $actor_id < 1 || $fence < 1 || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $error_code ) ) {
			throw new WorkflowRunStoreException( 'invalid_step_result' );
		}

		return $this->finish_step( $run_id, $step_id, $fence, $actor_id, WorkflowStepState::FAILED, '', $error_code, '', '' );
	}

	/** Ensure run tables exist. */
	private function ensure_storage(): void {
		if ( ! RunInstaller::install() ) {
			throw new WorkflowRunStoreException( 'storage_unavailable' );
		}
	}

	/**
	 * Validate exact plan identity before persistence.
	 *
	 * @param string       $run_id              Run ID.
	 * @param string       $workflow_id         Workflow ID.
	 * @param int          $workflow_version    Pinned version.
	 * @param string       $definition_checksum Pinned checksum.
	 * @param WorkflowPlan $plan                Exact plan.
	 */
	private function assert_identity( string $run_id, string $workflow_id, int $workflow_version, string $definition_checksum, WorkflowPlan $plan ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{1,63}$/D', $run_id ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $workflow_id ) || $workflow_version < 1 || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $definition_checksum ) || ! preg_match( '/^[a-f0-9]{64}$/D', $plan->hash() ) ) {
			throw new WorkflowRunStoreException( 'invalid_run_identity' );
		}
		$identity = $plan->identity();
		if ( (string) ( $identity['workflow_id'] ?? '' ) !== $workflow_id || (int) ( $identity['definition_revision'] ?? 0 ) !== $workflow_version || (string) ( $identity['definition_checksum'] ?? '' ) !== $definition_checksum ) {
			throw new WorkflowRunStoreException( 'plan_definition_mismatch' );
		}
	}

	/** Insert all exact plan step bindings in one transaction. */
	private function insert_steps( int $run_pk, WorkflowPlan $plan, string $now ): void {
		$identity = $plan->identity();
		$steps    = $identity['steps'] ?? array();
		if ( ! is_array( $steps ) || array() === $steps ) {
			throw new WorkflowRunStoreException( 'steps_missing' );
		}

		global $wpdb;
		foreach ( $steps as $position => $raw_step ) {
			$step = $raw_step instanceof stdClass ? get_object_vars( $raw_step ) : $raw_step;
			if ( ! is_array( $step ) ) {
				throw new WorkflowRunStoreException( 'steps_invalid' );
			}
			$inserted = $wpdb->insert(
				RunInstaller::table_names()['steps'],
				array(
					'run_pk'          => $run_pk,
					'step_id'         => (string) ( $step['step_id'] ?? '' ),
					'step_position'   => (int) $position,
					'adapter_id'      => (string) ( $step['adapter_id'] ?? '' ),
					'adapter_version' => (int) ( $step['adapter_version'] ?? 0 ),
					'ability_id'      => (string) ( $step['ability_id'] ?? '' ),
					'kind'            => (string) ( $step['kind'] ?? '' ),
					'created_at'      => $now,
					'updated_at'      => $now,
				),
				array( '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			if ( false === $inserted ) {
				throw new WorkflowRunStoreException( 'step_create_failed' );
			}
		}
	}

	/** Finish one claimed step through an exact fence. */
	private function finish_step( string $run_id, string $step_id, int $fence, int $actor_id, WorkflowStepState $state, string $result_code, string $error_code, string $ciphertext, string $output_hash ): ?WorkflowStepRecord {
		$run = $this->run_row( $run_id );
		if ( null === $run || WorkflowRunState::RUNNING->value !== (string) ( $run['state'] ?? '' ) ) {
			return null;
		}
		global $wpdb;
		$now       = $this->now_sql();
		$query     = 'UPDATE %i AS s SET state = %s, result_code = %s, error_code = %s, output_ciphertext = %s, output_hash = %s, lease_expires_at = NULL, completed_at = %s, updated_at = %s WHERE s.run_pk = %d AND s.step_id = %s AND s.state = %s AND s.fence = %d AND EXISTS (SELECT 1 FROM %i AS r WHERE r.id = s.run_pk AND r.state = %s)';
		$arguments = array(
			RunInstaller::table_names()['steps'],
			$state->value,
			$result_code,
			$error_code,
			$ciphertext,
			$output_hash,
			$now,
			$now,
			(int) $run['id'],
			$step_id,
			WorkflowStepState::RUNNING->value,
			$fence,
			RunInstaller::table_names()['runs'],
			WorkflowRunState::RUNNING->value,
		);
		if ( WorkflowStepState::COMPLETED === $state || ( WorkflowStepState::FAILED === $state && 'execution_uncertain' !== $error_code ) ) {
			$query      .= ' AND (s.lease_expires_at IS NULL OR s.lease_expires_at > %s)';
			$arguments[] = $now;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query shape contains only fixed fragments and all values use placeholders.
		$updated = $wpdb->query( $wpdb->prepare( $query, ...$arguments ) );
		if ( 1 !== (int) $updated ) {
			$current_run = $this->run_row( $run_id );
			if ( null === $current_run || WorkflowRunState::RUNNING->value !== (string) ( $current_run['state'] ?? '' ) ) {
				return null;
			}
			$current = $this->step( $run_id, $step_id );
			if ( null === $current || $current->fence() !== $fence ) {
				return null;
			}
			if ( WorkflowStepState::RUNNING === $current->state() && $this->lease_expired( $current ) ) {
				return null;
			}

			return $current;
		}

		$record = $this->step( $run_id, $step_id );
		if ( null !== $record ) {
			$this->touch_run( $run_id, $actor_id );
		}

		return $record;
	}

	/** Return one step row and detach its encrypted output. */
	private function step( string $run_id, string $step_id ): ?WorkflowStepRecord {
		$run = $this->run_row( $run_id );
		if ( null === $run ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT s.*, r.run_id FROM %i s INNER JOIN %i r ON r.id = s.run_pk WHERE s.run_pk = %d AND s.step_id = %s LIMIT 1',
				RunInstaller::table_names()['steps'],
				RunInstaller::table_names()['runs'],
				(int) $run['id'],
				$step_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->mapper->step( $row ) : null;
	}

	/**
	 * Return one run database row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function run_row( string $run_id ): ?array {
		if ( '' === $run_id ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %s LIMIT 1', RunInstaller::table_names()['runs'], $run_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/** Update run activity without changing its lifecycle fence. */
	private function touch_run( string $run_id, int $actor_id ): void {
		global $wpdb;
		$wpdb->update(
			RunInstaller::table_names()['runs'],
			array(
				'updated_by' => $actor_id,
				'updated_at' => $this->now_sql(),
			),
			array( 'run_id' => $run_id ),
			array( '%d', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Delete a bounded batch of stale terminal or expired waiting runs.
	 *
	 * Run and step tables intentionally have no foreign key so the plugin can
	 * install safely on existing WordPress databases. Delete child steps first
	 * and only after selecting exact run identities, keeping output retention
	 * bounded without touching active runs.
	 */
	private function prune_retention(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'query' ) ) {
			return;
		}

		$now     = ( $this->clock )();
		$now_sql = gmdate( 'Y-m-d H:i:s', $now );
		$cutoff  = gmdate( 'Y-m-d H:i:s', $now - self::RETENTION_SECONDS );
		$tables  = RunInstaller::table_names();
		$this->fence_abandoned_running( $tables, $cutoff, $now_sql );
		try {
			$run_ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE ((state IN (%s, %s, %s, %s, %s, %s) AND updated_at < %s) OR (state IN (%s, %s) AND waiting_expires_at IS NOT NULL AND waiting_expires_at <= %s)) ORDER BY updated_at ASC, id ASC LIMIT %d',
					$tables['runs'],
					WorkflowRunState::CREATED->value,
					WorkflowRunState::PREPARED->value,
					WorkflowRunState::DRY_RUN_READY->value,
					WorkflowRunState::COMPLETED->value,
					WorkflowRunState::FAILED->value,
					WorkflowRunState::CANCELLED->value,
					$cutoff,
					WorkflowRunState::WAITING_FOR_INPUT->value,
					WorkflowRunState::WAITING_FOR_APPROVAL->value,
					$now_sql,
					self::PRUNE_LIMIT
				)
			);
		} catch ( Throwable ) {
			return;
		}
		if ( ! is_array( $run_ids ) ) {
			return;
		}

		foreach ( $run_ids as $run_id ) {
			$run_id = (int) $run_id;
			if ( $run_id < 1 ) {
				continue;
			}
			try {
				$this->begin();
				$deleted = $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM %i WHERE id = %d AND ((state IN (%s, %s, %s, %s, %s, %s) AND updated_at < %s) OR (state IN (%s, %s) AND waiting_expires_at IS NOT NULL AND waiting_expires_at <= %s))',
						$tables['runs'],
						$run_id,
						WorkflowRunState::CREATED->value,
						WorkflowRunState::PREPARED->value,
						WorkflowRunState::DRY_RUN_READY->value,
						WorkflowRunState::COMPLETED->value,
						WorkflowRunState::FAILED->value,
						WorkflowRunState::CANCELLED->value,
						$cutoff,
						WorkflowRunState::WAITING_FOR_INPUT->value,
						WorkflowRunState::WAITING_FOR_APPROVAL->value,
						$now_sql
					)
				);
				if ( false === $deleted ) {
					throw new WorkflowRunStoreException( 'retention_delete_failed' );
				}
				if ( 1 === (int) $deleted ) {
					$steps_deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE run_pk = %d', $tables['steps'], $run_id ) );
					if ( false === $steps_deleted ) {
						throw new WorkflowRunStoreException( 'retention_steps_delete_failed' );
					}
				}
				$this->commit();
			} catch ( Throwable ) {
				$this->rollback();
				// Retention is best effort; a storage outage must not block a new run.
				continue;
			}
		}
	}

	/**
	 * Fence stale running rows into an explicitly uncertain terminal state.
	 *
	 * @param array<string,string> $tables Table names.
	 * @param string               $cutoff Retention cutoff.
	 * @param string               $now_sql Current UTC timestamp.
	 */
	private function fence_abandoned_running( array $tables, string $cutoff, string $now_sql ): void {
		global $wpdb;
		try {
			$updated = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i AS r SET state = %s, state_version = state_version + 1, outcome_code = %s, waiting_expires_at = NULL, updated_by = %d, updated_at = %s WHERE r.state = %s AND r.updated_at < %s AND NOT EXISTS (SELECT 1 FROM %i AS s WHERE s.run_pk = r.id AND s.state = %s AND (s.lease_expires_at IS NULL OR s.lease_expires_at > %s))',
					$tables['runs'],
					WorkflowRunState::FAILED->value,
					'execution_uncertain',
					0,
					$now_sql,
					WorkflowRunState::RUNNING->value,
					$cutoff,
					$tables['steps'],
					WorkflowStepState::RUNNING->value,
					$now_sql
				)
			);
			if ( false === $updated ) {
				return;
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
			// Retention is best effort; an unavailable store must not block a new run.
		}
	}

	/** Seal one bounded JSON payload. */
	private function seal( string $json ): string {
		try {
			return $this->vault->seal( $json );
		} catch ( Throwable ) {
			throw new WorkflowRunStoreException( 'payload_storage_unavailable' );
		}
	}

	private function now_sql(): string {
		return gmdate( 'Y-m-d H:i:s', ( $this->clock )() );
	}

	/** Return whether a step lease has expired against the store clock. */
	private function lease_expired( WorkflowStepRecord $step ): bool {
		$lease = $step->lease_expires_at();
		if ( null === $lease || '' === $lease ) {
			return false;
		}
		$expires = strtotime( $lease . ' UTC' );

		return false !== $expires && $expires <= ( $this->clock )();
	}

	private function begin(): void {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new WorkflowRunStoreException( 'transaction_failed' );
		}
	}

	private function commit(): void {
		global $wpdb;
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new WorkflowRunStoreException( 'transaction_failed' );
		}
	}

	private function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}
}
