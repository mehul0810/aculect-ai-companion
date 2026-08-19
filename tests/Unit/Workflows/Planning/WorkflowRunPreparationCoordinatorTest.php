<?php
/**
 * Pure workflow run-preparation coordinator tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Planning;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Planning\WorkflowAvailabilitySnapshot;
use Aculect\AICompanion\Workflows\Planning\WorkflowDryRun;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanReadinessEvaluator;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanningException;
use Aculect\AICompanion\Workflows\Planning\WorkflowPreparationResult;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunPreparationCoordinator;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use Aculect\AICompanion\Workflows\Planning\WorkflowStateSnapshot;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Locks pure preflight composition without execution or persistence.
 */
final class WorkflowRunPreparationCoordinatorTest extends TestCase {

	public function test_complete_input_stops_at_dry_run_ready_with_deferred_validation(): void {
		$result = $this->prepare( $this->ordered_definition(), '{"brief":"Prepare content"}' );
		$plan   = $result->plan();

		self::assertSame( WorkflowRunState::DRY_RUN_READY, $result->snapshot()->state() );
		self::assertInstanceOf( WorkflowDryRun::class, $result->dry_run() );
		self::assertSame( $plan->hash(), $result->dry_run()->plan_hash() );
		self::assertNull( $result->readiness()->requirements_error() );
		self::assertSame( 'validation_unchecked', $result->readiness()->validation_error_for( $plan ) );
		self::assertNotSame( WorkflowRunState::RUNNING, $result->snapshot()->state() );
	}

	public function test_missing_input_stops_before_availability_evaluation_and_dry_run(): void {
		$result = ( new WorkflowRunPreparationCoordinator() )->prepare(
			$this->ordered_definition(),
			WorkflowInputContract::from_json( '{}' ),
			WorkflowAvailabilitySnapshot::from_value(
				array(
					'availability_schema_version' => 2,
					'bindings'                    => array(),
				)
			)
		);

		self::assertSame( WorkflowRunState::WAITING_FOR_INPUT, $result->snapshot()->state() );
		self::assertNull( $result->dry_run() );
		self::assertSame( 'requirements_unchecked', $result->readiness()->requirements_error() );
		self::assertSame( array( '$.brief' ), $result->plan()->missing_paths() );
	}

	public function test_exact_missing_requirements_are_preserved_without_starting(): void {
		$plan   = ( new WorkflowPlanBuilder() )->build(
			$this->ordered_definition(),
			WorkflowInputContract::from_json( '{"brief":"Missing dependencies"}' )
		);
		$result = ( new WorkflowRunPreparationCoordinator() )->prepare(
			$this->ordered_definition(),
			WorkflowInputContract::from_json( '{"brief":"Missing dependencies"}' ),
			WorkflowAvailabilitySnapshot::from_value(
				array(
					'availability_schema_version' => 2,
					'bindings'                    => array(
						array(
							'adapter_id'      => 'wordpress',
							'adapter_version' => 2,
							'ability_id'      => 'content/get-item',
							'kind'            => 'read',
						),
					),
				)
			)
		);

		self::assertSame( $plan->hash(), $result->plan()->hash() );
		self::assertSame(
			array(
				'content_planner@1|content/prepare-draft|proposal',
				'wordpress@1|content/create-draft|write',
			),
			$result->readiness()->missing_bindings()
		);
		self::assertSame( array( 'content_planner@1', 'wordpress@1' ), $result->readiness()->missing_adapters() );
		self::assertSame( array( 'content/create-draft', 'content/prepare-draft' ), $result->readiness()->missing_abilities() );
		self::assertSame( 'requirements_unchecked', $result->readiness()->requirements_error() );
		self::assertSame( WorkflowRunState::DRY_RUN_READY, $result->snapshot()->state() );
	}

