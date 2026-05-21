<?php
/**
 * PHPUnit bootstrap for fast, WordPress-light unit tests.
 *
 * These stubs intentionally cover only the WordPress APIs used by the current
 * unit tests. Integration tests should load a real WordPress test environment
 * instead of extending this file.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	if ( 'cli' !== PHP_SAPI ) {
		exit;
	}
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ACULECT_AI_COMPANION_VERSION' ) ) {
	define( 'ACULECT_AI_COMPANION_VERSION', '0.2.0' );
}

$GLOBALS['aculect_ai_companion_test_options'] = array();

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames -- PHPUnit bootstrap stubs WordPress core functions.
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Return a test option value.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( string $option, mixed $default = false ): mixed {
		return array_key_exists( $option, $GLOBALS['aculect_ai_companion_test_options'] ) ? $GLOBALS['aculect_ai_companion_test_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Store a test option value.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Option value.
	 * @param mixed  $autoload Autoload flag.
	 * @return bool
	 */
	function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
		unset( $autoload );

		$GLOBALS['aculect_ai_companion_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Return an unfiltered test value.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Filter value.
	 * @param mixed  ...$args   Additional arguments.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
		unset( $hook_name, $args );

		return $value;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * Delete a test option value.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( string $option ): bool {
		unset( $GLOBALS['aculect_ai_companion_test_options'][ $option ] );

		return true;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	/**
	 * Return a deterministic test home URL.
	 *
	 * @param string $path Optional path.
	 */
	function home_url( string $path = '' ): string {
		return 'https://example.com' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Remove trailing slash characters.
	 *
	 * @param string $value Raw string.
	 */
	function untrailingslashit( string $value ): string {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	/**
	 * Validate a URL for tests.
	 *
	 * @param string $url Raw URL.
	 * @return string|false
	 */
	function wp_http_validate_url( string $url ): string|false {
		return false === filter_var( $url, FILTER_VALIDATE_URL ) ? false : $url;
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Return a non-negative integer.
	 *
	 * @param mixed $maybeint Raw value.
	 */
	function absint( mixed $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Sanitize a key-like string.
	 *
	 * @param string $key Raw key.
	 */
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Sanitize text similarly enough for unit tests.
	 *
	 * @param string $str Raw text.
	 */
	function sanitize_text_field( string $str ): string {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( $str ) ) ?? '' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Sanitize a URL for tests.
	 *
	 * @param string $url Raw URL.
	 */
	function esc_url_raw( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Parse a URL.
	 *
	 * @param string $url       Raw URL.
	 * @param int    $component Optional parse component.
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encode JSON.
	 *
	 * @param mixed $data    Data to encode.
	 * @param int   $options JSON options.
	 * @param int   $depth   Max depth.
	 * @return string|false
	 */
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Strip slashes from a value.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	function wp_unslash( mixed $value ): mixed {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal REST request test double.
	 */
	class WP_REST_Request {
		/**
		 * @param array<string, mixed>  $params  Request params.
		 * @param array<string, string> $headers Request headers.
		 * @param array<string, mixed>  $json    JSON params.
		 */
		public function __construct(
			private array $params = array(),
			private array $headers = array(),
			private array $json = array(),
			private string $method = 'GET',
			private string $route = ''
		) {}

		/**
		 * Return one request parameter.
		 *
		 * @param string $key Parameter name.
		 * @return mixed
		 */
		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * Return one request header.
		 *
		 * @param string $key Header name.
		 */
		public function get_header( string $key ): string {
			return $this->headers[ strtolower( $key ) ] ?? $this->headers[ $key ] ?? '';
		}

		/**
		 * Return JSON parameters.
		 *
		 * @return array<string, mixed>
		 */
		public function get_json_params(): array {
			return $this->json;
		}

		/**
		 * Return request method.
		 */
		public function get_method(): string {
			return $this->method;
		}

		/**
		 * Return request route.
		 */
		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal REST response test double.
	 */
	class WP_REST_Response {
		/** @var array<string, string> */
		private array $headers = array();

		/**
		 * @param mixed $data   Response data.
		 * @param int   $status HTTP status.
		 */
		public function __construct( private mixed $data = null, private int $status = 200 ) {}

		/**
		 * Return response data.
		 *
		 * @return mixed
		 */
		public function get_data(): mixed {
			return $this->data;
		}

		/**
		 * Return HTTP status.
		 */
		public function get_status(): int {
			return $this->status;
		}

		/**
		 * Set or retrieve a response header.
		 *
		 * @param string      $key   Header key.
		 * @param string|null $value Header value.
		 * @return string|null
		 */
		public function header( string $key, ?string $value = null ): ?string {
			if ( null === $value ) {
				return $this->headers[ $key ] ?? null;
			}

			$this->headers[ $key ] = $value;

			return null;
		}
	}
}
