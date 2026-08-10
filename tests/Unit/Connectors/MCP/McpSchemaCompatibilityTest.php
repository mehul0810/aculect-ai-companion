<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpProtocolVersion;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpSchemaCompatibility;
use PHPUnit\Framework\TestCase;

/**
 * Verifies bounded current-schema preparation and legacy preservation.
 */
final class McpSchemaCompatibilityTest extends TestCase {

	public function test_current_schema_supports_local_definitions_composition_and_canonical_keys(): void {
		$schema = array(
			'type'       => 'object',
			'$defs'      => array(
				'value' => array(
					'type'    => array( 'string', 'null' ),
					'pattern' => '^[a-z]+$',
				),
			),
			'properties' => array(
				'value' => array( '$ref' => '#/$defs/value' ),
			),
			'anyOf'      => array(
				array( 'required' => array( 'value' ) ),
				true,
			),
		);

		$result = ( new McpSchemaCompatibility() )->prepare( $schema, McpProtocolVersion::CURRENT );

		self::assertTrue( $result['valid'] );
		self::assertSame( array( '$defs', 'anyOf', 'properties', 'type' ), array_keys( $result['schema'] ) );
		$properties = get_object_vars( $result['schema']['properties'] );
		self::assertSame( '#/$defs/value', $properties['value']['$ref'] );
	}

	public function test_empty_object_schema_uses_the_correct_current_wire_type_and_exact_legacy_value(): void {
		$compatibility = new McpSchemaCompatibility();
		$current       = $compatibility->prepare( array(), McpProtocolVersion::CURRENT );
		$legacy        = $compatibility->prepare( array(), McpProtocolVersion::LEGACY );

		self::assertTrue( $current['valid'] );
		self::assertInstanceOf( \stdClass::class, $current['schema'] );
		self::assertSame( '{}', wp_json_encode( $current['schema'] ) );
		self::assertTrue( $legacy['valid'] );
		self::assertSame( array(), $legacy['schema'] );
	}

	public function test_current_empty_schema_maps_remain_json_objects_on_the_wire(): void {
		$result = ( new McpSchemaCompatibility() )->prepare(
			array(
				'type'       => 'object',
				'properties' => new \stdClass(),
				'$defs'      => array(),
			),
			McpProtocolVersion::CURRENT
		);

		self::assertTrue( $result['valid'] );
		self::assertInstanceOf( \stdClass::class, $result['schema']['properties'] );
		self::assertInstanceOf( \stdClass::class, $result['schema']['$defs'] );
		self::assertSame( '{"$defs":{},"properties":{},"type":"object"}', wp_json_encode( $result['schema'] ) );
	}

	public function test_legacy_safe_schema_is_preserved_exactly(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'query' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'required'   => array( 'query' ),
		);

		$result = ( new McpSchemaCompatibility() )->prepare( $schema, McpProtocolVersion::LEGACY );

