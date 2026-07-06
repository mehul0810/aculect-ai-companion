<?php
/**
 * WordPress theme lifecycle test doubles.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress test doubles for the ThemeLifecycleAbilities unit test.

if ( ! function_exists( 'is_network_admin' ) ) {
	/**
	 * Return whether the current test request is network-admin scoped.
	 */
	function is_network_admin(): bool {
		return (bool) ( $GLOBALS['aculect_ai_companion_test_network_admin'] ?? false );
	}
}
