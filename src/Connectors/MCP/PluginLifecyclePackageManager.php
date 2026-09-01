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
	 * @param string $plugin Installed plugin basename.
	 * @return bool|\WP_Error
	 */
	public function update( string $plugin ): bool|\WP_Error {
		$upgrader = $this->upgrader();
		if ( $upgrader instanceof \WP_Error ) {
			return $upgrader;
		}

		return $upgrader->upgrade( $plugin, array( 'clear_update_cache' => true ) );
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
