<?php
/**
 * MCP modules for the durable custom workflow connector.
 *
 * @package Aculect\AICompanion\Connectors\MCP\Modules
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP\Modules;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleInterface;
use Aculect\AICompanion\Workflows\Connectors\WorkflowAbilityConnector;

/**
 * Owns the public custom workflow connector declarations.
 */
final class CustomWorkflowAbilityModules {

	public function __construct( private readonly AbilityModuleFactory $factory = new AbilityModuleFactory() ) {}

	/**
	 * Return custom workflow modules keyed by internal ID.
	 *
	 * @return array<string, AbilityModuleInterface>
	 */
	public function all(): array {
		$modules = array(
			$this->factory->create(
				'content_workflow.list',
				'List Custom Content Workflows',
				'List published custom workflows and fixed workflow guides available to the connected assistant.',
				'Custom Content Workflows',
				'content:read',
				true,
				$this->list_schema(),
				static fn ( array $args ): array => ( new WorkflowAbilityConnector() )->list_workflows( $args )
			),
			$this->factory->create(
				'content_workflow.get',
				'Get Custom Content Workflow',
				'Inspect one published custom workflow input schema, allowed abilities, ordered steps, write policy, approvals, and compatibility metadata.',
				'Custom Content Workflows',
				'content:read',
				true,
				$this->get_schema(),
				static fn ( array $args ): array => ( new WorkflowAbilityConnector() )->get( $args )
			),
			$this->factory->create(
				'content_workflow.prepare',
				'Prepare Custom Content Workflow',
				'Validate a custom workflow input and create a durable, resumable run without changing WordPress content.',
				'Custom Content Workflows',
				'content:read',
				true,
				$this->prepare_schema(),
				static fn ( array $args ): array => ( new WorkflowAbilityConnector() )->prepare( $args )
			),
			$this->factory->create(
				'content_workflow.dry_run',
				'Dry-Run Custom Content Workflow',
				'Return a deterministic plan of reads, proposals, writes, approvals, and validation requirements without changing WordPress content.',
				'Custom Content Workflows',
				'content:read',
				true,
				$this->run_input_schema(),
				static fn ( array $args ): array => ( new WorkflowAbilityConnector() )->dry_run( $args )
			),
			$this->factory->create(
				'content_workflow.execute',
				'Execute Custom Content Workflow',
				'Start or advance one approved custom workflow step while preserving global ability policy, OAuth scopes, WordPress capabilities, and durable run fencing.',
				'Custom Content Workflows',
				'content:draft',
				false,
				$this->execute_schema(),
				static fn ( array $args ): array => true === ( $args['dry_run'] ?? false )
					? ( new WorkflowAbilityConnector() )->preview_execute( $args )
					: ( new WorkflowAbilityConnector() )->execute( $args )
			),
			$this->factory->create(
				'content_workflow.resume',
				'Resume Custom Content Workflow',
				'Resume a custom workflow after missing input or approval, or advance its next durable step.',
				'Custom Content Workflows',
				'content:draft',
				false,
				$this->resume_schema(),
				static fn ( array $args ): array => true === ( $args['dry_run'] ?? false )
					? ( new WorkflowAbilityConnector() )->preview_execute( $args )
					: ( new WorkflowAbilityConnector() )->resume( $args )
			),
			$this->factory->create(
				'content_workflow.cancel',
				'Cancel Custom Content Workflow',
				'Cancel a custom workflow before execution, or stop a running workflow only after the current step reaches a safe boundary.',
				'Custom Content Workflows',
				'content:draft',
				false,
				$this->cancel_schema(),
				static fn ( array $args ): array => ( new WorkflowAbilityConnector() )->cancel( $args )
			),
			$this->factory->create(
				'content_workflow.status',
				'Get Custom Content Workflow Status',
				'Read a bounded custom workflow run state and step progress snapshot without returning input or result payloads.',
				'Custom Content Workflows',
				'content:read',
				true,
				$this->run_id_schema(),
				static fn ( array $args ): array => ( new WorkflowAbilityConnector() )->status( $args )
			),
			$this->factory->create(
				'content_workflow.result',
				'Get Custom Content Workflow Result',
				'Return a bounded run summary, step outcomes, and summary-only audit events without exposing secrets or raw payloads.',
				'Custom Content Workflows',
				'content:read',
				true,
				$this->run_id_schema(),
				static fn ( array $args ): array => ( new WorkflowAbilityConnector() )->result( $args )
			),
		);

		$keyed = array();
		foreach ( $modules as $module ) {
			$keyed[ $module->id() ] = $module;
		}

		return $keyed;
	}

