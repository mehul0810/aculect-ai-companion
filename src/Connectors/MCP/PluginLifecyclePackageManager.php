<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Loads the WordPress core package APIs used by plugin lifecycle writes.
 *
 * Keeping the upgrader boundary in one small collaborator makes it possible to
 * test lifecycle decisions without loading a partial wp-admin runtime.
 */
final class PluginLifecyclePackageManager {

	/**
	 * Return WordPress.org plugin information for one slug.
	 *
	 * @param string $slug WordPress.org plugin slug.
	 * @return mixed Plugin information object, array, or WP_Error.
	 */
	public function information( string $slug ): mixed {
		if ( ! $this->load_plugin_install_api() ) {
			return new \WP_Error( 'plugin_install_unavailable', 'WordPress plugin installation APIs are unavailable.' );
		}

		return plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections'     => false,
					'banners'      => false,
					'icons'        => false,
					'contributors' => false,
					'reviews'      => false,
					'screenshots'  => false,
				),
			)
		);
	}

	/**
	 * Install one package with the WordPress core plugin upgrader.
	 *
	 * @param string $package Public package URL returned by a trusted API.
	 * @return bool|\WP_Error
	 */
	public function install( string $package ): bool|\WP_Error {
		$upgrader = $this->upgrader();
		if ( $upgrader instanceof \WP_Error ) {
			return $upgrader;
		}

		return $upgrader->install( $package, array( 'clear_update_cache' => true ) );
	}

	/**
	 * Update one installed plugin with the WordPress core plugin upgrader.
	 *
	 * @param string $plugin  Installed plugin basename.
	 * @param string $package Exact package URL resolved during the preview.
	 * @return bool|\WP_Error
	 */
	public function update( string $plugin, string $package ): bool|\WP_Error {
		$upgrader = $this->upgrader();
		if ( $upgrader instanceof \WP_Error ) {
			return $upgrader;
		}

		if ( ! method_exists( $upgrader, 'run' ) || ! method_exists( $upgrader, 'init' ) || ! method_exists( $upgrader, 'upgrade_strings' ) ) {
			return new \WP_Error( 'plugin_upgrader_unavailable', 'WordPress plugin upgrader APIs are unavailable.' );
		}

		$destination = defined( 'WP_PLUGIN_DIR' )
			? WP_PLUGIN_DIR
			: rtrim( (string) ABSPATH, '/' ) . '/wp-content/plugins';
		$hook_extra  = array(
			'plugin' => $plugin,
			'type'   => 'plugin',
			'action' => 'update',
		);
		$directory   = dirname( $plugin );
		if ( '.' !== $directory && '' !== $directory ) {
			$hook_extra['temp_backup'] = array(
				'slug' => $directory,
				'src'  => $destination,
				'dir'  => 'plugins',
			);
		}

		$upgrader->init();
		$upgrader->upgrade_strings();
		add_filter( 'upgrader_pre_install', array( $upgrader, 'deactivate_plugin_before_upgrade' ), 10, 2 );
		add_filter( 'upgrader_pre_install', array( $upgrader, 'active_before' ), 10, 2 );
		add_filter( 'upgrader_clear_destination', array( $upgrader, 'delete_old_plugin' ), 10, 4 );
		add_filter( 'upgrader_post_install', array( $upgrader, 'active_after' ), 10, 2 );
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			add_action( 'upgrader_process_complete', 'wp_clean_plugins_cache', 9, 0 );
		}

		try {
			$upgrader->run(
				array(
					'package'           => $package,
					'destination'       => $destination,
					'clear_destination' => true,
					'clear_working'     => true,
					'hook_extra'        => $hook_extra,
				)
			);
			$result = $upgrader->result;
		} finally {
			if ( function_exists( 'wp_clean_plugins_cache' ) ) {
				remove_action( 'upgrader_process_complete', 'wp_clean_plugins_cache', 9 );
			}
			if ( function_exists( 'remove_filter' ) ) {
				remove_filter( 'upgrader_pre_install', array( $upgrader, 'deactivate_plugin_before_upgrade' ) );
				remove_filter( 'upgrader_pre_install', array( $upgrader, 'active_before' ) );
				remove_filter( 'upgrader_clear_destination', array( $upgrader, 'delete_old_plugin' ) );
				remove_filter( 'upgrader_post_install', array( $upgrader, 'active_after' ) );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( array() === $result ) {
			return new \WP_Error( 'plugin_update_failed', 'WordPress could not update the plugin.' );
		}

		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}

		return true;
	}

	/**
	 * Ensure WordPress.org's plugin-install API is available.
	 */
	private function load_plugin_install_api(): bool {
		if ( function_exists( 'plugins_api' ) ) {
			return true;
		}

		$path = ABSPATH . 'wp-admin/includes/plugin-install.php';
		if ( ! is_file( $path ) ) {
			return false;
		}

		require_once $path;

		return function_exists( 'plugins_api' );
	}

	/**
	 * Build a quiet core plugin upgrader.
	 *
	 * @return \Plugin_Upgrader|\WP_Error
	 */
	private function upgrader(): object {
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			$path = ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			if ( ! is_file( $path ) ) {
				return new \WP_Error( 'plugin_upgrader_unavailable', 'WordPress plugin upgrader APIs are unavailable.' );
			}

			require_once $path;
		}

		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			return new \WP_Error( 'plugin_upgrader_unavailable', 'WordPress plugin upgrader APIs are unavailable.' );
		}

		$skin = class_exists( 'Automatic_Upgrader_Skin' )
			? new \Automatic_Upgrader_Skin()
			: new \WP_Upgrader_Skin();

		return new \Plugin_Upgrader( $skin );
	}
}
