<?php
/**
 * Deterministic workflow planning value tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Planning;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Planning\WorkflowDryRun;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanningException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies plan identity, input bounds, and dry-run determinism.
 */
final class WorkflowPlanningTest extends TestCase {

	public function test_ordered_fixture_builds_exact_deterministic_plan(): void {
		$plan     = $this->ordered_plan( '{"brief":"Build a durable page"}' );
		$identity = $plan->identity();

		self::assertSame( 'ordered_multi_step_fixture', $identity['workflow_id'] );
		self::assertSame( 3, $identity['definition_revision'] );
		self::assertSame( '80cf306c70c9c905d15f719e73235a1d1d85f989eee7142c866532c1cfa53037', $identity['definition_checksum'] );
		self::assertSame( array( 'read_context', 'prepare_content', 'create_draft' ), $identity['step_ids'] );
		self::assertSame( array( 'create_draft' ), $identity['approval_gate_step_ids'] );
		self::assertSame( array( 'require_title' ), $identity['validation_rule_ids'] );
		self::assertSame(
			array( 'content/create-draft', 'content/get-item', 'content/prepare-draft' ),
			$identity['ability_requirements']
		);
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/D', $plan->hash() );
		self::assertTrue( $plan->is_input_ready() );
		self::assertTrue( $plan->requires_validation() );
	}

	public function test_equivalent_input_map_order_has_identical_identity(): void {
		$definition = $this->fixture( 'proposal-only-v1.json' );
		$builder    = new WorkflowPlanBuilder();
		$first      = $builder->build( $definition, WorkflowInputContract::from_json( '{"post_id":9,"extra":{"b":2,"a":1}}' ) );
		$second     = $builder->build( $definition, WorkflowInputContract::from_json( '{"extra":{"a":1,"b":2},"post_id":9}' ) );

		self::assertSame( $first->input_hash(), $second->input_hash() );
		self::assertSame( $first->hash(), $second->hash() );
		self::assertSame( $first->canonical_json(), $second->canonical_json() );
	}

	public function test_list_order_and_numeric_types_are_identity_significant(): void {
		$integer = WorkflowInputContract::from_json( '{"value":1,"items":[1,2]}' );
		$float   = WorkflowInputContract::from_json( '{"value":1.0,"items":[1,2]}' );
		$reverse = WorkflowInputContract::from_json( '{"value":1,"items":[2,1]}' );

		self::assertNotSame( $integer->hash(), $float->hash() );
		self::assertNotSame( $integer->hash(), $reverse->hash() );
	}

	public function test_explicit_object_and_list_remain_distinct_and_detached(): void {
		$input = WorkflowInputContract::from_json( '{"object":{},"list":[],"0":"numeric-name"}' );
		$value = $input->value();

		self::assertInstanceOf( stdClass::class, $value->object );
		self::assertSame( array(), $value->list );
		self::assertSame( 'numeric-name', $value->{'0'} );

		$value->object->mutated = true;
		self::assertFalse( property_exists( $input->value()->object, 'mutated' ) );
	}

	public function test_missing_and_invalid_input_paths_are_sorted_and_bounded(): void {
		$missing = $this->ordered_plan( '{}' );
		$invalid = $this->ordered_plan( '{"brief":""}' );

		self::assertSame( array( '$.brief' ), $missing->missing_paths() );
		self::assertSame( array(), $missing->invalid_paths() );
		self::assertSame( array(), $invalid->missing_paths() );
		self::assertSame( array( '$.brief' ), $invalid->invalid_paths() );
	}

	public function test_dry_run_is_exact_and_never_contains_raw_input_or_arguments(): void {
		$plan    = $this->ordered_plan( '{"brief":"secret editorial brief"}' );
		$first   = WorkflowDryRun::from_plan( $plan );
		$second  = WorkflowDryRun::from_plan( $plan );
		$payload = $first->to_array();

		self::assertSame( $first->canonical_json(), $second->canonical_json() );
		self::assertSame( $plan->hash(), $payload['plan_hash'] );
		self::assertSame( 'deferred', $payload['validation_status'] );
		self::assertFalse( $payload['execution_started'] );
		self::assertStringNotContainsString( 'secret editorial brief', $first->canonical_json() );
		self::assertStringNotContainsString( 'blocks', $first->canonical_json(), 'Step arguments must not be exposed.' );
	}

	/**
	 * Verify malformed and unbounded inputs fail closed.
	 *
	 * @param mixed  $value Expected-invalid root/value.
	 * @param string $code  Expected stable code.
	 */
	#[DataProvider( 'invalid_input_provider' )]
	public function test_invalid_input_values_fail_closed( mixed $value, string $code ): void {
		try {
			if ( is_string( $value ) ) {
				WorkflowInputContract::from_json( $value );
			} else {
				WorkflowInputContract::from_value( $value );
			}
			self::fail( 'Expected input validation failure.' );
		} catch ( WorkflowPlanningException $exception ) {
			self::assertSame( $code, $exception->error_code() );
			self::assertLessThanOrEqual( 96, strlen( $exception->path() ) );
		}
	}

