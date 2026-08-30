<?php
/**
 * Tests for durable workflow runner lifecycle orchestration.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Execution
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.CapitalPDangit.MisspelledInText -- Exact adapter identity fixtures use the lowercase machine token.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused lease tests replace wpdb with an isolated adapter.

namespace Aculect\AICompanion\Tests\Unit\Workflows\Execution;

require_once dirname( __DIR__, 3 ) . '/Support/InMemoryWorkflowRunStore.php';
require_once dirname( __DIR__, 3 ) . '/Support/WorkflowRunSqliteWpdb.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';

use Aculect\AICompanion\Tests\Support\InMemoryWorkflowRunStore;
use Aculect\AICompanion\Tests\Support\WorkflowRunSqliteWpdb;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterInterface;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterCatalog;
use Aculect\AICompanion\Workflows\Adapters\NativeAbilityWorkflowAdapter;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterRegistry;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunner;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunnerException;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStore;
use Aculect\AICompanion\Workflows\Execution\WorkflowStepState;
use Aculect\AICompanion\Workflows\Planning\WorkflowApprovalEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanningException;
use Aculect\AICompanion\Workflows\Planning\WorkflowReadinessEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use Aculect\AICompanion\Workflows\Planning\WorkflowTransitionGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves the runner preserves immutable plans, approval gates, dependencies,
 * safe cancellation, and bounded adapter failures.
 */
final class WorkflowRunnerTest extends TestCase {

	public function test_single_step_run_starts_and_completes_without_exposing_output(): void {
		$definition = $this->record( 'proposal-only-v1.json' );
		$plan       = $this->plan( $definition, '{"post_id":9}' );
		$executions = array();
		$adapter    = $this->adapter(
			'wordpress',
			1,
			'content/get-item',
			'read',
			static function ( WorkflowPlan $received_plan, string $step_id, array $arguments, array $auth ) use ( &$executions ): WorkflowAdapterResult {
				$executions[] = array( $received_plan->hash(), $step_id, $arguments, $auth );

				return WorkflowAdapterResult::success(
					array(
						'private' => 'detached',
						'ok'      => true,
					)
				);
			}
		);
		$runner     = $this->runner( array( $adapter ) );
		$record     = $runner->create( $definition, $plan, WorkflowInputContract::from_json( '{"post_id":9}' ), 7 );

		self::assertSame( WorkflowRunState::PREPARED, $record->state() );
		$readiness = WorkflowReadinessEvidence::from_evaluation( $plan, array() );
		$started   = $runner->start( $record->run_id(), $plan, $readiness, 7 );
		self::assertSame( WorkflowRunState::RUNNING, $started->state() );

		$progress = $runner->execute_next( $definition, $record->run_id(), $plan, array( 'session' => 'bounded' ), 7 );
		self::assertTrue( $progress->progressed() );
		self::assertSame( WorkflowStepState::COMPLETED, $progress->step()?->state() );
		self::assertSame( WorkflowRunState::RUNNING, $progress->run()->state() );
		self::assertCount( 1, $executions );
		self::assertSame( array(), $executions[0][2], 'Static definition arguments are the only dispatch arguments.' );
		self::assertSame( array( 'session' => 'bounded' ), $executions[0][3] );
		self::assertArrayNotHasKey( 'output_json', $progress->step()?->to_array() ?? array() );

		$completed = $runner->execute_next( $definition, $record->run_id(), $plan, array(), 7 );
		self::assertSame( WorkflowRunState::COMPLETED, $completed->run()->state() );
		self::assertSame( 'completed', $completed->run()->outcome_code() );
		self::assertCount( 1, $executions, 'The terminal tick must not dispatch the adapter again.' );

		$terminal = $runner->execute_next( $definition, $record->run_id(), $plan, array(), 7 );
		self::assertFalse( $terminal->progressed() );
		self::assertSame( WorkflowRunState::COMPLETED, $terminal->run()->state() );
	}

