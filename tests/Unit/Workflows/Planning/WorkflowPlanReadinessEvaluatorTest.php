<?php
/**
 * Pure workflow plan readiness evaluator tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Planning;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Planning\WorkflowAvailabilitySnapshot;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanReadinessEvaluator;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanningException;
use Aculect\AICompanion\Workflows\Planning\WorkflowReadinessEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Locks exact version-aware requirement evaluation and bounded failures.
 */
final class WorkflowPlanReadinessEvaluatorTest extends TestCase {

	public function test_exact_and_extra_availability_produces_ready_bound_evidence(): void {
		$plan     = $this->ordered_plan();
		$evidence = ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$plan,
			$this->snapshot(
				array(
					array(
						'adapter_id'       => 'wordpress',
						'adapter_versions' => array( 9, 2, 1 ),
					),
					array(
						'adapter_id'       => 'content_planner',
						'adapter_versions' => array( 1, 7 ),
					),
				),
				array( 'content/prepare-draft', 'content/create-draft', 'content/get-item' )
			)
		);

		self::assertNull( $evidence->binding_error_for( $plan ) );
		self::assertNull( $evidence->requirements_error() );
		self::assertSame( 'validation_unchecked', $evidence->validation_error_for( $plan ) );
		self::assertSame( array(), $evidence->missing_adapters() );
		self::assertSame( array(), $evidence->missing_abilities() );
	}

	public function test_every_exact_step_adapter_version_is_required_even_when_metadata_groups_versions(): void {
		$plan                  = $this->ordered_plan();
		$identity              = $plan->identity();
		$expected_requirements = wp_json_encode(
			array(
				array(
					'adapter_id'       => 'content_planner',
					'adapter_versions' => array( 1 ),
				),
				array(
					'adapter_id'       => 'wordpress',
					'adapter_versions' => array( 1, 2 ),
				),
			)
		);
		$actual_requirements   = wp_json_encode( $identity['adapter_requirements'] );
		self::assertIsString( $expected_requirements );
		self::assertIsString( $actual_requirements );
		self::assertJsonStringEqualsJsonString( $expected_requirements, $actual_requirements );

		$evidence = ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$plan,
			$this->snapshot(
				array(
					array(
						'adapter_id'       => 'content_planner',
						'adapter_versions' => array( 1 ),
					),
					array(
						'adapter_id'       => 'wordpress',
						'adapter_versions' => array( 2 ),
					),
				),
				$identity['ability_requirements']
			)
		);

		self::assertSame( 'requirements_unchecked', $evidence->requirements_error() );
		self::assertSame( array( 'wordpress@1' ), $evidence->missing_adapters() );
	}

	public function test_wrong_versions_and_missing_abilities_are_sorted_and_fail_closed(): void {
		$plan     = $this->ordered_plan();
		$evidence = ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$plan,
			$this->snapshot(
				array(
					array(
						'adapter_id'       => 'wordpress',
						'adapter_versions' => array( 3 ),
					),
				),
				array( 'content/get-item' )
			)
		);

		self::assertSame( 'requirements_unchecked', $evidence->requirements_error() );
		self::assertSame(
			array( 'content_planner@1', 'wordpress@1', 'wordpress@2' ),
			$evidence->missing_adapters()
		);
		self::assertSame(
			array( 'content/create-draft', 'content/prepare-draft' ),
			$evidence->missing_abilities()
		);
	}

	public function test_repeated_exact_step_version_is_reported_once_when_missing(): void {
		$json = file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/ordered-multi-step-v1.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned local fixture.
		self::assertIsString( $json );
		$value = json_decode( $json );
		self::assertInstanceOf( stdClass::class, $value );
		self::assertIsArray( $value->steps );
		$repeated          = clone $value->steps[0];
		$repeated->step_id = 'read_context_repeat';
		$value->steps[]    = $repeated;
		$definition_json   = wp_json_encode( $value );
		self::assertIsString( $definition_json );

		$plan     = ( new WorkflowPlanBuilder() )->build(
			WorkflowDefinition::from_json( $definition_json ),
			WorkflowInputContract::from_json( '{"brief":"Repeated adapter"}' )
		);
		$identity = $plan->identity();
		$evidence = ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$plan,
			$this->snapshot(
				array(
					array(
						'adapter_id'       => 'content_planner',
						'adapter_versions' => array( 1 ),
					),
					array(
						'adapter_id'       => 'wordpress',
						'adapter_versions' => array( 1 ),
					),
				),
				$identity['ability_requirements']
			)
		);

		self::assertSame( array( 'wordpress@2' ), $evidence->missing_adapters() );
	}

	public function test_equivalent_availability_order_is_deterministic_and_detached(): void {
		$first  = $this->snapshot(
			array(
				array(
					'adapter_id'       => 'wordpress',
					'adapter_versions' => array( 2, 1 ),
				),
				array(
					'adapter_id'       => 'content_planner',
					'adapter_versions' => array( 1 ),
				),
			),
			array( 'content/prepare-draft', 'content/get-item', 'content/create-draft' )
		);
		$second = $this->snapshot(
			array(
				array(
					'adapter_id'       => 'content_planner',
					'adapter_versions' => array( 1 ),
				),
				array(
					'adapter_id'       => 'wordpress',
					'adapter_versions' => array( 1, 2 ),
				),
			),
			array( 'content/create-draft', 'content/get-item', 'content/prepare-draft' )
		);

		self::assertSame( $first->adapters(), $second->adapters() );
		self::assertSame( $first->abilities(), $second->abilities() );

		$copy                           = $first->adapters();
		$copy[0]['adapter_versions'][0] = 999;
		self::assertSame( 1, $first->adapters()[0]['adapter_versions'][0] );

		$evaluator = new WorkflowPlanReadinessEvaluator();
		$plan      = $this->ordered_plan();
		self::assertSame(
			$evaluator->evaluate( $plan, $first )->missing_adapters(),
			$evaluator->evaluate( $plan, $second )->missing_adapters()
		);
	}

	public function test_no_validation_rules_produces_fully_ready_evidence(): void {
		$plan     = $this->proposal_plan();
		$identity = $plan->identity();
		$evidence = ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$plan,
			$this->snapshot( $identity['adapter_requirements'], $identity['ability_requirements'] )
		);

		self::assertNull( $evidence->requirements_error() );
		self::assertNull( $evidence->validation_error_for( $plan ) );
	}

	public function test_evidence_is_bound_to_exact_plan_hash_and_contains_no_input(): void {
		$first    = $this->ordered_plan( '{"brief":"private first brief"}' );
		$second   = $this->ordered_plan( '{"brief":"private second brief"}' );
		$identity = $first->identity();
		$evidence = ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$first,
			$this->snapshot( $identity['adapter_requirements'], $identity['ability_requirements'] )
		);

		self::assertNull( $evidence->binding_error_for( $first ) );
		self::assertSame( 'evidence_mismatch', $evidence->binding_error_for( $second ) );
		$serialized_evidence = serialize( $evidence ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only inspection; never deserialized.
		self::assertStringNotContainsString( 'private first brief', $serialized_evidence );
		self::assertStringNotContainsString( 'private second brief', $serialized_evidence );
	}

	public function test_checked_evidence_factory_has_one_production_caller(): void {
		$source_root = dirname( __DIR__, 4 ) . '/src';
		$matches     = array();
		$iterator    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $source_root ) );
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$source = file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only architecture guard.
			if ( is_string( $source ) && str_contains( $source, 'WorkflowReadinessEvidence::from_evaluation(' ) ) {
				$matches[] = $file->getFilename();
			}
		}

		self::assertSame( array( 'WorkflowPlanReadinessEvaluator.php' ), $matches );
	}

	public function test_internal_checked_factory_rejects_malformed_missing_details(): void {
		$plan    = $this->ordered_plan();
		$invalid = array(
			array( array( 'wordpress@2', 'wordpress@1' ), array() ),
			array( array( 'wordpress@1', 'wordpress@1' ), array() ),
			array( array( 'wordpress@0' ), array() ),
			array( array(), array( 'content/get-item', 'content/get-item' ) ),
		);

		foreach ( $invalid as $missing ) {
			try {
				WorkflowReadinessEvidence::from_evaluation( $plan, $missing[0], $missing[1] );
				self::fail( 'Expected invalid missing evidence.' );
			} catch ( WorkflowPlanningException $exception ) {
				self::assertSame( 'invalid_request', $exception->error_code() );
			}
		}
	}

	/**
	 * Verify malformed snapshots fail closed with bounded errors.
	 *
	 * @param array  $adapters  Invalid adapters.
	 * @param array  $abilities Invalid abilities.
	 * @param string $code      Expected error code.
	 */
	#[DataProvider( 'invalid_snapshot_provider' )]
	public function test_malformed_snapshots_fail_closed( array $adapters, array $abilities, string $code ): void {
		try {
			$this->snapshot( $adapters, $abilities );
			self::fail( 'Expected snapshot failure.' );
		} catch ( WorkflowPlanningException $exception ) {
			self::assertSame( $code, $exception->error_code() );
			self::assertLessThanOrEqual( 96, strlen( $exception->path() ) );
		}
	}

	/**
	 * Return invalid snapshot fixtures.
	 *
	 * @return iterable<string, array{0:array,1:array,2:string}>
	 */
	public static function invalid_snapshot_provider(): iterable {
		yield 'duplicate adapter' => array(
			array(
				array(
					'adapter_id'       => 'wordpress',
					'adapter_versions' => array( 1 ),
				),
				array(
					'adapter_id'       => 'wordpress',
					'adapter_versions' => array( 2 ),
				),
			),
			array(),
			'duplicate_adapter_id',
		);
		yield 'duplicate version' => array(
			array(
				array(
					'adapter_id'       => 'wordpress',
					'adapter_versions' => array( 1, 1 ),
				),
			),
			array(),
			'duplicate_adapter_version',
		);
		yield 'coerced version' => array(
			array(
				array(
					'adapter_id'       => 'wordpress',
					'adapter_versions' => array( '1' ),
				),
			),
			array(),
			'invalid_adapter_version',
		);
		yield 'case folded adapter' => array(
			array(
				array(
					'adapter_id'       => 'WordPress',
					'adapter_versions' => array( 1 ),
				),
			),
			array(),
			'invalid_adapter_id',
		);
		yield 'duplicate ability' => array( array(), array( 'content/get-item', 'content/get-item' ), 'duplicate_ability_id' );
		yield 'wildcard ability' => array( array(), array( 'content/*' ), 'invalid_ability_id' );
	}

	/**
	 * Build one object-shaped availability fixture.
	 *
	 * @param array $adapters  Adapter availability.
	 * @param array $abilities Ability availability.
	 */
	private function snapshot( array $adapters, array $abilities ): WorkflowAvailabilitySnapshot {
		return WorkflowAvailabilitySnapshot::from_value(
			array(
				'adapters'  => $adapters,
				'abilities' => $abilities,
			)
		);
	}

	private function ordered_plan( string $input = '{"brief":"Readiness"}' ): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build(
			$this->fixture( 'ordered-multi-step-v1.json' ),
			WorkflowInputContract::from_json( $input )
		);
	}

	private function proposal_plan(): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build(
			$this->fixture( 'proposal-only-v1.json' ),
			WorkflowInputContract::from_json( '{"post_id":9}' )
		);
	}

	private function fixture( string $name ): WorkflowDefinition {
		$json = file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned local fixture.
		self::assertIsString( $json );

		return WorkflowDefinition::from_json( $json );
	}
}
