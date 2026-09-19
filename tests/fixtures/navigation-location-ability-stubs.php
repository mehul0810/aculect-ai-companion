<?php
/**
 * Isolated native theme-mod write stub for navigation location tests.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress runtime stubs.
if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Return a deterministic test secret for expected-state HMACs.
	 *
	 * @param string $scheme Salt scheme.
	 */
	function wp_salt( string $scheme = 'auth' ): string {
		unset( $scheme );
		return 'navigation-location-ability-test-secret';
	}
}

if ( ! function_exists( 'get_theme_mods' ) ) {
	/**
	 * Return the raw test theme-mod option without theme-mod filters.
	 *
	 * @return array<string, mixed>|false
	 */
	function get_theme_mods(): array|false {
		$mods = $GLOBALS['aculect_ai_companion_test_theme_mods'] ?? array();
		return is_array( $mods ) ? $mods : false;
	}
}

if ( ! function_exists( 'set_theme_mod' ) ) {
	/**
	 * Record one native location-map write and apply its test outcome.
	 *
	 * @param string $name  Theme-mod name.
	 * @param mixed  $value Theme-mod value.
	 */
	function set_theme_mod( string $name, mixed $value ): void {
		$GLOBALS['aculect_ai_companion_test_theme_mod_calls'][] = array(
			'name'  => $name,
			'value' => $value,
		);
		$callback = $GLOBALS['aculect_ai_companion_test_theme_mod_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$callback( $name, $value );
			return;
		}
		if ( 'nav_menu_locations' === $name ) {
			$GLOBALS['aculect_ai_companion_test_nav_menu_locations'] = $value;
		}
		$mods = get_theme_mods();
		if ( ! is_array( $mods ) ) {
			$mods = array();
		}
		$mods[ $name ]                                   = $value;
		$GLOBALS['aculect_ai_companion_test_theme_mods'] = $mods;
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
