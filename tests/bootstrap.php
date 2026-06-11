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

	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ACULECT_AI_COMPANION_VERSION' ) ) {
	define( 'ACULECT_AI_COMPANION_VERSION', '0.5.2' );
}

if ( ! defined( 'ACULECT_AI_COMPANION_PLUGIN_FILE' ) ) {
	define( 'ACULECT_AI_COMPANION_PLUGIN_FILE', dirname( __DIR__ ) . '/aculect-ai-companion.php' );
}

if ( ! defined( 'ACULECT_AI_COMPANION_PLUGIN_DIR' ) ) {
	define( 'ACULECT_AI_COMPANION_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

if ( ! defined( 'ACULECT_AI_COMPANION_PLUGIN_URL' ) ) {
	define( 'ACULECT_AI_COMPANION_PLUGIN_URL', 'https://example.com/wp-content/plugins/aculect-ai-companion/' );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
$GLOBALS['aculect_ai_companion_test_options']     = array();
$GLOBALS['aculect_ai_companion_test_transients']  = array();
$GLOBALS['aculect_ai_companion_test_admin_pages'] = array(
	'menu'    => array(),
	'options' => array(),
	'submenu' => array(),
);
$GLOBALS['aculect_ai_companion_test_hooks']       = array(
	'actions' => array(),
	'filters' => array(),
);
$GLOBALS['aculect_ai_companion_test_rest_routes'] = array();
$GLOBALS['aculect_ai_companion_test_environment_type'] = 'production';
$GLOBALS['aculect_ai_companion_test_roles']       = array(
	'administrator' => array( 'name' => 'Administrator' ),
	'editor'        => array( 'name' => 'Editor' ),
	'author'        => array( 'name' => 'Author' ),
);
$GLOBALS['aculect_ai_companion_test_users']       = array();
$GLOBALS['aculect_ai_companion_test_posts']       = array();
$GLOBALS['aculect_ai_companion_test_blocks']      = array();
$GLOBALS['aculect_ai_companion_test_patterns']    = array();

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

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * Return the test site timezone.
	 */
	function wp_timezone(): \DateTimeZone {
		$timezone_string = (string) get_option( 'timezone_string', '' );
		if ( '' !== $timezone_string ) {
			try {
				return new \DateTimeZone( $timezone_string );
			} catch ( \Exception ) {
				// Fall through to the numeric GMT offset.
			}
		}

		$offset  = (float) get_option( 'gmt_offset', 0 );
		$hours   = (int) $offset;
		$minutes = (int) round( abs( $offset - $hours ) * 60 );
		$sign    = $offset < 0 ? '-' : '+';

		return new \DateTimeZone( sprintf( '%s%02d:%02d', $sign, abs( $hours ), $minutes ) );
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Add a test option value only when it does not already exist.
	 *
	 * @param string $option     Option name.
	 * @param mixed  $value      Option value.
	 * @param mixed  $deprecated Deprecated description argument.
	 * @param mixed  $autoload   Autoload flag.
	 * @return bool
	 */
	function add_option( string $option, mixed $value = '', mixed $deprecated = '', mixed $autoload = null ): bool {
		unset( $deprecated, $autoload );

		if ( array_key_exists( $option, $GLOBALS['aculect_ai_companion_test_options'] ) ) {
			return false;
		}

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

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * Record a test action registration.
	 *
	 * @param string $hook_name     Action hook name.
	 * @param mixed  $callback      Callback.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Accepted argument count.
	 */
	function add_action( string $hook_name, mixed $callback, int $priority = 10, int $accepted_args = 1 ): true {
		$GLOBALS['aculect_ai_companion_test_hooks']['actions'][] = array(
			'hook_name'     => $hook_name,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Record a test filter registration.
	 *
	 * @param string $hook_name     Filter hook name.
	 * @param mixed  $callback      Callback.
	 * @param int    $priority      Hook priority.
	 * @param int    $accepted_args Accepted argument count.
	 */
	function add_filter( string $hook_name, mixed $callback, int $priority = 10, int $accepted_args = 1 ): true {
		$GLOBALS['aculect_ai_companion_test_hooks']['filters'][] = array(
			'hook_name'     => $hook_name,
			'callback'      => $callback,
			'priority'      => $priority,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Record a test REST route registration.
	 *
	 * @param string               $namespace Route namespace.
	 * @param string               $route     Route path.
	 * @param array<string, mixed> $args      Route arguments.
	 * @param bool                 $override  Whether to override existing route.
	 */
	function register_rest_route( string $namespace, string $route, array $args = array(), bool $override = false ): bool {
		$GLOBALS['aculect_ai_companion_test_rest_routes'][] = array(
			'namespace' => $namespace,
			'route'     => $route,
			'args'      => $args,
			'override'  => $override,
		);

		return true;
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal post object used by unit tests.
	 */
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'post';
		public string $post_status = 'draft';
		public string $post_title = '';
		public string $post_content = '';
		public string $post_excerpt = '';
		public string $post_name = '';
		public int $post_author = 0;
		public string $post_date = '';
		public string $post_date_gmt = '';

		/**
		 * @param array<string, mixed> $data Post fields.
		 */
		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				if ( property_exists( $this, (string) $key ) ) {
					$this->{$key} = is_int( $this->{$key} ) ? absint( $value ) : (string) $value;
				}
			}
		}
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * Return a test post object.
	 *
	 * @param int $post_id Post ID.
	 */
	function get_post( int $post_id ): ?WP_Post {
		$post = $GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] ?? null;
		if ( $post instanceof WP_Post ) {
			return $post;
		}

		return is_array( $post ) ? new WP_Post( $post ) : null;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Return test capability checks.
	 *
	 * @param string $capability Capability name.
	 * @param mixed  ...$args    Capability args.
	 */
	function current_user_can( string $capability, mixed ...$args ): bool {
		unset( $args );

		$denied = $GLOBALS['aculect_ai_companion_test_denied_caps'] ?? array();
		return ! in_array( $capability, is_array( $denied ) ? $denied : array(), true );
	}
}

if ( ! function_exists( 'add_menu_page' ) ) {
	/**
	 * Record a top-level admin menu registration.
	 *
	 * @param string   $page_title Page title.
	 * @param string   $menu_title Menu title.
	 * @param string   $capability Required capability.
	 * @param string   $menu_slug  Menu slug.
	 * @param mixed    $callback   Page callback.
	 * @param string   $icon_url   Menu icon URL.
	 * @param int|null $position   Menu position.
	 */
	function add_menu_page( string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', string $icon_url = '', ?int $position = null ): string {
		$GLOBALS['aculect_ai_companion_test_admin_pages']['menu'][] = array(
			'page_title' => $page_title,
			'menu_title' => $menu_title,
			'capability' => $capability,
			'menu_slug'  => $menu_slug,
			'callback'   => $callback,
			'icon_url'   => $icon_url,
			'position'   => $position,
		);

		return 'toplevel_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'add_options_page' ) ) {
	/**
	 * Record a Settings submenu page registration.
	 *
	 * @param string   $page_title Page title.
	 * @param string   $menu_title Menu title.
	 * @param string   $capability Required capability.
	 * @param string   $menu_slug  Menu slug.
	 * @param mixed    $callback   Page callback.
	 * @param int|null $position   Menu position.
	 */
	function add_options_page( string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', ?int $position = null ): string {
		$GLOBALS['aculect_ai_companion_test_admin_pages']['options'][] = array(
			'page_title' => $page_title,
			'menu_title' => $menu_title,
			'capability' => $capability,
			'menu_slug'  => $menu_slug,
			'callback'   => $callback,
			'position'   => $position,
		);

		return 'settings_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'add_submenu_page' ) ) {
	/**
	 * Record a generic admin submenu registration.
	 *
	 * @param string   $parent_slug Parent menu slug.
	 * @param string   $page_title  Page title.
	 * @param string   $menu_title  Menu title.
	 * @param string   $capability  Required capability.
	 * @param string   $menu_slug   Menu slug.
	 * @param mixed    $callback    Page callback.
	 * @param int|null $position    Menu position.
	 */
	function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', ?int $position = null ): string {
		$GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'][] = array(
			'parent_slug' => $parent_slug,
			'page_title'  => $page_title,
			'menu_title'  => $menu_title,
			'capability'  => $capability,
			'menu_slug'   => $menu_slug,
			'callback'    => $callback,
			'position'    => $position,
		);

		return $parent_slug . '_page_' . $menu_slug;
	}
}

if ( ! function_exists( 'wp_roles' ) ) {
	/**
	 * Return test roles.
	 */
	function wp_roles(): object {
		return (object) array(
			'roles' => $GLOBALS['aculect_ai_companion_test_roles'],
		);
	}
}

if ( ! function_exists( 'translate_user_role' ) ) {
	/**
	 * Return an untranslated role name for tests.
	 *
	 * @param string $name Role display name.
	 */
	function translate_user_role( string $name ): string {
		return $name;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	/**
	 * Return test users filtered by role.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, mixed>
	 */
	function get_users( array $args = array() ): array {
		$users = array_values( $GLOBALS['aculect_ai_companion_test_users'] );
		if ( isset( $args['role'] ) ) {
			$role  = (string) $args['role'];
			$users = array_values(
				array_filter(
					$users,
					static fn( object $user ): bool => in_array( $role, (array) ( $user->roles ?? array() ), true )
				)
			);
		}

		if ( isset( $args['number'] ) && (int) $args['number'] > 0 ) {
			$users = array_slice( $users, 0, (int) $args['number'] );
		}

		if ( isset( $args['fields'] ) && 'ID' === $args['fields'] ) {
			return array_map( static fn( object $user ): int => (int) $user->ID, $users );
		}

		return $users;
	}
}

if ( ! function_exists( 'count_users' ) ) {
	/**
	 * Return test user counts by role.
	 *
	 * @return array{total_users:int, avail_roles:array<string, int>}
	 */
	function count_users(): array {
		if ( array_key_exists( 'aculect_ai_companion_count_users_calls', $GLOBALS ) ) {
			++$GLOBALS['aculect_ai_companion_count_users_calls'];
		}

		$roles = array();
		foreach ( $GLOBALS['aculect_ai_companion_test_users'] as $user ) {
			foreach ( (array) ( $user->roles ?? array() ) as $role ) {
				$role           = (string) $role;
				$roles[ $role ] = ( $roles[ $role ] ?? 0 ) + 1;
			}
		}

		return array(
			'total_users' => count( $GLOBALS['aculect_ai_companion_test_users'] ),
			'avail_roles' => $roles,
		);
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * Return one test user.
	 *
	 * @param int $user_id User ID.
	 * @return object|false
	 */
	function get_userdata( int $user_id ): object|false {
		return $GLOBALS['aculect_ai_companion_test_users'][ $user_id ] ?? false;
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

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Store a test transient value.
	 *
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration Expiration in seconds.
	 * @return bool
	 */
	function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['aculect_ai_companion_test_transients'][ $transient ] = array(
			'value'      => $value,
			'expires_at' => $expiration > 0 ? time() + $expiration : 0,
		);

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Return a test transient value.
	 *
	 * @param string $transient Transient name.
	 * @return mixed
	 */
	function get_transient( string $transient ): mixed {
		$item = $GLOBALS['aculect_ai_companion_test_transients'][ $transient ] ?? null;
		if ( ! is_array( $item ) ) {
			return false;
		}

		if ( ! empty( $item['expires_at'] ) && (int) $item['expires_at'] < time() ) {
			unset( $GLOBALS['aculect_ai_companion_test_transients'][ $transient ] );
			return false;
		}

		return $item['value'];
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Delete a test transient.
	 *
	 * @param string $transient Transient name.
	 * @return bool
	 */
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['aculect_ai_companion_test_transients'][ $transient ] );

		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Return untranslated text in tests.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 */
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Return untranslated escaped text in tests.
	 *
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Return a deterministic test admin URL.
	 *
	 * @param string $path Optional path.
	 */
	function admin_url( string $path = '' ): string {
		return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/**
	 * Add query args to a URL for tests.
	 *
	 * @param array<string, mixed>|string $args Query args or key.
	 * @param mixed                       $value Query value or URL.
	 * @param string|null                 $url   URL when key/value form is used.
	 */
	function add_query_arg( array|string $args, mixed $value = null, ?string $url = null ): string {
		if ( is_array( $args ) ) {
			$query_args = $args;
			$url        = is_string( $value ) ? $value : (string) $url;
		} else {
			$query_args = array( $args => $value );
			$url        = (string) $url;
		}

		$url       = '' === $url ? 'https://example.com/' : $url;
		$separator = str_contains( $url, '?' ) ? '&' : '?';

		return $url . $separator . http_build_query( $query_args );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Return a deterministic current user ID.
	 */
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['aculect_ai_companion_test_current_user_id'] ?? 1 );
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	/**
	 * Return deterministic test nonces.
	 *
	 * @param string $action Nonce action.
	 */
	function wp_create_nonce( string $action = '' ): string {
		return 'nonce-' . $action;
	}
}

if ( ! function_exists( 'get_file_data' ) ) {
	/**
	 * Parse simple plugin headers for tests.
	 *
	 * @param string               $file            File path.
	 * @param array<string,string> $default_headers Header map.
	 * @param string               $context         Header context.
	 * @return array<string,string>
	 */
	function get_file_data( string $file, array $default_headers, string $context = '' ): array {
		unset( $context );

		if ( ! file_exists( $file ) ) {
			return array_fill_keys( array_keys( $default_headers ), '' );
		}

		$contents = file_get_contents( $file );
		$contents = false === $contents ? '' : $contents;
		$data     = array();

		foreach ( $default_headers as $key => $header ) {
			$pattern      = '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi';
			$data[ $key ] = preg_match( $pattern, $contents, $matches ) ? trim( $matches[1] ) : '';
		}

		return $data;
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

if ( ! function_exists( 'site_url' ) ) {
	/**
	 * Return a deterministic test site URL.
	 *
	 * @param string $path Optional path.
	 */
	function site_url( string $path = '' ): string {
		return 'https://example.com' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Return a deterministic test REST URL.
	 *
	 * @param string $path Optional path.
	 */
	function rest_url( string $path = '' ): string {
		return 'https://example.com/wp-json/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * Return deterministic test site metadata.
	 *
	 * @param string $show Requested field.
	 */
	function get_bloginfo( string $show = '' ): string {
		return 'version' === $show ? '6.8.1' : '';
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	/**
	 * Return a deterministic test environment type.
	 */
	function wp_get_environment_type(): string {
		return (string) ( $GLOBALS['aculect_ai_companion_test_environment_type'] ?? 'production' );
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

if ( ! function_exists( 'get_user_by' ) ) {
	/**
	 * Return one test user by ID.
	 *
	 * @param string $field User field.
	 * @param mixed  $value Field value.
	 * @return object|false
	 */
	function get_user_by( string $field, mixed $value ): object|false {
		if ( 'id' !== $field && 'ID' !== $field ) {
			return false;
		}

		return $GLOBALS['aculect_ai_companion_test_users'][ (int) $value ] ?? false;
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

if ( ! function_exists( 'wp_hash_password' ) ) {
	/**
	 * Hash a password for tests.
	 *
	 * @param string $password Raw password.
	 */
	function wp_hash_password( string $password ): string {
		return password_hash( $password, PASSWORD_BCRYPT );
	}
}

if ( ! function_exists( 'wp_check_password' ) ) {
	/**
	 * Check a password hash for tests.
	 *
	 * @param string $password Raw password.
	 * @param string $hash     Password hash.
	 */
	function wp_check_password( string $password, string $hash ): bool {
		return password_verify( $password, $hash );
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

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Strip all HTML tags for tests.
	 *
	 * @param string $text Raw text.
	 */
	function wp_strip_all_tags( string $text ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stub implements the WordPress helper.
		return strip_tags( $text );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	/**
	 * Sanitize a filename for tests.
	 *
	 * @param string $filename Raw filename.
	 */
	function sanitize_file_name( string $filename ): string {
		return trim( preg_replace( '/[^A-Za-z0-9._-]+/', '-', $filename ) ?? '', '.-' );
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

if ( ! function_exists( 'parse_blocks' ) ) {
	/**
	 * Parse serialized block comments well enough for unit tests.
	 *
	 * @param string $content Serialized block content.
	 * @return list<array<string, mixed>>
	 */
	function parse_blocks( string $content ): array {
		preg_match_all( '/<!--\s+wp:([A-Za-z0-9_\/.-]+)(?:\s+[^>]*)?-->/i', $content, $matches );

		$blocks = array();
		foreach ( $matches[1] ?? array() as $name ) {
			$name     = str_contains( (string) $name, '/' ) ? (string) $name : 'core/' . (string) $name;
			$blocks[] = array(
				'blockName'   => $name,
				'innerBlocks' => array(),
			);
		}

		return $blocks;
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.NamingConventions.NoReservedKeywordParameterNames

if ( ! class_exists( 'WP_REST_Server' ) ) {
	/**
	 * Minimal REST server constants used by route registration tests.
	 */
	class WP_REST_Server {
		public const READABLE   = 'GET';
		public const CREATABLE  = 'POST';
		public const EDITABLE   = 'POST, PUT, PATCH';
		public const DELETABLE  = 'DELETE';
		public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
	}
}

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	/**
	 * Minimal block type registry test double.
	 */
	class WP_Block_Type_Registry {

		private static ?self $instance = null;

		/**
		 * Return the singleton registry.
		 */
		public static function get_instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Register a test block.
		 *
		 * @param string              $name Block name.
		 * @param array<string,mixed> $args Block metadata.
		 */
		public function register( string $name, array|object $args = array() ): object {
			$block       = is_object( $args ) ? $args : (object) $args;
			$block->name = $name;

			$GLOBALS['aculect_ai_companion_test_blocks'][ $name ] = $block;

			return $block;
		}

		/**
		 * Return all registered test blocks.
		 *
		 * @return array<string, object>
		 */
		public function get_all_registered(): array {
			return $GLOBALS['aculect_ai_companion_test_blocks'];
		}

		/**
		 * Return one registered test block.
		 */
		public function get_registered( string $name ): ?object {
			return $GLOBALS['aculect_ai_companion_test_blocks'][ $name ] ?? null;
		}

		/**
		 * Reset registered test blocks.
		 */
		public function unregister_all(): void {
			$GLOBALS['aculect_ai_companion_test_blocks'] = array();
		}
	}
}

if ( ! class_exists( 'WP_Block_Patterns_Registry' ) ) {
	/**
	 * Minimal block patterns registry test double.
	 */
	class WP_Block_Patterns_Registry {

		private static ?self $instance = null;

		/**
		 * Return the singleton registry.
		 */
		public static function get_instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Register a test pattern.
		 *
		 * @param string              $name    Pattern name.
		 * @param array<string,mixed> $pattern Pattern metadata.
		 */
		public function register( string $name, array $pattern ): bool {
			$pattern['name'] = $pattern['name'] ?? $name;

			$GLOBALS['aculect_ai_companion_test_patterns'][ $name ] = $pattern;

			return true;
		}

		/**
		 * Return all registered test patterns.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		public function get_all_registered(): array {
			return $GLOBALS['aculect_ai_companion_test_patterns'];
		}

		/**
		 * Reset registered test patterns.
		 */
		public function unregister_all(): void {
			$GLOBALS['aculect_ai_companion_test_patterns'] = array();
		}
	}
}

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
