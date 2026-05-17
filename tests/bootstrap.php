<?php
/**
 * PHPUnit bootstrap for fast, WordPress-light unit tests.
 *
 * These stubs intentionally cover only the WordPress APIs used by the current
 * unit tests. Integration tests should load a real WordPress test environment
 * instead of extending this file.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	if ( 'cli' !== PHP_SAPI ) {
		exit;
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ACULECT_AI_COMPANION_VERSION' ) ) {
	define( 'ACULECT_AI_COMPANION_VERSION', '0.1.0' );
}

$GLOBALS['aculect_ai_companion_test_options'] = array();

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames -- PHPUnit bootstrap stubs WordPress core functions.
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Return a test option value.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( string $option, mixed $default = false ): mixed {
		return array_key_exists( $option, $GLOBALS['aculect_ai_companion_test_options'] ) ? $GLOBALS['aculect_ai_companion_test_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Store a test option value.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Option value.
	 * @param mixed  $autoload Autoload flag.
	 * @return bool
	 */
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		unset( $autoload );

		$GLOBALS['aculect_ai_companion_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Delete a test option value.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( string $option ): bool {
		unset( $GLOBALS['aculect_ai_companion_test_options'][ $option ] );

		return true;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Return a non-negative integer.
	 *
	 * @param mixed $maybeint Raw value.
	 */
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Sanitize a key-like string.
	 *
	 * @param string $key Raw key.
	 */
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Sanitize text similarly enough for unit tests.
	 *
	 * @param string $str Raw text.
	 */
	function sanitize_text_field( string $str ): string {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $str ) ) ?? '' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Sanitize a URL for tests.
	 *
	 * @param string $url Raw URL.
	 */
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Parse a URL.
	 *
	 * @param string $url       Raw URL.
	 * @param int    $component Optional parse component.
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encode JSON.
	 *
	 * @param mixed $data    Data to encode.
	 * @param int   $options JSON options.
	 * @param int   $depth   Max depth.
	 * @return string|false
	 */
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Strip slashes from a value.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	function wp_unslash( mixed $value ): mixed {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames
