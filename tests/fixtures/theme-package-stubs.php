<?php
/**
 * WordPress theme package test doubles.
 *
 * @package Aculect\AICompanion\Tests\Fixtures
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Fixture mirrors narrow WordPress theme package APIs.

require_once __DIR__ . '/site-workflow-stubs.php';
require_once __DIR__ . '/plugin-lifecycle-upgrader-stubs.php';

if ( ! function_exists( 'themes_api' ) ) {
	/**
	 * Return the configured theme information payload.
	 *
	 * @param string               $action API action.
	 * @param array<string, mixed> $args   API arguments.
	 * @return mixed
	 */
	function themes_api( string $action, array $args = array() ): mixed {
		$GLOBALS['aculect_ai_companion_test_theme_api_request'] = array(
			'action' => $action,
			'args'   => $args,
		);
		return $GLOBALS['aculect_ai_companion_test_theme_api'] ?? new WP_Error( 'theme_not_found', 'Theme not found.' );
	}
}

if ( ! function_exists( 'get_theme_root' ) ) {
	/**
	 * Return the fixture theme root and record the requested stylesheet.
	 *
	 * @param string $stylesheet Theme stylesheet, when known.
	 */
	function get_theme_root( string $stylesheet = '' ): string {
		$GLOBALS['aculect_ai_companion_test_theme_root_stylesheet'] = $stylesheet;
		return $GLOBALS['aculect_ai_companion_test_theme_roots'][ $stylesheet ] ?? '/fixture/wp-content/themes';
	}
}

