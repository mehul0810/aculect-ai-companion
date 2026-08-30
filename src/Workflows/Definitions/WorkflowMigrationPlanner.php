<?php
/**
 * Workflow definition migration preview service.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use JsonException;
use stdClass;

/**
 * Produces a deterministic, fail-closed migration preview before a new
 * immutable workflow version is persisted.
 */
final class WorkflowMigrationPlanner {

	/**
	 * Preview changes between two validated workflow definitions.
	 *
	 * Aliases may make a step or ability rename safe, but they never alter the
	 * stored definition or silently change an active run's pinned version.
	 *
	 * @param WorkflowDefinition    $source         Existing definition.
	 * @param WorkflowDefinition    $target         Candidate next definition.
	 * @param array<string, string> $step_aliases Old-to-new step IDs.
	 * @param array<string, string> $ability_aliases Old-to-new ability IDs.
	 * @throws WorkflowDefinitionValidationException When aliases or definitions are invalid.
	 */
	public function preview(
		WorkflowDefinition $source,
		WorkflowDefinition $target,
		array $step_aliases = array(),
		array $ability_aliases = array()
	): WorkflowMigrationPlan {
		$report          = ( new WorkflowDefinitionCompatibilityEvaluator() )->evaluate( $source, $target );
		$step_aliases    = $this->normalize_aliases( $step_aliases, 'step' );
		$ability_aliases = $this->normalize_aliases( $ability_aliases, 'ability' );
		$actions         = array();

		$this->add_step_actions( $source, $target, $step_aliases, $ability_aliases, $actions );
		$this->add_ability_actions( $source, $target, $ability_aliases, $actions );
		$this->add_report_actions( $report, $actions );
		usort( $actions, static fn ( array $left, array $right ): int => array( $left['path'], $left['code'] ) <=> array( $right['path'], $right['code'] ) );

		$status = $this->status( $actions );
		$seed   = $report->source_checksum() . '|' . $report->target_checksum() . '|' . $this->canonical_aliases( $step_aliases, $ability_aliases );

		return new WorkflowMigrationPlan(
			$report,
			$status,
			$actions,
			$step_aliases,
			$ability_aliases,
			hash( 'sha256', $seed )
		);
	}

	/**
	 * Add explicit step-removal/rename actions.
	 *
	 * @param WorkflowDefinition                                        $source         Existing definition.
	 * @param WorkflowDefinition                                        $target         Candidate definition.
	 * @param array<string, string>                                     $step_aliases   Step aliases.
	 * @param array<string, string>                                     $ability_aliases Ability aliases.
	 * @param list<array{code: string, path: string, guidance: string}> $actions Actions.
	 */
	private function add_step_actions( WorkflowDefinition $source, WorkflowDefinition $target, array $step_aliases, array $ability_aliases, array &$actions ): void {
		$source_steps = $this->steps_by_id( $source );
		$target_steps = $this->steps_by_id( $target );
		foreach ( $source_steps as $step_id => $step ) {
			if ( isset( $target_steps[ $step_id ] ) ) {
				continue;
			}
			$alias = $step_aliases[ $step_id ] ?? '';
			if ( '' !== $alias && isset( $target_steps[ $alias ] ) && $this->same_step_except_identity( $step, $target_steps[ $alias ], $step_aliases, $ability_aliases ) ) {
				$this->add_action(
					$actions,
					array(
						'code'     => 'step_alias_applied',
						'path'     => '$.steps',
						'guidance' => 'Step rename is covered by an explicit compatibility alias.',
					)
				);
				continue;
			}
			$this->add_action(
				$actions,
				array(
					'code'     => 'step_removed',
					'path'     => '$.steps',
					'guidance' => 'Repair the workflow or provide a validated step alias before migration.',
				)
			);
		}
	}

