<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Closure;
use Throwable;

/**
 * Runs narrowly scoped theme package operations through WordPress core.
 */
final class ThemePackageManager {

	private const FILE_MOD_CONTEXT = 'aculect_extension_lifecycle';

	/**
	 * Retrieve bounded WordPress.org information for one theme slug.
	 *
	 * @param string $slug WordPress.org theme slug.
	 * @return mixed Theme metadata or WP_Error.
	 */
	public function information( string $slug ): mixed {
		if ( ! $this->load_theme_api() ) {
			return new \WP_Error( 'theme_install_unavailable', 'WordPress theme installation APIs are unavailable.' );
		}

		try {
			return themes_api(
				'theme_information',
				array(
					'slug'   => $slug,
					'fields' => array(
						'sections'     => false,
						'tags'         => false,
						'screenshots'  => false,
						'parent'       => true,
						'template'     => true,
						'versions'     => false,
						'downloadlink' => false,
						'homepage'     => false,
						'description'  => false,
						'rating'       => false,
						'ratings'      => false,
						'downloaded'   => false,
					),
				),
			);
		} catch ( Throwable ) {
			return new \WP_Error( 'theme_install_information_failed', 'WordPress could not retrieve theme package information.' );
		}
	}

	/**
	 * Return a fixed failure if theme file writes are not direct and permitted.
	 *
	 * @param string|null $stylesheet Destination stylesheet for filesystem context.
	 * @return \WP_Error|null
	 */
	public function filesystem_error( ?string $stylesheet = null ): ?\WP_Error {
		if ( is_multisite() ) {
			return new \WP_Error( 'theme_multisite_filesystem_scope', 'Theme package writes are disabled on multisite.' );
		}
		if ( ! function_exists( 'wp_is_file_mod_allowed' ) || ! wp_is_file_mod_allowed( self::FILE_MOD_CONTEXT ) ) {
			return new \WP_Error( 'theme_file_modifications_disallowed', 'WordPress has disabled theme file modifications.' );
		}
		$root = function_exists( 'get_theme_root' )
			? get_theme_root( is_string( $stylesheet ) ? $stylesheet : '' )
			: '';
		if ( ! $this->load_filesystem_api() || ! function_exists( 'get_filesystem_method' ) || 'direct' !== get_filesystem_method( array(), $root ) ) {
			return new \WP_Error( 'theme_direct_filesystem_unavailable', 'Theme package operations require direct filesystem access; no credentials will be requested.' );
		}

		global $wp_filesystem;
		if ( isset( $wp_filesystem ) && ( ! class_exists( 'WP_Filesystem_Direct' ) || ! $wp_filesystem instanceof \WP_Filesystem_Direct ) ) {
			return new \WP_Error( 'theme_direct_filesystem_unavailable', 'Theme package operations require direct filesystem access; no credentials will be requested.' );
		}

		return null;
	}

	/**
	 * Install one exact theme package without activation or parent installation.
	 *
	 * @param string $slug    Confirmed WordPress.org theme slug.
	 * @param string $version Confirmed theme version.
	 * @param string $package Confirmed canonical package URL.
	 * @return bool|\WP_Error
	 */
	public function install( string $slug, string $version, string $package ): bool|\WP_Error {
		return $this->perform( 'install', $slug, $version, $package );
	}

	/**
	 * Update one exact installed theme package.
	 *
	 * @param string $stylesheet Installed theme stylesheet.
	 * @param string $version    Confirmed target version.
	 * @param string $package    Confirmed canonical package URL.
	 * @return bool|\WP_Error
	 */
	public function update( string $stylesheet, string $version, string $package ): bool|\WP_Error {
		return $this->perform( 'update', $stylesheet, $version, $package );
	}

