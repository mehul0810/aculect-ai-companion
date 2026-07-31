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
	define( 'ACULECT_AI_COMPANION_VERSION', '0.6.0' );
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
$GLOBALS['aculect_ai_companion_test_post_meta']   = array();
$GLOBALS['aculect_ai_companion_test_url_to_postid'] = array();
$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array();
$GLOBALS['aculect_ai_companion_test_post_types']  = array();
$GLOBALS['aculect_ai_companion_test_taxonomies']  = array();
$GLOBALS['aculect_ai_companion_test_post_statuses'] = array();
$GLOBALS['aculect_ai_companion_test_blocks']      = array();
$GLOBALS['aculect_ai_companion_test_patterns']    = array();
$GLOBALS['aculect_ai_companion_test_block_templates'] = array();
$GLOBALS['aculect_ai_companion_test_global_settings'] = array();
$GLOBALS['aculect_ai_companion_test_global_styles'] = array();
$GLOBALS['aculect_ai_companion_test_theme_supports'] = array();
$GLOBALS['aculect_ai_companion_test_registered_nav_menus'] = array();
$GLOBALS['aculect_ai_companion_test_nav_menu_locations'] = array();
$GLOBALS['aculect_ai_companion_test_nav_menus'] = array();
$GLOBALS['aculect_ai_companion_test_nav_menu_items'] = array();
$GLOBALS['aculect_ai_companion_test_registered_settings'] = array();

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

		$failed_options = $GLOBALS['aculect_ai_companion_test_failed_option_updates'] ?? array();
		if ( is_array( $failed_options ) && in_array( $option, $failed_options, true ) ) {
			return false;
		}

		$GLOBALS['aculect_ai_companion_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * Return a test network option value.
	 *
	 * @param string $option  Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_site_option( string $option, mixed $default = false ): mixed {
		return array_key_exists( $option, $GLOBALS['aculect_ai_companion_test_site_options'] ?? array() ) ? $GLOBALS['aculect_ai_companion_test_site_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'get_site_transient' ) ) {
	/**
	 * Return a test site transient value.
	 *
	 * @param string $transient Transient name.
	 * @return mixed
	 */
	function get_site_transient( string $transient ): mixed {
		return get_site_option( '_site_transient_' . $transient, false );
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * Return whether the test site is multisite.
	 */
	function is_multisite(): bool {
		return (bool) ( $GLOBALS['aculect_ai_companion_test_is_multisite'] ?? false );
	}
}

if ( ! function_exists( 'get_current_blog_id' ) ) {
	/**
	 * Return the current test site ID.
	 */
	function get_current_blog_id(): int {
		return (int) ( $GLOBALS['aculect_ai_companion_test_blog_id'] ?? 1 );
	}
}

if ( ! function_exists( 'get_stylesheet' ) ) {
	/**
	 * Return the active test stylesheet.
	 */
	function get_stylesheet(): string {
		return (string) ( $GLOBALS['aculect_ai_companion_test_stylesheet'] ?? 'test-theme' );
	}
}

if ( ! function_exists( 'get_template' ) ) {
	/**
	 * Return the active test template.
	 */
	function get_template(): string {
		return (string) ( $GLOBALS['aculect_ai_companion_test_template'] ?? get_stylesheet() );
	}
}

if ( ! function_exists( 'current_theme_supports' ) ) {
	/**
	 * Return whether the active test theme supports a feature.
	 *
	 * @param string $feature Theme feature.
	 */
	function current_theme_supports( string $feature ): bool {
		$supports = $GLOBALS['aculect_ai_companion_test_theme_supports'] ?? array();
		if ( array_key_exists( $feature, is_array( $supports ) ? $supports : array() ) ) {
			return (bool) $supports[ $feature ];
		}

		if ( 'menus' === $feature ) {
			return array() !== ( $GLOBALS['aculect_ai_companion_test_registered_nav_menus'] ?? array() );
		}

		return false;
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

		$failed_options = $GLOBALS['aculect_ai_companion_test_failed_option_adds'] ?? array();
		if ( is_array( $failed_options ) && in_array( $option, $failed_options, true ) ) {
			return false;
		}

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
		$test_callbacks = $GLOBALS['aculect_ai_companion_test_filter_callbacks'] ?? array();
		$callback       = is_array( $test_callbacks ) ? ( $test_callbacks[ $hook_name ] ?? null ) : null;
		if ( is_callable( $callback ) ) {
			return $callback( $value, ...$args );
		}

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
		public int $post_parent = 0;
		public string $post_mime_type = '';
		public string $post_date = '';
		public string $post_date_gmt = '';
		public string $post_modified_gmt = '';

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

if ( ! class_exists( 'WP_Post_Type' ) ) {
	/**
	 * Minimal post type object used by unit tests.
	 */
	class WP_Post_Type {
		public string $name = 'post';
		public string $label = 'Posts';
		public bool $public = true;
		public bool $show_ui = true;
		public bool $show_in_rest = true;
		public bool $hierarchical = false;
		public string $rest_base = '';
		public string $rest_namespace = 'wp/v2';
		public object $cap;

		/**
		 * @param string               $name Post type name.
		 * @param array<string, mixed> $args Post type args.
		 */
		public function __construct( string $name = 'post', array $args = array() ) {
			$this->name         = $name;
			$this->label        = (string) ( $args['label'] ?? ucfirst( $name ) );
			$this->public       = (bool) ( $args['public'] ?? true );
			$this->show_ui      = (bool) ( $args['show_ui'] ?? true );
			$this->show_in_rest = (bool) ( $args['show_in_rest'] ?? true );
			$this->hierarchical = (bool) ( $args['hierarchical'] ?? false );
			$this->rest_base    = (string) ( $args['rest_base'] ?? $name );
			$this->rest_namespace = (string) ( $args['rest_namespace'] ?? 'wp/v2' );
			$this->cap          = (object) array_merge(
				array(
					'read'              => 'read',
					'edit_posts'        => 'edit_posts',
					'create_posts'      => 'edit_posts',
					'publish_posts'     => 'publish_posts',
					'edit_others_posts' => 'edit_others_posts',
					'delete_posts'      => 'delete_posts',
				),
				(array) ( $args['cap'] ?? array() )
			);
		}
	}
}

if ( ! class_exists( 'WP_Taxonomy' ) ) {
	class WP_Taxonomy {
		public string $name = 'category';
		public string $label = 'Categories';
		public bool $public = true;
		public bool $show_ui = true;
		public bool $show_in_rest = true;
		public bool $hierarchical = false;
		public string $rest_base = '';
		public string $rest_namespace = 'wp/v2';
		/** @var list<string> */
		public array $object_type = array( 'post' );
		public object $cap;

		/**
		 * @param string               $name Taxonomy name.
		 * @param array<string, mixed> $args Taxonomy args.
		 */
		public function __construct( string $name = 'category', array $args = array() ) {
			$this->name           = $name;
			$this->label          = (string) ( $args['label'] ?? ucfirst( $name ) );
			$this->public         = (bool) ( $args['public'] ?? true );
			$this->show_ui        = (bool) ( $args['show_ui'] ?? true );
			$this->show_in_rest   = (bool) ( $args['show_in_rest'] ?? true );
			$this->hierarchical   = (bool) ( $args['hierarchical'] ?? false );
			$this->rest_base      = (string) ( $args['rest_base'] ?? $name );
			$this->rest_namespace = (string) ( $args['rest_namespace'] ?? 'wp/v2' );
			$this->object_type    = array_values( array_map( 'strval', (array) ( $args['object_type'] ?? array( 'post' ) ) ) );
			$this->cap            = (object) array_merge(
				array(
					'assign_terms' => 'edit_posts',
					'edit_terms'   => 'manage_categories',
					'manage_terms' => 'manage_categories',
				),
				(array) ( $args['cap'] ?? array() )
			);
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/**
	 * Minimal term object used by unit tests.
	 */
	class WP_Term {
		public int $term_id = 0;
		public string $name = '';
		public string $slug = '';
		public string $taxonomy = '';
		public string $description = '';
		public int $parent = 0;
		public int $count = 0;

		/**
		 * @param array<string, mixed> $data Term fields.
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

if ( ! function_exists( 'wp_get_object_terms' ) ) {
	/**
	 * Return assigned object terms for tests.
	 *
	 * @param int|string          $object_id  Object ID.
	 * @param string|array<mixed> $taxonomies Taxonomy names.
	 * @param array<string,mixed> $args       Query args.
	 * @return list<WP_Term>
	 */
	function wp_get_object_terms( int|string $object_id, string|array $taxonomies, array $args = array() ): array {
		unset( $args );

		$object_id = absint( $object_id );
		$allowed   = array_map( 'strval', (array) $taxonomies );
		$assigned  = $GLOBALS['aculect_ai_companion_test_object_terms'][ $object_id ] ?? array();
		$terms     = array();

		foreach ( is_array( $assigned ) ? $assigned : array() as $term ) {
			$term = $term instanceof WP_Term ? $term : new WP_Term( is_array( $term ) ? $term : array() );
			if ( in_array( $term->taxonomy, $allowed, true ) ) {
				$terms[] = $term;
			}
		}

		usort( $terms, static fn( WP_Term $a, WP_Term $b ): int => $a->term_id <=> $b->term_id );

		return $terms;
	}
}

if ( ! function_exists( 'get_registered_nav_menus' ) ) {
	/**
	 * Return registered classic navigation menu locations for tests.
	 *
	 * @return array<string, string>
	 */
	function get_registered_nav_menus(): array {
		return array_map( 'strval', (array) ( $GLOBALS['aculect_ai_companion_test_registered_nav_menus'] ?? array() ) );
	}
}

if ( ! function_exists( 'get_nav_menu_locations' ) ) {
	/**
	 * Return classic navigation menu assignments for tests.
	 *
	 * @return array<string, int>
	 */
	function get_nav_menu_locations(): array {
		return array_map( 'absint', (array) ( $GLOBALS['aculect_ai_companion_test_nav_menu_locations'] ?? array() ) );
	}
}

if ( ! function_exists( 'wp_get_nav_menus' ) ) {
	/**
	 * Return test classic nav menus.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return list<WP_Term>
	 */
	function wp_get_nav_menus( array $args = array() ): array {
		unset( $args );

		$menus = array();
		foreach ( (array) ( $GLOBALS['aculect_ai_companion_test_nav_menus'] ?? array() ) as $menu ) {
			$menu = $menu instanceof WP_Term ? $menu : new WP_Term( is_array( $menu ) ? $menu : array() );
			if ( 'nav_menu' === $menu->taxonomy || '' === $menu->taxonomy ) {
				if ( '' === $menu->taxonomy ) {
					$menu->taxonomy = 'nav_menu';
				}
				$menus[] = $menu;
			}
		}

		usort( $menus, static fn ( WP_Term $a, WP_Term $b ): int => strcmp( $a->name, $b->name ) );

		return $menus;
	}
}

if ( ! function_exists( 'wp_get_nav_menu_object' ) ) {
	/**
	 * Resolve a test classic nav menu by ID, slug, or name.
	 *
	 * @param WP_Term|int|string $menu Menu identifier.
	 */
	function wp_get_nav_menu_object( WP_Term|int|string $menu ): ?WP_Term {
		if ( $menu instanceof WP_Term ) {
			return $menu;
		}

		$menu_id = is_numeric( $menu ) ? absint( $menu ) : 0;
		$key     = sanitize_key( (string) $menu );
		$name    = sanitize_text_field( (string) $menu );

		foreach ( wp_get_nav_menus() as $term ) {
			if ( ( $menu_id > 0 && $menu_id === $term->term_id ) || $key === $term->slug || $name === $term->name ) {
				return $term;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
	/**
	 * Return test classic nav menu items.
	 *
	 * @param WP_Term|int|string    $menu Menu identifier.
	 * @param array<string, mixed> $args Query args.
	 * @return list<object|array<string, mixed>>
	 */
	function wp_get_nav_menu_items( WP_Term|int|string $menu, array $args = array() ): array {
		unset( $args );

		$term = wp_get_nav_menu_object( $menu );
		if ( ! $term instanceof WP_Term ) {
			return array();
		}

		$items = $GLOBALS['aculect_ai_companion_test_nav_menu_items'][ $term->term_id ] ?? array();
		$items = is_array( $items ) ? array_values( $items ) : array();

		usort(
			$items,
			static function ( mixed $a, mixed $b ): int {
				$a_order = is_object( $a ) ? (int) ( $a->menu_order ?? 0 ) : (int) ( $a['menu_order'] ?? 0 );
				$b_order = is_object( $b ) ? (int) ( $b->menu_order ?? 0 ) : (int) ( $b['menu_order'] ?? 0 );
				return $a_order <=> $b_order;
			}
		);

		return $items;
	}
}

if ( ! function_exists( 'get_post_types' ) ) {
	/**
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, WP_Post_Type|string>
	 */
	function get_post_types( array $args = array(), string $output = 'names' ): array {
		unset( $args );

		$types = $GLOBALS['aculect_ai_companion_test_post_types'];
		if ( array() === $types ) {
			$types = array(
				'post' => new WP_Post_Type( 'post', array( 'label' => 'Posts', 'rest_base' => 'posts' ) ),
				'page' => new WP_Post_Type( 'page', array( 'label' => 'Pages', 'hierarchical' => true, 'rest_base' => 'pages' ) ),
			);
		}

		$objects = array();
		foreach ( $types as $name => $type ) {
			if ( false === $type ) {
				continue;
			}

			$objects[ (string) $name ] = $type instanceof WP_Post_Type ? $type : new WP_Post_Type( (string) $name, is_array( $type ) ? $type : array() );
		}

		return 'objects' === $output ? $objects : array_keys( $objects );
	}
}

if ( ! function_exists( 'get_taxonomies' ) ) {
	/**
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, WP_Taxonomy|string>
	 */
	function get_taxonomies( array $args = array(), string $output = 'names' ): array {
		unset( $args );

		$taxonomies = $GLOBALS['aculect_ai_companion_test_taxonomies'];
		if ( array() === $taxonomies ) {
			$taxonomies = array(
				'category' => new WP_Taxonomy( 'category', array( 'label' => 'Categories', 'hierarchical' => true ) ),
				'post_tag' => new WP_Taxonomy( 'post_tag', array( 'label' => 'Tags' ) ),
			);
		}

		$objects = array();
		foreach ( $taxonomies as $name => $taxonomy ) {
			if ( false === $taxonomy ) {
				continue;
			}

			$objects[ (string) $name ] = $taxonomy instanceof WP_Taxonomy ? $taxonomy : new WP_Taxonomy( (string) $name, is_array( $taxonomy ) ? $taxonomy : array() );
		}

		return 'objects' === $output ? $objects : array_keys( $objects );
	}
}

if ( ! function_exists( 'get_object_taxonomies' ) ) {
	/**
	 * @return array<int|string, mixed>
	 */
	function get_object_taxonomies( string $object_type, string $output = 'names' ): array {
		$matches = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $name => $taxonomy ) {
			if ( $taxonomy instanceof WP_Taxonomy && in_array( $object_type, $taxonomy->object_type, true ) ) {
				$matches[ (string) $name ] = 'objects' === $output ? $taxonomy : (string) $name;
			}
		}

		return 'objects' === $output ? $matches : array_values( $matches );
	}
}

if ( ! function_exists( 'get_post_stati' ) ) {
	/**
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, object|string>
	 */
	function get_post_stati( array $args = array(), string $output = 'names' ): array {
		unset( $args );

		$statuses = $GLOBALS['aculect_ai_companion_test_post_statuses'];
		if ( array() === $statuses ) {
			$statuses = array(
				'publish' => (object) array( 'name' => 'publish', 'label' => 'Published', 'public' => true, 'private' => false, 'protected' => false, 'internal' => false, 'show_in_admin_all_list' => true ),
				'draft'   => (object) array( 'name' => 'draft', 'label' => 'Draft', 'public' => false, 'private' => false, 'protected' => false, 'internal' => false, 'show_in_admin_all_list' => true ),
			);
		}

		return 'objects' === $output ? $statuses : array_keys( $statuses );
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

if ( ! function_exists( 'get_post_type' ) ) {
	/**
	 * Return a test post type.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 */
	function get_post_type( int|\WP_Post $post ): string {
		$post = $post instanceof WP_Post ? $post : get_post( $post );

		return $post instanceof WP_Post ? (string) $post->post_type : '';
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	/**
	 * Return a test post type object.
	 *
	 * @param string $post_type Post type slug.
	 */
	function get_post_type_object( string $post_type ): ?WP_Post_Type {
		$type = $GLOBALS['aculect_ai_companion_test_post_types'][ $post_type ] ?? null;
		if ( false === $type ) {
			return null;
		}

		if ( $type instanceof WP_Post_Type ) {
			return $type;
		}

		return new WP_Post_Type( $post_type );
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	/**
	 * Return stored test terms for one post/taxonomy pair.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @param array  $args     Query args.
	 * @return array<int, \WP_Term>
	 */
	function wp_get_post_terms( int $post_id, string $taxonomy, array $args = array() ): array {
		unset( $args );

		$terms = $GLOBALS['aculect_ai_companion_test_post_terms'][ $post_id ][ $taxonomy ] ?? array();
		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					static fn ( mixed $term ): ?WP_Term => $term instanceof WP_Term ? $term : ( is_array( $term ) ? new WP_Term( $term ) : null ),
					$terms
				)
			)
		);
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
		$denied = $GLOBALS['aculect_ai_companion_test_denied_caps'] ?? array();
		if ( 'read_post' === $capability && isset( $args[0] ) ) {
			$denied_post_ids = $GLOBALS['aculect_ai_companion_test_denied_post_ids'] ?? array();
			if ( in_array( absint( $args[0] ), is_array( $denied_post_ids ) ? $denied_post_ids : array(), true ) ) {
				return false;
			}
		}

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
	function add_submenu_page( ?string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', ?int $position = null ): string {
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

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * Minimal WordPress user object used by unit tests.
	 */
	class WP_User {
		public int $ID = 0;
		/** @var list<string> */
		public array $roles = array();

		/**
		 * @param array<string, mixed> $data User fields.
		 */
		public function __construct( array $data = array() ) {
			$this->ID    = absint( $data['ID'] ?? 0 );
			$this->roles = array_values( array_map( 'strval', (array) ( $data['roles'] ?? array() ) ) );
		}
	}
}

if ( ! function_exists( 'wp_get_current_user' ) ) {
	/**
	 * Return the current test user.
	 */
	function wp_get_current_user(): WP_User {
		$user = get_userdata( get_current_user_id() );
		if ( $user instanceof WP_User ) {
			return $user;
		}

		return is_object( $user ) ? new WP_User( (array) $user ) : new WP_User();
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * Return whether a current test user is set.
	 */
	function is_user_logged_in(): bool {
		return get_current_user_id() > 0;
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
		$failed_options = $GLOBALS['aculect_ai_companion_test_failed_option_deletes'] ?? array();
		if ( is_array( $failed_options ) && in_array( $option, $failed_options, true ) ) {
			return false;
		}

		unset( $GLOBALS['aculect_ai_companion_test_options'][ $option ] );

		return true;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * Record cache invalidation for tests.
	 *
	 * @param string $key   Cache key.
	 * @param string $group Cache group.
	 */
	function wp_cache_delete( string $key, string $group = '' ): bool {
		$GLOBALS['aculect_ai_companion_test_cache_deletes'][] = array(
			'key'   => $key,
			'group' => $group,
		);

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

if ( ! function_exists( 'nocache_headers' ) ) {
	/**
	 * No-op test replacement for WordPress cache headers.
	 */
	function nocache_headers(): void {}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	/**
	 * Render a deterministic nonce field for tests.
	 *
	 * @param string $action Nonce action.
	 * @param string $name   Nonce field name.
	 */
	function wp_nonce_field( string $action = '-1', string $name = '_wpnonce' ): void {
		printf(
			'<input type="hidden" name="%s" value="%s">',
			esc_attr( $name ),
			esc_attr( wp_create_nonce( $action ) )
		);
	}
}

if ( ! function_exists( 'wp_is_block_theme' ) ) {
	/**
	 * Return active test theme type.
	 */
	function wp_is_block_theme(): bool {
		return (bool) ( $GLOBALS['aculect_ai_companion_test_is_block_theme'] ?? true );
	}
}

if ( ! function_exists( 'wp_get_global_settings' ) ) {
	/**
	 * Return test Site Editor global settings.
	 *
	 * @return array<string, mixed>
	 */
	function wp_get_global_settings(): array {
		return $GLOBALS['aculect_ai_companion_test_global_settings'];
	}
}

if ( ! function_exists( 'wp_get_global_styles' ) ) {
	/**
	 * Return test Site Editor global styles.
	 *
	 * @return array<string, mixed>
	 */
	function wp_get_global_styles(): array {
		return $GLOBALS['aculect_ai_companion_test_global_styles'];
	}
}

if ( ! function_exists( 'get_block_templates' ) ) {
	/**
	 * Return test Site Editor templates.
	 *
	 * @param array<string, mixed> $query Template query.
	 * @param string               $template_type Template post type.
	 * @return list<object>
	 */
	function get_block_templates( array $query = array(), string $template_type = 'wp_template' ): array {
		unset( $query );

		$templates = $GLOBALS['aculect_ai_companion_test_block_templates'][ $template_type ] ?? array();
		if ( array() !== $templates ) {
			return $templates;
		}

		return array(
			(object) array(
				'id'             => 'twentytwentysix//index',
				'slug'           => 'index',
				'theme'          => 'twentytwentysix',
				'type'           => $template_type,
				'source'         => 'theme',
				'origin'         => 'theme',
				'status'         => 'publish',
				'title'          => 'Index',
				'description'    => 'Default template.',
				'wp_id'          => 0,
				'has_theme_file' => true,
				'is_custom'      => false,
				'content'        => '<!-- wp:group --><div class="wp-block-group"></div><!-- /wp:group -->',
			),
		);
	}
}

if ( ! function_exists( 'get_block_template' ) ) {
	/**
	 * Return one test Site Editor template.
	 *
	 * @param string $id Template ID.
	 * @param string $template_type Template post type.
	 */
	function get_block_template( string $id, string $template_type = 'wp_template' ): ?object {
		foreach ( get_block_templates( array(), $template_type ) as $template ) {
			if ( $id === (string) ( $template->id ?? '' ) ) {
				return $template;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	/**
	 * Return whether a test post type exists.
	 *
	 * @param string $post_type Post type.
	 */
	function post_type_exists( string $post_type ): bool {
		return 'wp_navigation' === $post_type || array_key_exists( $post_type, $GLOBALS['aculect_ai_companion_test_post_types'] );
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Return test posts for simple queries.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return list<WP_Post>
	 */
	function get_posts( array $args = array() ): array {
		$post_type = $args['post_type'] ?? '';
		$post_type = is_array( $post_type ) ? array_map( 'strval', $post_type ) : (string) $post_type;
		$status    = $args['post_status'] ?? '';
		$statuses  = is_array( $status ) ? array_map( 'strval', $status ) : ( '' === $status ? array() : array( (string) $status ) );
		$limit     = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : -1;
		$parent    = array_key_exists( 'post_parent', $args ) ? absint( $args['post_parent'] ) : null;
		$posts     = array();
		foreach ( $GLOBALS['aculect_ai_companion_test_posts'] as $post ) {
			$post = $post instanceof WP_Post ? $post : new WP_Post( is_array( $post ) ? $post : array() );
			if ( 'any' !== $post_type && '' !== $post_type && ( is_array( $post_type ) ? ! in_array( $post->post_type, $post_type, true ) : $post_type !== $post->post_type ) ) {
				continue;
			}
			if ( array() !== $statuses && ! in_array( $post->post_status, $statuses, true ) ) {
				continue;
			}
			if ( null !== $parent && $parent !== (int) $post->post_parent ) {
				continue;
			}

			$posts[] = $post;
			if ( 0 < $limit && count( $posts ) >= $limit ) {
				break;
			}
		}

		return $posts;
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	/**
	 * Return a test post title.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 */
	function get_the_title( WP_Post|int $post ): string {
		$post = is_int( $post ) ? get_post( $post ) : $post;

		return $post instanceof WP_Post ? $post->post_title : '';
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * Return a deterministic test permalink.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 */
	function get_permalink( WP_Post|int $post ): string {
		$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;

		return 'https://example.com/?p=' . $post_id;
	}
}

if ( ! function_exists( 'url_to_postid' ) ) {
	/**
	 * Resolve a test URL to a post ID.
	 *
	 * @param string $url URL.
	 */
	function url_to_postid( string $url ): int {
		return absint( $GLOBALS['aculect_ai_companion_test_url_to_postid'][ $url ] ?? 0 );
	}
}

if ( ! function_exists( 'get_edit_post_link' ) ) {
	/**
	 * Return a deterministic test edit link.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $context Link context.
	 */
	function get_edit_post_link( int $post_id, string $context = 'display' ): string {
		unset( $context );

		return admin_url( 'post.php?post=' . $post_id . '&action=edit' );
	}
}

if ( ! function_exists( 'post_type_supports' ) ) {
	/**
	 * Return whether a test post type supports a feature.
	 *
	 * @param string $post_type Post type.
	 * @param string $feature Feature name.
	 */
	function post_type_supports( string $post_type, string $feature ): bool {
		$supports = $GLOBALS['aculect_ai_companion_test_post_type_supports'][ $post_type ] ?? array( 'thumbnail' );

		return in_array( $feature, is_array( $supports ) ? $supports : array(), true );
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	/**
	 * Return a test featured image ID.
	 *
	 * @param WP_Post|int $post Post object or ID.
	 */
	function get_post_thumbnail_id( WP_Post|int $post ): int {
		$post_id = $post instanceof WP_Post ? $post->ID : (int) $post;

		return (int) ( $GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ]['_thumbnail_id'] ?? 0 );
	}
}

if ( ! function_exists( 'set_post_thumbnail' ) ) {
	/**
	 * Store a test featured image ID.
	 *
	 * @param int $post_id Post ID.
	 * @param int $thumbnail_id Attachment ID.
	 */
	function set_post_thumbnail( int $post_id, int $thumbnail_id ): bool {
		$GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ]['_thumbnail_id'] = $thumbnail_id;

		return true;
	}
}

if ( ! function_exists( 'delete_post_thumbnail' ) ) {
	/**
	 * Delete a test featured image ID.
	 *
	 * @param int $post_id Post ID.
	 */
	function delete_post_thumbnail( int $post_id ): bool {
		unset( $GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ]['_thumbnail_id'] );

		return true;
	}
}

if ( ! function_exists( 'wp_update_post' ) ) {
	/**
	 * Update a test post object.
	 *
	 * @param array<string,mixed> $postarr Post update payload.
	 * @param bool                $wp_error Whether to return WP_Error on failure.
	 * @return int|WP_Error
	 */
	function wp_update_post( array $postarr = array(), bool $wp_error = false ): int|WP_Error {
		unset( $wp_error );

		$post_id = absint( $postarr['ID'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'not_found', 'Post not found.' );
		}

		$map = array(
			'post_title'    => 'post_title',
			'post_content'  => 'post_content',
			'post_excerpt'  => 'post_excerpt',
			'post_name'     => 'post_name',
			'post_status'   => 'post_status',
			'post_author'   => 'post_author',
			'post_parent'   => 'post_parent',
			'post_date'     => 'post_date',
			'post_date_gmt' => 'post_date_gmt',
		);

		foreach ( $map as $payload_key => $property ) {
			if ( array_key_exists( $payload_key, $postarr ) ) {
				$post->{$property} = is_int( $post->{$property} ) ? absint( $postarr[ $payload_key ] ) : (string) $postarr[ $payload_key ];
			}
		}

		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = $post;

		return $post_id;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * Return test post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key Meta key.
	 * @param bool   $single Whether to return one value.
	 * @return mixed
	 */
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		$meta = $GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ] ?? array();
		if ( '' === $key ) {
			return $meta;
		}

		$value = $meta[ $key ] ?? '';
		if ( ! $single ) {
			return is_array( $value ) ? $value : array( $value );
		}

		return is_array( $value ) ? reset( $value ) : $value;
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	/**
	 * Store test post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key Meta key.
	 * @param mixed  $value Meta value.
	 */
	function update_post_meta( int $post_id, string $key, mixed $value ): bool {
		$GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * Record a scheduled single event for tests.
	 *
	 * @param int          $timestamp Unix timestamp.
	 * @param string       $hook      Hook name.
	 * @param array<mixed> $args      Event args.
	 * @param bool         $wp_error  Whether to return a WP_Error on failure.
	 */
	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array(), bool $wp_error = false ): bool|WP_Error {
		unset( $args );

		$literal_false_hooks = $GLOBALS['aculect_ai_companion_test_schedule_literal_false_hooks'] ?? array();
		if ( is_array( $literal_false_hooks ) && in_array( $hook, $literal_false_hooks, true ) ) {
			return false;
		}

		if ( ! empty( $GLOBALS['aculect_ai_companion_test_schedule_failure'] ) ) {
			return $wp_error ? new WP_Error( 'schedule_failed', 'Test event scheduling failed.' ) : false;
		}
		$failed_hooks = $GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'] ?? array();
		if ( is_array( $failed_hooks ) && in_array( $hook, $failed_hooks, true ) ) {
			return $wp_error ? new WP_Error( 'schedule_failed', 'Test event scheduling failed.' ) : false;
		}

		$GLOBALS['aculect_ai_companion_test_scheduled_events'][ $hook ] = $timestamp;

		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * Return the next scheduled timestamp for a test cron hook.
	 *
	 * @param string       $hook Event hook.
	 * @param array<mixed> $args Event args.
	 */
	function wp_next_scheduled( string $hook, array $args = array() ): int|false {
		unset( $args );

		$scheduled = $GLOBALS['aculect_ai_companion_test_scheduled_events'][ $hook ] ?? false;

		return is_numeric( $scheduled ) ? (int) $scheduled : false;
	}
}

if ( ! function_exists( 'wp_unschedule_event' ) ) {
	/**
	 * Remove one scheduled test event.
	 *
	 * @param int          $timestamp Event timestamp.
	 * @param string       $hook      Event hook.
	 * @param array<mixed> $args      Event args.
	 * @param bool         $wp_error  Whether to return a WP_Error on failure.
	 */
	function wp_unschedule_event( int $timestamp, string $hook, array $args = array(), bool $wp_error = false ): bool|WP_Error {
		unset( $timestamp, $args, $wp_error );

		if ( ! isset( $GLOBALS['aculect_ai_companion_test_scheduled_events'][ $hook ] ) ) {
			return false;
		}

		unset( $GLOBALS['aculect_ai_companion_test_scheduled_events'][ $hook ] );

		return true;
	}
}

if ( ! function_exists( 'wp_unschedule_hook' ) ) {
	/**
	 * Remove one scheduled hook from the test registry.
	 *
	 * @param string $hook Hook name.
	 */
	function wp_unschedule_hook( string $hook ): void {
		unset( $GLOBALS['aculect_ai_companion_test_scheduled_events'][ $hook ] );
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	/**
	 * Return a deterministic attachment URL.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	function wp_get_attachment_url( int $attachment_id ): string {
		$url = $GLOBALS['aculect_ai_companion_test_post_meta'][ $attachment_id ]['_source_url'] ?? '';
		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}

		return 'https://example.com/uploads/image-' . $attachment_id . '.jpg';
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	/**
	 * Return whether an attachment is image-like.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	function wp_attachment_is_image( int $attachment_id ): bool {
		$post = get_post( $attachment_id );

		return $post instanceof WP_Post && 'attachment' === $post->post_type && str_starts_with( $post->post_mime_type, 'image/' );
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	/**
	 * Return test attachment metadata.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>|false
	 */
	function wp_get_attachment_metadata( int $attachment_id ): array|false {
		$metadata = $GLOBALS['aculect_ai_companion_test_attachment_metadata'][ $attachment_id ]
			?? $GLOBALS['aculect_ai_companion_test_post_meta'][ $attachment_id ]['_wp_attachment_metadata']
			?? false;

		return is_array( $metadata ) ? $metadata : false;
	}
}

if ( ! function_exists( 'wp_update_attachment_metadata' ) ) {
	/**
	 * Store test attachment metadata.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $metadata Attachment metadata.
	 */
	function wp_update_attachment_metadata( int $attachment_id, array $metadata ): bool {
		$GLOBALS['aculect_ai_companion_test_attachment_metadata'][ $attachment_id ] = $metadata;
		$GLOBALS['aculect_ai_companion_test_post_meta'][ $attachment_id ]['_wp_attachment_metadata'] = $metadata;

		return true;
	}
}

if ( ! function_exists( 'wp_get_post_revisions' ) ) {
	/**
	 * Return test post revisions for a parent post.
	 *
	 * @param int                  $post_id Parent post ID.
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, WP_Post>
	 */
	function wp_get_post_revisions( int $post_id, array $args = array() ): array {
		unset( $args );

		$revisions = array();
		foreach ( $GLOBALS['aculect_ai_companion_test_post_revisions'][ $post_id ] ?? array() as $revision_id => $revision ) {
			$revision = $revision instanceof WP_Post ? $revision : new WP_Post( is_array( $revision ) ? $revision : array() );
			if ( $post_id === (int) $revision->post_parent ) {
				$revisions[ (int) $revision_id ] = $revision;
			}
		}

		return $revisions;
	}
}

if ( ! function_exists( 'get_post_revisions' ) ) {
	/**
	 * Return test post revisions for compatibility with older code paths.
	 *
	 * @param int                  $post_id Parent post ID.
	 * @param array<string, mixed> $args Query args.
	 * @return array<int, WP_Post>
	 */
	function get_post_revisions( int $post_id, array $args = array() ): array {
		return wp_get_post_revisions( $post_id, $args );
	}
}

if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	/**
	 * Return the parent post ID when a revision object is an autosave.
	 *
	 * @param WP_Post|int $post Revision object or ID.
	 * @return int|false
	 */
	function wp_is_post_autosave( WP_Post|int $post ): int|false {
		$revision = $post instanceof WP_Post ? $post : get_post( $post );
		if ( ! $revision instanceof WP_Post ) {
			return false;
		}

		return str_contains( $revision->post_name, 'autosave' ) ? (int) $revision->post_parent : false;
	}
}

if ( ! function_exists( 'wp_get_post_autosave' ) ) {
	/**
	 * Return a test autosave for a parent post and user.
	 *
	 * @param int $post_id Parent post ID.
	 * @param int $user_id User ID.
	 * @return WP_Post|false
	 */
	function wp_get_post_autosave( int $post_id, int $user_id = 0 ): WP_Post|false {
		$user_id  = 0 < $user_id ? $user_id : get_current_user_id();
		$autosave = $GLOBALS['aculect_ai_companion_test_post_autosaves'][ $post_id ][ $user_id ] ?? false;

		if ( $autosave instanceof WP_Post ) {
			return $autosave;
		}

		return is_array( $autosave ) ? new WP_Post( $autosave ) : false;
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	/**
	 * Trim text to a bounded number of words.
	 *
	 * @param string $text      Text.
	 * @param int    $num_words Word limit.
	 * @param string $more      Suffix.
	 */
	function wp_trim_words( string $text, int $num_words = 55, string $more = '...' ): string {
		$words = preg_split( '/\s+/', trim( wp_strip_all_tags( $text ) ) );
		$words = false === $words ? array() : array_values( array_filter( $words ) );

		if ( count( $words ) <= $num_words ) {
			return implode( ' ', $words );
		}

		return implode( ' ', array_slice( $words, 0, max( 1, $num_words ) ) ) . $more;
	}
}

if ( ! function_exists( 'get_registered_settings' ) ) {
	/**
	 * Return test registered settings metadata.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	function get_registered_settings(): array {
		return $GLOBALS['aculect_ai_companion_test_registered_settings'] ?: array(
			'blogname' => array(
				'group'       => 'general',
				'type'        => 'string',
				'description' => 'Site title.',
				'show_in_rest' => true,
				'default'     => '',
			),
		);
	}
}

if ( ! function_exists( 'plugin_basename' ) ) {
	/**
	 * Return a deterministic plugin basename for tests.
	 *
	 * @param string $file Plugin file path.
	 */
	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
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

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Validate deterministic test nonces.
	 *
	 * @param string $nonce  Nonce value.
	 * @param string $action Nonce action.
	 */
	function wp_verify_nonce( string $nonce, string $action = '-1' ): bool {
		return $nonce === wp_create_nonce( $action );
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	/**
	 * Fail fast in tests when wp_die() would have been called.
	 *
	 * @param mixed $message Error message.
	 * @param mixed $title   Error title.
	 * @param mixed $args    Additional args.
	 */
	function wp_die( mixed $message = '', mixed $title = '', mixed $args = array() ): never {
		unset( $title, $args );

		throw new \RuntimeException( is_scalar( $message ) ? (string) $message : 'wp_die' );
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
		$home = (string) ( $GLOBALS['aculect_ai_companion_test_home_url'] ?? 'https://example.com' );

		return rtrim( $home, '/' ) . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	/**
	 * Return a deterministic test site URL.
	 *
	 * @param string $path Optional path.
	 */
	function site_url( string $path = '' ): string {
		$site = (string) ( $GLOBALS['aculect_ai_companion_test_site_url'] ?? 'https://example.com' );

		return rtrim( $site, '/' ) . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * Return a deterministic test REST URL.
	 *
	 * @param string $path Optional path.
	 */
	function rest_url( string $path = '' ): string {
		$rest = (string) ( $GLOBALS['aculect_ai_companion_test_rest_url'] ?? 'https://example.com/wp-json/' );

		return rtrim( $rest, '/' ) . '/' . ltrim( $path, '/' );
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
		if ( isset( $GLOBALS['aculect_ai_companion_test_wp_hash_password_calls'] ) ) {
			++$GLOBALS['aculect_ai_companion_test_wp_hash_password_calls'];
		}

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

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Return post HTML unchanged for unit tests.
	 *
	 * @param string $data Raw post HTML.
	 */
	function wp_kses_post( string $data ): string {
		return $data;
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

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Escape a URL for tests.
	 *
	 * @param string $url Raw URL.
	 */
	function esc_url( string $url ): string {
		return esc_url_raw( $url );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Escape an attribute value for tests.
	 *
	 * @param string $text Raw text.
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escape HTML text for tests.
	 *
	 * @param string $text Raw text.
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	/**
	 * Sanitize a title-like value for tests.
	 *
	 * @param string $title Raw title.
	 */
	function sanitize_title( string $title ): string {
		$title = strtolower( wp_strip_all_tags( $title ) );
		$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';

		return trim( $title, '-' );
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

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	/**
	 * Return deterministic test HTTP GET responses.
	 *
	 * @param string              $url  Request URL.
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>|WP_Error
	 */
	function wp_safe_remote_get( string $url, array $args = array() ): array|WP_Error {
		$callback = $GLOBALS['aculect_ai_companion_test_http_get'] ?? null;
		if ( is_callable( $callback ) ) {
			$result = $callback( $url, $args );
			if ( $result instanceof WP_Error || is_array( $result ) ) {
				return $result;
			}
		}

		return new WP_Error( 'http_not_mocked', 'No test HTTP response was registered.' );
	}
}

if ( ! function_exists( 'wp_safe_remote_head' ) ) {
	/**
	 * Return deterministic test HTTP HEAD responses.
	 *
	 * @param string              $url  Request URL.
	 * @param array<string,mixed> $args Request args.
	 * @return array<string,mixed>|WP_Error
	 */
	function wp_safe_remote_head( string $url, array $args = array() ): array|WP_Error {
		$callback = $GLOBALS['aculect_ai_companion_test_http_head'] ?? null;
		if ( is_callable( $callback ) ) {
			$result = $callback( $url, $args );
			if ( $result instanceof WP_Error || is_array( $result ) ) {
				return $result;
			}
		}

		return new WP_Error( 'http_not_mocked', 'No test HTTP response was registered.' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * Return a test HTTP status code.
	 *
	 * @param array<string,mixed> $response HTTP response.
	 */
	function wp_remote_retrieve_response_code( array $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * Return a test HTTP body.
	 *
	 * @param array<string,mixed> $response HTTP response.
	 */
	function wp_remote_retrieve_body( array $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
	/**
	 * Return test HTTP headers.
	 *
	 * @param array<string,mixed> $response HTTP response.
	 * @return array<string,mixed>
	 */
	function wp_remote_retrieve_headers( array $response ): array {
		return is_array( $response['headers'] ?? null ) ? $response['headers'] : array();
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
		$offset = 0;

		return aculect_ai_companion_test_parse_blocks_fragment( $content, $offset );
	}
}

if ( ! function_exists( 'aculect_ai_companion_test_parse_blocks_fragment' ) ) {
	/**
	 * Parse a block fragment recursively for unit tests.
	 *
	 * @param string $content Serialized block content.
	 * @param int    $offset  Current parser offset.
	 * @return list<array<string, mixed>>
	 */
	function aculect_ai_companion_test_parse_blocks_fragment( string $content, int &$offset = 0 ): array {
		$blocks = array();
		$length = strlen( $content );

		while ( $offset < $length && preg_match( '/<!--\s+(\/?)wp:([A-Za-z0-9_\/.-]+)(?:\s+({.*?}))?\s*(\/?)-->/s', $content, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
			$is_closer = '/' === $matches[1][0];
			if ( $is_closer ) {
				$offset = $matches[0][1] + strlen( $matches[0][0] );
				break;
			}

			$name        = str_contains( (string) $matches[2][0], '/' ) ? (string) $matches[2][0] : 'core/' . (string) $matches[2][0];
			$attrs       = array();
			$attrs_json  = $matches[3][0] ?? '';
			$self_closed = '/' === ( $matches[4][0] ?? '' );
			if ( '' !== $attrs_json ) {
				$decoded = json_decode( $attrs_json, true );
				$attrs   = is_array( $decoded ) ? $decoded : array();
			}

			$open_end = $matches[0][1] + strlen( $matches[0][0] );
			$offset   = $open_end;
			if ( $self_closed ) {
				$blocks[] = array(
					'blockName'    => $name,
					'attrs'        => $attrs,
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				);
				continue;
			}

			$close_pattern = '/<!--\s+\/wp:' . preg_quote( (string) $matches[2][0], '/' ) . '\s+-->/s';
			if ( 1 !== preg_match( $close_pattern, $content, $close_match, PREG_OFFSET_CAPTURE, $offset ) ) {
				break;
			}

			$inner_start  = $offset;
			$inner_length = $close_match[0][1] - $inner_start;
			$inner_html   = substr( $content, $inner_start, $inner_length );
			$inner_offset = 0;
			$inner_blocks = aculect_ai_companion_test_parse_blocks_fragment( $inner_html, $inner_offset );
			$offset       = $close_match[0][1] + strlen( $close_match[0][0] );

			$blocks[] = array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerBlocks'  => $inner_blocks,
				'innerHTML'    => $inner_html,
				'innerContent' => array( $inner_html ),
			);
		}

		return $blocks;
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	/**
	 * Serialize parsed test blocks.
	 *
	 * @param list<array<string, mixed>> $blocks Parsed blocks.
	 */
	function serialize_blocks( array $blocks ): string {
		return implode( '', array_map( 'serialize_block', $blocks ) );
	}
}

if ( ! function_exists( 'serialize_block' ) ) {
	/**
	 * Serialize one parsed test block.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	function serialize_block( array $block ): string {
		$name       = (string) ( $block['blockName'] ?? '' );
		$short_name = str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
		$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) && array() !== $block['attrs']
			? ' ' . wp_json_encode( $block['attrs'] )
			: '';
		$inner      = (string) ( $block['innerHTML'] ?? '' );
		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && array() !== $block['innerBlocks'] ) {
			$inner = serialize_blocks( $block['innerBlocks'] );
		}

		return sprintf( '<!-- wp:%1$s%2$s -->%3$s<!-- /wp:%1$s -->', $short_name, $attrs, $inner );
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

		/**
		 * @return array<string, mixed>
		 */
		public function get_routes(): array {
			return $GLOBALS['aculect_ai_companion_test_rest_routes'] ?? array();
		}
	}
}

if ( ! function_exists( 'rest_get_server' ) ) {
	function rest_get_server(): WP_REST_Server {
		return new WP_REST_Server();
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

if ( ! class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
	/**
	 * Minimal block pattern categories registry test double.
	 */
	class WP_Block_Pattern_Categories_Registry {

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
		 * Register a test pattern category.
		 *
		 * @param string              $name     Category name.
		 * @param array<string,mixed> $category Category metadata.
		 */
		public function register( string $name, array $category ): bool {
			$category['name'] = $category['name'] ?? $name;

			$GLOBALS['aculect_ai_companion_test_pattern_categories'][ $name ] = $category;

			return true;
		}

		/**
		 * Return all registered test pattern categories.
		 *
		 * @return array<string, array<string, mixed>>
		 */
		public function get_all_registered(): array {
			return $GLOBALS['aculect_ai_companion_test_pattern_categories'] ?? array();
		}

		/**
		 * Reset registered test pattern categories.
		 */
		public function unregister_all(): void {
			$GLOBALS['aculect_ai_companion_test_pattern_categories'] = array();
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
		 * @param string                $method  HTTP method.
		 * @param string                $route   REST route.
		 * @param string                $body    Raw request body.
		 */
		public function __construct(
			private array $params = array(),
			private array $headers = array(),
			private array $json = array(),
			private string $method = 'GET',
			private string $route = '',
			private string $body = ''
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
		 * Return the raw request body.
		 */
		public function get_body(): string {
			return $this->body;
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

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WordPress error test double.
	 */
	class WP_Error {
		/**
		 * @param string               $code    Error code.
		 * @param string               $message Error message.
		 * @param array<string, mixed> $data    Error data.
		 */
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}

		/**
		 * Return the first error code.
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Return the first error message.
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * Return error data.
		 *
		 * @return array<string, mixed>
		 */
		public function get_error_data(): array {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Return whether a value is a WordPress error.
	 *
	 * @param mixed $thing Candidate value.
	 */
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}
