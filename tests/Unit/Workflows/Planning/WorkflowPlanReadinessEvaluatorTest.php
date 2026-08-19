<?php
/**
 * Pure workflow planned-requirement readiness evaluator tests.
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
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies pure availability comparison and exact plan binding.
 */
final class WorkflowPlanReadinessEvaluatorTest extends TestCase {

	public function test_exact_requirements_return_bound_ready_evidence_without_raw_plan_data(): void {
		$plan     = $this->ordered_plan( '{"brief":"secret-editorial-brief"}' );
		$evidence = $this->evaluate(
			$plan,
			array( 'wordpress', 'content_planner' ),
			array( 'content/create-draft', 'content/get-item', 'content/prepare-draft' )
		);

		self::assertTrue( $evidence->requirements_ready() );
		self::assertSame( array(), $evidence->missing_adapter_ids() );
		self::assertSame( array(), $evidence->missing_ability_ids() );
		self::assertNull( $evidence->requirements_error() );
		self::assertFalse( $evidence->validation_checked(), 'Availability does not evaluate plan validation rules.' );
		self::assertSame( 'validation_unchecked', $evidence->validation_error_for( $plan ) );
		self::assertSame( 'ordered_multi_step_fixture', $evidence->workflow_id() );
		self::assertSame( $plan->definition_revision(), $evidence->definition_revision() );
		self::assertSame( $plan->definition_checksum(), $evidence->definition_checksum() );
		self::assertSame( $plan->input_hash(), $evidence->normalized_input_hash() );
		self::assertSame( $plan->hash(), $evidence->plan_hash() );
		self::assertNull( $evidence->binding_error_for( $plan ) );
		$payload = $evidence->to_array();
		self::assertArrayNotHasKey( 'raw_input', $payload );
		self::assertArrayNotHasKey( 'step_arguments', $payload );
		self::assertArrayNotHasKey( 'provider_output', $payload );
		self::assertArrayNotHasKey( 'secrets', $payload );
		self::assertArrayNotHasKey( 'credentials', $payload );
	}

	public function test_mixed_availability_returns_sorted_exact_missing_ids(): void {
		$evidence = $this->evaluate(
			$this->ordered_plan( '{"brief":"Mixed"}' ),
			array( 'content_planner' ),
			array( 'content/prepare-draft' )
		);

		self::assertFalse( $evidence->requirements_ready() );
		self::assertSame( 'requirements_unchecked', $evidence->requirements_error() );
		self::assertSame( array( 'wordpress' ), $evidence->missing_adapter_ids() );
		self::assertSame(
			array( 'content/create-draft', 'content/get-item' ),
			$evidence->missing_ability_ids()
		);
	}

	public function test_empty_missing_requirements_return_ready_evidence_for_validation_free_plan(): void {
		$plan     = $this->proposal_plan( '{"post_id":7}' );
		$evidence = $this->evaluate( $plan, array( 'wordpress' ), array( 'content/get-item' ) );

		self::assertTrue( $evidence->requirements_ready() );
		self::assertNull( $evidence->requirements_error() );
		self::assertTrue( $evidence->validation_checked() );
		self::assertNull( $evidence->validation_error_for( $plan ) );
	}

	public function test_exact_matching_never_case_folds_prefixes_or_aliases(): void {
		$plan     = $this->proposal_plan( '{"post_id":8}' );
		$evidence = $this->evaluate( $plan, array( 'word_press' ), array( 'content/get_item' ) );

		self::assertSame( array( 'wordpress' ), $evidence->missing_adapter_ids() );
		self::assertSame( array( 'content/get-item' ), $evidence->missing_ability_ids() );
	}

	public function test_equivalent_snapshot_order_produces_identical_evidence(): void {
		$plan      = $this->ordered_plan( '{"brief":"Equivalent"}' );
		$evaluator = new WorkflowPlanReadinessEvaluator();
		$first     = $evaluator->evaluate(
			$plan,
			WorkflowAvailabilitySnapshot::from_ids(
				array( 'wordpress', 'content_planner' ),
				array( 'content/prepare-draft', 'content/create-draft', 'content/get-item' )
			)
		);
		$second    = $evaluator->evaluate(
			$plan,
			WorkflowAvailabilitySnapshot::from_ids(
				array( 'content_planner', 'wordpress' ),
				array( 'content/get-item', 'content/create-draft', 'content/prepare-draft' )
			)
		);

		self::assertSame( $first->to_array(), $second->to_array() );
	}

	public function test_evidence_binds_every_required_plan_identity_field(): void {
		$plan        = $this->ordered_plan( '{"brief":"First"}' );
		$evidence    = $this->evaluate(
			$plan,
			array( 'wordpress', 'content_planner' ),
			array( 'content/create-draft', 'content/get-item', 'content/prepare-draft' )
		);
		$other_input = $this->ordered_plan( '{"brief":"Second"}' );

		self::assertSame( $plan->definition_revision(), $evidence->definition_revision() );
		self::assertSame( $plan->definition_checksum(), $evidence->definition_checksum() );
		self::assertSame( $plan->input_hash(), $evidence->normalized_input_hash() );
		self::assertSame( $plan->hash(), $evidence->plan_hash() );
		self::assertSame( 'evidence_mismatch', $evidence->binding_error_for( $other_input ) );
	}

