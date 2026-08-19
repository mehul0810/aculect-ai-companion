<?php
/**
 * Workflow availability snapshot boundary tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Planning;

use Aculect\AICompanion\Workflows\Planning\WorkflowAvailabilitySnapshot;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanningException;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Locks the fail-closed untrusted exact-binding availability boundary.
 */
final class WorkflowAvailabilitySnapshotTest extends TestCase {

	public function test_array_object_and_json_inputs_normalize_identically_and_detach(): void {
		$array = array(
			'availability_schema_version' => 2,
			'bindings'                    => array(
				$this->binding( 'wordpress', 2, 'content/update-item', 'write' ),
				$this->binding( 'wordpress', 1, 'content/get-item', 'read' ),
			),
		);

		$object                              = new stdClass();
		$object->availability_schema_version = 2;
		$object->bindings                    = array( (object) $array['bindings'][0], (object) $array['bindings'][1] );
		$json                                = wp_json_encode( $object );
		self::assertIsString( $json );

		$from_array  = WorkflowAvailabilitySnapshot::from_value( $array );
		$from_object = WorkflowAvailabilitySnapshot::from_value( $object );
		$from_json   = WorkflowAvailabilitySnapshot::from_json( $json );

		self::assertSame( $from_array->bindings(), $from_object->bindings() );
		self::assertSame( $from_array->bindings(), $from_json->bindings() );
		self::assertSame( 'content/get-item', $from_json->bindings()[0]['ability_id'] );

		$array['bindings'][0]['adapter_version'] = 99;
		$copy                                    = $from_json->bindings();
		$copy[0]['adapter_version']              = 88;
		self::assertSame( 1, $from_json->bindings()[0]['adapter_version'] );
	}

	public function test_legacy_adapters_and_abilities_root_is_rejected_without_inference(): void {
		$this->expect_failure(
			'invalid_availability',
			static fn () => WorkflowAvailabilitySnapshot::from_value(
				array(
					'adapters'  => array(),
					'abilities' => array(),
				)
			)
		);
	}

	public function test_exactly_fifty_distinct_bindings_are_accepted(): void {
		$bindings = array();
		for ( $version = 1; $version <= WorkflowAvailabilitySnapshot::MAX_BINDINGS; ++$version ) {
			$bindings[] = $this->binding( 'wordpress', $version, 'content/get-item', 'read' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Exact lowercase adapter identifier.
		}

		$snapshot = WorkflowAvailabilitySnapshot::from_value(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => array_reverse( $bindings ),
			)
		);

		self::assertCount( WorkflowAvailabilitySnapshot::MAX_BINDINGS, $snapshot->bindings() );
		self::assertSame(
			$snapshot->bindings(),
			WorkflowAvailabilitySnapshot::from_value(
				array(
					'availability_schema_version' => 2,
					'bindings'                    => $bindings,
				)
			)->bindings()
		);
	}

	/**
	 * Verify hostile root shapes fail closed without TypeError.
	 *
	 * @param mixed $value Candidate value.
	 */
	#[DataProvider( 'invalid_root_provider' )]
	public function test_invalid_value_roots_fail_closed( mixed $value ): void {
		$this->expect_failure( 'invalid_availability_root', static fn () => WorkflowAvailabilitySnapshot::from_value( $value ) );
	}

	/**
	 * Return hostile root values.
	 *
	 * @return iterable<string, array{0:mixed}>
	 */
	public static function invalid_root_provider(): iterable {
		yield 'null' => array( null );
		yield 'scalar' => array( 'availability' );
		yield 'list' => array( array() );
		yield 'arbitrary object' => array( new ArrayObject() );
		yield 'anonymous object' => array( new class() {} );
	}

