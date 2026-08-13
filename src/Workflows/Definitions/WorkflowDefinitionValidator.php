<?php
/**
 * Workflow definition v1 validator.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use JsonException;
use SplObjectStorage;
use stdClass;

/**
 * Validates the closed, bounded, storage-independent definition contract.
 */
final class WorkflowDefinitionValidator {

	/**
	 * Validate a raw workflow definition or fail closed.
	 *
	 * @param array<mixed> $definition Raw workflow definition.
	 * @throws WorkflowDefinitionValidationException When validation fails.
	 */
	public function validate( array $definition ): void {
		$this->validate_json_bounds( $definition );
		$this->require_exact_keys( $definition, WorkflowDefinitionSchema::TOP_LEVEL_KEYS, '$' );

		$this->require_integer( $definition['definition_schema_version'], '$.definition_schema_version', 1 );
		if ( WorkflowDefinitionSchema::VERSION !== $definition['definition_schema_version'] ) {
			$this->fail( 'unsupported_schema_version', '$.definition_schema_version' );
		}

		$this->require_identifier( $definition['workflow_id'], '$.workflow_id', 3, 64 );
		$this->require_integer( $definition['workflow_version'], '$.workflow_version', 1 );
		$this->require_string( $definition['name'], '$.name', 1, 120 );
		$this->require_string( $definition['description'], '$.description', 1, 1000 );
		$this->validate_content_target( $definition['content_target'] );
		$this->validate_schema( $definition['input_schema'], '$.input_schema' );
		$allowed_abilities = $this->validate_allowed_abilities( $definition['allowed_abilities'] );
		$steps             = $this->validate_steps( $definition['steps'], $allowed_abilities );
		$write_mode        = $this->validate_write_policy( $definition['write_policy'] );
		$this->validate_approval_gates( $definition['approval_gates'], $steps );
		$this->validate_write_safety( $steps, $write_mode );
		$this->validate_schema( $definition['output_contract'], '$.output_contract' );
		$this->validate_rules( $definition['validation_rules'] );
		$this->require_enum( $definition['status'], '$.status', array( 'draft', 'published', 'disabled' ) );
		$this->require_integer( $definition['created_by'], '$.created_by', 1 );
		$this->require_integer( $definition['updated_by'], '$.updated_by', 1 );
		$this->validate_compatibility( $definition['compatibility'] );
	}

	/**
	 * Validate whole-document JSON and resource bounds before deeper traversal.
	 *
	 * @param array<mixed> $definition Raw definition.
	 */
	private function validate_json_bounds( array $definition ): void {
		$nodes = 0;
		$bytes = 0;
		/**
		 * Active object ancestry used to reject cycles.
		 *
		 * @var SplObjectStorage<stdClass, null> $objects
		 */
		$objects = new SplObjectStorage();
		$this->validate_json_value( $definition, '$', 1, $nodes, $bytes, $objects );

		try {
			$encoded = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Throwing JSON validation is required before normalization.
				$definition,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
				512
			);
		} catch ( JsonException ) {
			$this->fail( 'non_json_value', '$' );
		}

