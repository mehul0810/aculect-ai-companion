<?php
/**
 * Pure validator for the workflow definition JSON Schema subset.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

use stdClass;

/**
 * Validates normalized input without invoking WordPress or external code.
 */
final class WorkflowInputValidator {

	private const MAX_ERRORS        = 50;
	private const MAX_PATH_BYTES    = 96;
	private const REGEX_MATCH_LIMIT = 100000;
	private const REGEX_DEPTH_LIMIT = 1000;

	/**
	 * Validate one input against an already-validated definition schema.
	 *
	 * @param WorkflowInputContract $input  Normalized input.
	 * @param array<mixed>|stdClass $schema Validated schema.
	 */
	public function validate( WorkflowInputContract $input, array|stdClass $schema ): WorkflowInputValidation {
		$missing = array();
		$invalid = array();
		$this->validate_node( $input->value(), $schema, '$', $missing, $invalid );

		$missing = array_values( array_unique( $missing ) );
		$invalid = array_values( array_unique( $invalid ) );
		sort( $missing, SORT_STRING );
		sort( $invalid, SORT_STRING );

		return new WorkflowInputValidation( $missing, $invalid );
	}

	/**
	 * Validate one schema node.
	 *
	 * @param mixed                 $value   Input value.
	 * @param array<mixed>|stdClass $schema  Schema node.
	 * @param string                $path    Input path.
	 * @param array                 $missing Missing paths.
	 * @param array                 $invalid Invalid paths.
	 * @phpstan-param list<string> $missing
	 * @phpstan-param list<string> $invalid
	 */
	private function validate_node(
		mixed $value,
		array|stdClass $schema,
		string $path,
		array &$missing,
		array &$invalid
	): void {
		if ( count( $missing ) + count( $invalid ) >= self::MAX_ERRORS ) {
			return;
		}

		$schema_map = $this->map( $schema );
		$type       = is_string( $schema_map['type'] ?? null ) ? $schema_map['type'] : '';
		if ( ! $this->matches_type( $value, $type ) ) {
			$this->record_path( $invalid, $path );
			return;
		}

		if ( array_key_exists( 'enum', $schema_map ) && is_array( $schema_map['enum'] ) && ! $this->contains_value( $schema_map['enum'], $value ) ) {
			$this->record_path( $invalid, $path );
			return;
		}

		if ( array_key_exists( 'const', $schema_map ) && ! $this->values_equal( $schema_map['const'], $value ) ) {
			$this->record_path( $invalid, $path );
			return;
		}

		match ( $type ) {
			'object' => $this->validate_object( $value, $schema_map, $path, $missing, $invalid ),
			'array' => $this->validate_array( $value, $schema_map, $path, $missing, $invalid ),
			'string' => $this->validate_string( $value, $schema_map, $path, $invalid ),
			'integer', 'number' => $this->validate_number( $value, $schema_map, $path, $invalid ),
			default => null,
		};
	}

