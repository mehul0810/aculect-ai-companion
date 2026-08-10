<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Bounded JSON Schema preparation for version-aware MCP descriptors.
 */
final class McpSchemaCompatibility {

	private const MAX_DEPTH = 16;
	private const MAX_NODES = 512;
	private const MAX_BYTES = 262144;

	private const CURRENT_DIALECT = 'https://json-schema.org/draft/2020-12/schema';

	private const SUPPORTED_VOCABULARIES = array(
		'https://json-schema.org/draft/2020-12/vocab/core',
		'https://json-schema.org/draft/2020-12/vocab/applicator',
		'https://json-schema.org/draft/2020-12/vocab/unevaluated',
		'https://json-schema.org/draft/2020-12/vocab/validation',
		'https://json-schema.org/draft/2020-12/vocab/meta-data',
		'https://json-schema.org/draft/2020-12/vocab/format-annotation',
		'https://json-schema.org/draft/2020-12/vocab/content',
	);

	private const SCHEMA_MAP_KEYWORDS = array( 'properties', 'patternProperties', '$defs', 'dependentSchemas' );

	private const SCHEMA_KEYWORDS = array( 'additionalProperties', 'unevaluatedProperties', 'propertyNames', 'items', 'unevaluatedItems', 'contains', 'contentSchema', 'not', 'if', 'then', 'else' );

	private const SCHEMA_LIST_KEYWORDS = array( 'allOf', 'anyOf', 'oneOf', 'prefixItems' );

	/**
	 * Prepare one schema for an explicit protocol without changing live output.
	 *
	 * @param mixed  $schema           Candidate JSON Schema.
	 * @param string $protocol_version Resolved protocol version.
	 * @return array{valid: true, schema: array<string, mixed>|bool|\stdClass}|array{valid: false, code: string, message: string}
	 */
	public function prepare( mixed $schema, string $protocol_version ): array {
		if ( ! McpProtocolVersion::is_known( $protocol_version ) ) {
			return $this->error( 'unsupported_protocol_version', 'The schema protocol version is not supported.' );
		}

		if ( McpProtocolVersion::LEGACY === $protocol_version ) {
			return $this->prepare_legacy( $schema );
		}

		$preflight_error = $this->json_value_error( $schema );
		if ( null !== $preflight_error ) {
			return $preflight_error;
		}

		$nodes        = 0;
		$schema_error = $this->current_schema_error( $schema, 0, $nodes );
		if ( null !== $schema_error ) {
			return $schema_error;
		}

		$encoded = wp_json_encode( $schema, JSON_PRESERVE_ZERO_FRACTION );
		if ( ! is_string( $encoded ) ) {
			return $this->error( 'invalid_schema', 'The schema must contain only finite, acyclic JSON values.' );
		}

		if ( self::MAX_BYTES < strlen( $encoded ) ) {
			return $this->error( 'schema_too_large', 'The schema exceeds the maximum encoded size.' );
		}

		return array(
			'valid'  => true,
			'schema' => $this->canonicalize_schema( $schema ),
		);
	}

	/**
	 * Preserve the exact existing schema value for legacy routing.
	 *
	 * Legacy compatibility is intentionally not subjected to current-dialect
	 * validation or canonicalization. The live transport already owns the
	 * trusted legacy descriptor boundary.
	 *
	 * @param mixed $schema Candidate schema.
	 * @return array{valid: true, schema: array<string, mixed>|bool|\stdClass}|array{valid: false, code: string, message: string}
	 */
	private function prepare_legacy( mixed $schema ): array {
		if ( ! is_array( $schema ) && ! is_bool( $schema ) && ! $schema instanceof \stdClass ) {
			return $this->error( 'legacy_schema_unsupported', 'Legacy MCP schemas must retain their existing JSON Schema representation.' );
		}

		return array(
			'valid'  => true,
			'schema' => $schema,
		);
	}