	public function test_resource_root_fails_closed(): void {
		$resource = fopen( 'php://memory', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Test-only hostile resource fixture.
		self::assertIsResource( $resource );
		try {
			$this->expect_failure( 'invalid_availability_root', static fn () => WorkflowAvailabilitySnapshot::from_value( $resource ) );
		} finally {
			fclose( $resource ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Test-only hostile resource fixture cleanup.
		}
	}

	/**
	 * Verify malformed exact bindings fail closed with stable codes.
	 *
	 * @param mixed  $value Candidate snapshot.
	 * @param string $code  Expected code.
	 */
	#[DataProvider( 'invalid_binding_provider' )]
	public function test_malformed_schema_and_bindings_fail_closed( mixed $value, string $code ): void {
		$this->expect_failure( $code, static fn () => WorkflowAvailabilitySnapshot::from_value( $value ) );
	}

	/**
	 * Return malformed schema and binding fixtures.
	 *
	 * @return iterable<string, array{0:mixed,1:string}>
	 */
	public static function invalid_binding_provider(): iterable {
		$valid = array(
			'adapter_id'      => 'wordpress',
			'adapter_version' => 1,
			'ability_id'      => 'content/get-item',
			'kind'            => 'read',
		);

		yield 'string schema version' => array(
			array(
				'availability_schema_version' => '2',
				'bindings'                    => array(),
			),
			'invalid_availability_schema_version',
		);
		yield 'unsupported schema version' => array(
			array(
				'availability_schema_version' => 1,
				'bindings'                    => array(),
			),
			'unsupported_availability_schema_version',
		);
		yield 'unknown root key' => array(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => array(),
				'abilities'                   => array(),
			),
			'invalid_availability',
		);
		yield 'bindings not list' => array(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => 'invalid',
			),
			'invalid_availability',
		);
		yield 'unknown binding key' => array(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => array( $valid + array( 'extra' => true ) ),
			),
			'invalid_availability',
		);
		yield 'coerced version' => array(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => array( array_replace( $valid, array( 'adapter_version' => '1' ) ) ),
			),
			'invalid_adapter_version',
		);
		yield 'wildcard ability' => array(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => array( array_replace( $valid, array( 'ability_id' => 'content/*' ) ) ),
			),
			'invalid_ability_id',
		);
		yield 'unknown kind' => array(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => array( array_replace( $valid, array( 'kind' => 'execute' ) ) ),
			),
			'invalid_binding_kind',
		);
		yield 'duplicate exact tuple' => array(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => array( $valid, $valid ),
			),
			'duplicate_binding',
		);
		yield 'same owner with different ability' => array(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => array(
					$valid,
					array_replace( $valid, array( 'ability_id' => 'content/update-item' ) ),
				),
			),
			'duplicate_binding_owner',
		);
		yield 'same owner with different kind' => array(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => array(
					$valid,
					array_replace( $valid, array( 'kind' => 'proposal' ) ),
				),
			),
			'duplicate_binding_owner',
		);
		yield 'over bound' => array(
			array(
				'availability_schema_version' => 2,
				'bindings'                    => array_fill( 0, WorkflowAvailabilitySnapshot::MAX_BINDINGS + 1, $valid ),
			),
			'invalid_availability',
		);
	}

	/**
	 * Verify malformed JSON values fail with stable bounded codes.
	 *
	 * @param mixed  $json Candidate JSON.
	 * @param string $code Expected code.
	 */
	#[DataProvider( 'invalid_json_provider' )]
	public function test_invalid_json_fails_closed( mixed $json, string $code ): void {
		$this->expect_failure( $code, static fn () => WorkflowAvailabilitySnapshot::from_json( $json ) );
	}

	/**
	 * Return invalid JSON fixtures.
	 *
	 * @return iterable<string, array{0:mixed,1:string}>
	 */
	public static function invalid_json_provider(): iterable {
		yield 'non string' => array( null, 'invalid_availability_json' );
		yield 'malformed' => array( '{', 'invalid_availability_json' );
		yield 'list root' => array( '[]', 'invalid_availability_root' );
		yield 'scalar root' => array( '"value"', 'invalid_availability_root' );
		yield 'oversized' => array( str_repeat( 'x', WorkflowAvailabilitySnapshot::MAX_ENCODED_BYTES + 1 ), 'availability_too_large' );
	}

	/**
	 * Build one exact binding.
	 *
	 * @param string $adapter_id      Exact adapter ID.
	 * @param int    $adapter_version Exact adapter version.
	 * @param string $ability_id      Exact ability ID.
	 * @param string $kind            Exact binding kind.
	 * @return array{adapter_id:string,adapter_version:int,ability_id:string,kind:string}
	 */
	private function binding( string $adapter_id, int $adapter_version, string $ability_id, string $kind ): array {
		return compact( 'adapter_id', 'adapter_version', 'ability_id', 'kind' );
	}

	/**
	 * Assert one stable bounded planning failure.
	 *
	 * @param string   $code     Expected error code.
	 * @param callable $callback Operation that must fail.
	 */
	private function expect_failure( string $code, callable $callback ): void {
		try {
			$callback();
			self::fail( 'Expected availability failure.' );
		} catch ( WorkflowPlanningException $exception ) {
			self::assertSame( $code, $exception->error_code() );
			self::assertLessThanOrEqual( 96, strlen( $exception->path() ) );
		}
	}
}
