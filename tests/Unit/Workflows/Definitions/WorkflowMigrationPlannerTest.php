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

	public function test_invalid_alias_fails_closed(): void {
		$this->expectException( WorkflowDefinitionValidationException::class );
		$this->expectExceptionMessage( 'invalid_alias' );

		( new WorkflowMigrationPlanner() )->preview( $this->fixture(), $this->target( $this->fixture() ), array( 'same_step' => 'same_step' ) );
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
