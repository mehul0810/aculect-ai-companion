<?php
/**
 * Tests for typed custom workflow step argument bindings.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Planning;

use Aculect\AICompanion\Workflows\Planning\WorkflowStepArgumentValidator;
use PHPUnit\Framework\TestCase;

/**
 * Keeps optional-source bindings and JSON Schema constraints executable.
 */
final class WorkflowStepArgumentValidatorTest extends TestCase {

	public function test_optional_input_binding_is_rejected_when_the_runner_cannot_supply_a_value(): void {
		$validator = new WorkflowStepArgumentValidator();
		$errors    = $validator->validate(
			array( 'title' => '{{input.title}}' ),
			array(
				'type'       => 'object',
				'properties' => array( 'title' => array( 'type' => 'string' ) ),
			),
			array(
				'type'       => 'object',
				'properties' => array( 'title' => array( 'type' => 'string' ) ),
				'required'   => array(),
			)
		);

		self::assertSame( array( '$.arguments.title' ), $errors );
	}

	public function test_finite_source_values_must_fit_target_enum_and_const_constraints(): void {
		$validator = new WorkflowStepArgumentValidator();
		$schema    = array(
			'type'       => 'object',
			'properties' => array(
				'mode' => array(
					'type' => 'string',
					'enum' => array( 'draft', 'publish' ),
				),
			),
			'required'   => array( 'mode' ),
		);

		$accepted = $validator->validate(
			array( 'mode' => '{{input.mode}}' ),
			$schema,
			array(
				'type'       => 'object',
				'properties' => array(
					'mode' => array(
						'type' => 'string',
						'enum' => array( 'draft' ),
					),
				),
				'required'   => array( 'mode' ),
			)
		);
		$rejected = $validator->validate(
			array( 'mode' => '{{input.mode}}' ),
			$schema,
			array(
				'type'       => 'object',
				'properties' => array( 'mode' => array( 'type' => 'string' ) ),
				'required'   => array( 'mode' ),
			)
		);

		self::assertSame( array(), $accepted );
		self::assertSame( array( '$.arguments.mode' ), $rejected );
	}

	public function test_string_array_and_numeric_constraints_are_compared_conservatively(): void {
		$validator = new WorkflowStepArgumentValidator();
		$target    = array(
			'type'       => 'object',
			'properties' => array(
				'slug'  => array(
					'type'      => 'string',
					'minLength' => 2,
					'maxLength' => 12,
					'pattern'   => '^[a-z]+$',
				),
				'count' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 20,
				),
				'items' => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 3,
					'uniqueItems' => true,
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'slug', 'count', 'items' ),
		);
		$source    = array(
			'type'       => 'object',
			'properties' => array(
				'slug'  => array(
					'type'      => 'string',
					'minLength' => 4,
					'maxLength' => 8,
					'pattern'   => '^[a-z]+$',
				),
				'count' => array(
					'type'    => 'integer',
					'minimum' => 3,
					'maximum' => 10,
				),
				'items' => array(
					'type'        => 'array',
					'minItems'    => 2,
					'maxItems'    => 3,
					'uniqueItems' => true,
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'   => array( 'slug', 'count', 'items' ),
		);
		$arguments = array(
			'slug'  => '{{input.slug}}',
			'count' => '{{input.count}}',
			'items' => '{{input.items}}',
		);

		self::assertSame( array(), $validator->validate( $arguments, $target, $source ) );

		$too_strict                                    = $target;
		$too_strict['properties']['slug']['minLength'] = 5;
		self::assertSame(
			array( '$.arguments.slug' ),
			$validator->validate( $arguments, $too_strict, $source )
		);
	}

	public function test_recursive_additional_property_schemas_must_be_compatible(): void {
		$validator      = new WorkflowStepArgumentValidator();
		$target         = array(
			'type'       => 'object',
			'properties' => array(
				'payload' => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'                 => 'object',
						'properties'           => array( 'id' => array( 'type' => 'integer' ) ),
						'required'             => array( 'id' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'   => array( 'payload' ),
		);
		$source         = array(
			'type'       => 'object',
			'properties' => array(
				'payload' => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'                 => 'object',
						'properties'           => array( 'id' => array( 'type' => 'string' ) ),
						'required'             => array( 'id' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'   => array( 'payload' ),
		);
		$adapter_schema = array(
			'type'       => 'object',
			'properties' => array( 'payload' => $target['properties']['payload'] ),
			'required'   => array( 'payload' ),
		);

		self::assertSame(
			array( '$.arguments.payload' ),
			$validator->validate( array( 'payload' => '{{input.payload}}' ), $adapter_schema, $source )
		);
	}
}
