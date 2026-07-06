<?php
/**
 * WordPress plugin lifecycle test doubles.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress test doubles for the PluginLifecycleAbilities unit test.

if ( ! function_exists( 'get_plugins' ) ) {
	/**
	 * Return installed test plugin headers.
	 *
	 * @return array<string, array<string, string>>
	 */
	function get_plugins(): array {
		return $GLOBALS['aculect_ai_companion_test_plugins'] ?? array();
	}
}

if ( ! function_exists( 'is_plugin_active' ) ) {
	/**
	 * Return whether a test plugin is active on the current site or network.
	 *
	 * @param string $plugin Plugin basename.
	 */
	function is_plugin_active( string $plugin ): bool {
		$active  = $GLOBALS['aculect_ai_companion_test_active_plugins'] ?? array();
		$network = $GLOBALS['aculect_ai_companion_test_network_active_plugins'] ?? array();

		return in_array( $plugin, is_array( $active ) ? $active : array(), true )
			|| in_array( $plugin, is_array( $network ) ? $network : array(), true );
	}
}

if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
	/**
	 * Return whether a test plugin is network active.
	 *
	 * @param string $plugin Plugin basename.
	 */
	function is_plugin_active_for_network( string $plugin ): bool {
		$network = $GLOBALS['aculect_ai_companion_test_network_active_plugins'] ?? array();

		return in_array( $plugin, is_array( $network ) ? $network : array(), true );
	}
}

if ( ! function_exists( 'wp_is_recovery_mode' ) ) {
	/**
	 * Return whether recovery mode is active in tests.
	 */
	function wp_is_recovery_mode(): bool {
		return (bool) ( $GLOBALS['aculect_ai_companion_test_recovery_mode'] ?? false );
	}
}

if ( ! function_exists( 'is_plugin_paused' ) ) {
	/**
	 * Return whether a test plugin is paused by recovery mode.
	 *
	 * @param string $plugin Plugin basename.
	 */
	function is_plugin_paused( string $plugin ): bool {
		$paused = $GLOBALS['aculect_ai_companion_test_paused_plugins'] ?? array();

		return in_array( $plugin, is_array( $paused ) ? $paused : array(), true );
	}
}

if ( ! function_exists( 'is_network_admin' ) ) {
	/**
	 * Return whether the current test request is network-admin scoped.
	 */
	function is_network_admin(): bool {
		return (bool) ( $GLOBALS['aculect_ai_companion_test_network_admin'] ?? false );
	}
}
