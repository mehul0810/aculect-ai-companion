<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use WP_REST_Request;

/**
 * Enforces bounded MCP request and tool-argument contracts before execution.
 */
final class McpInputValidator {

	private const MAX_BODY_BYTES         = 16000000;
	private const MAX_ARGUMENT_DEPTH     = 16;
	private const MAX_ARGUMENT_NODES     = 5000;
	private const MAX_ARGUMENT_KEY_BYTES = 128;
	private const MAX_COLLECTION_ITEMS   = 1000;
	private const MAX_ERROR_PATH_BYTES   = 512;
	private const MAX_STRING_BYTES       = 15000000;

	/**
	 * Return the raw request-body ceiling.
	 */
	public static function max_body_bytes(): int {
		return self::MAX_BODY_BYTES;
	}

	/**
	 * Reject an oversized request before WordPress decodes its JSON body.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array{code: string, message: string}|null
	 */
	public function request_error( WP_REST_Request $request ): ?array {
		$content_length = trim( (string) $request->get_header( 'content-length' ) );
		if ( '' !== $content_length && ctype_digit( $content_length ) && (int) $content_length > self::MAX_BODY_BYTES ) {
			return $this->error( 'request_body_too_large', 'MCP request body exceeds the supported size limit.' );
		}

		$body = $request->get_body();
		if ( strlen( $body ) > self::MAX_BODY_BYTES ) {
			return $this->error( 'request_body_too_large', 'MCP request body exceeds the supported size limit.' );
		}

		return null;
	}

	/**
	 * Validate tool arguments against global budgets and the advertised schema.
	 *
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param array<string, mixed> $schema    Tool input schema.
	 * @return array{code: string, message: string}|null
	 */
	public function arguments_error( array $arguments, array $schema ): ?array {
		$nodes = 0;
		$error = $this->budget_error( $arguments, 0, $nodes );
		if ( null !== $error ) {
			return $error;
		}

		return $this->schema_error( $arguments, $schema, 'arguments' );
	}

	/**
	 * Enforce request-wide depth, node, collection, and string budgets.
	 *
	 * @param mixed $value Value to inspect.
	 * @param int   $depth Current nesting depth.
	 * @param int   $nodes Running node count.
	 * @return array{code: string, message: string}|null
	 */
	private function budget_error( mixed $value, int $depth, int &$nodes ): ?array {
		if ( $depth > self::MAX_ARGUMENT_DEPTH ) {
			return $this->error( 'argument_depth_exceeded', 'Tool arguments exceed the supported nesting depth.' );
		}

		++$nodes;
		if ( $nodes > self::MAX_ARGUMENT_NODES ) {
			return $this->error( 'argument_complexity_exceeded', 'Tool arguments exceed the supported complexity limit.' );
		}

		if ( is_string( $value ) && strlen( $value ) > self::MAX_STRING_BYTES ) {
			return $this->error( 'argument_string_too_large', 'A tool argument exceeds the supported string size.' );
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( count( $value ) > self::MAX_COLLECTION_ITEMS ) {
			return $this->error( 'argument_collection_too_large', 'A tool argument collection exceeds the supported item limit.' );
		}

		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && strlen( $key ) > self::MAX_ARGUMENT_KEY_BYTES ) {
				return $this->error( 'argument_key_too_large', 'A tool argument property name exceeds the supported size.' );
			}

			++$nodes;
			if ( $nodes > self::MAX_ARGUMENT_NODES ) {
				return $this->error( 'argument_complexity_exceeded', 'Tool arguments exceed the supported complexity limit.' );
			}

			$error = $this->budget_error( $item, $depth + 1, $nodes );
			if ( null !== $error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Validate the JSON-schema subset used by first-party MCP tools.
	 *
	 * @param mixed                $value  Value to validate.
	 * @param array<string, mixed> $schema Input schema.
	 * @param string               $path   Client-safe argument path.
	 * @return array{code: string, message: string}|null
	 */
	private function schema_error( mixed $value, array $schema, string $path ): ?array {
		$types = $this->schema_types( $schema );
		if ( array() !== $types && ! $this->matches_any_type( $value, $types ) ) {
			return $this->error( 'invalid_argument_type', sprintf( '%s has an invalid type.', $path ) );
		}

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return $this->error( 'invalid_argument_value', sprintf( '%s is not an allowed value.', $path ) );
		}

		if ( is_string( $value ) ) {
			$length = strlen( $value );
			if ( isset( $schema['minLength'] ) && $length < (int) $schema['minLength'] ) {
				return $this->error( 'argument_string_too_short', sprintf( '%s is shorter than the supported minimum.', $path ) );
			}
			if ( isset( $schema['maxLength'] ) && $length > (int) $schema['maxLength'] ) {
				return $this->error( 'argument_string_too_large', sprintf( '%s exceeds the supported string size.', $path ) );
			}
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
				return $this->error( 'argument_number_too_small', sprintf( '%s is below the supported minimum.', $path ) );
			}
			if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
				return $this->error( 'argument_number_too_large', sprintf( '%s exceeds the supported maximum.', $path ) );
			}
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( $this->schema_accepts_type( $types, 'array' ) && array_is_list( $value ) ) {
			$count = count( $value );
			if ( isset( $schema['minItems'] ) && $count < (int) $schema['minItems'] ) {
				return $this->error( 'argument_collection_too_small', sprintf( '%s contains too few items.', $path ) );
			}
			if ( isset( $schema['maxItems'] ) && $count > (int) $schema['maxItems'] ) {
				return $this->error( 'argument_collection_too_large', sprintf( '%s contains too many items.', $path ) );
			}

			$item_schema = isset( $schema['items'] ) && is_array( $schema['items'] ) ? $schema['items'] : array();
			foreach ( $value as $index => $item ) {
				$error = $this->schema_error( $item, $item_schema, $this->child_path( $path, (string) $index, true ) );
				if ( null !== $error ) {
					return $error;
				}
			}

			return null;
		}

		if ( ! $this->schema_accepts_type( $types, 'object' ) ) {
			return null;
		}

		$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
		foreach ( $required as $required_key ) {
			if ( is_string( $required_key ) && ! array_key_exists( $required_key, $value ) ) {
				return $this->error( 'missing_required_argument', sprintf( '%s is required.', $this->child_path( $path, $required_key ) ) );
			}
		}

		$properties            = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$additional_properties = $schema['additionalProperties'] ?? true;
		foreach ( $value as $key => $item ) {
			$key = (string) $key;
			if ( isset( $properties[ $key ] ) && is_array( $properties[ $key ] ) ) {
				$error = $this->schema_error( $item, $properties[ $key ], $this->child_path( $path, $key ) );
			} elseif ( false === $additional_properties ) {
				return $this->error( 'unexpected_argument', 'Tool arguments contain an unsupported property.' );
			} elseif ( is_array( $additional_properties ) ) {
				$error = $this->schema_error( $item, $additional_properties, $this->child_path( $path, $key ) );
			} else {
				$error = null;
			}

			if ( null !== $error ) {
				return $error;
			}
		}

		return null;
	}

