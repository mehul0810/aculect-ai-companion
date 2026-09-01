<?php
/**
 * WordPress plugin upgrader test doubles.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Core exposes these related upgrader classes globally.

if ( ! class_exists( 'WP_Upgrader_Skin' ) ) {
	/**
	 * Quiet test double for the WordPress upgrader skin.
	 */
	class WP_Upgrader_Skin {
	}
}

if ( ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
	/**
	 * Quiet test double for the automatic WordPress upgrader skin.
	 */
	class Automatic_Upgrader_Skin extends WP_Upgrader_Skin {
	}
}

if ( ! class_exists( 'Plugin_Upgrader' ) ) {
	/**
	 * Test double for the WordPress core plugin upgrader.
	 */
	class Plugin_Upgrader {
		/**
		 * Construct a quiet upgrader with the supplied test skin.
		 *
		 * @param mixed $skin Quiet test skin.
		 */
		public function __construct( mixed $skin = null ) {
			unset( $skin );
		}

		/**
		 * Install a configured test plugin package.
		 *
		 * @param string               $package Package URL.
		 * @param array<string, mixed> $args    Upgrader arguments.
		 * @return bool|WP_Error
		 */
		public function install( string $package, array $args = array() ): bool|WP_Error {
			unset( $args );
			$GLOBALS['aculect_ai_companion_test_last_plugin_package'] = $package;
			$result = $GLOBALS['aculect_ai_companion_test_plugin_install_result'] ?? true;
			if ( $result instanceof WP_Error || false === $result ) {
				return $result;
			}

			$new_plugin = $GLOBALS['aculect_ai_companion_test_plugin_to_install'] ?? null;
			if ( is_array( $new_plugin ) && isset( $new_plugin['file'], $new_plugin['headers'] ) ) {
				$GLOBALS['aculect_ai_companion_test_plugins'][ (string) $new_plugin['file'] ] = (array) $new_plugin['headers'];
			}

			return true;
		}

		/**
		 * Update a configured test plugin package.
		 *
		 * @param string               $plugin Installed plugin basename.
		 * @param array<string, mixed> $args   Upgrader arguments.
		 * @return bool|WP_Error
		 */
		public function upgrade( string $plugin, array $args = array() ): bool|WP_Error {
			unset( $args );
			$GLOBALS['aculect_ai_companion_test_last_plugin_upgrade'] = $plugin;
			$result = $GLOBALS['aculect_ai_companion_test_plugin_update_result'] ?? true;
			if ( $result instanceof WP_Error || false === $result ) {
				return $result;
			}

			$version = $GLOBALS['aculect_ai_companion_test_plugin_update_versions'][ $plugin ] ?? null;
			if ( is_scalar( $version ) && isset( $GLOBALS['aculect_ai_companion_test_plugins'][ $plugin ]['Version'] ) ) {
				$GLOBALS['aculect_ai_companion_test_plugins'][ $plugin ]['Version'] = (string) $version;
			}

			return true;
		}
	}
}
