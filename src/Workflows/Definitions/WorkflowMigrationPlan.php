<?php
/**
 * Bounded workflow definition migration preview.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

/**
 * Immutable, value-free migration decision for two workflow snapshots.
 */
final readonly class WorkflowMigrationPlan {

	public const READY           = 'ready';
	public const REVIEW_REQUIRED = 'review_required';
	public const BLOCKED         = 'blocked';

	/**
	 * Create a migration plan from validated preview data.
	 *
	 * @param WorkflowDefinitionCompatibilityReport                     $report       Compatibility report.
	 * @param string                                                    $status       Migration decision.
	 * @param list<array{code: string, path: string, guidance: string}> $actions Bounded actions.
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

		$previous = '';
		foreach ( $actions as $action ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $action['code'] )
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
		return array_map( static fn ( array $action ): array => $action, $this->actions );
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
