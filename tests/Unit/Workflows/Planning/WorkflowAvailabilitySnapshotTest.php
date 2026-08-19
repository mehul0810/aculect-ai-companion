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
 * Locks the fail-closed untrusted availability input boundary.
 */
final class WorkflowAvailabilitySnapshotTest extends TestCase {

	public function test_array_object_and_json_inputs_normalize_identically_and_detach(): void {
		$array = array(
			'adapters'  => array(
				array(
					'adapter_id'       => 'wordpress',
					'adapter_versions' => array( 2, 1 ),
				),
			),
			'abilities' => array( 'content/update-item', 'content/get-item' ),
		);

		$object            = new stdClass();
		$object->adapters  = array( (object) $array['adapters'][0] );
		$object->abilities = $array['abilities'];
		$json              = wp_json_encode( $object );
		self::assertIsString( $json );

		$from_array  = WorkflowAvailabilitySnapshot::from_value( $array );
		$from_object = WorkflowAvailabilitySnapshot::from_value( $object );
		$from_json   = WorkflowAvailabilitySnapshot::from_json( $json );

		self::assertSame( $from_array->adapters(), $from_object->adapters() );
		self::assertSame( $from_array->adapters(), $from_json->adapters() );
		self::assertSame( array( 'content/get-item', 'content/update-item' ), $from_json->abilities() );

		$array['adapters'][0]['adapter_versions'][0] = 99;
		$copy                                        = $from_json->adapters();
		$copy[0]['adapter_versions'][0]              = 88;
		self::assertSame( array( 1, 2 ), $from_json->adapters()[0]['adapter_versions'] );
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

	public function test_nested_wrong_types_fail_closed_without_throwable_leakage(): void {
		$this->expect_failure(
			'invalid_availability',
			static fn () => WorkflowAvailabilitySnapshot::from_value(
				array(
					'adapters'  => 'not-a-list',
					'abilities' => null,
				)
			)
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
	 * Assert one stable bounded planning failure.
	 *
	 * @param string   $code     Expected code.
	 * @param callable $callback Operation.
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
