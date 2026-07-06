<?php
/**
 * Read-only plugin lifecycle inventory abilities.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Builds bounded, lifecycle-oriented plugin status models without mutating plugins.
 */
final class PluginLifecycleAbilities extends AbstractAbilityService {

	private const DEFAULT_PER_PAGE = 50;
	private const MAX_PER_PAGE     = 100;
	private const STATUSES         = array( 'all', 'active', 'inactive', 'network_active', 'update_available', 'paused' );

	/**
	 * List installed plugin lifecycle status.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_plugins( array $args ): array {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect plugin lifecycle status.' );
		}

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
		$status   = $this->status_filter( $args['status'] ?? 'all' );
		$items    = array_values(
			array_filter(
				$this->plugin_inventory(),
				fn ( array $item ): bool => $this->matches_status( $item, $status )
			)
		);

		$offset = ( $page - 1 ) * $per_page;

		return array(
			'items'               => array_slice( $items, $offset, $per_page ),
			'pagination'          => array(
				'page'     => $page,
				'per_page' => $per_page,
				'total'    => count( $items ),
				'returned' => min( $per_page, max( 0, count( $items ) - $offset ) ),
			),
			'total'               => count( $items ),
			'context'             => $this->site_context(),
			'capabilities'        => $this->lifecycle_capabilities(),
			'capability_blockers' => $this->capability_blockers(),
			'filters'             => array(
				'status'                       => $status,
				'bounded'                      => true,
				'forced_update_checks'         => false,
				'raw_update_payloads_included' => false,
				'plugin_source_scanned'        => false,
				'filesystem_paths_included'    => false,
			),
			'safety'              => $this->safety_metadata(),
		);
	}

	/**
	 * Return one installed plugin lifecycle status record.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_plugin( array $args ): array {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect plugin lifecycle status.' );
		}

		$plugin_file = $this->requested_plugin_file( $args['plugin'] ?? '' );
		if ( is_array( $plugin_file ) ) {
			return $plugin_file;
		}

		foreach ( $this->plugin_inventory() as $item ) {
			if ( $plugin_file === $item['plugin'] ) {
				return array(
					'plugin'              => $item,
					'context'             => $this->site_context(),
					'capabilities'        => $this->lifecycle_capabilities(),
					'capability_blockers' => $this->capability_blockers(),
					'safety'              => $this->safety_metadata(),
				);
			}
		}

		return $this->error( 'plugin_not_found', 'Requested plugin is not installed.' );
	}

	/**
	 * Build deterministic installed plugin inventory records.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function plugin_inventory(): array {
		$this->load_plugin_functions();

		$plugins         = get_plugins();
		$active_plugins  = $this->active_plugin_files();
		$network_plugins = $this->network_active_plugin_files();
		$update_metadata = $this->plugin_update_metadata();
		$items           = array();

		foreach ( $plugins as $file => $plugin ) {
			$file           = (string) $file;
			$site_active    = in_array( $file, $active_plugins, true );
			$network_active = is_multisite() && in_array( $file, $network_plugins, true );
			$active         = $site_active || $network_active || ( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) );

			$items[] = array(
				'plugin'         => $file,
				'slug'           => $this->plugin_slug( $file ),
				'name'           => $this->bounded_text( (string) ( $plugin['Name'] ?? '' ), 120 ),
				'version'        => $this->bounded_text( (string) ( $plugin['Version'] ?? '' ), 40 ),
				'description'    => $this->bounded_text( (string) ( $plugin['Description'] ?? '' ), 240 ),
				'author'         => $this->bounded_text( (string) ( $plugin['Author'] ?? '' ), 120 ),
				'status'         => $this->plugin_status( $site_active, $network_active ),
				'active'         => $active,
				'site_active'    => $site_active,
				'network_active' => $network_active,
				'update'         => $this->plugin_update_status( $file, $update_metadata ),
				'recovery'       => $this->plugin_recovery_status( $file ),
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$by_name = strnatcasecmp( (string) $a['name'], (string) $b['name'] );
				return 0 === $by_name ? strcmp( (string) $a['plugin'], (string) $b['plugin'] ) : $by_name;
			}
		);

		return $items;
	}

	/**
	 * Load WordPress plugin helpers when needed.
	 */
	private function load_plugin_functions(): void {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Return current-site active plugin basenames.
	 *
	 * @return list<string>
	 */
	private function active_plugin_files(): array {
		$active = get_option( 'active_plugins', array() );

		return array_values( array_map( 'strval', is_array( $active ) ? $active : array() ) );
	}

	/**
	 * Return network-active plugin basenames without exposing option payloads.
	 *
	 * @return list<string>
	 */
	private function network_active_plugin_files(): array {
		$active = get_site_option( 'active_sitewide_plugins', array() );

		return array_values( array_map( 'strval', array_keys( is_array( $active ) ? $active : array() ) ) );
	}

	/**
	 * Return existing plugin update metadata without forcing remote checks.
	 *
	 * @return array{available: bool, age_hours: int, response: array<string, mixed>}
	 */
	private function plugin_update_metadata(): array {
		$value        = get_site_option( '_site_transient_update_plugins', false );
		$available    = false !== $value && null !== $value;
		$last_checked = $this->metadata_int( $value, 'last_checked' );
		$response     = $this->metadata_value( $value, 'response' );

		if ( is_object( $response ) ) {
			$response = get_object_vars( $response );
		}

		return array(
			'available' => $available,
			'age_hours' => 0 < $last_checked ? max( 0, (int) floor( ( time() - $last_checked ) / HOUR_IN_SECONDS ) ) : 0,
			'response'  => is_array( $response ) ? $response : array(),
		);
	}

	/**
	 * Return safe update status for one plugin.
	 *
	 * @param string                                                                 $file     Plugin basename.
	 * @param array{available: bool, age_hours: int, response: array<string, mixed>} $metadata Update metadata.
	 * @return array<string, mixed>
	 */
	private function plugin_update_status( string $file, array $metadata ): array {
		$update = $metadata['response'][ $file ] ?? null;

		return array(
			'available'                    => null !== $update,
			'new_version'                  => null === $update ? '' : $this->bounded_text( $this->metadata_string( $update, 'new_version' ), 40 ),
			'tested'                       => null === $update ? '' : $this->bounded_text( $this->metadata_string( $update, 'tested' ), 40 ),
			'requires_wordpress'           => null === $update ? '' : $this->bounded_text( $this->metadata_string( $update, 'requires' ), 40 ),
			'requires_php'                 => null === $update ? '' : $this->bounded_text( $this->metadata_string( $update, 'requires_php' ), 40 ),
			'metadata_available'           => $metadata['available'],
			'metadata_age_hours'           => $metadata['age_hours'],
			'forced_update_checks'         => false,
			'package_url_included'         => false,
			'raw_update_payloads_included' => false,
		);
	}

	/**
	 * Return safe recovery-mode and paused-plugin status for one plugin.
	 *
	 * @param string $file Plugin basename.
	 * @return array<string, mixed>
	 */
	private function plugin_recovery_status( string $file ): array {
		$recovery_available = function_exists( 'wp_is_recovery_mode' );
		$paused_available   = function_exists( 'is_plugin_paused' );

		return array(
			'recovery_mode_available'  => $recovery_available,
			'recovery_mode_active'     => $recovery_available ? wp_is_recovery_mode() : false,
			'paused_state_available'   => $paused_available,
			'paused'                   => $paused_available ? is_plugin_paused( $file ) : false,
			'error_details_included'   => false,
			'raw_stack_trace_included' => false,
		);
	}

	/**
	 * Return plugin lifecycle capabilities for the connected user.
	 *
	 * @return array<string, bool>
	 */
	private function lifecycle_capabilities(): array {
		return array(
			'can_manage_plugins'         => current_user_can( 'activate_plugins' ),
			'can_install_plugins'        => current_user_can( 'install_plugins' ),
			'can_update_plugins'         => current_user_can( 'update_plugins' ),
			'can_activate_plugins'       => current_user_can( 'activate_plugins' ),
			'can_deactivate_plugins'     => current_user_can( 'activate_plugins' ),
			'can_manage_network_plugins' => is_multisite() && current_user_can( 'manage_network_plugins' ),
		);
	}

	/**
	 * Return missing capabilities for lifecycle actions not implemented in this slice.
	 *
	 * @return array<string, array{capability: string}>
	 */
	private function capability_blockers(): array {
		$requirements = array(
			'install'    => 'install_plugins',
			'update'     => 'update_plugins',
			'activate'   => 'activate_plugins',
			'deactivate' => 'activate_plugins',
		);

		if ( is_multisite() ) {
			$requirements['network_activate']   = 'manage_network_plugins';
			$requirements['network_deactivate'] = 'manage_network_plugins';
		}

		$blockers = array();
		foreach ( $requirements as $operation => $capability ) {
			if ( ! current_user_can( $capability ) ) {
				$blockers[ $operation ] = array( 'capability' => $capability );
			}
		}

		return $blockers;
	}

	/**
	 * Return multisite/network context for the inventory.
	 *
	 * @return array<string, mixed>
	 */
	private function site_context(): array {
		return array(
			'multisite'                       => is_multisite(),
			'blog_id'                         => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0,
			'network_admin'                   => function_exists( 'is_network_admin' ) ? is_network_admin() : false,
			'network_active_status_available' => is_multisite(),
		);
	}

	/**
	 * Return safety metadata shared by list/get responses.
	 *
	 * @return array<string, bool>
	 */
	private function safety_metadata(): array {
		return array(
			'read_only'                    => true,
			'install_implemented'          => false,
			'update_implemented'           => false,
			'activate_implemented'         => false,
			'deactivate_implemented'       => false,
			'filesystem_credentials_used'  => false,
			'filesystem_writes'            => false,
			'option_writes'                => false,
			'raw_plugin_code_included'     => false,
			'raw_update_payloads_included' => false,
			'secret_values_included'       => false,
			'filesystem_paths_included'    => false,
		);
	}

	/**
	 * Normalize one requested plugin basename.
	 *
	 * @param mixed $value Raw requested plugin.
	 * @return string|array<string, string>
	 */
	private function requested_plugin_file( mixed $value ): string|array {
		if ( ! is_scalar( $value ) ) {
			return $this->error( 'invalid_plugin', 'Plugin must be an installed plugin basename.' );
		}

		$file = str_replace( '\\', '/', trim( (string) $value ) );
		if (
			'' === $file
			|| str_starts_with( $file, '/' )
			|| str_contains( $file, "\0" )
			|| str_contains( $file, '../' )
			|| str_contains( $file, '/..' )
		) {
			return $this->error( 'invalid_plugin', 'Plugin must be an installed plugin basename.' );
		}

		return $file;
	}

	/**
	 * Return an item status.
	 *
	 * @param bool $site_active    Whether the plugin is active on the current site.
	 * @param bool $network_active Whether the plugin is active for the network.
	 */
	private function plugin_status( bool $site_active, bool $network_active ): string {
		if ( $network_active ) {
			return 'network_active';
		}

		return $site_active ? 'active' : 'inactive';
	}

	/**
	 * Check a plugin against a list status filter.
	 *
	 * @param array<string, mixed> $item   Plugin inventory item.
	 * @param string               $status Status filter.
	 */
	private function matches_status( array $item, string $status ): bool {
		return match ( $status ) {
			'active' => true === $item['active'],
			'inactive' => false === $item['active'],
			'network_active' => true === $item['network_active'],
			'update_available' => ! empty( $item['update']['available'] ),
			'paused' => ! empty( $item['recovery']['paused'] ),
			default => true,
		};
	}

	/**
	 * Normalize requested status filters.
	 *
	 * @param mixed $value Raw status.
	 */
	private function status_filter( mixed $value ): string {
		$status = sanitize_key( (string) $value );

		return in_array( $status, self::STATUSES, true ) ? $status : 'all';
	}

	/**
	 * Return a plugin slug from a basename.
	 *
	 * @param string $file Plugin basename.
	 */
	private function plugin_slug( string $file ): string {
		$parts = explode( '/', $file );
		$slug  = 1 < count( $parts ) ? $parts[0] : preg_replace( '/\.php$/', '', $file );

		return sanitize_key( (string) $slug );
	}

	/**
	 * Return plain, bounded text for public MCP payloads.
	 *
	 * @param string $text       Raw text.
	 * @param int    $max_length Maximum returned length.
	 */
	private function bounded_text( string $text, int $max_length ): string {
		$text = preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $text ) ?? '';
		$text = sanitize_text_field( wp_strip_all_tags( $text ) );
		if ( strlen( $text ) <= $max_length ) {
			return $text;
		}

		return rtrim( substr( $text, 0, max( 1, $max_length - 3 ) ) ) . '...';
	}

	/**
	 * Return a metadata field from an object or array.
	 *
	 * @param mixed  $metadata Raw metadata.
	 * @param string $key      Field key.
	 */
	private function metadata_value( mixed $metadata, string $key ): mixed {
		if ( is_array( $metadata ) ) {
			return $metadata[ $key ] ?? null;
		}

		if ( is_object( $metadata ) ) {
			return $metadata->{$key} ?? null;
		}

		return null;
	}

	/**
	 * Return an integer metadata field.
	 *
	 * @param mixed  $metadata Raw metadata.
	 * @param string $key      Field key.
	 */
	private function metadata_int( mixed $metadata, string $key ): int {
		$value = $this->metadata_value( $metadata, $key );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Return a string metadata field.
	 *
	 * @param mixed  $metadata Raw metadata.
	 * @param string $key      Field key.
	 */
	private function metadata_string( mixed $metadata, string $key ): string {
		$value = $this->metadata_value( $metadata, $key );

		return is_scalar( $value ) ? (string) $value : '';
	}
}
