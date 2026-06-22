<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Site abilities implementation.
 */
final class SiteAbilities extends AbstractAbilityService {
	/**
	 * Return editable site settings exposed through MCP.
	 *
	 * @return array<string, mixed>
	 */
	public function get_settings(): array {
		return array(
			'name'                => get_option( 'blogname' ),
			'description'         => get_option( 'blogdescription' ),
			'home_url'            => home_url( '/' ),
			'site_url'            => site_url( '/' ),
			'timezone'            => wp_timezone_string(),
			'locale'              => get_locale(),
			'date_format'         => get_option( 'date_format' ),
			'time_format'         => get_option( 'time_format' ),
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
			'theme'               => wp_get_theme()->get( 'Name' ),
		);
	}

	/**
	 * Return site, WordPress, PHP, theme, and connector metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_site_info(): array {
		$theme = wp_get_theme();

		return array(
			'name'                         => get_option( 'blogname' ),
			'description'                  => get_option( 'blogdescription' ),
			'home_url'                     => home_url( '/' ),
			'site_url'                     => site_url( '/' ),
			'wordpress'                    => array(
				'version'          => get_bloginfo( 'version' ),
				'multisite'        => is_multisite(),
				'environment_type' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			),
			'php'                          => array(
				'version' => PHP_VERSION,
			),
			'active_theme'                 => array(
				'name'       => $theme->get( 'Name' ),
				'stylesheet' => $theme->get_stylesheet(),
				'template'   => $theme->get_template(),
				'version'    => $theme->get( 'Version' ),
			),
			'active_plugins'               => count( (array) get_option( 'active_plugins', array() ) ),
			'locale'                       => get_locale(),
			'timezone'                     => wp_timezone_string(),
			'rest_url'                     => rest_url(),
			'abilities_api'                => function_exists( 'wp_get_abilities' ),
			'aculect_ai_companion_version' => ACULECT_AI_COMPANION_VERSION,
			'mcp_endpoint_url'             => \Aculect\AICompanion\Connectors\Helpers::mcp_resource(),
		);
	}

	/**
	 * Return safe, high-level site health signals.
	 *
	 * @return array<string, mixed>
	 */
	public function get_site_health(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to view site health information.' );
		}

		$theme               = wp_get_theme();
		$update_counts       = $this->update_counts();
		$is_using_https      = wp_is_using_https();
		$permalink_structure = (string) get_option( 'permalink_structure' );
		$has_rest_url        = function_exists( 'rest_url' ) && '' !== rest_url();
		$checks              = array(
			'https'      => array(
				'status'  => $is_using_https ? 'good' : 'critical',
				'message' => $is_using_https ? 'HTTPS is active.' : 'HTTPS does not appear to be active.',
			),
			'permalinks' => array(
				'status'  => '' !== $permalink_structure ? 'good' : 'recommended',
				'message' => '' !== $permalink_structure ? 'Pretty permalinks are configured.' : 'Pretty permalinks are not configured.',
			),
			'rest_api'   => array(
				'status'  => $has_rest_url ? 'good' : 'critical',
				'message' => $has_rest_url ? 'REST API URL is available.' : 'REST API URL is not available.',
			),
			'updates'    => array(
				'status'  => 0 === $update_counts['total'] ? 'good' : 'recommended',
				'message' => 0 === $update_counts['total'] ? 'No available updates were found in cached update data.' : 'Cached update data shows available updates.',
				'counts'  => $update_counts,
			),
		);

