<?php
/**
 * Deterministic workflow definition compatibility metadata.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use stdClass;

/**
 * Derives detached metadata from one validated immutable definition.
 */
final class WorkflowDefinitionCompatibilityMetadata {

	/**
	 * Return deterministic compatibility metadata.
	 *
	 * @param WorkflowDefinition $definition Validated definition value.
	 * @return array<string, mixed>
	 */
	public function for_definition( WorkflowDefinition $definition ): array {
		$value         = $definition->to_array();
		$compatibility = $value['compatibility'] instanceof stdClass ? get_object_vars( $value['compatibility'] ) : $value['compatibility'];
		$abilities     = $value['allowed_abilities'];
		sort( $abilities, SORT_STRING );

		$adapters = array();
		foreach ( $value['steps'] as $step ) {
			if ( $step instanceof stdClass ) {
				$step = get_object_vars( $step );
			}
			$adapter_id                                  = $step['adapter_id'];
			$adapter_version                             = $step['adapter_version'];
			$adapters[ $adapter_id ][ $adapter_version ] = true;
		}
		ksort( $adapters, SORT_STRING );

		$requirements = array();
		foreach ( $adapters as $adapter_id => $versions ) {
			$adapter_versions = array_map( 'intval', array_keys( $versions ) );
			sort( $adapter_versions, SORT_NUMERIC );
			$requirements[] = array(
				'adapter_id'       => $adapter_id,
				'adapter_versions' => $adapter_versions,
			);
		}

		return array(
			'definition_schema_version' => $value['definition_schema_version'],
			'workflow_id'               => $value['workflow_id'],
			'workflow_version'          => $value['workflow_version'],
			'checksum'                  => $definition->checksum(),
			'input_contract_version'    => $compatibility['input_contract_version'],
			'output_contract_version'   => $compatibility['output_contract_version'],
			'allowed_abilities'         => $abilities,
			'adapter_requirements'      => $requirements,
		);
	}
}
