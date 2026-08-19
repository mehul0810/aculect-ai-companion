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
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Locks exact tuple-aware requirement evaluation and bounded failures.
 */
final class WorkflowPlanReadinessEvaluatorTest extends TestCase {

	public function test_exact_and_extra_bindings_produce_ready_bound_evidence(): void {
		$plan       = $this->ordered_plan();
		$bindings   = $this->plan_bindings( $plan );
		$bindings[] = $this->binding( 'wordpress', 9, 'content/get-item', 'read' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Exact lowercase adapter identifier.
		$evidence   = ( new WorkflowPlanReadinessEvaluator() )->evaluate( $plan, $this->snapshot( $bindings ) );

		self::assertNull( $evidence->binding_error_for( $plan ) );
		self::assertNull( $evidence->requirements_error() );
		self::assertSame( 'validation_unchecked', $evidence->validation_error_for( $plan ) );
		self::assertSame( array(), $evidence->missing_bindings() );
		self::assertSame( array(), $evidence->missing_adapters() );
		self::assertSame( array(), $evidence->missing_abilities() );
	}

	public function test_cross_paired_adapter_and_ability_do_not_satisfy_exact_bindings(): void {
		$plan     = $this->ordered_plan();
		$evidence = ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$plan,
			$this->snapshot(
				array(
					$this->binding( 'wordpress', 2, 'content/prepare-draft', 'read' ),
					$this->binding( 'content_planner', 1, 'content/get-item', 'proposal' ),
					$this->binding( 'wordpress', 1, 'content/create-draft', 'write' ),
				)
			)
		);

		self::assertSame( 'requirements_unchecked', $evidence->requirements_error() );
		self::assertSame(
			array(
				'content_planner@1|content/prepare-draft|proposal',
				'wordpress@2|content/get-item|read',
			),
			$evidence->missing_bindings()
		);
	}

	public function test_wrong_kind_and_wrong_version_fail_closed_even_with_same_ability(): void {
		$plan     = $this->ordered_plan();
		$evidence = ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$plan,
			$this->snapshot(
				array(
					$this->binding( 'wordpress', 3, 'content/get-item', 'read' ),
					$this->binding( 'content_planner', 1, 'content/prepare-draft', 'read' ),
					$this->binding( 'wordpress', 1, 'content/create-draft', 'write' ),
				)
			)
		);

