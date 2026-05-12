<?php
/**
 * PHPUnit bootstrap for fast, WordPress-light unit tests.
 *
 * These stubs intentionally cover only the WordPress APIs used by the current
 * unit tests. Integration tests should load a real WordPress test environment
 * instead of extending this file.
 *
 * @package Quark\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	if ( 'cli' !== PHP_SAPI ) {
		exit;
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'QUARK_VERSION' ) ) {
	define( 'QUARK_VERSION', '0.1.0' );
}

$GLOBALS['quark_test_options'] = array();

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
		return array_key_exists( $option, $GLOBALS['quark_test_options'] ) ? $GLOBALS['quark_test_options'][ $option ] : $default;
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

		$GLOBALS['quark_test_options'][ $option ] = $value;

		return true;
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames
