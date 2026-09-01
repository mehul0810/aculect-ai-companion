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

if ( ! function_exists( 'plugins_api' ) ) {
	/**
	 * Return configured WordPress.org plugin information for tests.
	 *
	 * @param string               $action API action.
	 * @param array<string, mixed> $args   API arguments.
	 * @return mixed
	 */
	function plugins_api( string $action, array $args = array() ): mixed {
		unset( $args );
		if ( 'plugin_information' !== $action ) {
			return new WP_Error( 'unsupported_action', 'Unsupported plugin API action.' );
		}

		return $GLOBALS['aculect_ai_companion_test_plugin_api'] ?? new WP_Error( 'plugin_not_found', 'Plugin not found.' );
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

if ( ! function_exists( 'activate_plugin' ) ) {
	/**
	 * Activate one installed test plugin on the current site.
	 *
	 * @param string $plugin Plugin basename.
	 * @return mixed
	 */
	function activate_plugin( string $plugin ): mixed {
		$errors = $GLOBALS['aculect_ai_companion_test_activate_plugin_errors'] ?? array();
		if ( isset( $errors[ $plugin ] ) && $errors[ $plugin ] instanceof \WP_Error ) {
			return $errors[ $plugin ];
		}

		$active = $GLOBALS['aculect_ai_companion_test_active_plugins'] ?? array();
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		if ( ! in_array( $plugin, $active, true ) ) {
			$active[] = $plugin;
		}

		$GLOBALS['aculect_ai_companion_test_active_plugins']            = array_values( $active );
		$GLOBALS['aculect_ai_companion_test_options']['active_plugins'] = array_values( $active );
		$GLOBALS['aculect_ai_companion_test_last_plugin_activation']    = $plugin;

		return null;
	}
}

if ( ! function_exists( 'deactivate_plugins' ) ) {
	/**
	 * Deactivate one or more installed test plugins on the current site.
	 *
	 * @param string|array<int, string> $plugins Plugin basename or basenames.
	 */
	function deactivate_plugins( string|array $plugins ): void {
		$targets = is_array( $plugins ) ? array_values( $plugins ) : array( $plugins );
		$active  = $GLOBALS['aculect_ai_companion_test_active_plugins'] ?? array();
		if ( ! is_array( $active ) ) {
			$active = array();
		}

		$GLOBALS['aculect_ai_companion_test_active_plugins']            = array_values(
			array_filter(
				$active,
				static fn ( mixed $plugin ): bool => ! in_array( (string) $plugin, $targets, true )
			)
		);
		$GLOBALS['aculect_ai_companion_test_options']['active_plugins'] = $GLOBALS['aculect_ai_companion_test_active_plugins'];
		$GLOBALS['aculect_ai_companion_test_last_plugin_deactivation']  = $targets;
	}
}