	/**
	 * Return malformed/unbounded input fixtures.
	 *
	 * @return iterable<string, array{0:mixed,1:string}>
	 */
	public static function invalid_input_provider(): iterable {
		yield 'list JSON root' => array( '[]', 'invalid_input_root' );
		yield 'scalar JSON root' => array( '"scalar"', 'invalid_input_root' );
		yield 'malformed JSON' => array( '{', 'non_json_input' );
		yield 'PHP list root' => array( array( 'value' ), 'invalid_input_root' );
		yield 'resource child' => array( (object) array( 'bad' => fopen( 'php://memory', 'rb' ) ), 'non_json_input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Deliberate hostile resource fixture.
		yield 'non finite number' => array( (object) array( 'bad' => INF ), 'non_json_input' );
		yield 'oversized input' => array( (object) array( 'value' => str_repeat( 'a', WorkflowInputContract::MAX_ENCODED_BYTES + 1 ) ), 'input_too_large' );
	}

	public function test_cycles_and_depth_are_bounded(): void {
		$cycle       = new stdClass();
		$cycle->self = $cycle;

		try {
			WorkflowInputContract::from_value( $cycle );
			self::fail( 'Expected cycle rejection.' );
		} catch ( WorkflowPlanningException $exception ) {
			self::assertSame( 'non_json_input', $exception->error_code() );
		}

		$deep = new stdClass();
		$node = $deep;
		for ( $index = 0; $index < WorkflowInputContract::MAX_DEPTH + 2; ++$index ) {
			$node->child = new stdClass();
			$node        = $node->child;
		}

		try {
			WorkflowInputContract::from_value( $deep );
			self::fail( 'Expected depth rejection.' );
		} catch ( WorkflowPlanningException $exception ) {
			self::assertSame( 'input_too_deep', $exception->error_code() );
		}
	}

	public function test_node_budget_and_unicode_are_deterministic(): void {
		$large = new stdClass();
		for ( $index = 0; $index < WorkflowInputContract::MAX_NODES; ++$index ) {
			$large->{'key_' . $index} = $index;
		}

		try {
			WorkflowInputContract::from_value( $large );
			self::fail( 'Expected node budget rejection.' );
		} catch ( WorkflowPlanningException $exception ) {
			self::assertSame( 'input_too_complex', $exception->error_code() );
		}

		$first  = WorkflowInputContract::from_json( '{"label":"नमस्ते 😀","object":{}}' );
		$second = WorkflowInputContract::from_value(
			(object) array(
				'object' => new stdClass(),
				'label'  => 'नमस्ते 😀',
			)
		);
		self::assertSame( $first->canonical_json(), $second->canonical_json() );
		self::assertSame( $first->hash(), $second->hash() );
	}

	public function test_planning_source_has_no_runtime_or_wordpress_integration_boundary(): void {
		$files = glob( dirname( __DIR__, 4 ) . '/src/Workflows/Planning/*.php' );
		self::assertIsArray( $files );
		self::assertNotEmpty( $files );

		$forbidden = '/(?:\bwp_[a-z0-9_]+\s*\(|\$wpdb\b|\bget_option\s*\(|\bupdate_option\s*\(|\bset_transient\s*\(|\bwp_cache_[a-z0-9_]+\s*\(|\bmicrotime\s*\(|\brandom_(?:bytes|int)\s*\(|AbilitiesRegistry|IntelligenceRegistry|McpController|WP_REST)/i';
		foreach ( $files as $file ) {
			$source = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned source-boundary fixture.
			self::assertIsString( $source );
			self::assertDoesNotMatchRegularExpression( $forbidden, $source, basename( $file ) . ' crossed the pure planning boundary.' );
		}
	}

	public function test_plan_and_dry_run_outputs_are_detached(): void {
		$plan                    = $this->ordered_plan( '{"brief":"Immutable"}' );
		$identity                = $plan->identity();
		$identity['step_ids'][0] = 'mutated';

		self::assertSame( 'read_context', $plan->identity()['step_ids'][0] );

		$dry_run                     = WorkflowDryRun::from_plan( $plan );
		$payload                     = $dry_run->to_array();
		$payload['steps'][0]->status = 'mutated';

		self::assertSame( 'planned', $dry_run->to_array()['steps'][0]->status );
	}

	private function ordered_plan( string $input_json ): WorkflowPlan {
		return ( new WorkflowPlanBuilder() )->build(
			$this->fixture( 'ordered-multi-step-v1.json' ),
			WorkflowInputContract::from_json( $input_json )
		);
	}

	private function fixture( string $name ): WorkflowDefinition {
		$json = file_get_contents( dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned local fixture.
		self::assertIsString( $json );

		return WorkflowDefinition::from_json( $json );
	}
}