	public function test_approval_gated_dependencies_execute_in_plan_order_then_complete(): void {
		$definition = $this->record( 'ordered-multi-step-v1.json' );
		$plan       = $this->plan( $definition, '{"brief":"Run this safely"}' );
		$executions = array();
		$adapters   = array(
			$this->adapter( 'wordpress', 2, 'content/get-item', 'read', $this->execution_callback( 'read_context', $executions ) ),
			$this->adapter( 'content_planner', 1, 'content/prepare-draft', 'proposal', $this->execution_callback( 'prepare_content', $executions ) ),
			$this->adapter( 'wordpress', 1, 'content/create-draft', 'write', $this->execution_callback( 'create_draft', $executions ) ),
		);
		$runner     = $this->runner( $adapters );
		$record     = $runner->create( $definition, $plan, WorkflowInputContract::from_json( '{"brief":"Run this safely"}' ), 7 );
		$dry_run    = $runner->build_dry_run( $record->run_id(), $plan, 7 );
		$waiting    = $runner->request_approval( $record->run_id(), $plan, 7 );
		self::assertSame( WorkflowRunState::WAITING_FOR_APPROVAL, $waiting->state() );

		$approval = new WorkflowApprovalEvidence( $plan->hash(), array( 'create_draft' ), 'approval-123', true );
		$started  = $runner->start(
			$record->run_id(),
			$plan,
			WorkflowReadinessEvidence::from_evaluation( $plan, array(), true ),
			7,
			$approval
		);
		self::assertSame( WorkflowRunState::RUNNING, $started->state() );
		self::assertSame( $plan->hash(), $dry_run->plan_hash() );

		for ( $index = 0; $index < 3; ++$index ) {
			$result = $runner->execute_next( $definition, $record->run_id(), $plan, array(), 7 );
			self::assertTrue( $result->progressed() );
			self::assertSame( WorkflowStepState::COMPLETED, $result->step()?->state() );
		}

		self::assertSame( array( 'read_context', 'prepare_content', 'create_draft' ), $executions );
		$completed = $runner->execute_next( $definition, $record->run_id(), $plan, array(), 7 );
		self::assertSame( WorkflowRunState::COMPLETED, $completed->run()->state() );
	}

	public function test_incomplete_input_can_resume_against_the_same_definition_revision(): void {
		$definition = $this->record( 'ordered-multi-step-v1.json' );
		$incomplete = $this->plan( $definition, '{}' );
		$complete   = $this->plan( $definition, '{"brief":"Now complete"}' );
		$runner     = new WorkflowRunner( new InMemoryWorkflowRunStore(), new WorkflowAdapterRegistry( array() ) );
		$record     = $runner->create( $definition, $incomplete, WorkflowInputContract::from_json( '{}' ), 7 );

		self::assertSame( WorkflowRunState::WAITING_FOR_INPUT, $record->state() );
		$resumed = $runner->resume_with_input(
			$record->run_id(),
			$complete,
			WorkflowInputContract::from_json( '{"brief":"Now complete"}' ),
			7
		);

		self::assertSame( WorkflowRunState::PREPARED, $resumed->state() );
		self::assertSame( $complete->hash(), $resumed->plan_hash() );
		self::assertSame( 2, $resumed->state_version() );
	}

	public function test_adapter_failure_fails_step_and_run_with_a_closed_code(): void {
		$definition = $this->record( 'proposal-only-v1.json' );
		$plan       = $this->plan( $definition, '{"post_id":9}' );
		$adapter    = $this->adapter(
			'wordpress',
			1,
			'content/get-item',
			'read',
			static fn (): WorkflowAdapterResult => WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_GATEWAY_REJECTED )
		);
		$runner     = $this->runner( array( $adapter ) );
		$record     = $runner->create( $definition, $plan, WorkflowInputContract::from_json( '{"post_id":9}' ), 7 );
		$runner->start( $record->run_id(), $plan, WorkflowReadinessEvidence::from_evaluation( $plan, array() ), 7 );