		if ( strlen( $encoded ) > WorkflowDefinitionSchema::MAX_ENCODED_BYTES ) {
			$this->fail( 'definition_too_large', '$' );
		}
	}

	/**
	 * Validate generic JSON values and structural bounds.
	 *
	 * @param mixed            $value   Value to inspect.
	 * @param string           $path    Structural path.
	 * @param int              $depth   Current depth.
	 * @param int              $nodes   Running node count.
	 * @param int              $bytes   Cumulative scalar and key byte estimate.
	 * @param SplObjectStorage $objects Active object ancestry.
	 * @phpstan-param SplObjectStorage<stdClass, null> $objects
	 */
	private function validate_json_value( mixed $value, string $path, int $depth, int &$nodes, int &$bytes, SplObjectStorage $objects ): void {
		++$nodes;
		++$bytes;
		if ( $nodes > WorkflowDefinitionSchema::MAX_NODES ) {
			$this->fail( 'definition_too_complex', '$' );
		}
		if ( $bytes > WorkflowDefinitionSchema::MAX_ENCODED_BYTES ) {
			$this->fail( 'definition_too_large', '$' );
		}

		if ( $depth > WorkflowDefinitionSchema::MAX_DEPTH ) {
			$this->fail( 'definition_too_deep', '$' );
		}

		if ( is_float( $value ) && ! is_finite( $value ) ) {
			$this->fail( 'non_json_value', $path );
		}

		if ( is_string( $value ) ) {
			$bytes += strlen( $value );
			if ( $bytes > WorkflowDefinitionSchema::MAX_ENCODED_BYTES ) {
				$this->fail( 'definition_too_large', '$' );
			}

			return;
		}

		if ( null === $value || is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
			return;
		}

		if ( $value instanceof stdClass ) {
			if ( $objects->contains( $value ) ) {
				$this->fail( 'non_json_value', $path );
			}
			$objects->attach( $value );
			// @phpstan-ignore-next-line -- Native foreach preserves numeric stdClass member names that array conversion coerces.
			foreach ( $value as $key => $item ) {
				$bytes += strlen( $key );
				if ( $bytes > WorkflowDefinitionSchema::MAX_ENCODED_BYTES ) {
					$this->fail( 'definition_too_large', '$' );
				}
				$this->validate_json_value( $item, $path, $depth + 1, $nodes, $bytes, $objects );
			}
			$objects->detach( $value );

			return;
		}

		if ( ! is_array( $value ) ) {
			$this->fail( 'non_json_value', $path );
		}

		if ( ! array_is_list( $value ) ) {
			foreach ( array_keys( $value ) as $key ) {
				if ( ! is_string( $key ) || 1 === preg_match( '/^[0-9]+$/', $key ) ) {
					$this->fail( 'invalid_json_object_key', $path );
				}
				$bytes += strlen( $key );
				if ( $bytes > WorkflowDefinitionSchema::MAX_ENCODED_BYTES ) {
					$this->fail( 'definition_too_large', '$' );
				}
			}
		}

		foreach ( $value as $key => $item ) {
			$child_path = is_int( $key ) ? $path . '[' . $key . ']' : $path;
			$this->validate_json_value( $item, $child_path, $depth + 1, $nodes, $bytes, $objects );
		}
	}

	/**
	 * Validate content targeting.
	 *
	 * @param mixed $target Raw content target.
	 */
	private function validate_content_target( mixed $target ): void {
		$target = $this->require_map( $target, '$.content_target' );
		$this->require_exact_keys( $target, WorkflowDefinitionSchema::CONTENT_TARGET_KEYS, '$.content_target' );
		$this->require_enum( $target['mode'], '$.content_target.mode', array( 'new', 'existing', 'either' ) );

		$post_types = $this->require_list( $target['post_types'], '$.content_target.post_types', 1, WorkflowDefinitionSchema::MAX_POST_TYPES );
		$seen       = array();
		foreach ( $post_types as $index => $post_type ) {
			if ( ! is_string( $post_type ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,31}$/', $post_type ) ) {
				$this->fail( 'invalid_post_type', '$.content_target.post_types[' . $index . ']' );
			}

			if ( isset( $seen[ $post_type ] ) ) {
				$this->fail( 'duplicate_post_type', '$.content_target.post_types[' . $index . ']' );
			}
			$seen[ $post_type ] = true;
		}
	}

	/**
	 * Validate a root-object schema from the deliberately bounded v1 subset.
	 *
	 * @param mixed  $schema Raw schema.
	 * @param string $path   Schema path.
	 */
	private function validate_schema( mixed $schema, string $path ): void {
		$schema = $this->require_map( $schema, $path );
		if ( 'object' !== ( $schema['type'] ?? null ) ) {
			$this->fail( 'invalid_schema_root', $path );
		}

		$this->validate_schema_node( $schema, $path );
	}

	/**
	 * Validate one schema node and its type-specific keywords.
	 *
	 * @param array<mixed> $schema Schema node.
	 * @param string       $path   Structural path.
	 */
	private function validate_schema_node( array $schema, string $path ): void {
		foreach ( array_keys( $schema ) as $key ) {
			if ( in_array( $key, WorkflowDefinitionSchema::SCHEMA_REFERENCE_KEYS, true ) ) {
				$this->fail( 'schema_reference_not_allowed', $path );
			}
			if ( in_array( $key, WorkflowDefinitionSchema::SCHEMA_VOCABULARY_KEYS, true ) ) {
				$this->fail( 'schema_vocabulary_not_allowed', $path );
			}
		}

		if ( ! array_key_exists( 'type', $schema ) ) {
			$this->fail( 'missing_schema_type', $path );
		}
		$type = $schema['type'];
		if ( ! is_string( $type ) || ! in_array( $type, WorkflowDefinitionSchema::SCHEMA_TYPES, true ) ) {
			$this->fail( 'invalid_schema_type', $path . '.type' );
		}
		$allowed_keys = array_merge(
			WorkflowDefinitionSchema::SCHEMA_COMMON_KEYS,
			WorkflowDefinitionSchema::SCHEMA_TYPE_KEYS[ $type ]
		);

		foreach ( array_keys( $schema ) as $key ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				$this->fail( 'unsupported_schema_keyword', $path );
			}
		}

		if ( array_key_exists( 'description', $schema ) ) {
			$this->require_string( $schema['description'], $path . '.description', 0, WorkflowDefinitionSchema::MAX_SCHEMA_DESCRIPTION_LENGTH );
		}
		if ( array_key_exists( 'enum', $schema ) ) {
			$this->validate_schema_enum( $schema['enum'], $type, $path . '.enum' );
		}
		if ( array_key_exists( 'const', $schema ) && ( is_array( $schema['const'] ) || $schema['const'] instanceof stdClass || ! $this->schema_value_matches_type( $schema['const'], $type ) ) ) {
			$this->fail( 'invalid_schema_const', $path . '.const' );
		}

		match ( $type ) {
			'object' => $this->validate_object_schema( $schema, $path ),
			'array' => $this->validate_array_schema( $schema, $path ),
			'string' => $this->validate_string_schema( $schema, $path ),
			'integer', 'number' => $this->validate_number_schema( $schema, $path ),
			default => null,
		};
	}

	/**
	 * Validate object-schema keywords.
	 *
	 * @param array<mixed> $schema Schema node.
	 * @param string       $path   Structural path.
	 */
	private function validate_object_schema( array $schema, string $path ): void {
		$properties = array();
		if ( array_key_exists( 'properties', $schema ) ) {
			$properties = $this->require_map( $schema['properties'], $path . '.properties', true );
			if ( count( $properties ) > WorkflowDefinitionSchema::MAX_SCHEMA_PROPERTIES ) {
				$this->fail( 'schema_properties_too_many', $path . '.properties' );
			}

			foreach ( $properties as $property_name => $property_schema ) {
				if ( ! is_string( $property_name ) || '' === $property_name || strlen( $property_name ) > 128 || preg_match( '/[\x00-\x1F\x7F]/', $property_name ) ) {
					$this->fail( 'invalid_schema_property_name', $path . '.properties' );
				}
				$property_schema = $this->require_map( $property_schema, $path . '.properties' );
				$this->validate_schema_node( $property_schema, $path . '.properties' );
			}
		}

		if ( array_key_exists( 'required', $schema ) ) {
			$required = $this->require_list( $schema['required'], $path . '.required', 0, WorkflowDefinitionSchema::MAX_SCHEMA_PROPERTIES );
			$seen     = array();
			foreach ( $required as $index => $property_name ) {
				if ( ! is_string( $property_name ) || ! array_key_exists( $property_name, $properties ) ) {
					$this->fail( 'schema_required_property_not_found', $path . '.required[' . $index . ']' );
				}
				if ( isset( $seen[ $property_name ] ) ) {
					$this->fail( 'duplicate_schema_required_property', $path . '.required[' . $index . ']' );
				}
				$seen[ $property_name ] = true;
			}
		}

		if ( array_key_exists( 'additionalProperties', $schema ) && ! is_bool( $schema['additionalProperties'] ) ) {
			$this->fail( 'invalid_schema_additional_properties', $path . '.additionalProperties' );
		}

		$this->validate_schema_count_pair( $schema, $path, 'minProperties', 'maxProperties', WorkflowDefinitionSchema::MAX_SCHEMA_PROPERTIES );
	}

	/**
	 * Validate array-schema keywords.
	 *
	 * @param array<mixed> $schema Schema node.
	 * @param string       $path   Structural path.
	 */
	private function validate_array_schema( array $schema, string $path ): void {
		if ( array_key_exists( 'items', $schema ) ) {
			$items = $this->require_map( $schema['items'], $path . '.items' );
			$this->validate_schema_node( $items, $path . '.items' );
		}

		if ( array_key_exists( 'uniqueItems', $schema ) && ! is_bool( $schema['uniqueItems'] ) ) {
			$this->fail( 'invalid_schema_unique_items', $path . '.uniqueItems' );
		}

		$this->validate_schema_count_pair( $schema, $path, 'minItems', 'maxItems', WorkflowDefinitionSchema::MAX_SCHEMA_COLLECTION_ITEMS );
	}

	/**
	 * Validate string-schema keywords.
	 *
	 * @param array<mixed> $schema Schema node.
	 * @param string       $path   Structural path.
	 */
	private function validate_string_schema( array $schema, string $path ): void {
		$this->validate_schema_count_pair( $schema, $path, 'minLength', 'maxLength', WorkflowDefinitionSchema::MAX_SCHEMA_STRING_LENGTH );

		if ( ! array_key_exists( 'pattern', $schema ) ) {
			return;
		}

		$this->require_string( $schema['pattern'], $path . '.pattern', 1, WorkflowDefinitionSchema::MAX_SCHEMA_PATTERN_LENGTH );
		$pattern_value = (string) $schema['pattern'];
		$pattern       = '~' . str_replace( '~', '\\~', $pattern_value ) . '~u';
		if ( false === @preg_match( $pattern, '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid patterns are converted to a stable validation error.
			$this->fail( 'invalid_schema_pattern', $path . '.pattern' );
		}
	}

	/**
	 * Validate numeric-schema keywords.
	 *
	 * @param array<mixed> $schema Schema node.
	 * @param string       $path   Structural path.
	 */
	private function validate_number_schema( array $schema, string $path ): void {
		foreach ( array( 'minimum', 'maximum' ) as $keyword ) {
			if ( array_key_exists( $keyword, $schema ) && ! is_int( $schema[ $keyword ] ) && ! is_float( $schema[ $keyword ] ) ) {
				$this->fail( 'invalid_schema_number', $path . '.' . $keyword );
			}
		}

		if ( isset( $schema['minimum'], $schema['maximum'] ) && $schema['minimum'] > $schema['maximum'] ) {
			$this->fail( 'invalid_schema_range', $path );
		}
	}

	/**
	 * Validate a bounded pair of non-negative schema counts.
	 *
	 * @param array<mixed> $schema      Schema node.
	 * @param string       $path        Structural path.
	 * @param string       $minimum_key Minimum keyword.
	 * @param string       $maximum_key Maximum keyword.
	 * @param int          $maximum     Contract maximum.
	 */
	private function validate_schema_count_pair( array $schema, string $path, string $minimum_key, string $maximum_key, int $maximum ): void {
		foreach ( array( $minimum_key, $maximum_key ) as $keyword ) {
			if ( array_key_exists( $keyword, $schema ) && ( ! is_int( $schema[ $keyword ] ) || $schema[ $keyword ] < 0 || $schema[ $keyword ] > $maximum ) ) {
				$this->fail( 'invalid_schema_count', $path . '.' . $keyword );
			}
		}

		if ( isset( $schema[ $minimum_key ], $schema[ $maximum_key ] ) && $schema[ $minimum_key ] > $schema[ $maximum_key ] ) {
			$this->fail( 'invalid_schema_range', $path );
		}
	}

	/**
	 * Validate bounded, unique scalar enum values against the declared type.
	 *
	 * @param mixed  $enum Raw enum.
	 * @param string $type Declared schema type.
	 * @param string $path Structural path.
	 */
	private function validate_schema_enum( mixed $enum, string $type, string $path ): void {
		$enum = $this->require_list( $enum, $path, 1, WorkflowDefinitionSchema::MAX_SCHEMA_ENUM_VALUES );
		$seen = array();
		foreach ( $enum as $index => $value ) {
			if ( ! $this->schema_value_matches_type( $value, $type ) || is_array( $value ) || $value instanceof stdClass ) {
				$this->fail( 'invalid_schema_enum_value', $path . '[' . $index . ']' );
			}
			$key = $this->schema_enum_key( $value, $type );
			if ( isset( $seen[ $key ] ) ) {
				$this->fail( 'duplicate_schema_enum_value', $path . '[' . $index . ']' );
			}
			$seen[ $key ] = true;
		}
	}

	/**
	 * Return a deterministic scalar enum identity without serialization.
	 *
	 * @param mixed  $value Validated scalar value.
	 * @param string $type  Declared schema type.
	 */
	private function schema_enum_key( mixed $value, string $type ): string {
		if ( 'number' === $type ) {
			$number = (float) $value;
			return 0.0 === $number ? 'number:0' : 'number:' . sprintf( '%.17G', $number );
		}

		return match ( true ) {
			is_string( $value ) => 'string:' . strlen( $value ) . ':' . $value,
			is_int( $value ) => 'integer:' . $value,
			is_bool( $value ) => $value ? 'boolean:true' : 'boolean:false',
			default => 'null',
		};
	}

	/**
	 * Return whether one scalar value matches a declared schema type.
	 *
	 * @param mixed  $value Candidate value.
	 * @param string $type  Declared schema type.
	 */
	private function schema_value_matches_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'string' => is_string( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'null' => null === $value,
			'array' => is_array( $value ) && array_is_list( $value ),
			'object' => $value instanceof stdClass || ( is_array( $value ) && ! array_is_list( $value ) ),
			default => false,
		};
	}

	/**
	 * Validate the ability allowlist.
	 *
	 * @param mixed $abilities Raw abilities.
	 * @return array<string, true>
	 */
	private function validate_allowed_abilities( mixed $abilities ): array {
		$abilities = $this->require_list( $abilities, '$.allowed_abilities', 1, WorkflowDefinitionSchema::MAX_ABILITIES );
		$seen      = array();

		foreach ( $abilities as $index => $ability ) {
			if ( ! is_string( $ability ) || strlen( $ability ) > 128 || 1 !== preg_match( '#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*$#', $ability ) ) {
				$this->fail( 'invalid_ability_id', '$.allowed_abilities[' . $index . ']' );
			}

			if ( isset( $seen[ $ability ] ) ) {
				$this->fail( 'duplicate_ability_id', '$.allowed_abilities[' . $index . ']' );
			}
			$seen[ $ability ] = true;
		}

		return $seen;
	}

	/**
	 * Validate ordered steps and their backward-only dependencies.
	 *
	 * @param mixed               $steps             Raw steps.
	 * @param array<string, true> $allowed_abilities Allowed ability lookup.
	 * @return array<string, string> Step ID to kind lookup.
	 */
	private function validate_steps( mixed $steps, array $allowed_abilities ): array {
		$steps      = $this->require_list( $steps, '$.steps', 1, WorkflowDefinitionSchema::MAX_STEPS );
		$step_kinds = array();

		foreach ( $steps as $index => $raw_step ) {
			$path = '$.steps[' . $index . ']';
			$step = $this->require_map( $raw_step, $path );
			$this->require_exact_keys( $step, WorkflowDefinitionSchema::STEP_KEYS, $path );
			$this->require_identifier( $step['step_id'], $path . '.step_id', 2, 64 );
			$this->require_identifier( $step['adapter_id'], $path . '.adapter_id', 2, 64 );
			$this->require_integer( $step['adapter_version'], $path . '.adapter_version', 1 );
			$this->require_enum( $step['kind'], $path . '.kind', array( 'read', 'proposal', 'write' ) );

			$step_id = $step['step_id'];
			if ( isset( $step_kinds[ $step_id ] ) ) {
				$this->fail( 'duplicate_step_id', $path . '.step_id' );
			}

			$ability_id = $step['ability_id'];
			if ( ! is_string( $ability_id ) || ! isset( $allowed_abilities[ $ability_id ] ) ) {
				$this->fail( 'ability_not_allowed', $path . '.ability_id' );
			}

			$this->require_map( $step['arguments'], $path . '.arguments', true );
			$dependencies = $this->require_list( $step['depends_on'], $path . '.depends_on', 0, WorkflowDefinitionSchema::MAX_STEPS );
			$seen         = array();
			foreach ( $dependencies as $dependency_index => $dependency ) {
				$dependency_path = $path . '.depends_on[' . $dependency_index . ']';
				if ( ! is_string( $dependency ) || isset( $seen[ $dependency ] ) ) {
					$this->fail( is_string( $dependency ) ? 'duplicate_step_dependency' : 'invalid_step_dependency', $dependency_path );
				}
				if ( ! isset( $step_kinds[ $dependency ] ) ) {
					$this->fail( 'step_dependency_not_prior', $dependency_path );
				}
				$seen[ $dependency ] = true;
			}

			$step_kinds[ $step_id ] = $step['kind'];
		}

		return $step_kinds;
	}

	/**
	 * Validate the write policy and return its mode.
	 *
	 * @param mixed $policy Raw policy.
	 */
	private function validate_write_policy( mixed $policy ): string {
		$policy = $this->require_map( $policy, '$.write_policy' );
		$this->require_exact_keys( $policy, WorkflowDefinitionSchema::WRITE_POLICY_KEYS, '$.write_policy' );
		$this->require_enum( $policy['mode'], '$.write_policy.mode', array( 'proposal_only', 'draft_only', 'approved_update' ) );

		return $policy['mode'];
	}

	/**
	 * Validate approval gates and write-step coverage.
	 *
	 * @param mixed                 $gates      Raw gates.
	 * @param array<string, string> $step_kinds Step kind lookup.
	 */
	private function validate_approval_gates( mixed $gates, array $step_kinds ): void {
		$gates = $this->require_list( $gates, '$.approval_gates', 0, WorkflowDefinitionSchema::MAX_STEPS );
		$seen  = array();

		foreach ( $gates as $index => $step_id ) {
			$path = '$.approval_gates[' . $index . ']';
			if ( ! is_string( $step_id ) || ! isset( $step_kinds[ $step_id ] ) ) {
				$this->fail( 'approval_gate_step_not_found', $path );
			}
			if ( isset( $seen[ $step_id ] ) ) {
				$this->fail( 'duplicate_approval_gate', $path );
			}
			if ( 'write' !== $step_kinds[ $step_id ] ) {
				$this->fail( 'approval_gate_not_write_step', $path );
			}
			$seen[ $step_id ] = true;
		}

		foreach ( $step_kinds as $step_id => $kind ) {
			if ( 'write' === $kind && ! isset( $seen[ $step_id ] ) ) {
				$this->fail( 'write_step_requires_approval', '$.approval_gates' );
			}
		}
	}

	/**
	 * Enforce write-policy safety against step kinds.
	 *
	 * @param array<string, string> $step_kinds Step kind lookup.
	 * @param string                $write_mode Write policy mode.
	 */
	private function validate_write_safety( array $step_kinds, string $write_mode ): void {
		if ( 'proposal_only' === $write_mode && in_array( 'write', $step_kinds, true ) ) {
			$this->fail( 'write_step_not_allowed', '$.steps' );
		}
	}

	/**
	 * Validate declarative validation rules.
	 *
	 * @param mixed $rules Raw rules.
	 */
	private function validate_rules( mixed $rules ): void {
		$rules = $this->require_list( $rules, '$.validation_rules', 0, WorkflowDefinitionSchema::MAX_RULES );
		$seen  = array();

		foreach ( $rules as $index => $raw_rule ) {
			$path = '$.validation_rules[' . $index . ']';
			$rule = $this->require_map( $raw_rule, $path );
			$this->require_exact_keys( $rule, WorkflowDefinitionSchema::VALIDATION_RULE_KEYS, $path );
			$this->require_identifier( $rule['rule_id'], $path . '.rule_id', 2, 64 );
			$this->require_enum( $rule['severity'], $path . '.severity', array( 'error', 'warning' ) );

			if ( isset( $seen[ $rule['rule_id'] ] ) ) {
				$this->fail( 'duplicate_validation_rule', $path . '.rule_id' );
			}
			$seen[ $rule['rule_id'] ] = true;
		}
	}

	/**
	 * Validate compatibility metadata.
	 *
	 * @param mixed $compatibility Raw compatibility metadata.
	 */
	private function validate_compatibility( mixed $compatibility ): void {
		$compatibility = $this->require_map( $compatibility, '$.compatibility' );
		$this->require_exact_keys( $compatibility, WorkflowDefinitionSchema::COMPATIBILITY_KEYS, '$.compatibility' );
		$this->require_integer( $compatibility['input_contract_version'], '$.compatibility.input_contract_version', 1 );
		$this->require_integer( $compatibility['output_contract_version'], '$.compatibility.output_contract_version', 1 );
	}

	/**
	 * Require exactly the expected keys.
	 *
	 * @param array<mixed> $value    Candidate map.
	 * @param array        $expected Expected keys.
	 * @param string       $path     Structural path.
	 * @phpstan-param list<string> $expected
	 */
	private function require_exact_keys( array $value, array $expected, string $path ): void {
		foreach ( $expected as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				$this->fail( 'missing_field', $path );
			}
		}

		if ( count( $value ) !== count( $expected ) ) {
			$this->fail( 'unknown_field', $path );
		}

		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $expected, true ) ) {
				$this->fail( 'unknown_field', $path );
			}
		}
	}

	/**
	 * Require an associative map. Empty arrays may represent an empty JSON object.
	 *
	 * @param mixed  $value       Candidate value.
	 * @param string $path        Structural path.
	 * @param bool   $allow_empty Whether an empty map is accepted.
	 * @return array<mixed>
	 */
	private function require_map( mixed $value, string $path, bool $allow_empty = false ): array {
		if ( $value instanceof stdClass ) {
			return get_object_vars( $value );
		}

		if ( ! is_array( $value ) ) {
			$this->fail( 'invalid_map', $path );
		}
		if ( array() === $value ) {
			if ( ! $allow_empty ) {
				$this->fail( 'invalid_map', $path );
			}

			return $value;
		}
		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) || 1 === preg_match( '/^[0-9]+$/', $key ) ) {
				$this->fail( 'invalid_json_object_key', $path );
			}
		}

		return $value;
	}

	/**
	 * Require a bounded list.
	 *
	 * @param mixed  $value Candidate value.
	 * @param string $path  Structural path.
	 * @param int    $min   Minimum items.
	 * @param int    $max   Maximum items.
	 * @return list<mixed>
	 */
	private function require_list( mixed $value, string $path, int $min, int $max ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			$this->fail( 'invalid_list', $path );
		}

		$count = count( $value );
		if ( $count < $min ) {
			$this->fail( 'list_too_short', $path );
		}
		if ( $count > $max ) {
			$this->fail( 'list_too_long', $path );
		}

		return $value;
	}

	/**
	 * Require a bounded non-empty string without transforming it.
	 *
	 * @param mixed  $value Candidate value.
	 * @param string $path  Structural path.
	 * @param int    $min   Minimum bytes.
	 * @param int    $max   Maximum bytes.
	 */
	private function require_string( mixed $value, string $path, int $min, int $max ): void {
		if ( ! is_string( $value ) ) {
			$this->fail( 'invalid_string', $path );
		}

		$length = strlen( $value );
		if ( $length < $min || $length > $max ) {
			$this->fail( 'invalid_string_length', $path );
		}
	}

	/**
	 * Require a strict positive integer.
	 *
	 * @param mixed  $value Candidate value.
	 * @param string $path  Structural path.
	 * @param int    $min   Minimum value.
	 */
	private function require_integer( mixed $value, string $path, int $min ): void {
		if ( ! is_int( $value ) || $value < $min ) {
			$this->fail( 'invalid_integer', $path );
		}
	}

	/**
	 * Require a strict identifier.
	 *
	 * @param mixed  $value Candidate value.
	 * @param string $path  Structural path.
	 * @param int    $min   Minimum bytes.
	 * @param int    $max   Maximum bytes.
	 */
	private function require_identifier( mixed $value, string $path, int $min, int $max ): void {
		if ( ! is_string( $value ) || strlen( $value ) < $min || strlen( $value ) > $max || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $value ) ) {
			$this->fail( 'invalid_identifier', $path );
		}
	}

	/**
	 * Require one exact string enum value.
	 *
	 * @param mixed  $value   Candidate value.
	 * @param string $path    Structural path.
	 * @param array  $allowed Allowed values.
	 * @phpstan-param list<string> $allowed
	 */
	private function require_enum( mixed $value, string $path, array $allowed ): void {
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			$this->fail( 'invalid_enum', $path );
		}
	}

	/**
	 * Fail validation with a stable code and bounded path.
	 *
	 * @param string $code Stable error code.
	 * @param string $path Bounded structural path.
	 * @throws WorkflowDefinitionValidationException Always.
	 */
	private function fail( string $code, string $path ): never {
		throw new WorkflowDefinitionValidationException( $code, $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal validation metadata is never rendered here.
	}
}
