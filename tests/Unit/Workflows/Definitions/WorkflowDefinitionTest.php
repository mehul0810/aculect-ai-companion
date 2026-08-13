<?php
/**
 * Tests for the immutable workflow definition v1 boundary.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Definitions;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies strict validation, deterministic normalization, and immutability.
 */
final class WorkflowDefinitionTest extends TestCase {

	public function test_minimal_definition_is_normalized_and_checksummed(): void {
		$definition = WorkflowDefinition::from_array( $this->minimal_definition() );
		$normalized = $definition->to_array();

		self::assertSame( 64, strlen( $definition->checksum() ) );
		self::assertSame( 'sample_workflow', $normalized['workflow_id'] );
		self::assertSame( array( 'type' => 'object' ), $normalized['input_schema'] );
		self::assertSame( array(), $normalized['steps'][0]['arguments'] );
		self::assertStringContainsString( '"arguments":{}', $definition->canonical_json() );
		self::assertStringContainsString( '"input_schema":{"type":"object"}', $definition->canonical_json() );
		self::assertSame( hash( 'sha256', $definition->canonical_json() ), $definition->checksum() );
	}

	public function test_representative_write_definition_is_valid(): void {
		$raw                        = $this->minimal_definition();
		$raw['write_policy']        = array( 'mode' => 'draft_only' );
		$raw['allowed_abilities'][] = 'content/create-draft';
		$raw['steps'][]             = array(
			'step_id'         => 'create_draft',
			'adapter_id'      => 'wordpress',
			'adapter_version' => 1,
			'ability_id'      => 'content/create-draft',
			'kind'            => 'write',
			'arguments'       => array(
				'post_type' => 'post',
				'status'    => 'draft',
			),
			'depends_on'      => array( 'read_context' ),
		);
		$raw['approval_gates']      = array( 'create_draft' );
		$raw['validation_rules']    = array(
			array(
				'rule_id'  => 'require_title',
				'severity' => 'error',
			),
		);

		$definition = WorkflowDefinition::from_array( $raw );

		self::assertSame( 'write', $definition->to_array()['steps'][1]['kind'] );
	}