		$failed = $runner->execute_next( $definition, $record->run_id(), $plan, array(), 7 );
		self::assertSame( WorkflowRunState::FAILED, $failed->run()->state() );
		self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $failed->run()->outcome_code() );
		self::assertSame( WorkflowStepState::FAILED, $failed->step()?->state() );
		self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $failed->step()?->error_code() );
	}

	public function test_confirmation_preview_fails_native_step_without_dispatching_dependents(): void {
		$original_options             = $GLOBALS['aculect_ai_companion_test_options'] ?? array();
		$original_transients          = $GLOBALS['aculect_ai_companion_test_transients'] ?? array();
		$original_users               = $GLOBALS['aculect_ai_companion_test_users'] ?? array();
		$original_posts               = $GLOBALS['aculect_ai_companion_test_posts'] ?? array();
		$original_current_user        = $GLOBALS['aculect_ai_companion_test_current_user_id'] ?? 0;
		$original_capability_callback = $GLOBALS['aculect_ai_companion_test_capability_callback'] ?? null;
		AbilitiesRegistry::reset_module_cache();
		$GLOBALS['aculect_ai_companion_test_options']             = array( ToolSafety::OPTION_CONFIRMATION_GROUPS => array( 'Content' ) );
		$GLOBALS['aculect_ai_companion_test_transients']          = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']     = 1;
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_users']               = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
		$GLOBALS['aculect_ai_companion_test_posts']               = array(
			123 => new \WP_Post(
				array(
					'ID'           => 123,
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_title'   => 'Original title',
					'post_content' => '<!-- wp:paragraph --><p>Original content.</p><!-- /wp:paragraph -->',
				)
			),
		);

		$dependent_dispatches = 0;
		$definition           = $this->native_update_chain_definition();
		$record               = $this->record_from_definition( $definition );
		$plan                 = $this->plan( $record, '{}' );
		$native               = new NativeAbilityWorkflowAdapter(
			'wordpress_content_update',
			1,
			'content/update-item',
			'content.update_item',
			'write',
			false,
			array( 'edit_post' ),
			null,
			new AbilitiesRegistry()
		);
		$dependent            = $this->adapter(
			'wordpress',
			1,
			'content/get-item',
			'read',
			static function () use ( &$dependent_dispatches ): WorkflowAdapterResult {
				++$dependent_dispatches;

				return WorkflowAdapterResult::success( array( 'ok' => true ) );
			}
		);
		$runner               = new WorkflowRunner(
			new InMemoryWorkflowRunStore(),
			new WorkflowAdapterRegistry( array( $native, $dependent ) )
		);

		try {
			$created = $runner->create( $record, $plan, WorkflowInputContract::from_json( '{}' ), 1 );
			$runner->build_dry_run( $created->run_id(), $plan, 1 );
			$runner->request_approval( $created->run_id(), $plan, 1 );
			$runner->start(
				$created->run_id(),
				$plan,
				WorkflowReadinessEvidence::from_evaluation( $plan, array() ),
				1,
				new WorkflowApprovalEvidence( $plan->hash(), array( 'update_item' ), 'approval-preview-test', true )
			);

			$progress = $runner->execute_next(
				$record,
				$created->run_id(),
				$plan,
				array(
					'user_id'                  => 1,
					'client_id'                => 'native-adapter-runner-test',
					'provider'                 => 'chatgpt',
					'scopes'                   => array( 'content:read', 'content:draft' ),
					'profile'                  => 'full_access',
					'access_level'             => '',
					'write_permission_enabled' => false,
				),
				1
			);

			self::assertTrue( $progress->progressed() );
			self::assertSame( WorkflowRunState::FAILED, $progress->run()->state() );
			self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $progress->run()->outcome_code() );
			self::assertSame( WorkflowStepState::FAILED, $progress->step()?->state() );
			self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $progress->step()?->error_code() );
			self::assertSame( 0, $dependent_dispatches );
			self::assertSame( 'Original title', get_post( 123 )?->post_title );
		} finally {
			$GLOBALS['aculect_ai_companion_test_options']             = $original_options;
			$GLOBALS['aculect_ai_companion_test_transients']          = $original_transients;
			$GLOBALS['aculect_ai_companion_test_users']               = $original_users;
			$GLOBALS['aculect_ai_companion_test_posts']               = $original_posts;
			$GLOBALS['aculect_ai_companion_test_current_user_id']     = $original_current_user;
			$GLOBALS['aculect_ai_companion_test_capability_callback'] = $original_capability_callback;
			AbilitiesRegistry::reset_module_cache();
		}
	}

	public function test_runner_completes_optional_only_native_read_with_empty_arguments(): void {
		$original_posts                                  = $GLOBALS['aculect_ai_companion_test_posts'] ?? array();
		$original_types                                  = $GLOBALS['aculect_ai_companion_test_post_types'] ?? array();
		$GLOBALS['aculect_ai_companion_test_posts']      = array(
			123 => new \WP_Post(
				array(
					'ID'           => 123,
					'post_type'    => 'post',
					'post_status'  => 'draft',
					'post_title'   => 'Optional list item',
					'post_content' => '<!-- wp:paragraph --><p>Optional content.</p><!-- /wp:paragraph -->',
				)
			),
		);
		$GLOBALS['aculect_ai_companion_test_post_types'] = array();
		AbilitiesRegistry::reset_module_cache();

		try {
			$definition = $this->native_optional_read_definition();
			$record     = $this->record_from_definition( $definition );
			$plan       = $this->plan( $record, '{}' );
			$adapter    = null;
			foreach ( WorkflowAdapterCatalog::adapters() as $candidate ) {
				if ( $candidate instanceof NativeAbilityWorkflowAdapter && 'wordpress_content_list' === $candidate->adapter_id() ) {
					$adapter = $candidate;
					break;
				}
			}
			self::assertInstanceOf( NativeAbilityWorkflowAdapter::class, $adapter );

			$runner  = new WorkflowRunner( new InMemoryWorkflowRunStore(), new WorkflowAdapterRegistry( array( $adapter ) ) );
			$created = $runner->create( $record, $plan, WorkflowInputContract::from_json( '{}' ), 1 );
			$runner->start( $created->run_id(), $plan, WorkflowReadinessEvidence::from_evaluation( $plan, array() ), 1 );
			$progress = $runner->execute_next(
				$record,
				$created->run_id(),
				$plan,
				array(
					'user_id'   => 1,
					'client_id' => 'native-optional-read-test',
					'provider'  => 'chatgpt',
					'scopes'    => array( 'content:read' ),
					'profile'   => 'full_access',
				),
				1
			);

			self::assertTrue( $progress->progressed() );
			self::assertSame( WorkflowStepState::COMPLETED, $progress->step()?->state() );
			self::assertSame( WorkflowRunState::RUNNING, $progress->run()->state() );
			$completed = $runner->execute_next( $record, $created->run_id(), $plan, array(), 1 );
			self::assertSame( WorkflowRunState::COMPLETED, $completed->run()->state() );
		} finally {
			$GLOBALS['aculect_ai_companion_test_posts']      = $original_posts;
			$GLOBALS['aculect_ai_companion_test_post_types'] = $original_types;
			AbilitiesRegistry::reset_module_cache();
		}
	}

	public function test_running_cancellation_requires_safe_execution_evidence(): void {
		$definition = $this->record( 'proposal-only-v1.json' );
		$plan       = $this->plan( $definition, '{"post_id":9}' );
		$runner     = $this->runner( array( $this->adapter( 'wordpress', 1, 'content/get-item', 'read' ) ) );
		$record     = $runner->create( $definition, $plan, WorkflowInputContract::from_json( '{"post_id":9}' ), 7 );
		$runner->start( $record->run_id(), $plan, WorkflowReadinessEvidence::from_evaluation( $plan, array() ), 7 );

		try {
			$runner->cancel( $record->run_id(), $plan, 7 );
			self::fail( 'Running cancellation without a safe boundary must fail closed.' );
		} catch ( WorkflowPlanningException $exception ) {
			self::assertSame( 'cancel_not_allowed', $exception->error_code() );
		}

		$cancelled = $runner->cancel(
			$record->run_id(),
			$plan,
			7,
			new \Aculect\AICompanion\Workflows\Planning\WorkflowExecutionEvidence( $plan->hash(), 'cancelled', 'safe_stop', true )
		);
		self::assertSame( WorkflowRunState::CANCELLED, $cancelled->state() );
		self::assertSame( 'safe_stop', $cancelled->outcome_code() );
	}

	public function test_plan_mismatch_and_invalid_actor_are_rejected_before_dispatch(): void {
		$definition = $this->record( 'proposal-only-v1.json' );
		$plan       = $this->plan( $definition, '{"post_id":9}' );
		$other      = $this->plan( $definition, '{"post_id":10}' );
		$runner     = $this->runner( array( $this->adapter( 'wordpress', 1, 'content/get-item', 'read' ) ) );

		try {
			$runner->create( $definition, $plan, WorkflowInputContract::from_json( '{"post_id":9}' ), 0 );
			self::fail( 'An invalid actor must fail closed.' );
		} catch ( WorkflowRunnerException $exception ) {
			self::assertSame( 'input_invalid', $exception->error_code() );
		}

		$record = $runner->create( $definition, $plan, WorkflowInputContract::from_json( '{"post_id":9}' ), 7 );
		try {
			$runner->start( $record->run_id(), $other, WorkflowReadinessEvidence::from_evaluation( $other, array() ), 7 );
			self::fail( 'A plan hash mismatch must fail before execution.' );
		} catch ( WorkflowRunnerException $exception ) {
			self::assertSame( 'plan_binding_mismatch', $exception->error_code() );
		}
	}

	public function test_expired_running_step_fails_closed_and_rejects_late_result(): void {
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			self::markTestSkipped( 'pdo_sqlite is required for lease fencing tests.' );
		}

		$original_wpdb                                = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']                              = new WorkflowRunSqliteWpdb();
		$GLOBALS['aculect_ai_companion_test_options'] = array(
			'aculect_ai_companion_workflow_runs_db_version' => '2026.08.29.1',
			'aculect_ai_companion_secret_storage_key' => str_repeat( 'k', 64 ),
		);
		$now   = 1724889600;
		$clock = static function () use ( &$now ): int {
			return $now;
		};

		try {
			$definition = $this->record( 'proposal-only-v1.json' );
			$plan       = $this->plan( $definition, '{"post_id":9}' );
			$store      = new WorkflowRunStore( null, $clock );
			$runner     = new WorkflowRunner( $store, new WorkflowAdapterRegistry( array() ), new WorkflowTransitionGuard(), $clock );
			$record     = $runner->create( $definition, $plan, WorkflowInputContract::from_json( '{"post_id":9}' ), 7 );
			$runner->start( $record->run_id(), $plan, WorkflowReadinessEvidence::from_evaluation( $plan, array() ), 7 );
			$claimed = $store->claim_step( $record->run_id(), 'read_content', 7 );
			self::assertNotNull( $claimed );

			$now     += 31;
			$progress = $runner->execute_next( $definition, $record->run_id(), $plan, array(), 7 );
			self::assertSame( WorkflowRunState::FAILED, $progress->run()->state() );
			self::assertSame( 'step_execution_uncertain', $progress->run()->outcome_code() );
			self::assertSame( WorkflowStepState::RUNNING, $progress->step()?->state() );
			self::assertTrue( $progress->progressed() );
			self::assertNull( $store->complete_step( $record->run_id(), 'read_content', $claimed?->fence() ?? 0, WorkflowAdapterResult::success( array( 'ok' => true ) ), 7 ) );
		} finally {
			if ( null === $original_wpdb ) {
				unset( $GLOBALS['wpdb'] );
			} else {
				$GLOBALS['wpdb'] = $original_wpdb;
			}
		}
	}

	/**
	 * Build a two-step plan whose write preview must not unlock a dependent read.
	 */
	private function native_update_chain_definition(): WorkflowDefinition {
		return WorkflowDefinition::from_array(
			array(
				'definition_schema_version' => 1,
				'workflow_id'               => 'native_update_chain_test',
				'workflow_version'          => 1,
				'name'                      => 'Native update chain test',
				'description'               => 'Verifies confirmation previews fail the workflow before dependent dispatch.',
				'content_target'            => array(
					'mode'       => 'either',
					'post_types' => array( 'post' ),
				),
				'input_schema'              => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'steps'                     => array(
					array(
						'step_id'         => 'update_item',
						'adapter_id'      => 'wordpress_content_update',
						'adapter_version' => 1,
						'ability_id'      => 'content/update-item',
						'kind'            => 'write',
						'arguments'       => array(
							'id'    => 123,
							'title' => 'Planned title',
						),
						'depends_on'      => array(),
					),
					array(
						'step_id'         => 'read_item',
						'adapter_id'      => 'wordpress',
						'adapter_version' => 1,
						'ability_id'      => 'content/get-item',
						'kind'            => 'read',
						'arguments'       => array( 'id' => 123 ),
						'depends_on'      => array( 'update_item' ),
					),
				),
				'allowed_abilities'         => array( 'content/update-item', 'content/get-item' ),
				'write_policy'              => array( 'mode' => 'draft_only' ),
				'approval_gates'            => array( 'update_item' ),
				'output_contract'           => array( 'type' => 'object' ),
				'validation_rules'          => array(),
				'status'                    => 'draft',
				'created_by'                => 1,
				'updated_by'                => 1,
				'compatibility'             => array(
					'input_contract_version'  => 1,
					'output_contract_version' => 1,
				),
			)
		);
	}

	/**
	 * Build a single optional-only native list step for runner object semantics.
	 */
	private function native_optional_read_definition(): WorkflowDefinition {
		return WorkflowDefinition::from_array(
			array(
				'definition_schema_version' => 1,
				'workflow_id'               => 'native_optional_read_test',
				'workflow_version'          => 1,
				'name'                      => 'Native optional read test',
				'description'               => 'Verifies empty object arguments reach optional-only native reads.',
				'content_target'            => array(
					'mode'       => 'either',
					'post_types' => array( 'post' ),
				),
				'input_schema'              => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
				'steps'                     => array(
					array(
						'step_id'         => 'list_items',
						'adapter_id'      => 'wordpress_content_list',
						'adapter_version' => 1,
						'ability_id'      => 'content/list-items',
						'kind'            => 'read',
						'arguments'       => array(),
						'depends_on'      => array(),
					),
				),
				'allowed_abilities'         => array( 'content/list-items' ),
				'write_policy'              => array( 'mode' => 'proposal_only' ),
				'approval_gates'            => array(),
				'output_contract'           => array( 'type' => 'object' ),
				'validation_rules'          => array(),
				'status'                    => 'draft',
				'created_by'                => 1,
				'updated_by'                => 1,
				'compatibility'             => array(
					'input_contract_version'  => 1,
					'output_contract_version' => 1,
				),
			)
		);
	}

	private function record_from_definition( WorkflowDefinition $definition ): WorkflowDefinitionRecord {
		$value = $definition->to_array();

		return new WorkflowDefinitionRecord(
			1,
			(string) $value['workflow_id'],
			(string) $value['status'],
			(int) $value['workflow_version'],
			0,
			'',
			0,
			(int) $value['created_by'],
			(int) $value['updated_by'],
			1,
			'2026-08-29 00:00:00',
			'2026-08-29 00:00:00',
			$definition
		);
	}

	/**
	 * Compose a runner with an isolated in-memory store.
	 *
	 * @param array $adapters Adapter implementations.
	 * @phpstan-param list<WorkflowAdapterInterface> $adapters
	 */
	private function runner( array $adapters ): WorkflowRunner {
		return new WorkflowRunner( new InMemoryWorkflowRunStore(), new WorkflowAdapterRegistry( $adapters ) );
	}

	private function plan( WorkflowDefinitionRecord $record, string $input_json ): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build( $record->definition(), WorkflowInputContract::from_json( $input_json ) );
	}

	private function record( string $fixture ): WorkflowDefinitionRecord {
		$path = dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/' . $fixture;
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned fixture.
		self::assertIsString( $json );
		$definition = WorkflowDefinition::from_json( $json );
		$value      = $definition->to_array();
		$status     = (string) $value['status'];
		$version    = (int) $value['workflow_version'];

		return new WorkflowDefinitionRecord(
			1,
			(string) $value['workflow_id'],
			$status,
			$version,
			'published' === $status ? $version : 0,
			'',
			0,
			(int) $value['created_by'],
			(int) $value['updated_by'],
			1,
			'2026-08-29 00:00:00',
			'2026-08-29 00:00:00',
			$definition
		);
	}

	private function adapter( string $id, int $version, string $ability, string $kind, ?callable $execute = null ): WorkflowAdapterInterface {
		$adapter = $this->createMock( WorkflowAdapterInterface::class );
		$adapter->method( 'adapter_id' )->willReturn( $id );
		$adapter->method( 'adapter_version' )->willReturn( $version );
		$adapter->method( 'ability_id' )->willReturn( $ability );
		$adapter->method( 'kind' )->willReturn( $kind );
		$adapter->method( 'is_read_only' )->willReturn( 'write' !== $kind );
		$adapter->method( 'required_capabilities' )->willReturn( array() );
		$adapter->method( 'input_schema' )->willReturn( array( 'type' => 'object' ) );
		$adapter->method( 'output_schema' )->willReturn( array( 'type' => 'object' ) );
		$adapter->method( 'execute' )->willReturnCallback(
			$execute ?? static fn (): WorkflowAdapterResult => WorkflowAdapterResult::success( array( 'ok' => true ) )
		);

		return $adapter;
	}

	/**
	 * Create a callback that records and verifies one expected step.
	 *
	 * @param string $expected_step Expected step ID.
	 * @param array  $executions    Executed step IDs.
	 * @phpstan-param list<string> $executions
	 */
	private function execution_callback( string $expected_step, array &$executions ): callable {
		return static function ( WorkflowPlan $plan, string $step_id, array $arguments, array $auth ) use ( $expected_step, &$executions ): WorkflowAdapterResult {
			unset( $plan, $arguments, $auth );
			if ( $expected_step !== $step_id ) {
				throw new RuntimeException( 'unexpected workflow step' );
			}
			$executions[] = $step_id;

			return WorkflowAdapterResult::success( array( 'step' => $step_id ) );
		};
	}
}
