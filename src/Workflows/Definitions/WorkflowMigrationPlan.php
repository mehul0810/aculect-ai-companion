<?php
/**
 * Bounded workflow definition migration preview.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use JsonException;

/**
 * Immutable, value-free migration decision for two workflow snapshots.
 */
final readonly class WorkflowMigrationPlan {

	public const READY           = 'ready';
	public const REVIEW_REQUIRED = 'review_required';
	public const BLOCKED         = 'blocked';

	/**
	 * Actions that always require manual repair.
	 *
	 * @var list<string>
	 */
	private const BLOCKING_ACTIONS = array(
		'step_removed',
		'ability_removed',
		'write_policy_changed',
		'approval_gates_changed',
		'publication_status_changed',
		'creation_identity_changed',
	);

	/**
	 * Create a migration plan from validated preview data.
	 *
	 * @param WorkflowDefinitionCompatibilityReport                     $report       Compatibility report.
	 * @param string                                                    $status       Migration decision.
	 * @param list<array<string, mixed>>                              $actions Bounded actions.
	 * @param array<string, string>                                     $step_aliases Step rename aliases.
	 * @param array<string, string>                                     $ability_aliases Ability aliases.
	 * @param string                                                    $migration_id Stable plan identifier.
	 * @throws WorkflowDefinitionValidationException When the preview is invalid.
	 */
	public function __construct(
		private WorkflowDefinitionCompatibilityReport $report,
		private string $status,
		private array $actions,
		private array $step_aliases,
		private array $ability_aliases,
		private string $migration_id
	) {
		if ( ! in_array( $status, array( self::READY, self::REVIEW_REQUIRED, self::BLOCKED ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/', $migration_id )
			|| count( $actions ) > 16
			|| count( $step_aliases ) > 50
			|| count( $ability_aliases ) > 50 ) {
			throw new WorkflowDefinitionValidationException( 'invalid_migration_plan', '$' );
		}

		if ( ! hash_equals( $this->expected_migration_id( $report, $step_aliases, $ability_aliases ), $migration_id ) ) {
			throw new WorkflowDefinitionValidationException( 'invalid_migration_plan', '$.migration_id' );
		}

		$previous = '';
		foreach ( $actions as $action ) {
			if ( ! isset( $action['code'], $action['path'], $action['guidance'] )
				|| ! is_string( $action['code'] )
				|| ! is_string( $action['path'] )
				|| ! is_string( $action['guidance'] )
				|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $action['code'] )
				|| 1 !== preg_match( '/^\$\.[a-z0-9_.]{1,62}$/', $action['path'] )
				|| '' === $action['guidance']
				|| strlen( $action['guidance'] ) > 240 ) {
				throw new WorkflowDefinitionValidationException( 'invalid_migration_action', '$.actions' );
			}
			$key = $action['path'] . "\0" . $action['code'];
			if ( '' !== $previous && $key <= $previous ) {
				throw new WorkflowDefinitionValidationException( 'invalid_migration_order', '$.actions' );
			}
			$previous = $key;
		}

		if ( $status !== $this->expected_status( $report, $actions ) ) {
			throw new WorkflowDefinitionValidationException( 'invalid_migration_plan', '$.status' );
		}
	}

	/** Return the underlying compatibility report. */
	public function report(): WorkflowDefinitionCompatibilityReport {
		return $this->report;
	}

	/** Return the migration decision. */
	public function status(): string {
		return $this->status;
	}

	/** Whether this migration can be applied without manual repair. */
	public function can_apply(): bool {
		return self::READY === $this->status;
	}

	/**
	 * Return stable migration actions.
	 *
	 * @return list<array{code: string, path: string, guidance: string}>
	 */
	public function actions(): array {
		/** @var list<array{code: string, path: string, guidance: string}> $detached */
		$detached = array_map( static fn ( array $action ): array => $action, $this->actions );

		return $detached;
	}

	/**
	 * Return detached step rename aliases.
	 *
	 * @return array<string, string>
	 */
	public function step_aliases(): array {
		return $this->step_aliases;
	}

	/**
	 * Return detached ability aliases.
	 *
	 * @return array<string, string>
	 */
	public function ability_aliases(): array {
		return $this->ability_aliases;
	}

	/** Return the deterministic migration identifier. */
	public function migration_id(): string {
		return $this->migration_id;
	}

	/**
	 * Derive the only status that the report and actions can support.
	 *
	 * An approval-gate change may be reconciled by a behavior-validated step
	 * rename. The planner records that evidence as step_alias_applied; all
	 * other incompatible report changes remain blocking even when an untrusted
	 * caller omits the corresponding action.
	 *
	 * @param WorkflowDefinitionCompatibilityReport                     $report  Compatibility report.
	 * @param list<array<string, mixed>>                              $actions Migration actions.
	 */
	private function expected_status( WorkflowDefinitionCompatibilityReport $report, array $actions ): string {
		$action_codes                  = array_column( $actions, 'code' );
		$has_blocking_action           = array_intersect( self::BLOCKING_ACTIONS, $action_codes ) !== array();
		$has_review_action             = array() !== $actions;
		$has_unreconciled_incompatible = false;
		$has_reconciled_gate           = in_array( 'step_alias_applied', $action_codes, true )
			&& ! in_array( 'approval_gates_changed', $action_codes, true );

		foreach ( $report->changes() as $change ) {
			if ( WorkflowDefinitionCompatibilityReport::INCOMPATIBLE !== $change['classification'] ) {
				if ( WorkflowDefinitionCompatibilityReport::MIGRATION_REQUIRED === $change['classification'] ) {
					$has_review_action = true;
				}
				continue;
			}

			if ( 'approval_gates_changed' === $change['code'] && $has_reconciled_gate ) {
				$has_review_action = true;
				continue;
			}

			$has_unreconciled_incompatible = true;
		}

		if ( $has_blocking_action || $has_unreconciled_incompatible ) {
			return self::BLOCKED;
		}

		return $has_review_action ? self::REVIEW_REQUIRED : self::READY;
	}

	/**
	 * Recreate the deterministic identifier used by the planner.
	 *
	 * @param WorkflowDefinitionCompatibilityReport $report          Compatibility report.
	 * @param array<string, string>                 $step_aliases    Step aliases.
	 * @param array<string, string>                 $ability_aliases Ability aliases.
	 * @throws WorkflowDefinitionValidationException When aliases cannot be encoded.
	 */
	private function expected_migration_id( WorkflowDefinitionCompatibilityReport $report, array $step_aliases, array $ability_aliases ): string {
		ksort( $step_aliases, SORT_STRING );
		ksort( $ability_aliases, SORT_STRING );

		try {
			$aliases = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure plan identity does not depend on WordPress runtime.
				array(
					'steps'     => $step_aliases,
					'abilities' => $ability_aliases,
				),
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( JsonException ) {
			throw new WorkflowDefinitionValidationException( 'invalid_migration_plan', '$.migration_id' );
		}

		return hash( 'sha256', $report->source_checksum() . '|' . $report->target_checksum() . '|' . $aliases );
	}

	/**
	 * Return a detached serializable preview.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'migration_id'    => $this->migration_id,
			'status'          => $this->status,
			'workflow_id'     => $this->report->workflow_id(),
			'source_version'  => $this->report->source_revision(),
			'target_version'  => $this->report->target_revision(),
			'source_checksum' => $this->report->source_checksum(),
			'target_checksum' => $this->report->target_checksum(),
			'actions'         => $this->actions(),
			'step_aliases'    => $this->step_aliases(),
			'ability_aliases' => $this->ability_aliases(),
		);
	}
}