if ( ! function_exists( 'wp_clean_themes_cache' ) ) {
	/**
	 * Clear only the fixture cache counter.
	 *
	 * @param bool $clear_update_cache Whether to clear update metadata.
	 */
	function wp_clean_themes_cache( bool $clear_update_cache = true ): void {
		unset( $clear_update_cache );
		$GLOBALS['aculect_ai_companion_test_theme_cache_clears'] = (int) ( $GLOBALS['aculect_ai_companion_test_theme_cache_clears'] ?? 0 ) + 1;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Remove a previously recorded test filter.
	 *
	 * @param string $hook_name Filter name.
	 * @param mixed  $callback  Callback to remove.
	 * @param int    $priority  Priority to remove.
	 */
	function remove_filter( string $hook_name, mixed $callback, int $priority = 10 ): bool {
		$filters = $GLOBALS['aculect_ai_companion_test_hooks']['filters'] ?? array();
		if ( ! is_array( $filters ) ) {
			return false;
		}
		$GLOBALS['aculect_ai_companion_test_hooks']['filters'] = array_values(
			array_filter(
				$filters,
				static function ( array $filter ) use ( $hook_name, $callback, $priority ): bool {
					$filter_hook     = (string) ( $filter['hook_name'] ?? '' );
					$filter_priority = (int) ( $filter['priority'] ?? 10 );
					$filter_callback = $filter['callback'] ?? null;
					$matches         = hash_equals( $hook_name, $filter_hook ) && 0 === ( $filter_priority - $priority ) && $callback === $filter_callback;
					return ! $matches;
				}
			)
		);
		return true;
	}
}

if ( ! class_exists( 'Theme_Upgrader' ) ) {
	/**
	 * Theme upgrader double that captures calls without touching disk.
	 */
	class Theme_Upgrader {
		/**
		 * Parsed package theme headers exposed by core to source filters.
		 *
		 * @var array<string, mixed>
		 */
		public array $new_theme_data = array();

		/**
		 * Construct the core double with its non-interactive skin.
		 *
		 * @param mixed $skin Quiet upgrader skin.
		 */
		public function __construct( mixed $skin = null ) {
			unset( $skin );
		}

		/** Initialize the fixture upgrader. */
		public function init(): void {}

		/** Initialize install message strings. */
		public function install_strings(): void {}

		/** Initialize update message strings. */
		public function upgrade_strings(): void {}

		/**
		 * Validate the fixture package and expose its headers to later guards.
		 *
		 * @param mixed $source Package source directory.
		 */
		public function check_package( mixed $source ): mixed {
			$headers              = $GLOBALS['aculect_ai_companion_test_theme_package_headers'] ?? array();
			$this->new_theme_data = is_array( $headers ) ? $headers : array();
			return $source;
		}

		/**
		 * Keep the active theme identity stable around fixture updates.
		 *
		 * @param mixed                $response Filter response.
		 * @param array<string, mixed> $hook_extra Upgrader context.
		 */
		public function current_before( mixed $response, array $hook_extra = array() ): mixed {
			unset( $hook_extra );
			return $response;
		}

		/**
		 * Keep the active theme identity stable after fixture updates.
		 *
		 * @param mixed                $response Filter response.
		 * @param array<string, mixed> $hook_extra Upgrader context.
		 */
		public function current_after( mixed $response, array $hook_extra = array() ): mixed {
			unset( $hook_extra );
			return $response;
		}

		/**
		 * Keep the installed fixture theme during update cleanup.
		 *
		 * @param mixed                $removed      Cleanup result.
		 * @param string               $local_source Local theme source.
		 * @param string               $remote_source Remote package source.
		 * @param array<string, mixed> $hook_extra   Upgrader context.
		 */
		public function delete_old_theme( mixed $removed, string $local_source = '', string $remote_source = '', array $hook_extra = array() ): mixed {
			unset( $local_source, $remote_source, $hook_extra );
			return $removed;
		}

		/**
		 * Capture an exact package run and apply the ability's source guards.
		 *
		 * @param array<string, mixed> $options Core upgrader options.
		 * @return mixed
		 */
		public function run( array $options ): mixed {
			$GLOBALS['aculect_ai_companion_test_theme_upgrader_runs']         = (int) ( $GLOBALS['aculect_ai_companion_test_theme_upgrader_runs'] ?? 0 ) + 1;
			$GLOBALS['aculect_ai_companion_test_last_theme_upgrader_options'] = $options;
			$result = $GLOBALS['aculect_ai_companion_test_theme_upgrader_result'] ?? null;
			if ( $result instanceof WP_Error || false === $result ) {
				return $result;
			}

			$extra   = is_array( $options['hook_extra'] ?? null ) ? $options['hook_extra'] : array();
			$theme   = is_string( $extra['theme'] ?? null ) ? $extra['theme'] : '';
			$source  = '/fixture/package/' . $theme;
			$filters = $GLOBALS['aculect_ai_companion_test_hooks']['filters'] ?? array();
			$filters = is_array( $filters ) ? $filters : array();
			usort( $filters, static fn ( array $left, array $right ): int => ( $left['priority'] ?? 10 ) <=> ( $right['priority'] ?? 10 ) );
			foreach ( $filters as $filter ) {
				if ( 'upgrader_source_selection' !== ( $filter['hook_name'] ?? '' ) || ! is_callable( $filter['callback'] ?? null ) ) {
					continue;
				}
				$args     = array( $source, '/fixture/remote/' . $theme, $this, $extra );
				$accepted = max( 1, (int) ( $filter['accepted_args'] ?? 1 ) );
				$source   = call_user_func_array( $filter['callback'], array_slice( $args, 0, $accepted ) );
				if ( $source instanceof WP_Error ) {
					return $source;
				}
			}
			if ( ! is_string( $source ) ) {
				return new WP_Error( 'theme_package_filter_failed', 'Theme package validation failed.' );
			}

			if ( true === $result || null === $result ) {
				$headers               = $this->new_theme_data;
				$headers['Stylesheet'] = $theme;
				$headers['Template']   = $theme;
				$GLOBALS['aculect_ai_companion_test_themes'][ $theme ] = $headers;
				return array( 'destination_name' => $theme );
			}

			return $result;
		}
	}
}
