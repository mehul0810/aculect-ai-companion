<?php
/**
 * Site workflow WordPress API stubs for unit tests.
 *
 * @package Aculect\AICompanion\Tests\Fixtures
 */

declare(strict_types=1);

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Test fixture stubs a small WP runtime surface.

if ( ! class_exists( 'WP_Theme' ) ) {
	/**
	 * Minimal WP_Theme test double.
	 */
	class WP_Theme {
		/**
		 * Store theme fields.
		 *
		 * @param array<string, string> $data Theme fields.
		 */
		public function __construct( private array $data = array() ) {}

		/**
		 * Return one theme header.
		 *
		 * @param string $header Theme header.
		 */
		public function get( string $header ): string {
			return (string) ( $this->data[ $header ] ?? '' );
		}

		/**
		 * Return stylesheet slug.
		 */
		public function get_stylesheet(): string {
			return (string) ( $this->data['Stylesheet'] ?? 'twentytwentysix' );
		}

		/**
		 * Return template slug.
		 */
		public function get_template(): string {
			return (string) ( $this->data['Template'] ?? $this->get_stylesheet() );
		}

		/**
		 * Return parent theme.
		 */
		public function parent(): false {
			return false;
		}
	}
}

if ( ! function_exists( 'wp_get_theme' ) ) {
	/**
	 * Return the active test theme.
	 */
	function wp_get_theme(): WP_Theme {
		$data = $GLOBALS['aculect_ai_companion_test_theme'] ?? array(
			'Name'       => 'Twenty Twenty-Six',
			'Version'    => '1.0.0',
			'Stylesheet' => 'twentytwentysix',
			'Template'   => 'twentytwentysix',
		);

		return new WP_Theme( is_array( $data ) ? $data : array() );
	}
}

if ( ! function_exists( 'wp_get_themes' ) ) {
	/**
	 * Return installed test themes.
	 *
	 * @return array<string, WP_Theme>
	 */
	function wp_get_themes(): array {
		return array(
			'twentytwentysix' => wp_get_theme(),
		);
	}
}

if ( ! function_exists( 'wp_is_using_https' ) ) {
	/**
	 * Return HTTPS state for tests.
	 */
	function wp_is_using_https(): bool {
		return (bool) ( $GLOBALS['aculect_ai_companion_test_using_https'] ?? true );
	}
}

if ( ! function_exists( 'wp_timezone_string' ) ) {
	/**
	 * Return the test timezone.
	 */
	function wp_timezone_string(): string {
		return 'UTC';
	}
}

if ( ! function_exists( 'get_locale' ) ) {
	/**
	 * Return the test locale.
	 */
	function get_locale(): string {
		return 'en_US';
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * Return multisite state for tests.
	 */
	function is_multisite(): bool {
		return false;
	}
}

if ( ! function_exists( 'get_core_updates' ) ) {
	/**
	 * Return cached core updates for tests.
	 *
	 * @return list<object>
	 */
	function get_core_updates(): array {
		return $GLOBALS['aculect_ai_companion_test_core_updates'] ?? array();
	}
}

if ( ! function_exists( 'get_plugin_updates' ) ) {
	/**
	 * Return cached plugin updates for tests.
	 *
	 * @return array<string, mixed>
	 */
	function get_plugin_updates(): array {
		return $GLOBALS['aculect_ai_companion_test_plugin_updates'] ?? array();
	}
}

if ( ! function_exists( 'get_theme_updates' ) ) {
	/**
	 * Return cached theme updates for tests.
	 *
	 * @return array<string, mixed>
	 */
	function get_theme_updates(): array {
		return $GLOBALS['aculect_ai_companion_test_theme_updates'] ?? array();
	}
}

if ( ! function_exists( '_get_cron_array' ) ) {
	/**
	 * Return scheduled cron events for tests.
	 *
	 * @return array<int, mixed>
	 */
	function _get_cron_array(): array {
		return $GLOBALS['aculect_ai_companion_test_cron_array'] ?? array( time() + HOUR_IN_SECONDS => array( 'example_hook' => array() ) );
	}
}
