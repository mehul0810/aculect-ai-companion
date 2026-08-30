<?php
/**
 * Atomic workflow run lifecycle state transitions.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

use Aculect\AICompanion\Workflows\Database\RunInstaller;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use Closure;

/**
 * Keeps parent fencing and waiting-deadline predicates outside the run store.
 */
final class WorkflowRunStateTransition {

	public function __construct( private Closure $clock ) {}

	/**
	 * Apply one lifecycle CAS while fencing active steps and expired waits.
	 *
	 * @param string           $run_id              Durable run ID.
	 * @param WorkflowRunState $expected_state      State expected by the caller.
	 * @param int              $expected_version    State version expected by the caller.
	 * @param WorkflowRunState $next_state           State to persist.
	 * @param int              $actor_id             Authenticated actor.
	 * @param string|null      $outcome_code         Terminal outcome code.
	 * @param string|null      $waiting_expires_at   Waiting deadline, when applicable.
	 * @return int|false Number of updated rows, or false on SQL failure.
	 */
	public function apply(
		string $run_id,
		WorkflowRunState $expected_state,
		int $expected_version,
		WorkflowRunState $next_state,
		int $actor_id,
		?string $outcome_code,
		?string $waiting_expires_at
	): int|false {
		global $wpdb;
		$tables = RunInstaller::table_names();
		$now    = $this->now_sql();

		if ( WorkflowRunState::CANCELLED === $next_state ) {
			return $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i AS parent_run SET state = %s, state_version = %d, outcome_code = %s, waiting_expires_at = NULL, updated_by = %d, updated_at = %s WHERE parent_run.run_id = %s AND parent_run.state = %s AND parent_run.state_version = %d AND NOT EXISTS (SELECT 1 FROM %i AS step_row WHERE step_row.run_pk = parent_run.id AND step_row.state = %s)',
					$tables['runs'],
					$next_state->value,
					$expected_version + 1,
					$outcome_code ?? '',
					$actor_id,
					$now,
					$run_id,
					$expected_state->value,
					$expected_version,
					$tables['steps'],
					WorkflowStepState::RUNNING->value
				)
			);
		}

		if ( WorkflowRunState::WAITING_FOR_INPUT === $expected_state ) {
			return $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET state = %s, state_version = %d, outcome_code = %s, waiting_expires_at = NULL, updated_by = %d, updated_at = %s WHERE run_id = %s AND state = %s AND state_version = %d AND waiting_expires_at IS NOT NULL AND waiting_expires_at > %s',
					$tables['runs'],
					$next_state->value,
					$expected_version + 1,
					$outcome_code ?? '',
					$actor_id,
					$now,
					$run_id,
					$expected_state->value,
					$expected_version,
					$now
				)
			);
		}

		return $wpdb->update(
			$tables['runs'],
			array(
				'state'              => $next_state->value,
				'state_version'      => $expected_version + 1,
				'outcome_code'       => $outcome_code ?? '',
				'waiting_expires_at' => $waiting_expires_at,
				'updated_by'         => $actor_id,
				'updated_at'         => $now,
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

	/**
	 * Return whether one persisted waiting deadline is still live.
	 *
	 * @param mixed  $expires_at Persisted deadline.
	 * @param string $now        Current UTC timestamp.
	 */
	public static function waiting_deadline_is_live( mixed $expires_at, string $now ): bool {
		return is_string( $expires_at ) && '' !== $expires_at && $expires_at > $now;
	}

	private function now_sql(): string {
		return gmdate( 'Y-m-d H:i:s', ( $this->clock )() );
	}
}
