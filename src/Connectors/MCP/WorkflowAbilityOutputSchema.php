<?php
/**
 * Output schema declarations for custom workflow abilities.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Keeps the custom workflow output contract out of the MCP transport class.
 *
 * @internal
 */
final class WorkflowAbilityOutputSchema {

	/**
	 * Return whether a module belongs to the custom workflow surface.
	 *
	 * @param string $module_id Internal module ID.
	 */
	public static function supports( string $module_id ): bool {
		return in_array(
			$module_id,
			array(
				'content_workflow.list',
				'content_workflow.get',
				'content_workflow.prepare',
				'content_workflow.dry_run',
				'content_workflow.execute',
				'content_workflow.resume',
				'content_workflow.cancel',
				'content_workflow.status',
				'content_workflow.result',
			),
			true
		);
	}

	/**
	 * Return the bounded fields exposed by custom workflow responses.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function fields(): array {
		return array(
			'status'              => array( 'type' => 'string' ),
			'error'               => array( 'type' => 'string' ),
			'message'             => array( 'type' => 'string' ),
			'bounded'             => array( 'type' => 'boolean' ),
			'custom_workflows'    => array( 'type' => 'array' ),
			'fixed_guides'        => array( 'type' => 'array' ),
			'pagination'          => array( 'type' => 'object' ),
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
		);
	}
}