	/**
	 * Add explicit ability-removal/rename actions.
	 *
	 * @param WorkflowDefinition                                        $source  Existing definition.
	 * @param WorkflowDefinition                                        $target  Candidate definition.
	 * @param array<string, string>                                     $aliases Ability aliases.
	 * @param list<array{code: string, path: string, guidance: string}> $actions Actions.
	 */
	private function add_ability_actions( WorkflowDefinition $source, WorkflowDefinition $target, array $aliases, array &$actions ): void {
		$source_value = $source->to_array();
		$target_value = $target->to_array();
		$target_ids   = array_fill_keys( $target_value['allowed_abilities'], true );
		foreach ( $source_value['allowed_abilities'] as $ability_id ) {
			if ( isset( $target_ids[ $ability_id ] ) ) {
				continue;
			}
			$alias = $aliases[ $ability_id ] ?? '';
			if ( '' !== $alias && isset( $target_ids[ $alias ] ) ) {
				$this->add_action(
					$actions,
					array(
						'code'     => 'ability_alias_applied',
						'path'     => '$.allowed_abilities',
						'guidance' => 'Ability rename is covered by an explicit compatibility alias.',
					)
				);
				continue;
			}
			$this->add_action(
				$actions,
				array(
					'code'     => 'ability_removed',
					'path'     => '$.allowed_abilities',
					'guidance' => 'Restore the ability or repair every step before migration.',
				)
			);
		}
	}

	/**
	 * Convert evaluator changes into bounded human-actionable guidance.
	 *
	 * @param WorkflowDefinitionCompatibilityReport                     $report  Compatibility report.
	 * @param list<array{code: string, path: string, guidance: string}> $actions Actions.
	 */
	private function add_report_actions( WorkflowDefinitionCompatibilityReport $report, array &$actions ): void {
		$blocked = array( 'write_policy_changed', 'approval_gates_changed', 'publication_status_changed', 'creation_identity_changed' );
		$review  = array( 'required_input_changed', 'input_schema_changed', 'output_contract_changed', 'contract_versions_changed', 'content_target_changed', 'validation_rules_changed', 'step_graph_changed', 'allowed_abilities_changed' );
		foreach ( $report->changes() as $change ) {
			if ( in_array( $change['code'], $blocked, true ) ) {
					$this->add_action(
						$actions,
						array(
							'code'     => $change['code'],
							'path'     => $change['path'],
							'guidance' => 'Manual administrator review is required before this behavior change can be applied.',
						)
					);
				continue;
			}
			if ( in_array( $change['code'], $review, true ) ) {
				$this->add_action(
					$actions,
					array(
						'code'     => $change['code'],
						'path'     => $change['path'],
						'guidance' => 'Review the compatibility diff and approve an explicit migration before applying.',
					)
				);
			}
		}
	}

	/**
	 * Return the final migration status from bounded actions.
	 *
	 * @param list<array{code: string, path: string, guidance: string}> $actions Actions.
	 */
	private function status( array $actions ): string {
		foreach ( $actions as $action ) {
			if ( in_array( $action['code'], array( 'step_removed', 'ability_removed', 'write_policy_changed', 'approval_gates_changed', 'publication_status_changed', 'creation_identity_changed' ), true ) ) {
				return WorkflowMigrationPlan::BLOCKED;
			}
		}

		return array() === $actions ? WorkflowMigrationPlan::READY : WorkflowMigrationPlan::REVIEW_REQUIRED;
	}

