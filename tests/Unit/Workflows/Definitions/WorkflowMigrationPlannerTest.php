<?php
/**
 * Workflow definition migration planner tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Definitions;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionValidationException;
use Aculect\AICompanion\Workflows\Definitions\WorkflowMigrationPlan;
use Aculect\AICompanion\Workflows\Definitions\WorkflowMigrationPlanner;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies migration previews remain deterministic and fail closed.
 */
final class WorkflowMigrationPlannerTest extends TestCase {

	public function test_descriptive_revision_is_ready_without_actions(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['description'] = 'Updated migration-safe description.';
			}
		);

		$plan = ( new WorkflowMigrationPlanner() )->preview( $source, $target );

		self::assertSame( WorkflowMigrationPlan::READY, $plan->status() );
		self::assertTrue( $plan->can_apply() );
		self::assertSame( array(), $plan->actions() );
	}

	public function test_structural_change_requires_review(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['steps'][0]->adapter_version = 3;
			}
		);

		$plan = ( new WorkflowMigrationPlanner() )->preview( $source, $target );

		self::assertSame( WorkflowMigrationPlan::REVIEW_REQUIRED, $plan->status() );
		self::assertFalse( $plan->can_apply() );
		self::assertContains( 'step_graph_changed', array_column( $plan->actions(), 'code' ) );
	}

	public function test_removed_step_is_blocked_without_alias(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['steps'][1]->step_id    = 'prepare_copy';
				$value['steps'][2]->depends_on = array( 'prepare_copy' );
			}
		);

		$plan = ( new WorkflowMigrationPlanner() )->preview( $source, $target );

		self::assertSame( WorkflowMigrationPlan::BLOCKED, $plan->status() );
		self::assertContains( 'step_removed', array_column( $plan->actions(), 'code' ) );
	}

	public function test_explicit_step_alias_is_recorded_and_deduplicated(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['steps'][1]->step_id    = 'prepare_copy';
				$value['steps'][2]->depends_on = array( 'prepare_copy' );
			}
		);

		$plan = ( new WorkflowMigrationPlanner() )->preview(
			$source,
			$target,
			array( 'prepare_content' => 'prepare_copy' )
		);

		self::assertSame( WorkflowMigrationPlan::REVIEW_REQUIRED, $plan->status() );
		self::assertSame( array( 'prepare_content' => 'prepare_copy' ), $plan->step_aliases() );
		self::assertContains( 'step_alias_applied', array_column( $plan->actions(), 'code' ) );
		self::assertNotContains( 'step_removed', array_column( $plan->actions(), 'code' ) );
	}

	public function test_removed_ability_is_blocked_without_alias(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['allowed_abilities']         = array( 'content/prepare-draft', 'content/create-draft' );
				$value['steps'][0]->ability_id      = 'content/prepare-draft';
				$value['steps'][0]->adapter_id      = 'content_planner';
				$value['steps'][0]->adapter_version = 1;
			}
		);

		$plan = ( new WorkflowMigrationPlanner() )->preview( $source, $target );

		self::assertSame( WorkflowMigrationPlan::BLOCKED, $plan->status() );
		self::assertContains( 'ability_removed', array_column( $plan->actions(), 'code' ) );
	}

	public function test_aliases_cover_ability_identity_change_but_keep_review_boundary(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['allowed_abilities']    = array( 'content/prepare-draft', 'content/read-item', 'content/create-draft' );
				$value['steps'][0]->ability_id = 'content/read-item';
			}
		);

		$plan = ( new WorkflowMigrationPlanner() )->preview(
			$source,
			$target,
			array(),
			array( 'content/get-item' => 'content/read-item' )
		);

		self::assertSame( WorkflowMigrationPlan::REVIEW_REQUIRED, $plan->status() );
		self::assertSame( array( 'content/get-item' => 'content/read-item' ), $plan->ability_aliases() );
		self::assertContains( 'ability_alias_applied', array_column( $plan->actions(), 'code' ) );
		self::assertNotContains( 'ability_removed', array_column( $plan->actions(), 'code' ) );
	}

	public function test_unused_ability_alias_does_not_cover_an_affected_step(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['allowed_abilities']    = array( 'content/prepare-draft', 'content/read-item', 'content/create-draft' );
				$value['steps'][0]->ability_id = 'content/prepare-draft';
			}
		);

		$plan = ( new WorkflowMigrationPlanner() )->preview(
			$source,
			$target,
			array(),
			array( 'content/get-item' => 'content/read-item' )
		);

		self::assertSame( WorkflowMigrationPlan::BLOCKED, $plan->status() );
		self::assertContains( 'ability_removed', array_column( $plan->actions(), 'code' ) );
		self::assertNotContains( 'ability_alias_applied', array_column( $plan->actions(), 'code' ) );
	}

	public function test_write_step_and_mandatory_gate_rename_remains_review_required(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['steps'][2]->step_id = 'create_content';
				$value['approval_gates']    = array( 'create_content' );
			}
		);

		$plan = ( new WorkflowMigrationPlanner() )->preview(
			$source,
			$target,
			array( 'create_draft' => 'create_content' )
		);

		self::assertSame( WorkflowMigrationPlan::REVIEW_REQUIRED, $plan->status() );
		self::assertContains( 'step_alias_applied', array_column( $plan->actions(), 'code' ) );
		self::assertNotContains( 'approval_gates_changed', array_column( $plan->actions(), 'code' ) );
	}

	public function test_write_step_alias_does_not_cover_an_added_mandatory_gate(): void {
		$source = $this->fixture();
		$target = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['steps'][2]->step_id   = 'create_content';
				$value['steps'][]             = (object) array(
					'step_id'         => 'update_content',
					'adapter_id'      => 'wordpress',
					'adapter_version' => 1,
					'ability_id'      => 'content/update-item',
					'kind'            => 'write',
					'arguments'       => new stdClass(),
					'depends_on'      => array( 'create_content' ),
				);
				$value['allowed_abilities'][] = 'content/update-item';
				$value['approval_gates']      = array( 'create_content', 'update_content' );
			}
		);

		$plan = ( new WorkflowMigrationPlanner() )->preview(
			$source,
			$target,
			array( 'create_draft' => 'create_content' )
		);

		self::assertSame( WorkflowMigrationPlan::BLOCKED, $plan->status() );
		self::assertContains( 'approval_gates_changed', array_column( $plan->actions(), 'code' ) );
	}

	public function test_contradictory_ready_plan_is_rejected(): void {
		$source  = $this->fixture();
		$target  = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['write_policy']->mode = 'approved_update';
			}
		);
		$blocked = ( new WorkflowMigrationPlanner() )->preview( $source, $target );

		$this->expectException( WorkflowDefinitionValidationException::class );
		$this->expectExceptionMessage( 'invalid_migration_plan' );
		new WorkflowMigrationPlan(
			$blocked->report(),
			WorkflowMigrationPlan::READY,
			$blocked->actions(),
			array(),
			array(),
			$blocked->migration_id()
		);
	}

	public function test_invalid_alias_fails_closed(): void {
		$this->expectException( WorkflowDefinitionValidationException::class );
		$this->expectExceptionMessage( 'invalid_alias' );

		( new WorkflowMigrationPlanner() )->preview( $this->fixture(), $this->target( $this->fixture() ), array( 'same_step' => 'same_step' ) );
	}

	public function test_step_alias_chain_is_rejected_independent_of_input_order(): void {
		$source  = $this->fixture();
		$target  = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['steps'][0]->step_id    = 'prepare_content';
				$value['steps'][1]->step_id    = 'prepare_copy';
				$value['steps'][1]->depends_on = array( 'prepare_content' );
				$value['steps'][2]->depends_on = array( 'prepare_copy' );
			}
		);
		$aliases = array(
			array(
				'read_context'    => 'prepare_content',
				'prepare_content' => 'prepare_copy',
			),
			array(
				'prepare_content' => 'prepare_copy',
				'read_context'    => 'prepare_content',
			),
		);

		foreach ( $aliases as $alias_map ) {
			try {
				( new WorkflowMigrationPlanner() )->preview( $source, $target, $alias_map );
				self::fail( 'Step alias chains must be rejected in every insertion order.' );
			} catch ( WorkflowDefinitionValidationException $exception ) {
				self::assertSame( 'invalid_alias', $exception->error_code() );
			}
		}
	}

	public function test_ability_alias_chain_is_rejected_independent_of_input_order(): void {
		$source  = $this->fixture();
		$target  = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['allowed_abilities']    = array( 'content/prepare-draft', 'content/read-item', 'content/create-draft' );
				$value['steps'][0]->ability_id = 'content/read-item';
			}
		);
		$aliases = array(
			array(
				'content/get-item'  => 'content/read-item',
				'content/read-item' => 'content/read-detail',
			),
			array(
				'content/read-item' => 'content/read-detail',
				'content/get-item'  => 'content/read-item',
			),
		);

		foreach ( $aliases as $alias_map ) {
			try {
				( new WorkflowMigrationPlanner() )->preview( $source, $target, array(), $alias_map );
				self::fail( 'Ability alias chains must be rejected in every insertion order.' );
			} catch ( WorkflowDefinitionValidationException $exception ) {
				self::assertSame( 'invalid_alias', $exception->error_code() );
			}
		}
	}

	public function test_step_alias_destination_collision_is_rejected_independent_of_input_order(): void {
		$source  = $this->fixture();
		$target  = $this->target( $source );
		$aliases = array(
			array(
				'read_context'    => 'prepare_copy',
				'prepare_content' => 'prepare_copy',
			),
			array(
				'prepare_content' => 'prepare_copy',
				'read_context'    => 'prepare_copy',
			),
		);

		foreach ( $aliases as $alias_map ) {
			try {
				( new WorkflowMigrationPlanner() )->preview( $source, $target, $alias_map );
				self::fail( 'Step alias destination collisions must be rejected in every insertion order.' );
			} catch ( WorkflowDefinitionValidationException $exception ) {
				self::assertSame( 'invalid_alias', $exception->error_code() );
			}
		}
	}

	public function test_ability_alias_destination_collision_is_rejected_independent_of_input_order(): void {
		$source  = $this->fixture();
		$target  = $this->target( $source );
		$aliases = array(
			array(
				'content/get-item'      => 'content/read-item',
				'content/prepare-draft' => 'content/read-item',
			),
			array(
				'content/prepare-draft' => 'content/read-item',
				'content/get-item'      => 'content/read-item',
			),
		);

		foreach ( $aliases as $alias_map ) {
			try {
				( new WorkflowMigrationPlanner() )->preview( $source, $target, array(), $alias_map );
				self::fail( 'Ability alias destination collisions must be rejected in every insertion order.' );
			} catch ( WorkflowDefinitionValidationException $exception ) {
				self::assertSame( 'invalid_alias', $exception->error_code() );
			}
		}
	}

	public function test_plan_id_is_deterministic_and_preview_is_detached(): void {
		$source  = $this->fixture();
		$target  = $this->target(
			$source,
			static function ( array &$value ): void {
				$value['description'] = 'Deterministic preview.';
			}
		);
		$planner = new WorkflowMigrationPlanner();
		$first   = $planner->preview( $source, $target );
		$second  = $planner->preview( $source, $target );
		$array   = $first->to_array();

		self::assertSame( $first->migration_id(), $second->migration_id() );
		self::assertSame( $first->to_array(), $second->to_array() );
		$array['step_aliases']['mutated'] = 'value';
		self::assertSame( array(), $first->step_aliases() );
	}

	private function fixture(): WorkflowDefinition {
		$json = file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/ordered-multi-step-v1.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned fixture.
		self::assertNotFalse( $json );

		return WorkflowDefinition::from_json( $json );
	}

	/**
	 * Build a valid later revision from one mutation.
	 *
	 * @param WorkflowDefinition $source Source definition.
	 * @param callable           $mutate Target mutation.
	 */
	private function target( WorkflowDefinition $source, ?callable $mutate = null ): WorkflowDefinition {
		$value = $source->to_array();
		++$value['workflow_version'];
		if ( null !== $mutate ) {
			$mutate( $value );
		}

		return WorkflowDefinition::from_array( $value );
	}
}