	public function test_supported_nested_schema_subset_is_valid_and_canonical(): void {
		$raw                 = $this->minimal_definition();
		$raw['input_schema'] = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'minProperties'        => 1,
			'maxProperties'        => 2,
			'properties'           => array(
				'title' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 120,
					'pattern'   => '^[^<>]+$',
				),
				'tags'  => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'minItems'    => 0,
					'maxItems'    => 10,
					'uniqueItems' => true,
				),
			),
			'required'             => array( 'title' ),
		);

		$definition = WorkflowDefinition::from_array( $raw );

		self::assertStringContainsString( '"properties":{"tags":{', $definition->canonical_json() );
		self::assertStringContainsString( '"required":["title"]', $definition->canonical_json() );
	}

	public function test_canonical_json_distinguishes_required_empty_map_from_nested_empty_list(): void {
		$empty_arguments                    = WorkflowDefinition::from_array( $this->minimal_definition() );
		$with_list                          = $this->minimal_definition();
		$with_list['steps'][0]['arguments'] = array( 'items' => array() );
		$list_arguments                     = WorkflowDefinition::from_array( $with_list );

		self::assertStringContainsString( '"arguments":{}', $empty_arguments->canonical_json() );
		self::assertStringContainsString( '"arguments":{"items":[]}', $list_arguments->canonical_json() );
		self::assertNotSame( $empty_arguments->checksum(), $list_arguments->checksum() );
	}

	public function test_explicit_nested_objects_preserve_empty_and_numeric_keys(): void {
		$object_raw                                     = $this->minimal_definition();
		$payload                                        = new stdClass();
		$payload->{'0'}                                 = 'x';
		$payload->empty                                 = new stdClass();
		$object_raw['steps'][0]['arguments']['payload'] = $payload;

		$list_raw                                     = $this->minimal_definition();
		$list_raw['steps'][0]['arguments']['payload'] = array( 'x' );

		$object = WorkflowDefinition::from_array( $object_raw );
		$list   = WorkflowDefinition::from_array( $list_raw );

		self::assertStringContainsString( '"payload":{"0":"x","empty":{}}', $object->canonical_json() );
		self::assertStringContainsString( '"payload":["x"]', $list->canonical_json() );
		self::assertNotSame( $object->checksum(), $list->checksum() );
	}

	public function test_from_json_preserves_object_identity_and_detaches_returned_objects(): void {
		$base                                      = WorkflowDefinition::from_array( $this->minimal_definition() );
		$json                                      = json_decode( $base->canonical_json(), false, 512, JSON_THROW_ON_ERROR );
		$json->steps[0]->arguments->payload        = new stdClass();
		$json->steps[0]->arguments->payload->{'0'} = 'x';
		$value                                     = WorkflowDefinition::from_json( json_encode( $json, JSON_THROW_ON_ERROR ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact JSON object fidelity is under test.

		$first_copy = $value->to_array();
		self::assertInstanceOf( stdClass::class, $first_copy['steps'][0]->arguments );
		self::assertSame( 'x', $first_copy['steps'][0]->arguments->payload->{'0'} );
		$first_copy['steps'][0]->arguments->payload->{'0'} = 'mutated';

		$second_copy = $value->to_array();
		self::assertSame( 'x', $second_copy['steps'][0]->arguments->payload->{'0'} );
		self::assertStringContainsString( '"payload":{"0":"x"}', $value->canonical_json() );
	}

	public function test_mutating_explicit_input_object_cannot_change_value(): void {
		$raw                                     = $this->minimal_definition();
		$payload                                 = new stdClass();
		$payload->name                           = 'original';
		$raw['steps'][0]['arguments']['payload'] = $payload;
		$value                                   = WorkflowDefinition::from_array( $raw );

		$payload->name = 'mutated';

		self::assertStringContainsString( '"name":"original"', $value->canonical_json() );
		self::assertSame( hash( 'sha256', $value->canonical_json() ), $value->checksum() );
	}

	public function test_from_json_rejects_invalid_and_non_object_roots(): void {
		foreach ( array( '{invalid', '[]', 'null' ) as $json ) {
			try {
				WorkflowDefinition::from_json( $json );
				self::fail( 'Expected JSON definition construction to fail.' );
			} catch ( WorkflowDefinitionValidationException $exception ) {
				self::assertContains( $exception->error_code(), array( 'invalid_json', 'invalid_json_root' ) );
			}
		}
	}

	public function test_validation_exception_sanitizes_and_bounds_paths(): void {
		$raw_path = '$.' . str_repeat( "unsafe\n", 30 );
		$first    = new WorkflowDefinitionValidationException( 'bad code', $raw_path );
		$second   = new WorkflowDefinitionValidationException( 'bad code', $raw_path );

		self::assertSame( 'validation_failed', $first->error_code() );
		self::assertSame( $first->error_path(), $second->error_path() );
		self::assertLessThanOrEqual( 64, strlen( $first->error_path() ) );
		self::assertDoesNotMatchRegularExpression( '/[^\x20-\x7E]/', $first->error_path() );
	}

	public function test_equivalent_map_key_order_has_same_definition_and_checksum(): void {
		$first                                = $this->minimal_definition();
		$first['steps'][0]['arguments']       = array(
			'zeta'  => array(
				'b' => true,
				'a' => false,
			),
			'alpha' => 1,
		);
		$first['input_schema']['properties']  = array(
			'title' => array(
				'type'        => 'string',
				'description' => 'A title.',
			),
		);
		$second                               = array_reverse( $first, true );
		$second['steps'][0]['arguments']      = array(
			'alpha' => 1,
			'zeta'  => array(
				'a' => false,
				'b' => true,
			),
		);
		$second['input_schema']['properties'] = array(
			'title' => array(
				'description' => 'A title.',
				'type'        => 'string',
			),
		);

		$first_value  = WorkflowDefinition::from_array( $first );
		$second_value = WorkflowDefinition::from_array( $second );

		self::assertSame( $first_value->to_array(), $second_value->to_array() );
		self::assertSame( $first_value->canonical_json(), $second_value->canonical_json() );
		self::assertSame( $first_value->checksum(), $second_value->checksum() );
	}

	public function test_material_changes_have_different_checksums(): void {
		$first          = WorkflowDefinition::from_array( $this->minimal_definition() );
		$second         = $this->minimal_definition();
		$second['name'] = 'Changed workflow';

		self::assertNotSame( $first->checksum(), WorkflowDefinition::from_array( $second )->checksum() );
	}

	public function test_value_is_detached_from_input_and_returned_array_mutation(): void {
		$raw        = $this->minimal_definition();
		$definition = WorkflowDefinition::from_array( $raw );
		$canonical  = $definition->canonical_json();
		$checksum   = $definition->checksum();

		$raw['name']      = 'Mutated input';
		$returned         = $definition->to_array();
		$returned['name'] = 'Mutated return';

		self::assertSame( 'Sample workflow', $definition->to_array()['name'] );
		self::assertSame( $canonical, $definition->canonical_json() );
		self::assertSame( $checksum, $definition->checksum() );
	}

	/**
	 * Verify an invalid definition fails with the expected stable code.
	 *
	 * @param callable $mutation      Mutation to test.
	 * @param string   $expected_code Expected validation code.
	 */
	#[DataProvider( 'invalid_definition_provider' )]
	public function test_invalid_definitions_fail_closed( callable $mutation, string $expected_code ): void {
		try {
			WorkflowDefinition::from_array( $mutation( $this->minimal_definition() ) );
			self::fail( 'Expected workflow definition validation to fail.' );
		} catch ( WorkflowDefinitionValidationException $exception ) {
			self::assertSame( $expected_code, $exception->error_code() );
			self::assertStringStartsWith( '$', $exception->error_path() );
			self::assertLessThanOrEqual( 64, strlen( $exception->error_path() ) );
		}
	}

	/**
	 * Return invalid definitions and their stable error codes.
	 *
	 * @return iterable<string, array{callable(array<string, mixed>): array<mixed>, string}>
	 */
	public static function invalid_definition_provider(): iterable {
		yield 'unknown top-level field' => array(
			static function ( array $value ): array {
				$value['unknown'] = true;
				return $value;
			},
			'unknown_field',
		);
		yield 'missing field' => array(
			static function ( array $value ): array {
				unset( $value['name'] );
				return $value;
			},
			'missing_field',
		);
		yield 'invalid workflow id' => array(
			static function ( array $value ): array {
				$value['workflow_id'] = 'Bad ID';
				return $value;
			},
			'invalid_identifier',
		);
		yield 'invalid schema version' => array(
			static function ( array $value ): array {
				$value['definition_schema_version'] = 2;
				return $value;
			},
			'unsupported_schema_version',
		);
		yield 'invalid status' => array(
			static function ( array $value ): array {
				$value['status'] = 'active';
				return $value;
			},
			'invalid_enum',
		);
		yield 'post types map instead of list' => array(
			static function ( array $value ): array {
				$value['content_target']['post_types'] = array( 'first' => 'post' );
				return $value;
			},
			'invalid_list',
		);
		yield 'duplicate post type' => array(
			static function ( array $value ): array {
				$value['content_target']['post_types'] = array( 'post', 'post' );
				return $value;
			},
			'duplicate_post_type',
		);
		yield 'schema boolean' => array(
			static function ( array $value ): array {
				$value['input_schema'] = true;
				return $value;
			},
			'invalid_map',
		);
		yield 'schema external reference' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array(
					'item' => array( '$ref' => 'https://example.com/schema.json' ),
				);
				return $value;
			},
			'schema_reference_not_allowed',
		);
		yield 'schema vocabulary declaration' => array(
			static function ( array $value ): array {
				$value['input_schema']['$vocabulary'] = array( 'https://json-schema.org/draft/2020-12/vocab/core' => true );
				return $value;
			},
			'schema_vocabulary_not_allowed',
		);
		yield 'schema unknown keyword' => array(
			static function ( array $value ): array {
				$value['input_schema']['format'] = 'custom';
				return $value;
			},
			'unsupported_schema_keyword',
		);
		yield 'schema nested unknown keyword' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array(
					'title' => array(
						'type'   => 'string',
						'format' => 'custom',
					),
				);
				return $value;
			},
			'unsupported_schema_keyword',
		);
		yield 'schema properties must be map' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array( array( 'type' => 'string' ) );
				return $value;
			},
			'invalid_json_object_key',
		);
		yield 'schema numeric property name' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array( 7 => array( 'type' => 'string' ) );
				return $value;
			},
			'invalid_json_object_key',
		);
		yield 'schema nested type required' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array( 'title' => array( 'description' => 'Missing type.' ) );
				return $value;
			},
			'missing_schema_type',
		);
		yield 'schema nested type invalid' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array( 'title' => array( 'type' => array( 'string', 'null' ) ) );
				return $value;
			},
			'invalid_schema_type',
		);
		yield 'schema required must be list' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array( 'title' => array( 'type' => 'string' ) );
				$value['input_schema']['required']   = array( 'title' => true );
				return $value;
			},
			'invalid_list',
		);
		yield 'schema required property must exist' => array(
			static function ( array $value ): array {
				$value['input_schema']['required'] = array( 'title' );
				return $value;
			},
			'schema_required_property_not_found',
		);
		yield 'schema required property unique' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array( 'title' => array( 'type' => 'string' ) );
				$value['input_schema']['required']   = array( 'title', 'title' );
				return $value;
			},
			'duplicate_schema_required_property',
		);
		yield 'schema pattern must compile' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array(
					'title' => array(
						'type'    => 'string',
						'pattern' => '[unterminated',
					),
				);
				return $value;
			},
			'invalid_schema_pattern',
		);
		yield 'schema pattern must be string' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array(
					'title' => array(
						'type'    => 'string',
						'pattern' => array( 'unsafe' ),
					),
				);
				return $value;
			},
			'invalid_string',
		);
		yield 'schema count must be non-negative integer' => array(
			static function ( array $value ): array {
				$value['input_schema']['minProperties'] = -1;
				return $value;
			},
			'invalid_schema_count',
		);
		yield 'schema count range must be ordered' => array(
			static function ( array $value ): array {
				$value['input_schema']['minProperties'] = 2;
				$value['input_schema']['maxProperties'] = 1;
				return $value;
			},
			'invalid_schema_range',
		);
		yield 'schema array count must be integer' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array(
					'tags' => array(
						'type'     => 'array',
						'maxItems' => '10',
					),
				);
				return $value;
			},
			'invalid_schema_count',
		);
		yield 'schema enum values must match type' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array(
					'count' => array(
						'type' => 'integer',
						'enum' => array( 1, '2' ),
					),
				);
				return $value;
			},
			'invalid_schema_enum_value',
		);
		yield 'schema enum values must be unique' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array(
					'count' => array(
						'type' => 'integer',
						'enum' => array( 1, 1 ),
					),
				);
				return $value;
			},
			'duplicate_schema_enum_value',
		);
		yield 'schema numeric enum treats signed zero equally' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array(
					'score' => array(
						'type' => 'number',
						'enum' => array( 0, -0.0 ),
					),
				);
				return $value;
			},
			'duplicate_schema_enum_value',
		);
		yield 'cyclic explicit object is not JSON' => array(
			static function ( array $value ): array {
				$cycle       = new stdClass();
				$cycle->self = $cycle;
				$value['steps'][0]['arguments']['cycle'] = $cycle;
				return $value;
			},
			'non_json_value',
		);
		yield 'schema additional properties must be boolean' => array(
			static function ( array $value ): array {
				$value['input_schema']['additionalProperties'] = array( 'type' => 'string' );
				return $value;
			},
			'invalid_schema_additional_properties',
		);
		yield 'arguments numeric map key' => array(
			static function ( array $value ): array {
				$value['steps'][0]['arguments'] = array( 7 => 'ambiguous' );
				return $value;
			},
			'invalid_json_object_key',
		);
		yield 'arguments numeric-looking string key' => array(
			static function ( array $value ): array {
				$value['steps'][0]['arguments'] = array( '01' => 'ambiguous' );
				return $value;
			},
			'invalid_json_object_key',
		);
		yield 'duplicate ability' => array(
			static function ( array $value ): array {
				$value['allowed_abilities'][] = 'content/get-item';
				return $value;
			},
			'duplicate_ability_id',
		);
		yield 'step ability unavailable' => array(
			static function ( array $value ): array {
				$value['steps'][0]['ability_id'] = 'content/missing';
				return $value;
			},
			'ability_not_allowed',
		);
		yield 'duplicate step id' => array(
			static function ( array $value ): array {
				$value['steps'][] = $value['steps'][0];
				return $value;
			},
			'duplicate_step_id',
		);
		yield 'dependency must reference prior step' => array(
			static function ( array $value ): array {
				$value['steps'][0]['depends_on'] = array( 'future_step' );
				return $value;
			},
			'step_dependency_not_prior',
		);
		yield 'proposal-only workflow cannot write' => array(
			static function ( array $value ): array {
				$value['steps'][0]['kind'] = 'write';
				$value['approval_gates']   = array( 'read_context' );
				return $value;
			},
			'write_step_not_allowed',
		);
		yield 'write step must be approved' => array(
			static function ( array $value ): array {
				$value['write_policy']['mode'] = 'approved_update';
				$value['steps'][0]['kind']      = 'write';
				return $value;
			},
			'write_step_requires_approval',
		);
		yield 'read step cannot be approval gate' => array(
			static function ( array $value ): array {
				$value['approval_gates'] = array( 'read_context' );
				return $value;
			},
			'approval_gate_not_write_step',
		);
		yield 'duplicate validation rule' => array(
			static function ( array $value ): array {
				$rule = array(
					'rule_id'  => 'require_title',
					'severity' => 'error',
				);
				$value['validation_rules'] = array( $rule, $rule );
				return $value;
			},
			'duplicate_validation_rule',
		);
		yield 'non-finite number' => array(
			static function ( array $value ): array {
				$value['steps'][0]['arguments']['score'] = INF;
				return $value;
			},
			'non_json_value',
		);
		yield 'unsupported object is not JSON data' => array(
			static function ( array $value ): array {
				$value['steps'][0]['arguments']['object'] = new \ArrayObject();
				return $value;
			},
			'non_json_value',
		);
		yield 'excessive encoded size' => array(
			static function ( array $value ): array {
				$value['description'] = str_repeat( 'x', 262145 );
				return $value;
			},
			'definition_too_large',
		);
		yield 'excessive nodes' => array(
			static function ( array $value ): array {
				$value['input_schema']['properties'] = array_fill_keys(
					array_map( static fn ( int $index ): string => 'field_' . $index, range( 1, 520 ) ),
					true
				);
				return $value;
			},
			'definition_too_complex',
		);
		yield 'excessive depth' => array(
			static function ( array $value ): array {
				$nested = 'leaf';
				for ( $depth = 0; $depth < 20; ++$depth ) {
					$nested = array( 'nested' => $nested );
				}
				$value['steps'][0]['arguments'] = array( 'deep' => $nested );
				return $value;
			},
			'definition_too_deep',
		);
		yield 'too many steps' => array(
			static function ( array $value ): array {
				$step = $value['steps'][0];
				$value['steps'] = array();
				for ( $index = 1; $index <= 51; ++$index ) {
					$step['step_id']  = 'step_' . $index;
					$value['steps'][] = $step;
				}
				return $value;
			},
			'list_too_long',
		);
	}

	/**
	 * Return the smallest representative read-only v1 definition.
	 *
	 * @return array<string, mixed>
	 */
	private function minimal_definition(): array {
		return array(
			'definition_schema_version' => 1,
			'workflow_id'               => 'sample_workflow',
			'workflow_version'          => 1,
			'name'                      => 'Sample workflow',
			'description'               => 'Read site content and return a structured proposal.',
			'content_target'            => array(
				'mode'       => 'either',
				'post_types' => array( 'post' ),
			),
			'input_schema'              => array( 'type' => 'object' ),
			'steps'                     => array(
				array(
					'step_id'         => 'read_context',
					'adapter_id'      => 'wordpress',
					'adapter_version' => 1,
					'ability_id'      => 'content/get-item',
					'kind'            => 'read',
					'arguments'       => array(),
					'depends_on'      => array(),
				),
			),
			'allowed_abilities'         => array( 'content/get-item' ),
			'write_policy'              => array( 'mode' => 'proposal_only' ),
			'approval_gates'            => array(),
			'output_contract'           => array( 'type' => 'object' ),
			'validation_rules'          => array(),
			'status'                    => 'draft',
			'created_by'                => 7,
			'updated_by'                => 7,
			'compatibility'             => array(
				'input_contract_version'  => 1,
				'output_contract_version' => 1,
			),
		);
	}
}