	/**
	 * Execute one package using explicit core upgrader arguments.
	 *
	 * @param 'install'|'update' $operation Operation name.
	 * @param string             $theme     Theme stylesheet slug.
	 * @param string             $version   Confirmed target version.
	 * @param string             $package   Confirmed exact package URL.
	 * @return bool|\WP_Error
	 */
	private function perform( string $operation, string $theme, string $version, string $package ): bool|\WP_Error {
		$filesystem_error = $this->filesystem_error( $theme );
		if ( $filesystem_error instanceof \WP_Error ) {
			return $filesystem_error;
		}

		$upgrader = $this->upgrader();
		if ( null === $upgrader ) {
			return new \WP_Error( 'theme_upgrader_unavailable', 'WordPress theme upgrader APIs are unavailable.' );
		}

		try {
			return $this->run_package( $upgrader, $operation, $theme, $version, $package );
		} catch ( Throwable ) {
			return new \WP_Error( 'theme_' . $operation . '_failed', 'WordPress could not ' . $operation . ' the theme.' );
		}
	}

	/**
	 * Run the core upgrader without the child-parent auto-install filter.
	 *
	 * @param \Theme_Upgrader    $upgrader  Core theme upgrader.
	 * @param 'install'|'update' $operation Operation name.
	 * @param string             $theme     Theme stylesheet slug.
	 * @param string             $version   Confirmed target version.
	 * @param string             $package   Confirmed exact package URL.
	 * @return bool|\WP_Error
	 */
	private function run_package( \Theme_Upgrader $upgrader, string $operation, string $theme, string $version, string $package ): bool|\WP_Error {
		$source_guard = $this->source_guard( $upgrader, $theme, $version );
		$source_check = array( $upgrader, 'check_package' );
		add_filter( 'upgrader_source_selection', $source_check, 10, 1 );
		add_filter( 'upgrader_source_selection', $source_guard, 20, 4 );
		if ( 'update' === $operation ) {
			$this->add_update_filters( $upgrader );
		}

		try {
			$upgrader->init();
			if ( 'install' === $operation ) {
				$upgrader->install_strings();
			} else {
				$upgrader->upgrade_strings();
			}
			$result = $upgrader->run( $this->upgrader_options( $operation, $theme, $package ) );
		} finally {
			$this->remove_operation_filters( $upgrader, $source_guard, $source_check, $operation );
		}

		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		if ( ! is_array( $result ) || ! isset( $result['destination_name'] ) || ! is_string( $result['destination_name'] ) || ! hash_equals( $theme, $result['destination_name'] ) ) {
			return new \WP_Error( 'theme_' . $operation . '_failed', 'WordPress did not install the confirmed theme package.' );
		}
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( true );
		}

