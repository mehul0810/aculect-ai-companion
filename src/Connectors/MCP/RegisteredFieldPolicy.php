<?php
/**
 * Scalar registered metadata exposure policy.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Keeps remote field access narrower than the native metadata registry. */
final class RegisteredFieldPolicy {

	/**
	 * Resolve bounded, editor-visible scalar schemas without exposing callbacks.
	 *
	 * @param string $post_type Post subtype.
	 * @return array<string, array<string, mixed>>
	 */
	public function schemas( string $post_type ): array {
		$registered = array_merge( get_registered_meta_keys( 'post' ), get_registered_meta_keys( 'post', $post_type ) );
		if ( count( $registered ) > 1000 ) {
			return array();
		}
		$schemas = array();
		foreach ( $registered as $key => $registration ) {
			if ( ! is_string( $key ) || ! $this->valid_key( $key ) || ! is_array( $registration ) ) {
				continue;
			}
			$schema = $this->schema( $registration );
			if ( null !== $schema ) {
				$rest_name = is_array( $registration['show_in_rest'] ) ? ( $registration['show_in_rest']['name'] ?? $key ) : $key;
				if ( ! is_string( $rest_name ) || '' === $rest_name || strlen( $rest_name ) > 191 || 1 !== preg_match( '/^[a-zA-Z0-9_.-]+$/D', $rest_name ) ) {
					continue;
				}
				$schema['x-rest-name']   = $rest_name;
				$schema['x-native-type'] = $registration['type'];
				$schemas[ $key ]         = $schema;
			}
		}
		ksort( $schemas );
		return $schemas;
	}

	public function valid_key( string $key ): bool {
		return '' !== $key && strlen( $key ) <= 191 && '_' !== $key[0]
			&& 1 === preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/D', $key )
			&& ! is_protected_meta( $key, 'post' );
	}

	/**
	 * Resolve the same REST override source used by native registered metadata.
	 *
	 * @param array<string, mixed> $registration Native registration.
	 * @return array<string, mixed>|null
	 */
	private function schema( array $registration ): ?array {
		$rest = $registration['show_in_rest'] ?? false;
		$type = $registration['type'] ?? '';
		if ( true !== ( $registration['single'] ?? false ) || ! in_array( $type, array( 'string', 'integer', 'number', 'boolean' ), true ) || ( true !== $rest && ! is_array( $rest ) ) ) {
			return null;
		}
		$override = is_array( $rest ) ? ( $rest['schema'] ?? array() ) : array();
		if ( ! is_array( $override ) ) {
			return null;
		}
		$schema = array_merge(
			array(
				'type'    => $type,
				'context' => array( 'view', 'edit' ),
			),
			$override
		);
		if ( ! in_array( $schema['type'], array( 'string', 'integer', 'number', 'boolean' ), true ) || ! is_array( $schema['context'] ) || ! in_array( 'edit', $schema['context'], true ) ) {
			return null;
		}
		if ( array_intersect( array_keys( $schema ), array( 'oneOf', 'anyOf', 'allOf', 'not', '$ref', 'properties', 'items', 'additionalProperties' ) ) ) {
			return null;
		}
		$allowed = array( 'type', 'context', 'enum', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'pattern', 'format', 'readonly' );
		$schema  = array_intersect_key( $schema, array_flip( $allowed ) );
		foreach ( $schema as $name => $value ) {
			if ( in_array( $name, array( 'context', 'enum' ), true ) ) {
				if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > 100 || array_filter( $value, fn ( mixed $item ): bool => ! $this->bounded_scalar( $item ) ) ) {
					return null;
				}
			} elseif ( ! $this->bounded_scalar( $value ) ) {
				return null;
			}
		}
		if ( ! $this->valid_constraints( $schema ) ) {
			return null;
		}
		ksort( $schema );
		return $schema;
	}

	/**
	 * Reject malformed constraints before they reach native REST helpers.
	 *
	 * @param array<string, mixed> $schema Effective schema.
	 */
	private function valid_constraints( array $schema ): bool {
		foreach ( $schema as $key => $value ) {
			$valid = match ( $key ) {
				'minLength', 'maxLength' => is_int( $value ) && $value >= 0,
				'minimum', 'maximum' => is_int( $value ) || is_float( $value ),
				'multipleOf' => ( is_int( $value ) || is_float( $value ) ) && $value > 0,
				'exclusiveMinimum', 'exclusiveMaximum', 'readonly' => is_bool( $value ),
				'pattern', 'format' => is_string( $value ),
				'context' => array() === array_filter( $value, static fn ( mixed $item ): bool => ! is_string( $item ) ),
				default => true,
			};
			if ( ! $valid ) {
				return false;
			}
		}
		return true;
	}

	public function bounded_scalar( mixed $value ): bool {
		return ( is_string( $value ) && strlen( $value ) <= 4000 && 1 === preg_match( '//u', $value ) && ! is_serialized( $value ) )
			|| is_int( $value ) || is_bool( $value ) || ( is_float( $value ) && is_finite( $value ) );
	}

	/**
	 * Normalize native storage strings while preserving the declared scalar type.
	 *
	 * @param mixed                $value  Native value.
	 * @param array<string, mixed> $schema Effective REST schema.
	 * @return array<string, mixed>
	 */
	public function normalize( mixed $value, array $schema ): array {
		if ( ! $this->bounded_scalar( $value ) ) {
			return array( 'error' => 'invalid_field_value' );
		}
		if ( is_string( $value ) && 'boolean' === $schema['type'] && in_array( $value, array( '', '0', '1' ), true ) ) {
			$value = '1' === $value;
		} elseif ( is_string( $value ) && 'integer' === $schema['type'] && false !== filter_var( $value, FILTER_VALIDATE_INT ) ) {
			$value = (int) $value;
		} elseif ( is_string( $value ) && 'number' === $schema['type'] && is_numeric( $value ) ) {
			$value = (float) $value;
		}
		return $this->validate( $value, $schema );
	}

	/**
	 * Validate strict scalar types before delegating constraints to WordPress.
	 *
	 * @param mixed                $value  Candidate scalar.
	 * @param array<string, mixed> $schema Effective REST schema.
	 * @return array<string, mixed>
	 */
	public function validate( mixed $value, array $schema ): array {
		$typed = match ( $schema['type'] ) {
			'string' => is_string( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			default => false,
		};
		if ( ! $typed || ! $this->bounded_scalar( $value ) || is_wp_error( rest_validate_value_from_schema( $value, $schema, 'value' ) ) ) {
			return array( 'error' => 'invalid_field_value' );
		}
		return array( 'value' => $value );
	}
}
