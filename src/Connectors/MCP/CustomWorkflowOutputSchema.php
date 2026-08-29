<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Publishes one bounded output contract for custom workflow tools.
 */
final class CustomWorkflowOutputSchema {

	/**
	 * Return the custom workflow schema for a module ID, when applicable.
	 *
	 * @param string $module_id Internal module ID.
	 * @return array<string, mixed>
	 */
	public static function for_id( string $module_id ): array {
		if ( ! in_array( $module_id, self::module_ids(), true ) ) {
			return array();
		}

		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status'              => array( 'type' => 'string' ),
				'error'               => array( 'type' => 'string' ),
				'message'             => array( 'type' => 'string' ),
				'bounded'             => array( 'type' => 'boolean' ),
				'custom_workflows'    => array( 'type' => 'array' ),
				'fixed_guides'        => array( 'type' => 'array' ),
				'workflow'            => array( 'type' => 'object' ),
				'run'                 => array( 'type' => 'object' ),
				'plan'                => array( 'type' => 'object' ),
				'dry_run'             => array( 'type' => 'object' ),
				'readiness'           => array( 'type' => 'object' ),
				'risks'               => array( 'type' => 'object' ),
				'step'                => array( 'type' => 'object' ),
				'step_result'         => array( 'type' => 'object' ),
				'audit'               => array( 'type' => 'array' ),
				'progressed'          => array( 'type' => 'boolean' ),
				'missing_input_paths' => array( 'type' => 'array' ),
				'missing_bindings'    => array( 'type' => 'array' ),
				'missing_abilities'   => array( 'type' => 'array' ),
				'approval_gate_ids'   => array( 'type' => 'array' ),
				'next_actions'        => array( 'type' => 'array' ),
			),
			'additionalProperties' => true,
		);
	}

	/**
	 * Return custom workflow module IDs.
	 *
	 * @return list<string>
	 */
	private static function module_ids(): array {
		return array(
			'content_workflow.list',
			'content_workflow.get',
			'content_workflow.prepare',
			'content_workflow.dry_run',
			'content_workflow.execute',
			'content_workflow.resume',
			'content_workflow.cancel',
			'content_workflow.status',
			'content_workflow.result',
		);
	}
}