	/**
	 * Bound the complete value graph before serialization or canonicalization.
	 *
	 * @param mixed $value Candidate JSON value.
	 * @return array{valid: false, code: string, message: string}|null
	 */
	private function json_value_error( mixed $value ): ?array {
		$pending         = array( array( $value, 0, false, '' ) );
		$nodes           = 0;
		$estimated_bytes = 0;
		$seen_references = array();
		$seen_objects    = new \SplObjectStorage();

		while ( array() !== $pending ) {
			$entry    = array_pop( $pending );
			$current  = $entry[0];
			$depth    = $entry[1];
			$leaving  = $entry[2];
			$identity = $entry[3];

			if ( $leaving ) {
				if ( $current instanceof \stdClass ) {
					$seen_objects->detach( $current );
				} elseif ( '' !== $identity ) {
					unset( $seen_references[ $identity ] );
				}
				continue;
			}
			++$nodes;

			if ( self::MAX_DEPTH < $depth ) {
				return $this->error( 'schema_too_deep', 'The schema exceeds the maximum nesting depth.' );
			}

			if ( self::MAX_NODES < $nodes ) {
				return $this->error( 'schema_too_complex', 'The schema exceeds the maximum node count.' );
			}

			if ( is_string( $current ) ) {
				$estimated_bytes += strlen( $current );
				if ( self::MAX_BYTES < $estimated_bytes ) {
					return $this->error( 'schema_too_large', 'The schema exceeds the maximum encoded size.' );
				}
				continue;
			}

			if ( is_float( $current ) && ! is_finite( $current ) ) {
				return $this->error( 'invalid_schema', 'The schema must contain only finite JSON values.' );
			}

			if ( is_null( $current ) || is_bool( $current ) || is_int( $current ) || is_float( $current ) ) {
				continue;
			}

			if ( $current instanceof \stdClass ) {
				if ( $seen_objects->contains( $current ) ) {
					return $this->error( 'invalid_schema', 'The schema must not contain recursive object references.' );
				}

				$seen_objects->attach( $current );
				$pending[] = array( $current, $depth, true, '' );
				$children  = get_object_vars( $current );
			} elseif ( is_array( $current ) ) {
				if ( '' !== $identity ) {
					if ( isset( $seen_references[ $identity ] ) ) {
						return $this->error( 'invalid_schema', 'The schema must not contain recursive array references.' );
					}

					$seen_references[ $identity ] = true;
					$pending[]                    = array( $current, $depth, true, $identity );
				}
				$children = $current;
			} else {
				return $this->error( 'invalid_schema', 'The schema must contain only JSON values.' );
			}

			$queued_values = 0;
			foreach ( $pending as $pending_entry ) {
				if ( ! $pending_entry[2] ) {
					++$queued_values;
				}
			}

			if ( self::MAX_NODES < $nodes + $queued_values + count( $children ) ) {
				return $this->error( 'schema_too_complex', 'The schema exceeds the maximum node count.' );
			}

			foreach ( $children as $key => $child ) {
				$estimated_bytes += is_string( $key ) ? strlen( $key ) : 0;
				if ( self::MAX_BYTES < $estimated_bytes ) {
					return $this->error( 'schema_too_large', 'The schema exceeds the maximum encoded size.' );
				}

				$reference_id = '';
				if ( is_array( $current ) && is_array( $child ) ) {
					$reference = \ReflectionReference::fromArrayElement( $current, $key );
					if ( null !== $reference ) {
						$reference_id = bin2hex( $reference->getId() );
					}
				}

				$pending[] = array( $child, $depth + 1, false, $reference_id );
			}
		}

		return null;
	}

