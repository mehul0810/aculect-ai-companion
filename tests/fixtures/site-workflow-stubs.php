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

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Minimal WP_Query test double for paginated maintenance reports.
	 */
	class WP_Query {
		/**
		 * Queried posts.
		 *
		 * @var list<WP_Post>
		 */
		public array $posts = array();

		/**
		 * Total matching posts before pagination.
		 */
		public int $found_posts = 0;

		/**
		 * Total pages.
		 */
		public int $max_num_pages = 0;

		/**
		 * @param array<string, mixed> $args Query args.
		 */
		public function __construct( array $args = array() ) {
			$post_types = array_map( 'strval', (array) ( $args['post_type'] ?? array() ) );
			$statuses   = array_map( 'strval', (array) ( $args['post_status'] ?? array() ) );
			$mime_type  = (string) ( $args['post_mime_type'] ?? '' );
			$per_page   = max( 1, absint( $args['posts_per_page'] ?? 10 ) );
			$page       = max( 1, absint( $args['paged'] ?? 1 ) );
			$posts      = array();

			foreach ( $GLOBALS['aculect_ai_companion_test_posts'] as $post ) {
				$post = $post instanceof WP_Post ? $post : new WP_Post( is_array( $post ) ? $post : array() );
				if ( array() !== $post_types && ! in_array( $post->post_type, $post_types, true ) ) {
					continue;
				}

				if ( array() !== $statuses && ! in_array( $post->post_status, $statuses, true ) ) {
					continue;
				}

				if ( '' !== $mime_type && ! str_starts_with( $post->post_mime_type, $mime_type . '/' ) ) {
					continue;
				}

				$posts[] = $post;
			}

			$this->found_posts   = count( $posts );
			$this->max_num_pages = (int) ceil( $this->found_posts / $per_page );
			$this->posts         = array_slice( $posts, ( $page - 1 ) * $per_page, $per_page );
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

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	/**
	 * Return attachment metadata for tests.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	function wp_get_attachment_metadata( int $attachment_id ): array {
		$metadata = $GLOBALS['aculect_ai_companion_test_attachment_metadata'][ $attachment_id ] ?? array();

		return is_array( $metadata ) ? $metadata : array();
	}
}