	/**
	 * Normalize and validate alias maps.
	 *
	 * @param array<mixed> $aliases Raw aliases.
	 * @param string       $kind    Alias kind.
	 * @return array<string, string>
	 * @throws WorkflowDefinitionValidationException When an alias is invalid.
	 */
	private function normalize_aliases( array $aliases, string $kind ): array {
		$pattern = 'ability' === $kind
			? '#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*$#'
			: '/^[a-z][a-z0-9_]*$/';
		if ( count( $aliases ) > 50 ) {
			throw new WorkflowDefinitionValidationException( 'too_many_aliases', '$' );
		}
		$normalized = array();
		foreach ( $aliases as $from => $to ) {
			if ( ! is_string( $from ) || ! is_string( $to ) || strlen( $from ) > 128 || strlen( $to ) > 128 || 1 !== preg_match( $pattern, $from ) || 1 !== preg_match( $pattern, $to ) || $from === $to ) {
					throw new WorkflowDefinitionValidationException( 'invalid_alias', '$' );
			}
			$normalized[ $from ] = $to;
		}
		ksort( $normalized, SORT_STRING );

		$destinations = array();
		foreach ( $normalized as $from => $to ) {
			if ( isset( $normalized[ $to ] ) || isset( $destinations[ $to ] ) ) {
				// Aliases are one-hop mappings. Reject chains, cycles, and
				// converging destinations from the complete map so validation
				// cannot depend on insertion order.
				throw new WorkflowDefinitionValidationException( 'invalid_alias', '$' );
			}
			$destinations[ $to ] = $from;
		}

		return $normalized;
	}

	/**
	 * Return steps keyed by their stable IDs.
	 *
	 * @param WorkflowDefinition $definition Validated definition.
	 * @return array<string, array<string, mixed>>
	 */
	private function steps_by_id( WorkflowDefinition $definition ): array {
		$steps = array();
		foreach ( $definition->to_array()['steps'] as $step ) {
			$step                               = $step instanceof stdClass ? get_object_vars( $step ) : $step;
			$steps[ (string) $step['step_id'] ] = $step;
		}

		return $steps;
	}

	/**
	 * Compare step behavior after applying dependency aliases.
	 *
	 * @param array<string, mixed>  $source         Source step.
	 * @param array<string, mixed>  $target         Target step.
	 * @param array<string, string> $step_aliases   Step aliases.
	 * @param array<string, string> $ability_aliases Ability aliases.
	 */
	private function same_step_except_identity( array $source, array $target, array $step_aliases, array $ability_aliases ): bool {
		$source['step_id']    = $target['step_id'];
		$source['ability_id'] = $ability_aliases[ $source['ability_id'] ] ?? $source['ability_id'];
		$source['depends_on'] = array_map( static fn ( string $dependency ): string => $step_aliases[ $dependency ] ?? $dependency, $source['depends_on'] );
		return $this->same( $source, $target );
	}

	/**
	 * Return canonical alias data for deterministic plan IDs.
	 *
	 * @param array<string, string> $step_aliases   Step aliases.
	 * @param array<string, string> $ability_aliases Ability aliases.
	 */
	private function canonical_aliases( array $step_aliases, array $ability_aliases ): string {
		return $this->encode(
			array(
				'steps'     => $step_aliases,
				'abilities' => $ability_aliases,
			)
		);
	}

	/**
	 * Compare JSON-compatible values without exposing them in the plan.
	 *
	 * @param mixed $left  First value.
	 * @param mixed $right Second value.
	 */
	private function same( mixed $left, mixed $right ): bool {
		return $this->encode( $left ) === $this->encode( $right );
	}

	/**
	 * Encode a bounded JSON-compatible value without importing WordPress runtime.
	 *
	 * @param mixed $value JSON-compatible value.
	 * @throws WorkflowDefinitionValidationException When encoding unexpectedly fails.
	 */
	private function encode( mixed $value ): string {
		try {
			return json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure definition code cannot depend on WordPress runtime JSON helpers.
				$value,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( JsonException ) {
			throw new WorkflowDefinitionValidationException( 'migration_encoding_failed', '$' );
		}
	}

	/**
	 * Add a bounded action once.
	 *
	 * @param list<array{code: string, path: string, guidance: string}> $actions Existing actions.
	 * @param array{code: string, path: string, guidance: string}       $action  Candidate action.
	 */
	private function add_action( array &$actions, array $action ): void {
		foreach ( $actions as $existing ) {
			if ( $existing['code'] === $action['code'] && $existing['path'] === $action['path'] ) {
				return;
			}
		}

		$actions[] = $action;
	}
}