	/**
	 * Build a bounded client-safe argument path.
	 *
	 * @param string $path     Parent path.
	 * @param string $segment  Child property or index.
	 * @param bool   $is_index Whether the segment is an array index.
	 */
	private function child_path( string $path, string $segment, bool $is_index = false ): string {
		$child = $is_index ? $path . '[' . $segment . ']' : $path . '.' . $segment;
		if ( strlen( $child ) <= self::MAX_ERROR_PATH_BYTES ) {
			return $child;
		}

		return substr( $child, 0, self::MAX_ERROR_PATH_BYTES - 3 ) . '...';
	}

	/**
	 * Return normalized schema types.
	 *
	 * @param array<string, mixed> $schema Input schema.
	 * @return list<string>
	 */
	private function schema_types( array $schema ): array {
		$type = $schema['type'] ?? null;
		if ( is_string( $type ) ) {
			return array( $type );
		}

		if ( ! is_array( $type ) ) {
			return array();
		}

		return array_values( array_filter( $type, 'is_string' ) );
	}

	/**
	 * Check whether a value matches any advertised type.
	 *
	 * @param mixed $value Value to inspect.
	 * @param array $types Schema types.
	 * @phpstan-param list<string> $types
	 */
	private function matches_any_type( mixed $value, array $types ): bool {
		foreach ( $types as $type ) {
			if ( $this->matches_type( $value, $type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check one JSON-schema type.
	 *
	 * @param mixed  $value Value to inspect.
	 * @param string $type  Schema type.
	 */
	private function matches_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'null' => null === $value,
			'boolean' => is_bool( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || is_float( $value ),
			'string' => is_string( $value ),
			'array' => is_array( $value ) && array_is_list( $value ),
			'object' => is_array( $value ) && ( array() === $value || ! array_is_list( $value ) ),
			default => false,
		};
	}

	/**
	 * Check whether a schema includes one type.
	 *
	 * @param array  $types Schema types.
	 * @param string $type  Expected type.
	 * @phpstan-param list<string> $types
	 */
	private function schema_accepts_type( array $types, string $type ): bool {
		return array() === $types || in_array( $type, $types, true );
	}

	/**
	 * Build a stable client-safe validation error.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Client-safe error message.
	 * @return array{code: string, message: string}
	 */
	private function error( string $code, string $message ): array {
		return array(
			'code'    => $code,
			'message' => $message,
		);
	}
}