	/**
	 * Validate current JSON Schema structure within fixed work bounds.
	 *
	 * @param mixed $schema Schema node.
	 * @param int   $depth  Current schema depth.
	 * @param int   $nodes  Visited schema nodes.
	 * @return array{valid: false, code: string, message: string}|null
	 */
	private function current_schema_error( mixed $schema, int $depth, int &$nodes ): ?array {
		++$nodes;
		if ( self::MAX_DEPTH < $depth ) {
			return $this->error( 'schema_too_deep', 'The schema exceeds the maximum nesting depth.' );
		}

		if ( self::MAX_NODES < $nodes ) {
			return $this->error( 'schema_too_complex', 'The schema exceeds the maximum node count.' );
		}

		if ( is_bool( $schema ) ) {
			return null;
		}

		$node = $this->object_map( $schema );
		if ( null === $node ) {
			return $this->error( 'invalid_schema', 'Every schema node must be a JSON object or boolean.' );
		}

		foreach ( array( '$ref', '$dynamicRef' ) as $reference_keyword ) {
			if ( array_key_exists( $reference_keyword, $node )
				&& ( ! is_string( $node[ $reference_keyword ] ) || ! $this->valid_local_reference( $node[ $reference_keyword ] ) ) ) {
				return $this->error( 'external_schema_reference', 'External and non-local schema references are not supported.' );
			}
		}

		if ( array_key_exists( '$schema', $node ) && self::CURRENT_DIALECT !== $node['$schema'] ) {
			return $this->error( 'unsupported_schema_dialect', 'Only JSON Schema 2020-12 is supported for current MCP clients.' );
		}

		if ( array_key_exists( 'type', $node ) && ! $this->valid_type( $node['type'] ) ) {
			return $this->error( 'invalid_schema', 'Schema types must use supported JSON Schema primitive names.' );
		}

		foreach ( array( '$schema', '$id', '$anchor', '$dynamicAnchor', '$comment', 'title', 'description', 'pattern', 'format', 'contentEncoding', 'contentMediaType' ) as $string_keyword ) {
			if ( array_key_exists( $string_keyword, $node ) && ! is_string( $node[ $string_keyword ] ) ) {
				return $this->error( 'invalid_schema', 'Schema string constraints must be strings.' );
			}
		}

		if ( isset( $node['pattern'] ) && ! $this->valid_pattern( $node['pattern'] ) ) {
			return $this->error( 'invalid_schema', 'Schema patterns must be valid regular expressions.' );
		}

		if ( isset( $node['$id'] ) && ! $this->valid_uri_reference( $node['$id'], false ) ) {
			return $this->error( 'invalid_schema', 'Schema identifiers must be fragment-free URI references.' );
		}

		foreach ( array( '$anchor', '$dynamicAnchor' ) as $anchor_keyword ) {
			if ( isset( $node[ $anchor_keyword ] ) && ! $this->valid_anchor( $node[ $anchor_keyword ] ) ) {
				return $this->error( 'invalid_schema', 'Schema anchors must use valid plain-name syntax.' );
			}
		}

		if ( array_key_exists( 'enum', $node ) && ! $this->valid_enum( $node['enum'] ) ) {
			return $this->error( 'invalid_schema', 'Schema enums must be non-empty lists of unique JSON values.' );
		}

		if ( array_key_exists( 'examples', $node ) && ( ! is_array( $node['examples'] ) || ! array_is_list( $node['examples'] ) ) ) {
			return $this->error( 'invalid_schema', 'Schema examples must be a JSON list.' );
		}

		foreach ( array( 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf' ) as $numeric_keyword ) {
			if ( array_key_exists( $numeric_keyword, $node ) && ! is_int( $node[ $numeric_keyword ] ) && ! is_float( $node[ $numeric_keyword ] ) ) {
				return $this->error( 'invalid_schema', 'Schema numeric constraints must be numbers.' );
			}
		}

		if ( array_key_exists( 'multipleOf', $node ) && 0 >= $node['multipleOf'] ) {
			return $this->error( 'invalid_schema', 'Schema multipleOf must be greater than zero.' );
		}

		foreach ( array( 'minLength', 'maxLength', 'minItems', 'maxItems', 'minContains', 'maxContains', 'minProperties', 'maxProperties' ) as $count_keyword ) {
			if ( array_key_exists( $count_keyword, $node ) && ( ! is_int( $node[ $count_keyword ] ) || 0 > $node[ $count_keyword ] ) ) {
				return $this->error( 'invalid_schema', 'Schema count constraints must be non-negative integers.' );
			}
		}

		foreach ( array( 'uniqueItems', 'readOnly', 'writeOnly', 'deprecated' ) as $boolean_keyword ) {
			if ( array_key_exists( $boolean_keyword, $node ) && ! is_bool( $node[ $boolean_keyword ] ) ) {
				return $this->error( 'invalid_schema', 'Schema boolean constraints must be booleans.' );
			}
		}

		if ( array_key_exists( 'required', $node ) && ! $this->valid_string_list( $node['required'] ) ) {
			return $this->error( 'invalid_schema', 'Schema required values must be a unique list of property names.' );
		}

		$dependent_required = array_key_exists( 'dependentRequired', $node ) ? $this->object_entries( $node['dependentRequired'] ) : array();
		if ( array_key_exists( 'dependentRequired', $node ) && null === $dependent_required ) {
			return $this->error( 'invalid_schema', 'Schema dependentRequired must be a JSON object.' );
		}

		foreach ( $dependent_required ?? array() as $entry ) {
			$name       = $entry['key'];
			$dependency = $entry['value'];
			if ( ! is_string( $name ) || ! $this->valid_string_list( $dependency ) ) {
				return $this->error( 'invalid_schema', 'Schema dependencies must be unique property-name lists.' );
			}
		}

		$vocabulary = array_key_exists( '$vocabulary', $node ) ? $this->object_entries( $node['$vocabulary'] ) : array();
		if ( array_key_exists( '$vocabulary', $node ) && null === $vocabulary ) {
			return $this->error( 'invalid_schema', 'Schema vocabulary declarations must be a JSON object.' );
		}

		foreach ( $vocabulary ?? array() as $entry ) {
			$uri      = $entry['key'];
			$required = $entry['value'];
			if ( ! is_string( $uri ) || ! $this->valid_absolute_uri( $uri ) || ! is_bool( $required ) ) {
				return $this->error( 'invalid_schema', 'Schema vocabulary declarations must use boolean values.' );
			}

			if ( $required && ! in_array( $uri, self::SUPPORTED_VOCABULARIES, true ) ) {
				return $this->error( 'unsupported_schema_vocabulary', 'A required JSON Schema vocabulary is not supported.' );
			}
		}

		foreach ( self::SCHEMA_MAP_KEYWORDS as $map_keyword ) {
			if ( ! array_key_exists( $map_keyword, $node ) ) {
				continue;
			}

			$children = $this->object_entries( $node[ $map_keyword ] );
			if ( null === $children ) {
				return $this->error( 'invalid_schema', 'Schema maps must be JSON objects.' );
			}

			foreach ( $children as $entry ) {
				$name  = $entry['key'];
				$child = $entry['value'];

				if ( 'patternProperties' === $map_keyword && ! $this->valid_pattern( $name ) ) {
					return $this->error( 'invalid_schema', 'Schema property patterns must be valid regular expressions.' );
				}

				$error = $this->current_schema_error( $child, $depth + 1, $nodes );
				if ( null !== $error ) {
					return $error;
				}
			}
		}

		foreach ( self::SCHEMA_KEYWORDS as $schema_keyword ) {
			if ( ! array_key_exists( $schema_keyword, $node ) ) {
				continue;
			}

			$error = $this->current_schema_error( $node[ $schema_keyword ], $depth + 1, $nodes );
			if ( null !== $error ) {
				return $error;
			}
		}

		foreach ( self::SCHEMA_LIST_KEYWORDS as $list_keyword ) {
			if ( ! array_key_exists( $list_keyword, $node ) ) {
				continue;
			}

			if ( ! is_array( $node[ $list_keyword ] ) || ! array_is_list( $node[ $list_keyword ] ) || array() === $node[ $list_keyword ] ) {
				return $this->error( 'invalid_schema', 'Schema composition keywords must contain a non-empty list of schemas.' );
			}

			foreach ( $node[ $list_keyword ] as $child ) {
				$error = $this->current_schema_error( $child, $depth + 1, $nodes );
				if ( null !== $error ) {
					return $error;
				}
			}
		}

		return null;
	}

	/**
	 * Convert a PHP JSON-object representation to an array for inspection.
	 *
	 * @param mixed $value Candidate JSON object.
	 * @return array<string, mixed>|null
	 */
	private function object_map( mixed $value ): ?array {
		if ( $value instanceof \stdClass ) {
			return get_object_vars( $value );
		}

		if ( is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ) ) {
			return $value;
		}

		return null;
	}

