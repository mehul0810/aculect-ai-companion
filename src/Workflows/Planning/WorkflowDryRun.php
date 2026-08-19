<?php
/**
 * Immutable deterministic workflow dry-run projection.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Exposes declarative planned work without raw input or execution.
 */
final readonly class WorkflowDryRun {

	/**
	 * Create a dry-run projection.
	 *
	 * @param array<string, mixed> $value     Deterministic projection.
	 * @param string               $canonical Canonical JSON.
	 */
	private function __construct(
		private array $value,
		private string $canonical
	) {
	}

	/**
	 * Build a dry-run from an immutable plan.
	 *
	 * @param WorkflowPlan $plan Immutable bound plan.
	 * @throws WorkflowPlanningException When the internal plan invariant is invalid.
	 */
	public static function from_plan( WorkflowPlan $plan ): self {
		$identity = $plan->identity();
		$rows     = array();
		foreach ( $identity['steps'] as $position => $step ) {
			$rows[] = array(
				'position'        => $position,
				'step_id'         => $step->step_id,
				'kind'            => $step->kind,
				'adapter_id'      => $step->adapter_id,
				'adapter_version' => $step->adapter_version,
				'ability_id'      => $step->ability_id,
				'depends_on'      => $step->depends_on,
				'status'          => 'planned',
			);
		}

		$value = array(
			'dry_run_version'           => 1,
			'plan_hash'                 => $plan->hash(),
			'workflow_id'               => $identity['workflow_id'],
			'definition_schema_version' => $identity['definition_schema_version'],
			'definition_revision'       => $identity['definition_revision'],
			'definition_checksum'       => $identity['definition_checksum'],
			'normalized_input_hash'     => $identity['normalized_input_hash'],
			'input_contract_version'    => $identity['input_contract_version'],
			'output_contract_version'   => $identity['output_contract_version'],
			'adapter_requirements'      => $identity['adapter_requirements'],
			'ability_requirements'      => $identity['ability_requirements'],
			'steps'                     => $rows,
			'approval_gate_step_ids'    => $identity['approval_gate_step_ids'],
			'validation_rule_ids'       => $identity['validation_rule_ids'],
			'missing_input_paths'       => $plan->missing_paths(),
			'invalid_input_paths'       => $plan->invalid_paths(),
			'validation_status'         => $plan->requires_validation() ? 'deferred' : 'complete',
			'execution_started'         => false,
		);

		$encoded = ( new WorkflowPlanningCanonicalizer() )->normalize_and_encode( $value );
		if ( ! $encoded['value'] instanceof \stdClass ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.dry_run' );
		}

		/**
		 * Normalized dry-run data.
		 *
		 * @var array<string, mixed> $normalized
		 */
		$normalized = get_object_vars( $encoded['value'] );

		return new self( $normalized, $encoded['json'] );
	}

	/**
	 * Return a detached dry-run map.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		/**
		 * Detached dry-run data.
		 *
		 * @var array<string, mixed> $copy
		 */
		$copy = ( new WorkflowPlanningCanonicalizer() )->copy( $this->value );

		return $copy;
	}

	/**
	 * Return canonical JSON.
	 */
	public function canonical_json(): string {
		return $this->canonical;
	}

	/**
	 * Return the bound plan hash.
	 */
	public function plan_hash(): string {
		return (string) $this->value['plan_hash'];
	}
}