	/**
	 * Validate an object node.
	 *
	 * @param mixed                $value   Object value.
	 * @param array<string, mixed> $schema  Schema map.
	 * @param string               $path    Input path.
	 * @param array                $missing Missing paths.
	 * @param array                $invalid Invalid paths.
	 * @phpstan-param list<string> $missing
	 * @phpstan-param list<string> $invalid
	 */
	private function validate_object( mixed $value, array $schema, string $path, array &$missing, array &$invalid ): void {
		if ( ! $value instanceof stdClass ) {
			$this->record_path( $invalid, $path );
			return;
		}

		$values     = $this->map( $value );
		$properties = isset( $schema['properties'] ) && ( is_array( $schema['properties'] ) || $schema['properties'] instanceof stdClass )
			? $this->map( $schema['properties'] )
			: array();
		$required   = is_array( $schema['required'] ?? null ) ? $schema['required'] : array();

		foreach ( $required as $required_key ) {
			if ( is_string( $required_key ) && ! array_key_exists( $required_key, $values ) ) {
				$this->record_path( $missing, $path . '.' . $required_key );
			}
		}

		if ( false === ( $schema['additionalProperties'] ?? true ) ) {
			foreach ( array_keys( $values ) as $key ) {
				if ( ! array_key_exists( $key, $properties ) ) {
					$this->record_path( $invalid, $path . '.' . $key );
				}
			}
		}

		$count = count( $values );
		if ( isset( $schema['minProperties'] ) && $count < (int) $schema['minProperties'] ) {
			$this->record_path( $invalid, $path );
		}
		if ( isset( $schema['maxProperties'] ) && $count > (int) $schema['maxProperties'] ) {
			$this->record_path( $invalid, $path );
		}

		foreach ( $properties as $key => $child_schema ) {
			if ( array_key_exists( $key, $values ) && ( is_array( $child_schema ) || $child_schema instanceof stdClass ) ) {
				$this->validate_node( $values[ $key ], $child_schema, $path . '.' . $key, $missing, $invalid );
			}
		}
	}

	/**
	 * Validate an array node.
	 *
	 * @param mixed                $value   List value.
	 * @param array<string, mixed> $schema  Schema map.
	 * @param string               $path    Input path.
	 * @param array                $missing Missing paths.
	 * @param array                $invalid Invalid paths.
	 * @phpstan-param list<string> $missing
	 * @phpstan-param list<string> $invalid
	 */
	private function validate_array( mixed $value, array $schema, string $path, array &$missing, array &$invalid ): void {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			$this->record_path( $invalid, $path );
			return;
		}

		$count = count( $value );
		if ( isset( $schema['minItems'] ) && $count < (int) $schema['minItems'] ) {
			$this->record_path( $invalid, $path );
		}
		if ( isset( $schema['maxItems'] ) && $count > (int) $schema['maxItems'] ) {
			$this->record_path( $invalid, $path );
		}

		if ( true === ( $schema['uniqueItems'] ?? false ) ) {
			$seen = array();
			foreach ( $value as $item ) {
				$key = $this->value_key( $item );
				if ( isset( $seen[ $key ] ) ) {
					$this->record_path( $invalid, $path );
					break;
				}
				$seen[ $key ] = true;
			}
		}