		self::assertTrue( $result['valid'] );
		self::assertSame( $schema, $result['schema'] );
	}

	public function test_legacy_schema_preserves_literal_values_without_current_keyword_scanning(): void {
		$schema = array(
			'type'    => 'object',
			'default' => array( 'allOf' => 'literal' ),
		);
		$result = ( new McpSchemaCompatibility() )->prepare( $schema, McpProtocolVersion::LEGACY );

		self::assertTrue( $result['valid'] );
		self::assertSame( $schema, $result['schema'] );
	}

	public function test_legacy_schema_is_not_subjected_to_current_size_or_finite_value_rules(): void {
		$schema = array(
			'default'     => INF,
			'description' => str_repeat( 'x', 262200 ),
		);
		$result = ( new McpSchemaCompatibility() )->prepare( $schema, McpProtocolVersion::LEGACY );

		self::assertTrue( $result['valid'] );
		self::assertSame( $schema, $result['schema'] );
	}

	/**
	 * Verify unsafe current schemas fail closed.
	 *
	 * @dataProvider invalid_schema_provider
	 *
	 * @param mixed  $schema Candidate schema.
	 * @param string $code   Expected error code.
	 */
	public function test_current_schema_fails_closed_for_unsafe_inputs( mixed $schema, string $code ): void {
		$result = ( new McpSchemaCompatibility() )->prepare( $schema, McpProtocolVersion::CURRENT );

		self::assertFalse( $result['valid'] );
		self::assertSame( $code, $result['code'] );
	}

	/**
	 * Provide malformed or unsafe current schemas.
	 *
	 * @return iterable<string, array{mixed, string}>
	 */
	public static function invalid_schema_provider(): iterable {
		yield 'external ref' => array( array( '$ref' => 'https://example.com/schema.json' ), 'external_schema_reference' );
		yield 'external dynamic ref' => array( array( '$dynamicRef' => 'https://example.com/schema.json' ), 'external_schema_reference' );
		yield 'content schema external ref' => array( array( 'contentSchema' => array( '$ref' => 'https://example.com/schema.json' ) ), 'external_schema_reference' );
		yield 'unevaluated items external ref' => array( array( 'unevaluatedItems' => array( '$ref' => 'https://example.com/schema.json' ) ), 'external_schema_reference' );
		yield 'non finite' => array( array( 'maximum' => INF ), 'invalid_schema' );
		yield 'invalid type' => array( array( 'type' => 'date' ), 'invalid_schema' );
		yield 'unsupported dialect' => array( array( '$schema' => 'https://json-schema.org/draft/2019-09/schema' ), 'unsupported_schema_dialect' );
		yield 'invalid composition' => array( array( 'allOf' => array() ), 'invalid_schema' );
		yield 'duplicate enum' => array( array( 'enum' => array( 1, 1.0 ) ), 'invalid_schema' );
		yield 'duplicate signed zero enum' => array( array( 'enum' => array( 0, -0.0 ) ), 'invalid_schema' );
		yield 'invalid required' => array( array( 'required' => array( 'id', 'id' ) ), 'invalid_schema' );
		yield 'invalid numeric constraint' => array( array( 'multipleOf' => 0 ), 'invalid_schema' );
		yield 'invalid count constraint' => array( array( 'maxItems' => 1.5 ), 'invalid_schema' );
		yield 'invalid boolean constraint' => array( array( 'uniqueItems' => 1 ), 'invalid_schema' );
		yield 'invalid dependency' => array( array( 'dependentRequired' => array( 'name' => array( 'id', 'id' ) ) ), 'invalid_schema' );
		yield 'invalid vocabulary' => array( array( '$vocabulary' => array( 'https://example.com/vocab' => 'yes' ) ), 'invalid_schema' );
		yield 'unsupported required vocabulary' => array( array( '$vocabulary' => array( 'https://example.com/vocab' => true ) ), 'unsupported_schema_vocabulary' );
		yield 'invalid property pattern' => array( array( 'patternProperties' => array( '[' => array( 'type' => 'string' ) ) ), 'invalid_schema' );
		yield 'identifier fragment' => array( array( '$id' => 'https://example.com/schema#fragment' ), 'invalid_schema' );
		yield 'malformed identifier' => array( array( '$id' => 'http://[' ), 'invalid_schema' );
		yield 'malformed IPvFuture identifier' => array( array( '$id' => 'https://[v.fe]/schema' ), 'invalid_schema' );
		yield 'empty IPvFuture identifier suffix' => array( array( '$id' => 'https://[v1.]/schema' ), 'invalid_schema' );
		yield 'raw brackets in IPv6 identifier path' => array( array( '$id' => 'https://[2001:db8::1]/[bad]' ), 'invalid_schema' );
		yield 'raw brackets in IPv6 identifier query' => array( array( '$id' => 'https://[2001:db8::1]/schema?q=[bad]' ), 'invalid_schema' );
		yield 'identifier missing scheme' => array( array( '$id' => '://bad' ), 'invalid_schema' );
		yield 'identifier invalid leading scheme character' => array( array( '$id' => '1abc:def' ), 'invalid_schema' );
		yield 'identifier encoded leading scheme character' => array( array( '$id' => '%41:bad' ), 'invalid_schema' );
		yield 'invalid anchor' => array( array( '$anchor' => 'bad anchor' ), 'invalid_schema' );
		yield 'invalid local reference' => array( array( '$ref' => '#bad space' ), 'external_schema_reference' );
		yield 'invalid percent encoding' => array( array( '$ref' => '#/bad%ZZ' ), 'external_schema_reference' );
		yield 'invalid pointer escape' => array( array( '$ref' => '#/$defs/bad~2name' ), 'external_schema_reference' );
		yield 'invalid vocabulary URI' => array( array( '$vocabulary' => array( 'not a uri' => false ) ), 'invalid_schema' );
		yield 'invalid pattern' => array(
			array(
				'type'    => 'string',
				'pattern' => '[',
			),
			'invalid_schema',
		);
		yield 'not an object' => array( array( 'string' ), 'invalid_schema' );
	}

	public function test_current_schema_rejects_depth_and_size_budgets(): void {
		$deep = array( 'type' => 'object' );
		for ( $index = 0; $index < 18; ++$index ) {
			$deep = array( 'properties' => array( 'child' => $deep ) );
		}

		$compatibility = new McpSchemaCompatibility();
		$depth_result  = $compatibility->prepare( $deep, McpProtocolVersion::CURRENT );
		$size_result   = $compatibility->prepare( array( 'description' => str_repeat( 'x', 262200 ) ), McpProtocolVersion::CURRENT );

		self::assertFalse( $depth_result['valid'] );
		self::assertSame( 'schema_too_deep', $depth_result['code'] );
		self::assertFalse( $size_result['valid'] );
		self::assertSame( 'schema_too_large', $size_result['code'] );
	}

	public function test_current_schema_bounds_unknown_literal_trees_before_canonicalization(): void {
		$literal = 'leaf';
		for ( $index = 0; $index < 18; ++$index ) {
			$literal = array( 'nested' => $literal );
		}

		$result = ( new McpSchemaCompatibility() )->prepare( array( 'x-extension' => $literal ), McpProtocolVersion::CURRENT );

		self::assertFalse( $result['valid'] );
		self::assertSame( 'schema_too_deep', $result['code'] );
	}

	public function test_reference_named_keys_in_literal_values_remain_literal_data(): void {
		$schema = array(
			'type'        => 'object',
			'default'     => array( '$ref' => 'https://example.com/literal' ),
			'examples'    => array( array( '$ref' => 'https://example.com/literal' ) ),
			'x-extension' => array( '$ref' => 'https://example.com/literal' ),
		);

		$result = ( new McpSchemaCompatibility() )->prepare( $schema, McpProtocolVersion::CURRENT );

		self::assertTrue( $result['valid'] );
		self::assertSame( $schema['default'], $result['schema']['default'] );
		self::assertSame( $schema['examples'], $result['schema']['examples'] );
		self::assertSame( $schema['x-extension'], $result['schema']['x-extension'] );
	}

	public function test_current_schema_bounds_the_complete_value_node_count(): void {
		$result = ( new McpSchemaCompatibility() )->prepare(
			array( 'examples' => array_fill( 0, 513, 'value' ) ),
			McpProtocolVersion::CURRENT
		);

		self::assertFalse( $result['valid'] );
		self::assertSame( 'schema_too_complex', $result['code'] );
	}

	public function test_current_schema_rejects_recursive_php_arrays_before_encoding(): void {
		$recursive         = array( 'type' => 'object' );
		$recursive['self'] =& $recursive;

		$result = ( new McpSchemaCompatibility() )->prepare( $recursive, McpProtocolVersion::CURRENT );

		self::assertFalse( $result['valid'] );
		self::assertSame( 'invalid_schema', $result['code'] );
	}

	public function test_current_schema_rejects_recursive_objects_but_allows_shared_acyclic_objects(): void {
		$recursive       = new \stdClass();
		$recursive->self = $recursive;
		$shared          = (object) array( 'type' => 'string' );

		$recursive_result = ( new McpSchemaCompatibility() )->prepare( $recursive, McpProtocolVersion::CURRENT );
		$shared_result    = ( new McpSchemaCompatibility() )->prepare(
			array(
				'anyOf' => array( $shared, $shared ),
			),
			McpProtocolVersion::CURRENT
		);

		self::assertFalse( $recursive_result['valid'] );
		self::assertSame( 'invalid_schema', $recursive_result['code'] );
		self::assertTrue( $shared_result['valid'] );
	}

	public function test_external_references_fail_in_every_supported_subschema_position(): void {
		$external = array( '$ref' => 'https://example.com/schema.json' );
		$schemas  = array();

		foreach ( array( 'additionalProperties', 'unevaluatedProperties', 'propertyNames', 'items', 'unevaluatedItems', 'contains', 'contentSchema', 'not', 'if', 'then', 'else' ) as $keyword ) {
			$schemas[ $keyword ] = array( $keyword => $external );
		}
		foreach ( array( 'properties', 'patternProperties', '$defs', 'dependentSchemas' ) as $keyword ) {
			$schemas[ $keyword ] = array( $keyword => array( 'value' => $external ) );
		}
		foreach ( array( 'allOf', 'anyOf', 'oneOf', 'prefixItems' ) as $keyword ) {
			$schemas[ $keyword ] = array( $keyword => array( $external ) );
		}

		$compatibility = new McpSchemaCompatibility();
		foreach ( $schemas as $position => $schema ) {
			$result = $compatibility->prepare( $schema, McpProtocolVersion::CURRENT );
			self::assertFalse( $result['valid'], $position );
			self::assertSame( 'external_schema_reference', $result['code'], $position );
		}
	}

	public function test_bounded_local_reference_is_accepted_without_dereferencing(): void {
		$result = ( new McpSchemaCompatibility() )->prepare(
			array(
				'$defs' => array(
					'node' => array(
						'type'       => 'object',
						'properties' => array( 'child' => array( '$ref' => '#/$defs/node' ) ),
					),
				),
				'$ref'  => '#/$defs/node',
			),
			McpProtocolVersion::CURRENT
		);

		self::assertTrue( $result['valid'] );
	}

	public function test_local_anchor_reference_and_identifier_are_accepted(): void {
		$result = ( new McpSchemaCompatibility() )->prepare(
			array(
				'$id'     => 'schemas/content-item',
				'$anchor' => 'content_item',
				'$ref'    => '#content_item',
			),
			McpProtocolVersion::CURRENT
		);

		self::assertTrue( $result['valid'] );
	}

	public function test_valid_absolute_and_network_uri_identifiers_are_accepted(): void {
		$compatibility = new McpSchemaCompatibility();
		foreach ( array( 'https://example.com/schema', 'urn:example:schema', '//example.com/schema', 'https://[2001:db8::1]/schema', 'https://[v1.fe]/schema', 'https://[V1.fe]/schema' ) as $identifier ) {
			$result = $compatibility->prepare( array( '$id' => $identifier ), McpProtocolVersion::CURRENT );
			self::assertTrue( $result['valid'], $identifier );
		}
	}

	public function test_numeric_looking_json_object_names_are_preserved(): void {
		$properties  = json_decode( '{"0":{"type":"string"}}' );
		$definitions = json_decode( '{"1":{"type":"integer"}}' );
		$schema      = array(
			'type'       => 'object',
			'properties' => $properties,
			'$defs'      => $definitions,
		);
		$result      = ( new McpSchemaCompatibility() )->prepare( $schema, McpProtocolVersion::CURRENT );

		self::assertTrue( $result['valid'] );
		self::assertSame( '{"$defs":{"1":{"type":"integer"}},"properties":{"0":{"type":"string"}},"type":"object"}', wp_json_encode( $result['schema'] ) );
	}

	public function test_numeric_looking_schema_keywords_preserve_object_wire_shape(): void {
		$schema = json_decode( '{"0":{"type":"string"},"allOf":[{"1":"literal"}]}' );
		$result = ( new McpSchemaCompatibility() )->prepare( $schema, McpProtocolVersion::CURRENT );

		self::assertTrue( $result['valid'] );
		self::assertSame( '{"0":{"type":"string"},"allOf":[{"1":"literal"}]}', wp_json_encode( $result['schema'] ) );
	}

	public function test_full_tool_manifest_preserves_legacy_schemas_and_accepts_current_shapes(): void {
		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
		AbilitiesRegistry::reset_module_cache();

		$tools         = ( new McpController() )->tool_manifest_for_user( 1 )['tools'];
		$compatibility = new McpSchemaCompatibility();
		$schema_count  = 0;

		self::assertGreaterThan( McpController::tools_page_size(), count( $tools ) );
		foreach ( $tools as $tool ) {
			foreach ( array( 'inputSchema', 'outputSchema' ) as $schema_key ) {
				if ( ! array_key_exists( $schema_key, $tool ) ) {
					continue;
				}
				++$schema_count;

				$legacy  = $compatibility->prepare( $tool[ $schema_key ], McpProtocolVersion::LEGACY );
				$current = $compatibility->prepare( $tool[ $schema_key ], McpProtocolVersion::CURRENT );

				self::assertTrue( $legacy['valid'], (string) $tool['name'] . ' legacy ' . $schema_key );
				self::assertSame( $tool[ $schema_key ], $legacy['schema'] );
				self::assertTrue( $current['valid'], (string) $tool['name'] . ' current ' . $schema_key );
			}
		}
		self::assertGreaterThanOrEqual( count( $tools ), $schema_count );
	}
}