	public function test_snapshot_and_evidence_arrays_are_detached(): void {
		$adapter_ids    = array( 'wordpress', 'content_planner' );
		$ability_ids    = array( 'content/get-item', 'content/prepare-draft', 'content/create-draft' );
		$snapshot       = WorkflowAvailabilitySnapshot::from_ids( $adapter_ids, $ability_ids );
		$adapter_ids[0] = 'mutated';
		$ability_ids[0] = 'mutated/value';

		self::assertSame( array( 'content_planner', 'wordpress' ), $snapshot->adapter_ids() );
		self::assertSame( array( 'content/create-draft', 'content/get-item', 'content/prepare-draft' ), $snapshot->ability_ids() );

		$payload                   = $snapshot->to_array();
		$payload['adapter_ids'][0] = 'mutated';
		self::assertSame( 'content_planner', $snapshot->adapter_ids()[0] );

		$evidence   = $this->evaluate( $this->ordered_plan( '{"brief":"Detached"}' ), array(), array() );
		$missing    = $evidence->missing_ability_ids();
		$missing[0] = 'mutated/value';
		self::assertSame( 'content/create-draft', $evidence->missing_ability_ids()[0] );
	}

	/**
	 * Verify untrusted malformed snapshots fail with existing bounded planning errors.
	 *
	 * @param mixed $value Candidate snapshot value.
	 */
	#[DataProvider( 'malformed_snapshot_provider' )]
	public function test_malformed_snapshots_fail_closed( mixed $value ): void {
		try {
			WorkflowAvailabilitySnapshot::from_value( $value );
			self::fail( 'Expected availability rejection.' );
		} catch ( WorkflowPlanningException $exception ) {
			self::assertSame( 'invalid_request', $exception->error_code() );
			self::assertLessThanOrEqual( 96, strlen( $exception->path() ) );
		}
	}

	/**
	 * Return hostile and malformed availability fixtures.
	 *
	 * @return iterable<string, array{0:mixed}>
	 */
	public static function malformed_snapshot_provider(): iterable {
		yield 'list root' => array( array() );
		yield 'object root' => array(
			(object) array(
				'adapter_ids' => array(),
				'ability_ids' => array(),
			),
		);
		yield 'array access root' => array(
			new ArrayObject(
				array(
					'adapter_ids' => array(),
					'ability_ids' => array(),
				)
			),
		);
		yield 'missing exact key' => array( array( 'adapter_ids' => array() ) );
		yield 'unknown key' => array(
			array(
				'adapter_ids' => array(),
				'ability_ids' => array(),
				'alias_ids'   => array(),
			),
		);
		yield 'adapter map' => array(
			array(
				'adapter_ids' => array( 'wordpress' => true ),
				'ability_ids' => array(),
			),
		);
		yield 'non-string adapter ID' => array(
			array(
				'adapter_ids' => array( 42 ),
				'ability_ids' => array(),
			),
		);
		yield 'invalid adapter ID' => array(
			array(
				'adapter_ids' => array( 'WordPress' ),
				'ability_ids' => array(),
			),
		);
		yield 'duplicate adapter ID' => array(
			array(
				'adapter_ids' => array( 'wordpress', 'wordpress' ),
				'ability_ids' => array(),
			),
		);
		yield 'overlong adapter ID' => array(
			array(
				'adapter_ids' => array( str_repeat( 'a', 65 ) ),
				'ability_ids' => array(),
			),
		);
		yield 'invalid ability ID' => array(
			array(
				'adapter_ids' => array(),
				'ability_ids' => array( 'content_get_item' ),
			),
		);
		yield 'duplicate ability ID' => array(
			array(
				'adapter_ids' => array(),
				'ability_ids' => array( 'content/get-item', 'content/get-item' ),
			),
		);
		yield 'too many adapter IDs' => array(
			array(
				'adapter_ids' => array_map( static fn ( int $index ): string => 'a' . $index, range( 0, WorkflowAvailabilitySnapshot::MAX_IDS ) ),
				'ability_ids' => array(),
			),
		);
	}

	/**
	 * Evaluate one plan against a detached availability snapshot.
	 *
	 * @param WorkflowPlan $plan        Plan to evaluate.
	 * @param array        $adapter_ids Exact available adapter IDs.
	 * @param array        $ability_ids Exact available ability IDs.
	 * @phpstan-param list<string> $adapter_ids
	 * @phpstan-param list<string> $ability_ids
	 */
	private function evaluate( WorkflowPlan $plan, array $adapter_ids, array $ability_ids ): WorkflowReadinessEvidence {
		return ( new WorkflowPlanReadinessEvaluator() )->evaluate(
			$plan,
			WorkflowAvailabilitySnapshot::from_ids( $adapter_ids, $ability_ids )
		);
	}

	private function ordered_plan( string $input_json ): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build(
			$this->fixture( 'ordered-multi-step-v1.json' ),
			WorkflowInputContract::from_json( $input_json )
		);
	}

	private function proposal_plan( string $input_json ): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build(
			$this->fixture( 'proposal-only-v1.json' ),
			WorkflowInputContract::from_json( $input_json )
		);
	}

	private function fixture( string $name ): WorkflowDefinition {
		$json = file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned local fixture.
		self::assertIsString( $json );

		return WorkflowDefinition::from_json( $json );
	}
}