		$items = $schema['items'] ?? null;
		if ( is_array( $items ) || $items instanceof stdClass ) {
			foreach ( $value as $index => $item ) {
				$this->validate_node( $item, $items, $path . '[' . $index . ']', $missing, $invalid );
			}
		}
	}

	/**
	 * Validate a string node.
	 *
	 * @param mixed                $value   String value.
	 * @param array<string, mixed> $schema  Schema map.
	 * @param string               $path    Input path.
	 * @param array                $invalid Invalid paths.
	 * @phpstan-param list<string> $invalid
	 */
	private function validate_string( mixed $value, array $schema, string $path, array &$invalid ): void {
		if ( ! is_string( $value ) ) {
			$this->record_path( $invalid, $path );
			return;
		}

		$length = $this->unicode_length( $value );
		if ( isset( $schema['minLength'] ) && $length < (int) $schema['minLength'] ) {
			$this->record_path( $invalid, $path );
		}
		if ( isset( $schema['maxLength'] ) && $length > (int) $schema['maxLength'] ) {
			$this->record_path( $invalid, $path );
		}
		if ( isset( $schema['pattern'] ) && is_string( $schema['pattern'] ) ) {
			$pattern = '~(*LIMIT_MATCH=' . self::REGEX_MATCH_LIMIT . ')(*LIMIT_DEPTH=' . self::REGEX_DEPTH_LIMIT . ')(?:' . str_replace( '~', '\\~', $schema['pattern'] ) . ')~u';
			if ( 1 !== preg_match( $pattern, $value ) ) {
				$this->record_path( $invalid, $path );
			}
		}
	}

	/**
	 * Validate numeric bounds.
	 *
	 * @param mixed                $value   Numeric value.
	 * @param array<string, mixed> $schema  Schema map.
	 * @param string               $path    Input path.
	 * @param array                $invalid Invalid paths.
	 * @phpstan-param list<string> $invalid
	 */
	private function validate_number( mixed $value, array $schema, string $path, array &$invalid ): void {
		if ( ! is_int( $value ) && ! is_float( $value ) ) {
			$this->record_path( $invalid, $path );
			return;
		}

		if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
			$this->record_path( $invalid, $path );
		}
		if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
			$this->record_path( $invalid, $path );
		}
	}

	/**
	 * Whether a value matches a JSON schema type.
	 *
	 * @param mixed  $value Value to check.
	 * @param string $type  Schema type.
	 */
	private function matches_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'object' => $value instanceof stdClass,
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
	 * Convert an explicit JSON object into an associative map.
	 *
	 * @param array<mixed>|stdClass $value Object value.
	 * @return array<string, mixed>
	 */
	private function map( array|stdClass $value ): array {
		if ( is_array( $value ) ) {
			/**
			 * Explicit object map.
			 *
			 * @var array<string, mixed> $value
			 */
			return $value;
		}

		$map = array();
		// @phpstan-ignore-next-line -- Native iteration preserves numeric JSON object member names.
		foreach ( $value as $key => $item ) {
			$map[ $key ] = $item;
		}

		return $map;
	}

	/**
	 * Whether an enum contains a value under canonical JSON equality.
	 *
	 * @param array<mixed> $values Enum values.
	 * @param mixed        $value  Input value.
	 */
	private function contains_value( array $values, mixed $value ): bool {
		foreach ( $values as $candidate ) {
			if ( $this->values_equal( $candidate, $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compare two JSON values deterministically.
	 *
	 * @param mixed $left  First value.
	 * @param mixed $right Second value.
	 */
	private function values_equal( mixed $left, mixed $right ): bool {
		return $this->value_key( $left ) === $this->value_key( $right );
	}

	/**
	 * Return a JSON-Schema-compatible canonical equality key.
	 *
	 * @param mixed $value JSON value.
	 */
	private function value_key( mixed $value ): string {
		if ( is_int( $value ) ) {
			return 'number:' . $value;
		}

		if ( is_float( $value ) ) {
			if ( 0.0 === $value || -0.0 === $value ) {
				return 'number:0';
			}

			if ( floor( $value ) === $value && $value >= PHP_INT_MIN && $value <= PHP_INT_MAX ) {
				return 'number:' . (int) $value;
			}
		}

		$encoded = ( new WorkflowPlanningCanonicalizer() )->normalize_and_encode( $value );

		return ( is_float( $value ) ? 'number:' : 'json:' ) . $encoded['json'];
	}

	/**
	 * Count UTF-8 code points without an optional PHP extension.
	 *
	 * @param string $value Valid JSON UTF-8 string.
	 */
	private function unicode_length( string $value ): int {
		$length = strlen( $value );
		$count  = 0;
		for ( $index = 0; $index < $length; ++$count ) {
			$byte = ord( $value[ $index ] );
			if ( $byte < 0x80 ) {
				++$index;
			} elseif ( $byte < 0xE0 ) {
				$index += 2;
			} elseif ( $byte < 0xF0 ) {
				$index += 3;
			} else {
				$index += 4;
			}
		}

		return $count;
	}

	/**
	 * Append one ASCII, bounded, public-safe field path.
	 *
	 * @param array  $paths Collected paths.
	 * @param string $path  Candidate path.
	 * @phpstan-param list<string> $paths
	 */
	private function record_path( array &$paths, string $path ): void {
		$bounded = preg_replace( '/[^A-Za-z0-9_$.[\]_-]/', '_', $path );
		$bounded = is_string( $bounded ) && '' !== $bounded ? $bounded : '$';

		$paths[] = substr( $bounded, 0, self::MAX_PATH_BYTES );
	}
}
