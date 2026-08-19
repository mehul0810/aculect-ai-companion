<?php
/**
 * Deterministic workflow plan builder.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionCompatibilityMetadata;
use stdClass;

/**
 * Projects an immutable definition and normalized input into a stable plan.
 */
final class WorkflowPlanBuilder {

	/**
	 * Build one deterministic plan.
	 *
	 * @param WorkflowDefinition    $definition Validated immutable definition.
	 * @param WorkflowInputContract $input      Normalized input.
	 * @throws WorkflowPlanningException When definition/input projection fails closed.
	 */
	public function build( WorkflowDefinition $definition, WorkflowInputContract $input ): WorkflowPlan {
		return WorkflowPlan::from_definition( $definition, $input );
	}

	/**
	 * Project verified plan data for the factory-only WorkflowPlan boundary.
	 *
	 * @param WorkflowDefinition    $definition Validated immutable definition.
	 * @param WorkflowInputContract $input      Normalized input.
	 * @return array{identity:array<string,mixed>,missing_paths:list<string>,invalid_paths:list<string>,canonical:string,plan_hash:string}
	 * @throws WorkflowPlanningException When definition/input projection fails closed.
	 * @internal
	 */
	public function project( WorkflowDefinition $definition, WorkflowInputContract $input ): array {
		$value    = $definition->to_array();
		$metadata = ( new WorkflowDefinitionCompatibilityMetadata() )->for_definition( $definition );
		$schema   = $value['input_schema'];
		if ( ! is_array( $schema ) && ! $schema instanceof stdClass ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.input_schema' );
		}

		$validation = ( new WorkflowInputValidator() )->validate( $input, $schema );
		$steps      = array();
		$step_ids   = array();
		$abilities  = array();
		foreach ( $value['steps'] as $step_value ) {
			$step                            = $this->map( $step_value );
			$row                             = array(
				'step_id'         => (string) $step['step_id'],
				'adapter_id'      => (string) $step['adapter_id'],
				'adapter_version' => (int) $step['adapter_version'],
				'ability_id'      => (string) $step['ability_id'],
				'kind'            => (string) $step['kind'],
				'depends_on'      => array_values( $step['depends_on'] ),
			);
			$steps[]                         = $row;
			$step_ids[]                      = $row['step_id'];
			$abilities[ $row['ability_id'] ] = true;
		}

		$ability_requirements = array_keys( $abilities );
		sort( $ability_requirements, SORT_STRING );

		$validation_rule_ids = array();
		foreach ( $value['validation_rules'] as $rule_value ) {
			$rule                  = $this->map( $rule_value );
			$validation_rule_ids[] = (string) $rule['rule_id'];
		}

		$identity = array(
			'plan_version'              => 1,
			'workflow_id'               => (string) $metadata['workflow_id'],
			'definition_schema_version' => (int) $metadata['definition_schema_version'],
			'definition_revision'       => (int) $metadata['workflow_version'],
			'definition_checksum'       => (string) $metadata['checksum'],
			'input_contract_version'    => (int) $metadata['input_contract_version'],
			'output_contract_version'   => (int) $metadata['output_contract_version'],
			'adapter_requirements'      => $metadata['adapter_requirements'],
			'ability_requirements'      => $ability_requirements,
			'step_ids'                  => $step_ids,
			'steps'                     => $steps,
			'approval_gate_step_ids'    => array_values( $value['approval_gates'] ),
			'validation_rule_ids'       => $validation_rule_ids,
			'normalized_input_hash'     => $input->hash(),
		);

		$encoded = ( new WorkflowPlanningCanonicalizer() )->normalize_and_encode( $identity );
		if ( ! $encoded['value'] instanceof stdClass ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.plan' );
		}

		/**
		 * Canonical plan identity.
		 *
		 * @var array<string, mixed> $identity
		 */
		$identity = get_object_vars( $encoded['value'] );

		return array(
			'identity'      => $identity,
			'missing_paths' => $validation->missing_paths(),
			'invalid_paths' => $validation->invalid_paths(),
			'canonical'     => $encoded['json'],
			'plan_hash'     => hash( 'sha256', $encoded['json'] ),
		);
	}

	/**
	 * Convert one validated explicit object to an associative map.
	 *
	 * @param mixed $value Object value.
	 * @return array<string, mixed>
	 * @throws WorkflowPlanningException When the definition invariant is broken.
	 */
	private function map( mixed $value ): array {
		if ( is_array( $value ) ) {
			/**
			 * Explicit object map.
			 *
			 * @var array<string, mixed> $value
			 */
			return $value;
		}

		if ( ! $value instanceof stdClass ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.definition' );
		}

		$map = array();
		// @phpstan-ignore-next-line -- Native iteration preserves numeric JSON object member names.
		foreach ( $value as $key => $item ) {
			$map[ $key ] = $item;
		}

		return $map;
	}
}
