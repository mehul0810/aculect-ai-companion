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

if ( ! function_exists( 'switch_theme' ) ) {
	/**
	 * Switch the active test theme.
	 *
	 * @param string $stylesheet Theme stylesheet slug.
	 */
	function switch_theme( string $stylesheet ): void {
		$GLOBALS['aculect_ai_companion_test_active_stylesheet'] = $stylesheet;
		$GLOBALS['aculect_ai_companion_test_switched_themes'][] = $stylesheet;
	}
}
