<?php
/**
 * Validates static workflow step arguments and typed bindings.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

use stdClass;

/**
 * Keeps guided definitions executable before they reach the durable runner.
 *
 * Static values are checked against the adapter's input schema. Binding
 * placeholders are checked against the input or a prior step's output schema,
 * including source requiredness and basic type compatibility.
 */
final class WorkflowStepArgumentValidator {

	private const MAX_ERRORS = 50;

	/**
	 * Validate one argument object.
	 *
	 * @param array<string,mixed>               $arguments               Candidate arguments.
	 * @param array<string,mixed>               $schema                  Adapter input schema.
	 * @param array<string,mixed>               $input_schema             Workflow input schema.
	 * @param array<string,array<string,mixed>> $prior_output_schemas     Prior step output schemas.
	 * @return list<string> Invalid argument paths.
	 */
	public function validate( array $arguments, array $schema, array $input_schema, array $prior_output_schemas = array() ): array {
		$errors = array();
		$this->validate_node( $arguments, $schema, '$.arguments', $input_schema, $prior_output_schemas, $errors );

		$errors = array_values( array_unique( $errors ) );
		sort( $errors, SORT_STRING );

		return $errors;
	}

	/**
	 * Validate one value or binding against a schema node.
	 *
	 * @param mixed                             $value          Candidate value.
	 * @param array<string,mixed>|stdClass      $schema         Schema node.
	 * @param string                            $path           Validation path.
	 * @param array<string,mixed>               $input_schema   Workflow input schema.
	 * @param array<string,array<string,mixed>> $prior_outputs  Prior output schemas.
	 * @param array<string>                     $errors         Invalid paths.
	 */
	private function validate_node( mixed $value, array|stdClass $schema, string $path, array $input_schema, array $prior_outputs, array &$errors ): void {
		if ( count( $errors ) >= self::MAX_ERRORS ) {
			return;
		}

		$schema_map = $this->map( $schema );
		if ( is_string( $value ) ) {
			$binding = $this->binding( $value );
			if ( null !== $binding ) {
				$this->validate_binding( $binding, $schema_map, $path, $input_schema, $prior_outputs, $errors );

				return;
			}
			if ( str_contains( $value, '{{' ) || str_contains( $value, '}}' ) ) {
				$this->record( $errors, $path );

				return;
			}
		}

		$type = is_string( $schema_map['type'] ?? null ) ? $schema_map['type'] : '';
		if ( ! $this->matches_type( $value, $type ) ) {
			$this->record( $errors, $path );

			return;
		}
		if ( array_key_exists( 'enum', $schema_map ) && is_array( $schema_map['enum'] ) && ! $this->contains( $schema_map['enum'], $value ) ) {
			$this->record( $errors, $path );
		}
		if ( array_key_exists( 'const', $schema_map ) && ! $this->equal( $schema_map['const'], $value ) ) {
			$this->record( $errors, $path );
		}

		match ( $type ) {
			'object' => $this->validate_object( $value, $schema_map, $path, $input_schema, $prior_outputs, $errors ),
			'array' => $this->validate_array( $value, $schema_map, $path, $input_schema, $prior_outputs, $errors ),
			'string' => $this->validate_string( $value, $schema_map, $path, $errors ),
			'integer', 'number' => $this->validate_number( $value, $schema_map, $path, $errors ),
			default => null,
		};
	}