		return array(
			'status'       => $this->health_status( $checks ),
			'checks'       => $checks,
			'environment'  => array(
				'wordpress_version' => get_bloginfo( 'version' ),
				'php_version'       => PHP_VERSION,
				'environment_type'  => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				'multisite'         => is_multisite(),
			),
			'active_theme' => array(
				'name'       => $theme->get( 'Name' ),
				'stylesheet' => $theme->get_stylesheet(),
				'template'   => $theme->get_template(),
				'version'    => $theme->get( 'Version' ),
			),
			'plugins'      => array(
				'active_count'      => count( (array) get_option( 'active_plugins', array() ) ),
				'updates_available' => $update_counts['plugins'],
			),
		);
	}

	/**
	 * List installed plugins for users who can activate plugins.
	 *
	 * @return array<string, mixed>
	 */
	public function list_plugins(): array {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to list plugins.' );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$items   = array();
		foreach ( $plugins as $file => $plugin ) {
			$items[] = array(
				'file'        => (string) $file,
				'name'        => (string) ( $plugin['Name'] ?? '' ),
				'version'     => (string) ( $plugin['Version'] ?? '' ),
				'description' => wp_strip_all_tags( (string) ( $plugin['Description'] ?? '' ) ),
				'author'      => wp_strip_all_tags( (string) ( $plugin['Author'] ?? '' ) ),
				'active'      => is_plugin_active( (string) $file ),
				'network'     => is_multisite() && is_plugin_active_for_network( (string) $file ),
			);
		}

		return array(
			'items' => $items,
			'total' => count( $items ),
		);
	}

	/**
	 * List installed themes for users who can switch themes.
	 *
	 * @return array<string, mixed>
	 */
	public function list_themes(): array {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to list themes.' );
		}

		$active = wp_get_theme();
		$items  = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$items[] = array(
				'stylesheet'  => (string) $stylesheet,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'description' => wp_strip_all_tags( $theme->get( 'Description' ) ),
				'template'    => $theme->get_template(),
				'parent'      => $theme->parent() ? $theme->parent()->get( 'Name' ) : '',
				'active'      => $active->get_stylesheet() === (string) $stylesheet,
			);
		}

		return array(
			'items' => $items,
			'total' => count( $items ),
		);
	}

	/**
	 * List scheduled cron events without exposing event arguments.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<string, mixed>
	 */
	public function list_cron_events( array $args = array() ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to list scheduled events.' );
		}

		$per_page   = $this->bounded_int( $args['per_page'] ?? 50, 1, 100 );
		$cron_array = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$items      = array();
		$total      = 0;

		foreach ( $cron_array as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}

				foreach ( $events as $signature => $event ) {
					++$total;

					if ( count( $items ) >= $per_page ) {
						continue;
					}

					$schedule = is_array( $event ) ? (string) ( $event['schedule'] ?? '' ) : '';
					$args     = is_array( $event ) && isset( $event['args'] ) && is_array( $event['args'] ) ? $event['args'] : array();

					$items[] = array(
						'hook'           => (string) $hook,
						'timestamp'      => (int) $timestamp,
						'next_run_gmt'   => gmdate( 'c', (int) $timestamp ),
						'schedule'       => $schedule,
						'recurring'      => '' !== $schedule,
						'argument_count' => count( $args ),
						'signature'      => (string) $signature,
					);
				}
			}
		}

		usort(
			$items,
			static fn( array $a, array $b ): int => 0 !== ( (int) $a['timestamp'] <=> (int) $b['timestamp'] ) ? ( (int) $a['timestamp'] <=> (int) $b['timestamp'] ) : strcmp( (string) $a['hook'], (string) $b['hook'] )
		);

		return array(
			'items'     => $items,
			'total'     => $total,
			'per_page'  => $per_page,
			'truncated' => $total > count( $items ),
		);
	}

	/**
	 * Return a bounded permalink and rewrite-rule summary.
	 *
	 * @param array<string, mixed> $args Ability input.
	 * @return array<string, mixed>
	 */
	public function get_rewrite_rules( array $args = array() ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to view rewrite rules.' );
		}

		global $wp_rewrite;

		$per_page = $this->bounded_int( $args['per_page'] ?? 50, 1, 100 );
		$rules    = is_object( $wp_rewrite ) && method_exists( $wp_rewrite, 'wp_rewrite_rules' ) ? $wp_rewrite->wp_rewrite_rules() : get_option( 'rewrite_rules', array() );
		$rules    = is_array( $rules ) ? $rules : array();
		$items    = array();

		foreach ( $rules as $regex => $query ) {
			if ( count( $items ) >= $per_page ) {
				break;
			}

			$items[] = array(
				'match' => (string) $regex,
				'query' => (string) $query,
			);
		}

		$structure = (string) get_option( 'permalink_structure', '' );

		return array(
			'permalink_structure' => $structure,
			'pretty_permalinks'   => '' !== $structure,
			'items'               => $items,
			'total'               => count( $rules ),
			'per_page'            => $per_page,
			'truncated'           => count( $rules ) > count( $items ),
		);
	}

	/**
	 * Return safe cache capabilities and drop-in status.
	 *
	 * @return array<string, mixed>
	 */
	public function get_cache_status(): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to view cache status.' );
		}

		$dropins = array(
			'advanced_cache' => defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ),
			'object_cache'   => defined( 'WP_CONTENT_DIR' ) && file_exists( WP_CONTENT_DIR . '/object-cache.php' ),
		);

		return array(
			'using_ext_object_cache' => function_exists( 'wp_using_ext_object_cache' ) ? wp_using_ext_object_cache() : false,
			'object_cache_supports'  => $this->object_cache_supports(),
			'page_cache_possible'    => $dropins['advanced_cache'] || ( defined( 'WP_CACHE' ) && WP_CACHE ),
			'wp_cache_constant'      => defined( 'WP_CACHE' ) && WP_CACHE,
			'dropins'                => $dropins,
			'multisite'              => is_multisite(),
		);
	}

	/**
	 * Return cached WordPress update counts without forcing remote checks.
	 *
	 * @return array{core: int, plugins: int, themes: int, total: int}
	 */
	private function update_counts(): array {
		$core_updates   = $this->core_update_count();
		$plugin_updates = $this->plugin_update_count();
		$theme_updates  = $this->theme_update_count();

		return array(
			'core'    => $core_updates,
			'plugins' => $plugin_updates,
			'themes'  => $theme_updates,
			'total'   => $core_updates + $plugin_updates + $theme_updates,
		);
	}

	/**
	 * Return available core update count from cached update data.
	 */
	private function core_update_count(): int {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$updates = get_core_updates();
		if ( ! is_array( $updates ) ) {
			return 0;
		}

		return count(
			array_filter(
				$updates,
				static fn( mixed $update ): bool => is_object( $update ) && isset( $update->response ) && 'upgrade' === (string) $update->response
			)
		);
	}

	/**
	 * Return available plugin update count from cached update data.
	 */
	private function plugin_update_count(): int {
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$updates = get_plugin_updates();
		return count( $updates );
	}

	/**
	 * Return available theme update count from cached update data.
	 */
	private function theme_update_count(): int {
		if ( ! function_exists( 'get_theme_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$updates = get_theme_updates();
		return count( $updates );
	}

	/**
	 * Return cache features when the object cache exposes support flags.
	 *
	 * @return array<string, bool>
	 */
	private function object_cache_supports(): array {
		$features = array( 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' );
		$supports = array();

		foreach ( $features as $feature ) {
			$supports[ $feature ] = function_exists( 'wp_cache_supports' ) && wp_cache_supports( $feature );
		}

		return $supports;
	}

	/**
	 * Bound an integer input value.
	 *
	 * @param mixed $value Input value.
	 * @param int   $min   Minimum allowed value.
	 * @param int   $max   Maximum allowed value.
	 */
	private function bounded_int( mixed $value, int $min, int $max ): int {
		$value = is_numeric( $value ) ? (int) $value : $min;
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Summarize individual health checks.
	 *
	 * @param array<string, array<string, mixed>> $checks Site health checks.
	 */
	private function health_status( array $checks ): string {
		$statuses = array_map(
			static fn( array $check ): string => (string) ( $check['status'] ?? '' ),
			$checks
		);

		if ( in_array( 'critical', $statuses, true ) ) {
			return 'critical';
		}

		if ( in_array( 'recommended', $statuses, true ) ) {
			return 'recommended';
		}

		return 'good';
	}
}