	public function test_gated_plan_stops_before_approval(): void {
		$result = $this->prepare( $this->ordered_definition(), '{"brief":"Approval later"}' );

		self::assertNotSame( WorkflowRunState::WAITING_FOR_APPROVAL, $result->snapshot()->state() );
		self::assertSame( array( 'create_draft' ), $result->plan()->approval_gate_step_ids() );
	}

	public function test_repeated_preparation_is_deterministic_and_does_not_expose_raw_input(): void {
		$first  = $this->prepare( $this->ordered_definition(), '{"brief":"private preparation value"}' );
		$second = $this->prepare( $this->ordered_definition(), '{"brief":"private preparation value"}' );

		self::assertSame( $first->plan()->hash(), $second->plan()->hash() );
		self::assertSame( $first->dry_run()->canonical_json(), $second->dry_run()->canonical_json() );
		$serialized = serialize( $first ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only privacy inspection.
		self::assertStringNotContainsString( 'private preparation value', $serialized );
	}

	public function test_result_rejects_cross_plan_evidence(): void {
		$first   = $this->prepared_plan( '{"brief":"First"}' );
		$second  = $this->prepared_plan( '{"brief":"Second"}' );
		$dry_run = WorkflowDryRun::from_plan( $first );

		$this->expectException( WorkflowPlanningException::class );
		new WorkflowPreparationResult(
			$first,
			new WorkflowStateSnapshot( WorkflowRunState::DRY_RUN_READY, $first, $dry_run ),
			$dry_run,
			( new WorkflowPlanReadinessEvaluator() )->evaluate( $second, $this->availability( $second ) )
		);
	}

	public function test_result_views_are_detached_from_mutation(): void {
		$result   = $this->prepare( $this->ordered_definition(), '{"brief":"Detached"}' );
		$identity = $result->plan()->identity();
		$dry_run  = $result->dry_run()->to_array();

		$identity['workflow_id'] = 'tampered';
		$dry_run['workflow_id']  = 'tampered';

		self::assertNotSame( 'tampered', $result->plan()->identity()['workflow_id'] );
		self::assertNotSame( 'tampered', $result->dry_run()->to_array()['workflow_id'] );
	}

	public function test_source_boundary_has_no_runtime_or_wordpress_integrations(): void {
		$source = file_get_contents( dirname( __DIR__, 4 ) . '/src/Workflows/Planning/WorkflowRunPreparationCoordinator.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only architecture guard.
		self::assertIsString( $source );
		foreach ( array( 'wpdb', 'get_option', 'set_transient', 'wp_cache', 'do_action', 'apply_filters', 'McpController', 'AbilityExecutionGateway', 'register_rest_route', 'OAuth' ) as $forbidden ) {
			self::assertStringNotContainsString( $forbidden, $source );
		}
	}

	private function prepare( WorkflowDefinition $definition, string $input_json ): WorkflowPreparationResult {
		$input = WorkflowInputContract::from_json( $input_json );
		$plan  = ( new WorkflowPlanBuilder() )->build( $definition, $input );

		return ( new WorkflowRunPreparationCoordinator() )->prepare( $definition, $input, $this->availability( $plan ) );
	}

	private function prepared_plan( string $input_json ): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build(
			$this->ordered_definition(),
			WorkflowInputContract::from_json( $input_json )
		);
	}

	private function availability( WorkflowPlan $plan ): WorkflowAvailabilitySnapshot {
		$bindings = array();
		foreach ( $plan->identity()['steps'] as $step_value ) {
			$step       = $step_value instanceof stdClass ? get_object_vars( $step_value ) : $step_value;
			$bindings[] = array(
				'adapter_id'      => $step['adapter_id'],
				'adapter_version' => $step['adapter_version'],
				'ability_id'      => $step['ability_id'],
				'kind'            => $step['kind'],
			);
		}

		return WorkflowAvailabilitySnapshot::from_value(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => $bindings,
			)
		);
	}

	private function ordered_definition(): WorkflowDefinition {
		$json = file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/ordered-multi-step-v1.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned test fixture.
		self::assertIsString( $json );

		return WorkflowDefinition::from_json( $json );
	}
}