	/**
	 * Validate an object and its declared members.
	 *
	 * @param mixed                             $value         Candidate object.
	 * @param array<string,mixed>               $schema        Schema map.
	 * @param string                            $path          Validation path.
	 * @param array<string,mixed>               $input_schema  Workflow input schema.
	 * @param array<string,array<string,mixed>> $prior_outputs Prior output schemas.
	 * @param array<string>                     $errors        Invalid paths.
	 */
	private function validate_object( mixed $value, array $schema, string $path, array $input_schema, array $prior_outputs, array &$errors ): void {
		if ( ! $this->is_object_map( $value ) ) {
			$this->record( $errors, $path );

			return;
		}

		$values     = $this->map( $value );
		$properties = $this->map( $schema['properties'] ?? array() );
		$required   = is_array( $schema['required'] ?? null ) ? $schema['required'] : array();
		foreach ( $required as $key ) {
			if ( is_string( $key ) && ! array_key_exists( $key, $values ) ) {
				$this->record( $errors, $path . '.' . $key );
			}
		}

		$additional = $schema['additionalProperties'] ?? true;
		foreach ( $values as $key => $item ) {
			$key_path = $path . '.' . $key;
			if ( array_key_exists( $key, $properties ) && ( is_array( $properties[ $key ] ) || $properties[ $key ] instanceof stdClass ) ) {
				$this->validate_node( $item, $properties[ $key ], $key_path, $input_schema, $prior_outputs, $errors );
				continue;
			}
			if ( false === $additional ) {
				$this->record( $errors, $key_path );
			} elseif ( is_array( $additional ) || $additional instanceof stdClass ) {
				$this->validate_node( $item, $additional, $key_path, $input_schema, $prior_outputs, $errors );
			}
		}

		$count = count( $values );
		if ( isset( $schema['minProperties'] ) && $count < (int) $schema['minProperties'] ) {
			$this->record( $errors, $path );
		}
		if ( isset( $schema['maxProperties'] ) && $count > (int) $schema['maxProperties'] ) {
			$this->record( $errors, $path );
		}
	}

	/**
	 * Validate a list and each declared item schema.
	 *
	 * @param mixed                             $value         Candidate list.
	 * @param array<string,mixed>               $schema        Schema map.
	 * @param string                            $path          Validation path.
	 * @param array<string,mixed>               $input_schema  Workflow input schema.
	 * @param array<string,array<string,mixed>> $prior_outputs Prior output schemas.
	 * @param array<string>                     $errors        Invalid paths.
	 */
	private function validate_array( mixed $value, array $schema, string $path, array $input_schema, array $prior_outputs, array &$errors ): void {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			$this->record( $errors, $path );

			return;
		}

		$count = count( $value );
		if ( isset( $schema['minItems'] ) && $count < (int) $schema['minItems'] ) {
			$this->record( $errors, $path );
		}
		if ( isset( $schema['maxItems'] ) && $count > (int) $schema['maxItems'] ) {
			$this->record( $errors, $path );
		}
		if ( true === ( $schema['uniqueItems'] ?? false ) ) {
			$seen = array();
			foreach ( $value as $item ) {
				$key = $this->value_key( $item );
				if ( isset( $seen[ $key ] ) ) {
					$this->record( $errors, $path );
					break;
				}
				$seen[ $key ] = true;
			}
		}

