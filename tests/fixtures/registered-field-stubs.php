<?php
/**
 * Process-isolated native scalar metadata API doubles.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

// phpcs:disable Squiz.Commenting.FunctionComment, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter -- Native signatures are preserved inside isolated registered-field test processes.

function get_registered_meta_keys( string $object_type, string $subtype = '' ): array {
	return $GLOBALS['registered_field_test_registry'][ $subtype ] ?? array();
}

function is_protected_meta( string $key, string $type ): bool {
	return str_starts_with( $key, '_' ) || 'private_field' === $key;
}

function is_serialized( mixed $value ): bool {
	return is_string( $value ) && ( 'N;' === $value || 1 === preg_match( '/^[aObisd]:/', $value ) );
}

function get_post_meta( int $id, string $key = '', bool $single = false ): mixed {
	$rows = $GLOBALS['registered_field_test_rows'][ $key ] ?? array();
	if ( array() === $rows && isset( $GLOBALS['registered_field_test_default'] ) ) {
		$rows = array( $GLOBALS['registered_field_test_default'] );
	}
	return $single ? ( $rows[0] ?? '' ) : $rows;
}

function metadata_exists( string $type, int $id, string $key ): bool {
	return array() !== ( $GLOBALS['registered_field_test_rows'][ $key ] ?? array() );
}

function update_post_meta( int $id, string $key, mixed $value, mixed $previous = '' ): bool|int {
	++$GLOBALS['registered_field_test_writes'];
	$mode = $GLOBALS['registered_field_test_write_mode'] ?? 'normal';
	if ( 'fail' === $mode ) {
		return false;
	}
	$rows = $GLOBALS['registered_field_test_rows'][ $key ] ?? array();
	if ( array( $previous ) !== $rows ) {
		return false;
	}
	$value = is_string( $value ) ? stripslashes( $value ) : $value;
	$value = sanitize_meta( $key, $value, 'post', 'post' );
	$GLOBALS['registered_field_test_rows'][ $key ] = array( 'wrong' === $mode ? 'unexpected' : ( is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value ) );
	if ( 'throw_after' === $mode ) {
		throw new \RuntimeException( 'Fixture after-write failure.' );
	}
	if ( 'inserted' === $mode ) {
		return 91;
	}
	return true;
}

function sanitize_meta( string $key, mixed $value, string $type, string $subtype = '' ): mixed {
	$callback = $GLOBALS['registered_field_test_sanitizer'] ?? null;
	return is_callable( $callback ) ? $callback( $value ) : $value;
}

function wp_slash( mixed $value ): mixed {
	return is_string( $value ) ? addslashes( $value ) : $value;
}

function rest_validate_value_from_schema( mixed $value, array $schema, string $param = '' ): bool|\WP_Error {
	if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
		return new \WP_Error( 'enum', 'Fixture enum violation.' );
	}
	if ( is_string( $value ) && ( strlen( $value ) < ( $schema['minLength'] ?? 0 ) || strlen( $value ) > ( $schema['maxLength'] ?? PHP_INT_MAX ) ) ) {
		return new \WP_Error( 'length', 'Fixture length violation.' );
	}
	if ( is_numeric( $value ) && ( $value < ( $schema['minimum'] ?? -INF ) || $value > ( $schema['maximum'] ?? INF ) ) ) {
		return new \WP_Error( 'range', 'Fixture range violation.' );
	}
	return true;
}

function rest_sanitize_value_from_schema( mixed $value, array $schema, string $param = '' ): mixed {
	return match ( $schema['type'] ) {
		'integer' => (int) $value,
		'number' => (float) $value,
		'boolean' => (bool) $value,
		default => $value,
	};
}
