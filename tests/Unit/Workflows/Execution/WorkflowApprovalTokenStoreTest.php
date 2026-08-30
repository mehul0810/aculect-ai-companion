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
		$GLOBALS['aculect_ai_companion_test_options']            = array();
		$GLOBALS['aculect_ai_companion_test_transients']         = array();
		$GLOBALS['aculect_ai_companion_test_failed_option_adds'] = array();
		$GLOBALS['aculect_ai_companion_test_cache_deletes']      = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['aculect_ai_companion_test_failed_option_adds'],
			$GLOBALS['aculect_ai_companion_test_cache_deletes']
		);

		parent::tearDown();
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

	public function test_concurrent_style_second_consumer_is_rejected_by_atomic_claim(): void {
		$issuer = new WorkflowApprovalTokenStore();
		$first  = new WorkflowApprovalTokenStore();
		$second = new WorkflowApprovalTokenStore();
		$plan   = $this->plan( 'approval_workflow', array( 'write_content' ) );
		$auth   = array(
			'user_id'   => 7,
			'client_id' => 'client-a',
			'provider'  => 'chatgpt',
		);
		$token  = $issuer->issue( 'run-approval-atomic', $plan, $auth );

		self::assertTrue( $first->consume( $token, 'run-approval-atomic', $plan, $auth ) );
		self::assertFalse( $second->consume( $token, 'run-approval-atomic', $plan, $auth ) );
		self::assertNotEmpty( $GLOBALS['aculect_ai_companion_test_options'] );
	}

	public function test_expired_server_token_is_rejected_even_when_transient_backend_has_not_evicted_it(): void {
		$store = new WorkflowApprovalTokenStore();
		$plan  = $this->plan( 'approval_workflow', array( 'write_content' ) );
		$auth  = array(
			'user_id'   => 7,
			'client_id' => 'client-a',
			'provider'  => 'chatgpt',
		);
		$token = $store->issue( 'run-approval-expired', $plan, $auth );
		$key   = $this->private_key( 'key', $token );

		$GLOBALS['aculect_ai_companion_test_transients'][ $key ]['value']['expires_at'] = time() - 1;

		self::assertFalse( $store->consume( $token, 'run-approval-expired', $plan, $auth ) );
		self::assertEmpty( $GLOBALS['aculect_ai_companion_test_options'] );
	}

	public function test_option_claim_failure_denies_consumption_until_storage_recovers(): void {
		$store = new WorkflowApprovalTokenStore();
		$plan  = $this->plan( 'approval_workflow', array( 'write_content' ) );
		$auth  = array(
			'user_id'   => 7,
			'client_id' => 'client-a',
			'provider'  => 'chatgpt',
		);
		$token = $store->issue( 'run-approval-storage', $plan, $auth );
		$key   = $this->private_key( 'consumed_key', $token );

		$GLOBALS['aculect_ai_companion_test_failed_option_adds'] = array( $key );
		self::assertFalse( $store->consume( $token, 'run-approval-storage', $plan, $auth ) );

		$GLOBALS['aculect_ai_companion_test_failed_option_adds'] = array();
		self::assertTrue( $store->consume( $token, 'run-approval-storage', $plan, $auth ) );
	}

	public function test_expired_claim_markers_are_pruned_in_a_bounded_maintenance_batch(): void {
		$store = new WorkflowApprovalTokenStore();
		$plan  = $this->plan( 'approval_workflow', array( 'write_content' ) );
		$auth  = array(
			'user_id'   => 7,
			'client_id' => 'client-a',
			'provider'  => 'chatgpt',
		);
		$token = $store->issue( 'run-approval-prune', $plan, $auth );
		$key   = $this->private_key( 'consumed_key', $token );

		self::assertTrue( $store->consume( $token, 'run-approval-prune', $plan, $auth ) );
		$GLOBALS['aculect_ai_companion_test_options'][ $key ] = time() - 1;

		$store->issue( 'run-approval-prune-next', $plan, $auth );

		self::assertArrayNotHasKey( $key, $GLOBALS['aculect_ai_companion_test_options'] );
	}

	public function test_production_claim_uses_atomic_unique_option_and_fails_closed_on_query_errors(): void {
		$original_options    = $GLOBALS['aculect_ai_companion_test_options'];
		$original_transients = $GLOBALS['aculect_ai_companion_test_transients'];
		$original_wpdb       = $GLOBALS['wpdb'] ?? null;
		$wpdb                = new class() {
			public string $options = 'wp_options';
			/**
			 * Prepared SQL calls and their arguments.
			 *
			 * @var list<array{query:string,args:array<int,mixed>}>
			 */
			public array $prepared = array();
			/**
			 * Claimed option rows.
			 *
			 * @var array<string,string>
			 */
			public array $rows             = array();
			public int $claim_attempts     = 0;
			public int|false $query_result = 1;

			public function esc_like( string $value ): string {
				return addcslashes( $value, '_%\\' );
			}

			public function prepare( string $query, mixed ...$args ): string {
				$this->prepared[] = array(
					'query' => $query,
					'args'  => $args,
				);

				return $query;
			}

			public function query( string $query ): int|false {
				if ( ! str_contains( $query, 'INSERT IGNORE' ) ) {
					return 0;
				}

				++$this->claim_attempts;
				$prepared = end( $this->prepared );
				$key      = is_array( $prepared ) ? (string) ( $prepared['args'][0] ?? '' ) : '';
				if ( array_key_exists( $key, $this->rows ) || false === $this->query_result ) {
					return false === $this->query_result ? false : 0;
				}

				$this->rows[ $key ] = is_array( $prepared ) ? (string) ( $prepared['args'][1] ?? '' ) : '';

				return 1;
			}
		};

		try {
			unset( $GLOBALS['aculect_ai_companion_test_options'] );
			$GLOBALS['aculect_ai_companion_test_transients']    = array();
			$GLOBALS['aculect_ai_companion_test_cache_deletes'] = array();
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Production-path test supplies a narrow wpdb double.
			$GLOBALS['wpdb'] = $wpdb;
			$store           = new WorkflowApprovalTokenStore();
			$plan            = $this->plan( 'approval_workflow', array( 'write_content' ) );
			$auth            = array(
				'user_id'   => 7,
				'client_id' => 'client-a',
				'provider'  => 'chatgpt',
			);
			$token           = $store->issue( 'run-approval-production', $plan, $auth );

			self::assertTrue( $store->consume( $token, 'run-approval-production', $plan, $auth ) );
			self::assertFalse( $store->consume( $token, 'run-approval-production', $plan, $auth ) );
			self::assertSame( 2, $wpdb->claim_attempts );
			$insert_query = array_values( array_filter( $wpdb->prepared, static fn ( array $query ): bool => str_contains( $query['query'], 'INSERT IGNORE' ) ) );
			self::assertNotEmpty( $insert_query );
			self::assertStringContainsString( "autoload) VALUES (%s, %s, 'no')", $insert_query[0]['query'] );
			self::assertNotEmpty( $GLOBALS['aculect_ai_companion_test_cache_deletes'] );

			$wpdb->query_result = false;
			$failed_token       = $store->issue( 'run-approval-production-failure', $plan, $auth );
			self::assertFalse( $store->consume( $failed_token, 'run-approval-production-failure', $plan, $auth ) );
		} finally {
			$GLOBALS['aculect_ai_companion_test_options']    = $original_options;
			$GLOBALS['aculect_ai_companion_test_transients'] = $original_transients;
			if ( null !== $original_wpdb ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the test's original wpdb double.
				$GLOBALS['wpdb'] = $original_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}

	/**
	 * Invoke one private key helper without duplicating its hashing contract.
	 *
	 * @param string $method Private helper name.
	 * @param string $token Opaque token.
	 */
	private function private_key( string $method, string $token ): string {
		$reflection = new \ReflectionMethod( WorkflowApprovalTokenStore::class, $method );

		return (string) $reflection->invoke( new WorkflowApprovalTokenStore(), $token );
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