	/**
	 * Build the list schema.
	 *
	 * @return array<string,mixed>
	 */
	private function list_schema(): array {
		return $this->object_schema(
			array(
				'limit' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
				),
				'page'  => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 1000,
					'description' => 'One-based page of published custom workflows.',
				),
			)
		);
	}

	/**
	 * Build the get schema.
	 *
	 * @return array<string,mixed>
	 */
	private function get_schema(): array {
		return $this->object_schema(
			array(
				'workflow_id' => array(
					'type'        => 'string',
					'description' => 'Published workflow ID returned by content_workflow_list.',
				),
				'id'          => array(
					'type'        => 'string',
					'description' => 'Alias for workflow_id.',
				),
				'version'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			array( 'workflow_id' )
		);
	}

	/**
	 * Build the prepare schema.
	 *
	 * @return array<string,mixed>
	 */
	private function prepare_schema(): array {
		return $this->object_schema(
			array(
				'workflow_id' => array( 'type' => 'string' ),
				'input'       => $this->input_schema(),
			),
			array( 'workflow_id', 'input' )
		);
	}

	/**
	 * Build the run-input schema.
	 *
	 * @return array<string,mixed>
	 */
	private function run_input_schema(): array {
		return $this->object_schema(
			array(
				'run_id' => array( 'type' => 'string' ),
				'input'  => $this->input_schema(),
			),
			array( 'run_id', 'input' )
		);
	}

	/**
	 * Build the execute schema.
	 *
	 * @return array<string,mixed>
	 */
	private function execute_schema(): array {
		return $this->object_schema(
			array(
				'run_id'   => array( 'type' => 'string' ),
				'input'    => $this->input_schema(),
				'approval' => $this->approval_schema(),
			),
			array( 'run_id', 'input' )
		);
	}

	/**
	 * Build the resume schema.
	 *
	 * @return array<string,mixed>
	 */
	private function resume_schema(): array {
		return $this->object_schema(
			array(
				'run_id'   => array( 'type' => 'string' ),
				'input'    => $this->input_schema(),
				'approval' => $this->approval_schema(),
			),
			array( 'run_id' )
		);
	}

	/**
	 * Build the cancel schema.
	 *
	 * @return array<string,mixed>
	 */
	private function cancel_schema(): array {
		return $this->object_schema(
			array(
				'run_id'    => array( 'type' => 'string' ),
				'input'     => $this->input_schema(),
				'dry_run'   => array(
					'type'        => 'boolean',
					'description' => 'Return a non-mutating cancellation preview without changing the workflow run.',
				),
				'safe_stop' => array(
					'type'        => 'boolean',
					'description' => 'Required when stopping a running workflow at a proven safe boundary.',
				),
			),
			array( 'run_id' )
		);
	}

	/**
	 * Build the run identifier schema.
	 *
	 * @return array<string,mixed>
	 */
	private function run_id_schema(): array {
		return $this->object_schema( array( 'run_id' => array( 'type' => 'string' ) ), array( 'run_id' ) );
	}

	/**
	 * Build the workflow input schema.
	 *
	 * @return array<string,mixed>
	 */
	private function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => true,
			'description'          => 'Workflow-specific JSON object validated against the published definition.',
		);
	}

	/**
	 * Build the approval evidence schema.
	 *
	 * @return array<string,mixed>
	 */
	private function approval_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'reference'      => array(
					'type'      => 'string',
					'maxLength' => 128,
				),
				'approved_gates' => array(
					'type'     => 'array',
					'maxItems' => 50,
					'items'    => array( 'type' => 'string' ),
				),
				'approved'       => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Build a closed object schema.
	 *
	 * @param array<string, array<string,mixed>> $properties Schema properties.
	 * @param array<int,string>                  $required Required property names.
	 * @return array<string,mixed>
	 */
	private function object_schema( array $properties, array $required = array() ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( array() !== $required ) {
			$schema['required'] = $required;
		}

		return $schema;
	}
}