		return true;
	}

	/**
	 * Build WordPress core's narrow install or update arguments.
	 *
	 * @param string $operation Install or update.
	 * @param string $theme     Theme stylesheet slug.
	 * @param string $package   Confirmed package URL.
	 * @return array<string, mixed>
	 */
	private function upgrader_options( string $operation, string $theme, string $package ): array {
		$root  = 'install' === $operation ? get_theme_root() : get_theme_root( $theme );
		$extra = array(
			'theme'  => $theme,
			'type'   => 'theme',
			'action' => $operation,
		);
		if ( 'update' === $operation ) {
			$extra['temp_backup'] = array(
				'slug' => $theme,
				'src'  => $root,
				'dir'  => 'themes',
			);
		}

		return array(
			'package'           => $package,
			'destination'       => $root,
			'clear_destination' => 'update' === $operation,
			'clear_working'     => true,
			'hook_extra'        => $extra,
		);
	}

	/**
	 * Register WordPress's active-theme safety filters for an update.
	 *
	 * @param \Theme_Upgrader $upgrader Core theme upgrader.
	 */
	private function add_update_filters( \Theme_Upgrader $upgrader ): void {
		add_filter( 'upgrader_pre_install', array( $upgrader, 'current_before' ), 10, 2 );
		add_filter( 'upgrader_post_install', array( $upgrader, 'current_after' ), 10, 2 );
		add_filter( 'upgrader_clear_destination', array( $upgrader, 'delete_old_theme' ), 10, 4 );
	}

	/**
	 * Remove only callbacks registered by this package operation.
	 *
	 * @param \Theme_Upgrader   $upgrader  Core theme upgrader.
	 * @param Closure           $guard     Exact source guard callback.
	 * @param array<int, mixed> $check     Core package checker callback.
	 * @param string            $operation Install or update.
	 */
	private function remove_operation_filters( \Theme_Upgrader $upgrader, Closure $guard, array $check, string $operation ): void {
		if ( ! function_exists( 'remove_filter' ) ) {
			return;
		}
		remove_filter( 'upgrader_source_selection', $check, 10 );
		remove_filter( 'upgrader_source_selection', $guard, 20 );
		if ( 'update' === $operation ) {
			remove_filter( 'upgrader_pre_install', array( $upgrader, 'current_before' ), 10 );
			remove_filter( 'upgrader_post_install', array( $upgrader, 'current_after' ), 10 );
			remove_filter( 'upgrader_clear_destination', array( $upgrader, 'delete_old_theme' ), 10 );
		}
	}

	/**
	 * Ensure the downloaded archive is exactly the previewed standalone theme.
	 *
	 * @param \Theme_Upgrader $upgrader Core theme upgrader.
	 * @param string          $theme    Confirmed theme stylesheet.
	 * @param string          $version  Confirmed theme version.
	 * @return Closure(mixed, mixed=, mixed=, mixed=): mixed
	 */
	private function source_guard( \Theme_Upgrader $upgrader, string $theme, string $version ): Closure {
		return static function ( mixed $source, mixed $remote_source = '', mixed $candidate = null, mixed $hook_extra = array() ) use ( $upgrader, $theme, $version ): mixed {
			unset( $remote_source, $hook_extra );
			if ( $source instanceof \WP_Error ) {
				return $source;
			}
			$source_theme = is_string( $source ) ? basename( rtrim( $source, '/\\' ) ) : '';
			if ( ! is_string( $source ) || $candidate !== $upgrader || ! hash_equals( $theme, $source_theme ) ) {
				return new \WP_Error( 'theme_package_mismatch', 'The downloaded package does not match the confirmed theme.' );
			}

			$properties = get_object_vars( $upgrader );
			$headers    = $properties['new_theme_data'] ?? null;
			if ( ! is_array( $headers ) || ! is_string( $headers['Name'] ?? null ) || '' === trim( $headers['Name'] ) || ! is_string( $headers['Version'] ?? null ) || ! hash_equals( $version, $headers['Version'] ) || ! is_string( $headers['Template'] ?? null ) || '' !== trim( $headers['Template'] ) ) {
				return new \WP_Error( 'theme_package_mismatch', 'The downloaded package does not match the confirmed standalone theme version.' );
			}

			return $source;
		};
	}

	/** Return the core Theme_Upgrader with a quiet, non-interactive skin. */
	private function upgrader(): ?\Theme_Upgrader {
		if ( ! $this->load_upgrader_api() ) {
			return null;
		}
		if ( ! class_exists( 'Theme_Upgrader' ) || ! class_exists( 'Automatic_Upgrader_Skin' ) ) {
			return null;
		}

		return new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
	}

	/** Load only the WordPress core API files required by theme writes. */
	private function load_upgrader_api(): bool {
		if ( class_exists( 'Theme_Upgrader' ) && class_exists( 'Automatic_Upgrader_Skin' ) ) {
			return true;
		}

		$includes = ABSPATH . 'wp-admin/includes/';
		foreach ( array( 'class-wp-upgrader.php', 'class-automatic-upgrader-skin.php', 'class-theme-upgrader.php' ) as $file ) {
			$path = $includes . $file;
			if ( ! is_file( $path ) ) {
				return false;
			}
			require_once $path;
		}

		return class_exists( 'Theme_Upgrader' ) && class_exists( 'Automatic_Upgrader_Skin' );
	}

	/** Load the WordPress core filesystem-method helper without prompting. */
	private function load_filesystem_api(): bool {
		if ( function_exists( 'get_filesystem_method' ) ) {
			return true;
		}
		$path = ABSPATH . 'wp-admin/includes/file.php';
		if ( ! is_file( $path ) ) {
			return false;
		}
		require_once $path;
		return function_exists( 'get_filesystem_method' );
	}

	/** Load the WordPress.org theme information API. */
	private function load_theme_api(): bool {
		if ( function_exists( 'themes_api' ) ) {
			return true;
		}
		$path = ABSPATH . 'wp-admin/includes/theme.php';
		if ( ! is_file( $path ) ) {
			return false;
		}
		require_once $path;
		return function_exists( 'themes_api' );
	}
}