		$items = $schema['items'] ?? null;
		if ( is_array( $items ) || $items instanceof stdClass ) {
			foreach ( $value as $index => $item ) {
				$this->validate_node( $item, $items, $path . '[' . $index . ']', $input_schema, $prior_outputs, $errors );
			}
		}
	}

	/**
	 * Validate a string's bounded constraints.
	 *
	 * @param mixed               $value  Candidate string.
	 * @param array<string,mixed> $schema Schema map.
	 * @param string              $path   Validation path.
	 * @param array<string>       $errors Invalid paths.
	 */
	private function validate_string( mixed $value, array $schema, string $path, array &$errors ): void {
		if ( ! is_string( $value ) ) {
			return;
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
		if ( isset( $schema['minLength'] ) && $length < (int) $schema['minLength'] ) {
			$this->record( $errors, $path );
		}
		if ( isset( $schema['maxLength'] ) && $length > (int) $schema['maxLength'] ) {
			$this->record( $errors, $path );
		}
		if ( isset( $schema['pattern'] ) && is_string( $schema['pattern'] ) ) {
			$pattern = '~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=1000)(?:' . str_replace( '~', '\\~', $schema['pattern'] ) . ')~u';
			if ( 1 !== preg_match( $pattern, $value ) ) {
				$this->record( $errors, $path );
			}
		}
	}

	/**
	 * Validate numeric bounds.
	 *
	 * @param mixed               $value  Candidate number.
	 * @param array<string,mixed> $schema Schema map.
	 * @param string              $path   Validation path.
	 * @param array<string>       $errors Invalid paths.
	 */
	private function validate_number( mixed $value, array $schema, string $path, array &$errors ): void {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			return;
		}
		if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
			$this->record( $errors, $path );
		}
		if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
			$this->record( $errors, $path );
		}
	}

	/**
	 * Validate one exact input/prior-output placeholder.
	 *
	 * @param array<string,mixed>               $binding       Parsed binding descriptor.
	 * @param array<string,mixed>               $target_schema Adapter target schema.
	 * @param string                            $path          Validation path.
	 * @param array<string,mixed>               $input_schema  Workflow input schema.
	 * @param array<string,array<string,mixed>> $prior_outputs Prior output schemas.
	 * @param array<string>                     $errors        Invalid paths.
	 * @phpstan-param array{source:string,step_id?:string,path:list<string>} $binding
	 */
	private function validate_binding( array $binding, array $target_schema, string $path, array $input_schema, array $prior_outputs, array &$errors ): void {
		$source = $this->source_schema( $binding, $input_schema, $prior_outputs );
		if ( null === $source || ! $source['guaranteed'] || ! $this->compatible( $source['schema'], $target_schema ) ) {
			$this->record( $errors, $path );
		}
	}

	/**
	 * Resolve a placeholder source schema and whether the path is guaranteed.
	 *
	 * @param array{source:string,step_id?:string,path:list<string>} $binding Binding descriptor.
	 * @param array<string,mixed>                                    $input_schema Workflow input schema.
	 * @param array<string,array<string,mixed>>                      $prior_outputs Prior output schemas.
	 * @return array{schema:array<string,mixed>,guaranteed:bool}|null
	 */
	private function source_schema( array $binding, array $input_schema, array $prior_outputs ): ?array {
		if ( 'input' === $binding['source'] ) {
			return $this->schema_at_path( $input_schema, $binding['path'] );
		}
		$step_id = (string) ( $binding['step_id'] ?? '' );
		if ( '' === $step_id || ! isset( $prior_outputs[ $step_id ] ) ) {
			return null;
		}

		return $this->schema_at_path( $prior_outputs[ $step_id ], $binding['path'] );
	}

	/**
	 * Resolve a dotted schema path.
	 *
	 * @param array<string,mixed>|stdClass $schema Schema root.
	 * @param array<string>                $path   Dotted path segments.
	 * @return array{schema:array<string,mixed>,guaranteed:bool}|null
	 * @phpstan-param list<string> $path
	 */
	private function schema_at_path( array|stdClass $schema, array $path ): ?array {
		$current    = $this->map( $schema );
		$guaranteed = true;
		foreach ( $path as $segment ) {
			if ( 'object' !== (string) ( $current['type'] ?? '' ) ) {
				return null;
			}
			$properties = $this->map( $current['properties'] ?? array() );
			$required   = is_array( $current['required'] ?? null ) ? $current['required'] : array();
			if ( array_key_exists( $segment, $properties ) && ( is_array( $properties[ $segment ] ) || $properties[ $segment ] instanceof stdClass ) ) {
				$guaranteed = $guaranteed && in_array( $segment, $required, true );
				$current    = $this->map( $properties[ $segment ] );
				continue;
			}
			$additional = $current['additionalProperties'] ?? false;
			if ( is_array( $additional ) || $additional instanceof stdClass ) {
				$guaranteed = false;
				$current    = $this->map( $additional );
				continue;
			}
			if ( true === $additional ) {
				return array(
					'schema'     => array(),
					'guaranteed' => false,
				);
			}

			return null;
		}

		return array(
			'schema'     => $current,
			'guaranteed' => $guaranteed,
		);
	}

	/**
	 * Check source and target schemas for executable type compatibility.
	 *
	 * @param array<string,mixed> $source Source schema.
	 * @param array<string,mixed> $target Target schema.
	 */
	private function compatible( array $source, array $target ): bool {
		$source_type = (string) ( $source['type'] ?? '' );
		$target_type = (string) ( $target['type'] ?? '' );
		if ( '' === $source_type || '' === $target_type ) {
			return false;
		}
		if ( $source_type !== $target_type && ( 'number' !== $target_type || 'integer' !== $source_type ) ) {
			return false;
		}

		$finite_values = $this->finite_values( $source );
		if ( null !== $finite_values ) {
			foreach ( $finite_values as $value ) {
				if ( ! $this->accepts( $value, $target ) ) {
					return false;
				}
			}

			return true;
		}
		if ( array_key_exists( 'enum', $target ) || array_key_exists( 'const', $target ) ) {
			return false;
		}

		return match ( $target_type ) {
			'object' => $this->compatible_object( $source, $target ),
			'array' => $this->compatible_array( $source, $target ),
			'string' => $this->compatible_string( $source, $target ),
			'integer', 'number' => $this->compatible_number( $source, $target ),
			default => true,
		};
	}

	/**
	 * Return finite values that make a source schema exactly enumerable.
	 *
	 * @param array<string,mixed> $schema Source schema.
	 * @return list<mixed>|null
	 */
	private function finite_values( array $schema ): ?array {
		if ( array_key_exists( 'const', $schema ) ) {
			return array( $schema['const'] );
		}
		if ( is_array( $schema['enum'] ?? null ) ) {
			return array_values( $schema['enum'] );
		}

		return null;
	}

	/**
	 * Validate one finite source value against a target schema.
	 *
	 * @param mixed               $value  Finite source value.
	 * @param array<string,mixed> $schema Target schema.
	 */
	private function accepts( mixed $value, array $schema ): bool {
		$errors = array();
		$this->validate_node( $value, $schema, '$', array(), array(), $errors );

		return array() === $errors;
	}

	/**
	 * Compare object constraints conservatively.
	 *
	 * @param array<string,mixed> $source Source schema.
	 * @param array<string,mixed> $target Target schema.
	 */
	private function compatible_object( array $source, array $target ): bool {
		if ( ! $this->bounded_lower( $source, $target, 'minProperties' ) || ! $this->bounded_upper( $source, $target, 'maxProperties' ) ) {
			return false;
		}

		$source_properties = $this->map( $source['properties'] ?? array() );
		$target_properties = $this->map( $target['properties'] ?? array() );
		$source_required   = is_array( $source['required'] ?? null ) ? $source['required'] : array();
		$target_required   = is_array( $target['required'] ?? null ) ? $target['required'] : array();
		$source_additional = $source['additionalProperties'] ?? true;
		$target_additional = $target['additionalProperties'] ?? true;

		if ( false === $target_additional && false !== $source_additional ) {
			return false;
		}
		if ( is_array( $target_additional ) || $target_additional instanceof stdClass ) {
			if ( true === $source_additional ) {
				return false;
			}
			if ( ( is_array( $source_additional ) || $source_additional instanceof stdClass ) && ! $this->compatible( $this->map( $source_additional ), $this->map( $target_additional ) ) ) {
				return false;
			}
		}
		foreach ( $target_required as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $source_required, true ) || ! array_key_exists( $key, $source_properties ) ) {
				return false;
			}
		}
		foreach ( $target_properties as $key => $target_property ) {
			if ( ! is_array( $target_property ) && ! $target_property instanceof stdClass ) {
				return false;
			}
			if ( array_key_exists( $key, $source_properties ) ) {
				$source_property = $source_properties[ $key ];
				if ( ( ! is_array( $source_property ) && ! $source_property instanceof stdClass ) || ! $this->compatible( $this->map( $source_property ), $this->map( $target_property ) ) ) {
					return false;
				}
			} elseif ( false !== $source_additional && ! $this->additional_schema_compatible( $source_additional, $target_property ) ) {
				return false;
			}
		}

		foreach ( $source_properties as $key => $source_property ) {
			if ( array_key_exists( $key, $target_properties ) ) {
				continue;
			}
			if ( false === $target_additional ) {
				return false;
			}
			$target_has_schema = is_array( $target_additional ) || $target_additional instanceof stdClass;
			$source_has_schema = is_array( $source_property ) || $source_property instanceof stdClass;
			if ( $target_has_schema ) {
				if ( ! $source_has_schema || ! $this->compatible( $this->map( $source_property ), $this->map( $target_additional ) ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Compare array constraints conservatively.
	 *
	 * @param array<string,mixed> $source Source schema.
	 * @param array<string,mixed> $target Target schema.
	 */
	private function compatible_array( array $source, array $target ): bool {
		if ( ! $this->bounded_lower( $source, $target, 'minItems' ) || ! $this->bounded_upper( $source, $target, 'maxItems' ) ) {
			return false;
		}
		if ( true === ( $target['uniqueItems'] ?? false ) && true !== ( $source['uniqueItems'] ?? false ) ) {
			return false;
		}
		$target_items = $target['items'] ?? null;
		if ( null === $target_items ) {
			return true;
		}
		$source_items = $source['items'] ?? null;
		if ( ( ! is_array( $source_items ) && ! $source_items instanceof stdClass ) || ( ! is_array( $target_items ) && ! $target_items instanceof stdClass ) ) {
			return false;
		}

		return $this->compatible( $this->map( $source_items ), $this->map( $target_items ) );
	}

	/**
	 * Compare string constraints conservatively.
	 *
	 * @param array<string,mixed> $source Source schema.
	 * @param array<string,mixed> $target Target schema.
	 */
	private function compatible_string( array $source, array $target ): bool {
		if ( ! $this->bounded_lower( $source, $target, 'minLength' ) || ! $this->bounded_upper( $source, $target, 'maxLength' ) ) {
			return false;
		}
		if ( isset( $target['pattern'] ) && ( ! isset( $source['pattern'] ) || $source['pattern'] !== $target['pattern'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Compare numeric constraints conservatively.
	 *
	 * @param array<string,mixed> $source Source schema.
	 * @param array<string,mixed> $target Target schema.
	 */
	private function compatible_number( array $source, array $target ): bool {
		return $this->bounded_lower( $source, $target, 'minimum' ) && $this->bounded_upper( $source, $target, 'maximum' );
	}

	/**
	 * Compare a lower bound where the target must be no stricter than source.
	 *
	 * @param array<string,mixed> $source Source schema.
	 * @param array<string,mixed> $target Target schema.
	 * @param string              $key    Bound key.
	 */
	private function bounded_lower( array $source, array $target, string $key ): bool {
		return ! array_key_exists( $key, $target ) || ( array_key_exists( $key, $source ) && $source[ $key ] >= $target[ $key ] );
	}

	/**
	 * Compare an upper bound where the target must be no stricter than source.
	 *
	 * @param array<string,mixed> $source Source schema.
	 * @param array<string,mixed> $target Target schema.
	 * @param string              $key    Bound key.
	 */
	private function bounded_upper( array $source, array $target, string $key ): bool {
		return ! array_key_exists( $key, $target ) || ( array_key_exists( $key, $source ) && $source[ $key ] <= $target[ $key ] );
	}

	/**
	 * Compare an additional-properties declaration with one target property.
	 *
	 * @param mixed $source_additional Source declaration.
	 * @param mixed $target_property   Target property schema.
	 */
	private function additional_schema_compatible( mixed $source_additional, mixed $target_property ): bool {
		if ( ! is_array( $source_additional ) && ! $source_additional instanceof stdClass ) {
			return false;
		}
		if ( ! is_array( $target_property ) && ! $target_property instanceof stdClass ) {
			return false;
		}

		return $this->compatible( $this->map( $source_additional ), $this->map( $target_property ) );
	}

	/**
	 * Parse one exact supported binding placeholder.
	 *
	 * @param string $value Candidate value.
	 * @return array{source:string,step_id?:string,path:list<string>}|null Parsed binding descriptor.
	 */
	private function binding( string $value ): ?array {
		if ( 1 === preg_match( '/^\\{\\{input\\.([a-z][a-z0-9_.]{0,127})\\}\\}$/D', $value, $matches ) ) {
			return array(
				'source' => 'input',
				'path'   => explode( '.', $matches[1] ),
			);
		}
		if ( 1 === preg_match( '/^\\{\\{steps\\.([a-z][a-z0-9_]{0,63})\\.output\\.([a-z][a-z0-9_.-]{0,127})\\}\\}$/D', $value, $matches ) ) {
			return array(
				'source'  => 'steps',
				'step_id' => $matches[1],
				'path'    => explode( '.', $matches[2] ),
			);
		}

		return null;
	}

	/**
	 * Whether a value is a JSON object map.
	 *
	 * @param mixed $value Candidate value.
	 */
	private function is_object_map( mixed $value ): bool {
		return $value instanceof stdClass || ( is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ) );
	}

	/**
	 * Whether a value matches a supported JSON schema type.
	 *
	 * @param mixed  $value Candidate value.
	 * @param string $type  JSON schema type.
	 */
	private function matches_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'object' => $this->is_object_map( $value ),
			'array' => is_array( $value ) && array_is_list( $value ),
			'string' => is_string( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'null' => null === $value,
			default => false,
		};
	}

	/**
	 * Convert arrays and objects to a detached map.
	 *
	 * @param mixed $value Candidate map.
	 * @return array<string,mixed>
	 */
	private function map( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( $value instanceof stdClass ) {
			return get_object_vars( $value );
		}

		return array();
	}

	/**
	 * Compare one enum value with strict scalar/object equality.
	 *
	 * @param mixed $left  First value.
	 * @param mixed $right Second value.
	 */
	private function equal( mixed $left, mixed $right ): bool {
		if ( is_array( $left ) && is_array( $right ) ) {
			if ( array_keys( $left ) !== array_keys( $right ) ) {
				return false;
			}
			foreach ( $left as $key => $value ) {
				if ( ! $this->equal( $value, $right[ $key ] ) ) {
					return false;
				}
			}

			return true;
		}

		return gettype( $left ) === gettype( $right ) && $left === $right;
	}

	/**
	 * Whether an enum contains a value.
	 *
	 * @param array<mixed> $values Candidate enum values.
	 * @param mixed        $value  Value to find.
	 */
	private function contains( array $values, mixed $value ): bool {
		foreach ( $values as $candidate ) {
			if ( $this->equal( $candidate, $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a deterministic uniqueness key for array items.
	 *
	 * @param mixed $value Candidate item.
	 */
	private function value_key( mixed $value ): string {
		if ( is_array( $value ) ) {
			return 'array:' . serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Local bounded uniqueness key only.
		}

		return gettype( $value ) . ':' . (string) $value;
	}

	/**
	 * Record one bounded path.
	 *
	 * @param array<string> $errors Invalid paths.
	 * @param string        $path   Validation path.
	 */
	private function record( array &$errors, string $path ): void {
		if ( count( $errors ) < self::MAX_ERRORS ) {
			$errors[] = $path;
		}
	}
}