		self::assertSame(
			array(
				'content_planner@1|content/prepare-draft|proposal',
				'wordpress@2|content/get-item|read',
			),
			$evidence->missing_bindings()
		);
		self::assertSame( array( 'content_planner@1', 'wordpress@2' ), $evidence->missing_adapters() );
		self::assertSame( array( 'content/get-item', 'content/prepare-draft' ), $evidence->missing_abilities() );
	}

	public function test_repeated_exact_binding_is_reported_once_when_missing(): void {
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

		$plan      = ( new WorkflowPlanBuilder() )->build(
			WorkflowDefinition::from_json( $definition_json ),
			WorkflowInputContract::from_json( '{"brief":"Repeated binding"}' )
		);
		$available = array_values(
			array_filter(
				$this->plan_bindings( $plan ),
				static fn ( array $binding ): bool => 2 !== $binding['adapter_version']
			)
		);
		$evidence  = ( new WorkflowPlanReadinessEvaluator() )->evaluate( $plan, $this->snapshot( $available ) );

		self::assertSame( array( 'wordpress@2|content/get-item|read' ), $evidence->missing_bindings() );
	}

	public function test_equivalent_binding_order_is_deterministic_and_detached(): void {
		$plan     = $this->ordered_plan();
		$bindings = $this->plan_bindings( $plan );
		$first    = $this->snapshot( $bindings );
		$second   = $this->snapshot( array_reverse( $bindings ) );

		self::assertSame( $first->bindings(), $second->bindings() );
		$copy                       = $first->bindings();
		$copy[0]['adapter_version'] = 999;
		self::assertNotSame( 999, $first->bindings()[0]['adapter_version'] );

		$evaluator = new WorkflowPlanReadinessEvaluator();
		self::assertSame(
			$evaluator->evaluate( $plan, $first )->missing_bindings(),
			$evaluator->evaluate( $plan, $second )->missing_bindings()
		);
	}

	public function test_no_validation_rules_produces_fully_ready_evidence(): void {
		$plan     = $this->proposal_plan();
		$evidence = ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$plan,
			$this->snapshot( $this->plan_bindings( $plan ) )
		);

		self::assertNull( $evidence->requirements_error() );
		self::assertNull( $evidence->validation_error_for( $plan ) );
	}

	public function test_evidence_is_bound_to_exact_plan_hash_and_contains_no_input(): void {
		$first    = $this->ordered_plan( '{"brief":"private first brief"}' );
		$second   = $this->ordered_plan( '{"brief":"private second brief"}' );
		$evidence = ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$first,
			$this->snapshot( $this->plan_bindings( $first ) )
		);

		self::assertNull( $evidence->binding_error_for( $first ) );
		self::assertSame( 'evidence_mismatch', $evidence->binding_error_for( $second ) );
		$serialized_evidence = serialize( $evidence ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only inspection; never deserialized.
		self::assertStringNotContainsString( 'private first brief', $serialized_evidence );
		self::assertStringNotContainsString( 'private second brief', $serialized_evidence );
	}

	public function test_internal_checked_factory_rejects_malformed_missing_binding_tokens(): void {
		$plan    = $this->ordered_plan();
		$invalid = array(
			array( 'wordpress@2|content/get-item|read', 'content_planner@1|content/prepare-draft|proposal' ),
			array( 'wordpress@2|content/get-item|read', 'wordpress@2|content/get-item|read' ),
			array( 'wordpress@0|content/get-item|read' ),
			array( 'wordpress@1|content/get-item|execute' ),
			array( 'wordpress@1|content/*|read' ),
			array( 'wordpress@999999999999999999999999|content/get-item|read' ),
			array( 'wordpress@1|content/' . str_repeat( 'a', 121 ) . '|read' ),
		);

		foreach ( $invalid as $missing ) {
			try {
				WorkflowReadinessEvidence::from_evaluation( $plan, $missing );
				self::fail( 'Expected invalid missing evidence.' );
			} catch ( WorkflowPlanningException $exception ) {
				self::assertSame( 'invalid_request', $exception->error_code() );
			}
		}
	}

	public function test_checked_evidence_factory_has_one_production_caller_and_no_inference_source(): void {
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

		$evaluator_source = file_get_contents( $source_root . '/Workflows/Planning/WorkflowPlanReadinessEvaluator.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only architecture guard.
		self::assertIsString( $evaluator_source );
		self::assertStringContainsString( '->bindings()', $evaluator_source );
		self::assertStringNotContainsString( '->adapters()', $evaluator_source );
		self::assertStringNotContainsString( '->abilities()', $evaluator_source );
	}

	public function test_pure_readiness_sources_have_no_runtime_storage_hook_or_public_integration(): void {
		$source_root = dirname( __DIR__, 4 ) . '/src/Workflows/Planning/';
		$forbidden   = array(
			'wpdb',
			'get_option',
			'set_transient',
			'wp_cache',
			'do_action',
			'apply_filters',
			'AbilityExecutionGateway',
			'WorkflowAdapterRegistry',
			'register_rest_route',
			'OAuth',
			'Admin',
		);

		foreach ( array( 'WorkflowAvailabilitySnapshot.php', 'WorkflowPlanReadinessEvaluator.php', 'WorkflowReadinessEvidence.php' ) as $filename ) {
			$source = file_get_contents( $source_root . $filename ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only architecture guard.
			self::assertIsString( $source );
			foreach ( $forbidden as $needle ) {
				self::assertStringNotContainsString( $needle, $source, $filename . ' must remain pure.' );
			}
		}
	}

	/**
	 * Build one v2 exact-binding snapshot.
	 *
	 * @param array $bindings Exact binding tuples.
	 */
	private function snapshot( array $bindings ): WorkflowAvailabilitySnapshot {
		return WorkflowAvailabilitySnapshot::from_value(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => $bindings,
			)
		);
	}

	/**
	 * Project the exact step tuples from one immutable plan.
	 *
	 * @param WorkflowPlan $plan Immutable plan.
	 *
	 * @return list<array{adapter_id:string,adapter_version:int,ability_id:string,kind:string}>
	 */
	private function plan_bindings( WorkflowPlan $plan ): array {
		$bindings = array();
		foreach ( $plan->identity()['steps'] as $step_value ) {
			$step       = $step_value instanceof stdClass ? get_object_vars( $step_value ) : $step_value;
			$bindings[] = $this->binding( $step['adapter_id'], $step['adapter_version'], $step['ability_id'], $step['kind'] );
		}

		return $bindings;
	}

	/**
	 * Build one exact binding tuple.
	 *
	 * @param string $adapter_id      Exact adapter ID.
	 * @param int    $adapter_version Exact adapter version.
	 * @param string $ability_id      Exact ability ID.
	 * @param string $kind            Exact binding kind.
	 *
	 * @return array{adapter_id:string,adapter_version:int,ability_id:string,kind:string}
	 */
	private function binding( string $adapter_id, int $adapter_version, string $ability_id, string $kind ): array {
		return compact( 'adapter_id', 'adapter_version', 'ability_id', 'kind' );
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
