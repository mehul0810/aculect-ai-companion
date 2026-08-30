<?php
/**
 * Tests for server-bound workflow approval confirmations.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Execution;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Execution\WorkflowApprovalTokenStore;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use PHPUnit\Framework\TestCase;

/**
 * Keeps caller-supplied approval references from becoming authorization.
 */
final class WorkflowApprovalTokenStoreTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_transients'] = array();
	}

	public function test_issue_is_bound_to_actor_run_plan_and_gate_set_and_is_single_use(): void {
		$store = new WorkflowApprovalTokenStore();
		$plan  = $this->plan( 'approval_workflow', array( 'write_content' ) );
		$auth  = array(
			'user_id'   => 7,
			'client_id' => 'client-a',
			'provider'  => 'chatgpt',
		);
		$token = $store->issue( 'run-approval-1', $plan, $auth );

		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $token );
		self::assertTrue( $store->consume( $token, 'run-approval-1', $plan, $auth ) );
		self::assertFalse( $store->consume( $token, 'run-approval-1', $plan, $auth ) );
	}

	public function test_caller_cannot_rebind_a_server_token_to_another_actor_run_plan_or_gate_set(): void {
		$store = new WorkflowApprovalTokenStore();
		$plan  = $this->plan( 'approval_workflow', array( 'write_content' ) );
		$auth  = array(
			'user_id'   => 7,
			'client_id' => 'client-a',
			'provider'  => 'chatgpt',
		);
		$token = $store->issue( 'run-approval-1', $plan, $auth );

		self::assertFalse( $store->consume( $token, 'run-approval-2', $plan, $auth ) );
		self::assertFalse(
			$store->consume(
				$token,
				'run-approval-1',
				$plan,
				array(
					'user_id'   => 8,
					'client_id' => 'client-a',
					'provider'  => 'chatgpt',
				)
			)
		);
		self::assertFalse( $store->consume( $token, 'run-approval-1', $this->plan( 'other_workflow', array( 'write_content' ) ), $auth ) );
		self::assertFalse( $store->consume( 'caller-invented', 'run-approval-1', $plan, $auth ) );
	}

	/**
	 * Build one minimal gated plan.
	 *
	 * @param string $workflow_id Workflow identifier.
	 * @param array  $approval_gates Gate IDs.
	 * @phpstan-param list<string> $approval_gates
	 */
	private function plan( string $workflow_id, array $approval_gates ): WorkflowPlan {
		$definition = WorkflowDefinition::from_array(
			array(
				'definition_schema_version' => 1,
				'workflow_id'               => $workflow_id,
				'workflow_version'          => 1,
				'name'                      => 'Approval workflow',
				'description'               => 'Bounded approval fixture.',
				'content_target'            => array(
					'mode'       => 'either',
					'post_types' => array( 'post' ),
				),
				'input_schema'              => array( 'type' => 'object' ),
				'steps'                     => array(
					array(
						'step_id'         => 'write_content',
						'adapter_id'      => 'wordpress',
						'adapter_version' => 1,
						'ability_id'      => 'content/update-item',
						'kind'            => 'write',
						'arguments'       => array(),
						'depends_on'      => array(),
					),
				),
				'allowed_abilities'         => array( 'content/update-item' ),
				'write_policy'              => array( 'mode' => 'approved_update' ),
				'approval_gates'            => $approval_gates,
				'output_contract'           => array( 'type' => 'object' ),
				'validation_rules'          => array(),
				'status'                    => 'published',
				'created_by'                => 7,
				'updated_by'                => 7,
				'compatibility'             => array(
					'input_contract_version'  => 1,
					'output_contract_version' => 1,
				),
			)
		);

		return WorkflowPlan::from_definition( $definition, WorkflowInputContract::from_json( '{}' ) );
	}
}