	/**
	 * Return JSON-object entries without coercing numeric-looking names.
	 *
	 * @param mixed $value Candidate JSON object.
	 * @return list<array{key: string, value: mixed}>|null
	 */
	private function object_entries( mixed $value ): ?array {
		$entries = array();
		if ( $value instanceof \stdClass ) {
			/**
			 * Direct object iteration preserves numeric-looking property names.
			 *
			 * @var iterable<string, mixed> $properties
			 */
			$properties = $value;
			foreach ( $properties as $key => $child ) {
				$entries[] = array(
					'key'   => $key,
					'value' => $child,
				);
			}

			return $entries;
		}

		if ( ! is_array( $value ) || ( array() !== $value && array_is_list( $value ) ) ) {
			return null;
		}

		foreach ( $value as $key => $child ) {
			if ( ! is_string( $key ) ) {
				return null;
			}

			$entries[] = array(
				'key'   => $key,
				'value' => $child,
			);
		}

		return $entries;
	}

	/**
	 * Check a JSON Schema type declaration.
	 *
	 * @param mixed $type Type declaration.
	 */
	private function valid_type( mixed $type ): bool {
		$types = is_string( $type ) ? array( $type ) : $type;
		if ( ! is_array( $types ) || ! array_is_list( $types ) || array() === $types ) {
			return false;
		}

		$allowed = array( 'null', 'boolean', 'object', 'array', 'number', 'integer', 'string' );
		return count( $types ) === count( array_unique( $types ) ) && array() === array_diff( $types, $allowed );
	}

	/**
	 * Check a non-empty enum contains semantically unique JSON values.
	 *
	 * @param mixed $enum Enum declaration.
	 */
	private function valid_enum( mixed $enum ): bool {
		if ( ! is_array( $enum ) || ! array_is_list( $enum ) || array() === $enum ) {
			return false;
		}

		$keys = array();
		foreach ( $enum as $value ) {
			$key = $this->value_key( $value );
			if ( isset( $keys[ $key ] ) ) {
				return false;
			}

			$keys[ $key ] = true;
		}

		return true;
	}

	/**
	 * Check a JSON list contains unique non-empty strings.
	 *
	 * @param mixed $values Candidate list.
	 */
	private function valid_string_list( mixed $values ): bool {
		if ( ! is_array( $values ) || ! array_is_list( $values ) ) {
			return false;
		}

		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				return false;
			}
		}

		return count( $values ) === count( array_unique( $values ) );
	}

	/**
	 * Build an equality key for one already JSON-encodable enum value.
	 *
	 * @param mixed $value JSON value.
	 */
	private function value_key( mixed $value ): string {
		if ( is_int( $value ) ) {
			return 'number:' . (string) $value;
		}

		if ( is_float( $value ) ) {
			if ( 0.0 === $value ) {
				return 'number:0';
			}

			return 'number:' . ( floor( $value ) === $value ? sprintf( '%.0f', $value ) : sprintf( '%.17g', $value ) );
		}

		$encoded = wp_json_encode( $this->canonicalize_json_value( $value ), JSON_PRESERVE_ZERO_FRACTION );

		return 'json:' . ( is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * Canonicalize one validated schema while preserving JSON object types.
	 *
	 * @param mixed $schema Schema node.
	 */
	private function canonicalize_schema( mixed $schema ): mixed {
		if ( is_bool( $schema ) ) {
			return $schema;
		}

		$entries = $this->sorted_object_entries( $schema );
		if ( array() === $entries ) {
			return new \stdClass();
		}

		$node = new \stdClass();
		foreach ( $entries as $entry ) {
			$key   = $entry['key'];
			$value = $entry['value'];
			if ( in_array( $key, self::SCHEMA_MAP_KEYWORDS, true ) ) {
				$map_entries = $this->sorted_object_entries( $value );
				if ( array() === $map_entries ) {
					$node->{$key} = new \stdClass();
					continue;
				}

				$map = new \stdClass();
				foreach ( $map_entries as $map_entry ) {
					$map->{$map_entry['key']} = $this->canonicalize_schema( $map_entry['value'] );
				}
				$node->{$key} = $map;
				continue;
			}

			if ( in_array( $key, self::SCHEMA_KEYWORDS, true ) ) {
				$node->{$key} = $this->canonicalize_schema( $value );
				continue;
			}

			if ( in_array( $key, self::SCHEMA_LIST_KEYWORDS, true ) ) {
				$node->{$key} = array_map( array( $this, 'canonicalize_schema' ), $value );
				continue;
			}

			if ( in_array( $key, array( 'dependentRequired', '$vocabulary' ), true ) ) {
				$map_entries = $this->sorted_object_entries( $value );
				if ( array() === $map_entries ) {
					$node->{$key} = new \stdClass();
					continue;
				}

				$map = new \stdClass();
				foreach ( $map_entries as $map_entry ) {
					$map->{$map_entry['key']} = $this->canonicalize_json_value( $map_entry['value'] );
				}
				$node->{$key} = $map;
				continue;
			}

			$node->{$key} = $this->canonicalize_json_value( $value );
		}

		return $schema instanceof \stdClass ? $node : (array) $node;
	}

	/**
	 * Canonicalize an already bounded literal JSON value.
	 *
	 * @param mixed $value JSON value.
	 */
	private function canonicalize_json_value( mixed $value ): mixed {
		if ( $value instanceof \stdClass ) {
			$result = new \stdClass();
			foreach ( $this->sorted_object_entries( $value ) as $entry ) {
				$result->{$entry['key']} = $this->canonicalize_json_value( $entry['value'] );
			}

			return $result;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_is_list( $value ) ) {
			return array_map( array( $this, 'canonicalize_json_value' ), $value );
		}

		ksort( $value, SORT_STRING );
		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->canonicalize_json_value( $child );
		}

		return $value;
	}

	/**
	 * Sort JSON-object entries without coercing their names.
	 *
	 * @param mixed $value JSON object.
	 * @return list<array{key: string, value: mixed}>
	 */
	private function sorted_object_entries( mixed $value ): array {
		$entries = $this->object_entries( $value ) ?? array();
		usort(
			$entries,
			static fn ( array $left, array $right ): int => strcmp( $left['key'], $right['key'] )
		);

		return $entries;
	}

	/**
	 * Validate a fragment-free RFC 3986 URI reference.
	 *
	 * @param string $value          URI reference.
	 * @param bool   $allow_fragment Whether a fragment is permitted.
	 */
	private function valid_uri_reference( string $value, bool $allow_fragment ): bool {
		if ( '' === $value || 1 === preg_match( '/[\x00-\x20\x7F]/', $value ) || 1 === preg_match( '/%(?![0-9A-Fa-f]{2})/', $value ) ) {
			return false;
		}

		if ( ! $allow_fragment && str_contains( $value, '#' ) ) {
			return false;
		}

		if ( 1 !== preg_match( "/^[A-Za-z0-9._~!$&'()*+,;=:@\/?%#\[\]\-]*$/D", $value ) ) {
			return false;
		}

		$first_delimiter = strcspn( $value, '/?#' );
		$colon           = strpos( $value, ':' );
		if ( false !== $colon && $colon < $first_delimiter ) {
			$scheme = substr( $value, 0, $colon );
			if ( 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9+.\-]*$/D', $scheme ) ) {
				return false;
			}
		}

		$parsed = wp_parse_url( $value );
		if ( false === $parsed ) {
			return false;
		}

		if ( str_contains( $value, '[' ) || str_contains( $value, ']' ) ) {
			$host = $parsed['host'] ?? '';
			if ( 1 !== substr_count( $value, '[' )
				|| 1 !== substr_count( $value, ']' )
				|| 2 > strlen( $host )
				|| '[' !== $host[0]
				|| ']' !== $host[ strlen( $host ) - 1 ]
				|| false === strpos( $value, $host ) ) {
				return false;
			}

			$ip_literal = substr( $host, 1, -1 );
			$is_ipv6    = false !== filter_var( $ip_literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
			$is_future  = 1 === preg_match( "/^[vV][0-9A-Fa-f]+\.[A-Za-z0-9._~!$&'()*+,;=:\-]+$/D", $ip_literal );
			if ( ! $is_ipv6 && ! $is_future ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate an absolute fragment-free vocabulary URI.
	 *
	 * @param string $value URI.
	 */
	private function valid_absolute_uri( string $value ): bool {
		return ! str_contains( $value, '#' )
			&& 1 === preg_match( '/^[A-Za-z][A-Za-z0-9+.\-]*:/D', $value )
			&& $this->valid_uri_reference( $value, false );
	}

	/**
	 * Validate one local JSON Pointer or plain-name reference.
	 *
	 * @param string $value Local reference.
	 */
	private function valid_local_reference( string $value ): bool {
		if ( ! str_starts_with( $value, '#' ) || 1 !== substr_count( $value, '#' ) || ! $this->valid_uri_reference( $value, true ) ) {
			return false;
		}

		$fragment = rawurldecode( substr( $value, 1 ) );
		if ( '' === $fragment ) {
			return true;
		}

		if ( str_starts_with( $fragment, '/' ) ) {
			return 0 === preg_match( '/~(?![01])/', $fragment );
		}

		return $this->valid_anchor( $fragment );
	}

	/**
	 * Validate a JSON Schema plain-name anchor.
	 *
	 * @param string $value Anchor.
	 */
	private function valid_anchor( string $value ): bool {
		return 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9._\-]*$/D', $value );
	}

	/**
	 * Validate a JSON Schema regular expression without exposing its value.
	 *
	 * @param string $pattern Pattern.
	 */
	private function valid_pattern( string $pattern ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid client-supplied patterns must fail closed without emitting warnings.
		return false !== @preg_match( '~' . str_replace( '~', '\\~', $pattern ) . '~u', '' );
	}

	/**
	 * Build one public-safe validation error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return array{valid: false, code: string, message: string}
	 */
	private function error( string $code, string $message ): array {
		return array(
			'valid'   => false,
			'code'    => $code,
			'message' => $message,
		);
	}
}
