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
	 * Request-local key used by the execution gateway for server-resolved writes.
	 *
	 * The key is never part of the public tool schema or response. It carries the
	 * package identity resolved by a dry run so a later confirmation cannot drift
	 * to a different plugin package.
	 */
	public const CONFIRMATION_BINDING_KEY = '_aculect_confirmation_binding';

	private PluginLifecyclePackagePolicy $package_policy;

	/**
	 * Construct lifecycle abilities with an isolated package policy collaborator.
	 *
	 * @param PluginLifecyclePackagePolicy|null $package_policy Package policy.
	 */
	public function __construct( ?PluginLifecyclePackagePolicy $package_policy = null ) {
		$this->package_policy = $package_policy ?? new PluginLifecyclePackagePolicy();
	}

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
	 * Install one plugin from the WordPress.org directory.
	 *
	 * Only the directory metadata and package URL returned by WordPress core are
	 * accepted. Installation never activates the plugin automatically.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function install_plugin( array $args ): array {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to install plugins.' );
		}

		$slug = $this->requested_plugin_slug( $args['slug'] ?? '' );
		if ( is_array( $slug ) ) {
			return $slug;
		}

		$this->load_plugin_functions();
		if ( null !== $this->plugin_inventory_item_by_slug( $slug ) ) {
			return $this->error( 'plugin_already_installed', 'A plugin with this slug is already installed.' );
		}

		$requires     = '';
		$requires_php = '';
		$binding      = $this->package_policy->requested_confirmation_binding( $args );
		if ( null !== $binding ) {
			$binding_error = $this->package_policy->validate_install_binding( $binding, $slug );
			if ( null !== $binding_error ) {
				return $binding_error;
			}

			$package      = (string) $binding['package'];
			$name         = (string) $binding['name'];
			$version      = (string) $binding['version'];
			$requires     = (string) ( $binding['requires'] ?? '' );
			$requires_php = (string) ( $binding['requires_php'] ?? '' );
		} else {
			$information = ( new PluginLifecyclePackageManager() )->information( $slug );
			if ( $information instanceof \WP_Error ) {
				return $this->package_api_error( $information, 'install' );
			}
			if ( ! is_array( $information ) && ! is_object( $information ) ) {
				return $this->error( 'plugin_information_unavailable', 'WordPress did not return usable plugin information.' );
			}

			$metadata_slug = sanitize_key( $this->metadata_string( $information, 'slug' ) );
			if ( '' !== $metadata_slug && $metadata_slug !== $slug ) {
				return $this->error( 'plugin_source_mismatch', 'WordPress returned package information for a different plugin.' );
			}

			$package = $this->package_policy->requested_package_url( $this->metadata_value( $information, 'download_link' ), $slug );
			if ( is_array( $package ) ) {
				return $package;
			}

			$name               = $this->bounded_text( $this->metadata_string( $information, 'name' ), 120 );
			$version            = $this->bounded_text( $this->metadata_string( $information, 'version' ), 40 );
			$requires           = $this->bounded_text( $this->metadata_string( $information, 'requires' ), 40 );
			$requires_php       = $this->bounded_text( $this->metadata_string( $information, 'requires_php' ), 40 );
			$requirements_error = $this->package_policy->requirements_error( 'install', $requires, $requires_php );
			if ( null !== $requirements_error ) {
				return $requirements_error;
			}
			if ( '' === $version ) {
				return $this->error( 'plugin_information_unavailable', 'WordPress did not return a target plugin version.' );
			}
		}

		$target = array(
			'type'    => 'plugin',
			'id'      => $slug,
			'name'    => $name,
			'version' => $version,
			'source'  => 'wordpress.org',
		);

		if ( $this->is_dry_run( $args ) ) {
			$preview = $this->preview_response(
				'plugin_lifecycle.install_plugin',
				$args,
				$target,
				array( $this->change( 'installed', false, true ), $this->change( 'version', '', $version ) ),
				$this->plugin_install_warnings()
			);
			return $this->package_policy->with_confirmation_binding(
				$preview,
				$this->package_policy->install_binding( $slug, $name, $version, $package, $requires, $requires_php )
			);
		}

		$result  = ( new PluginLifecyclePackageManager() )->install( $package );
		$failure = $this->package_operation_failure( $result, 'install' );
		if ( null !== $failure ) {
			return $failure;
		}

		$installed = null;
		foreach ( $this->plugin_inventory() as $item ) {
			if ( (string) ( $item['slug'] ?? '' ) === $slug ) {
				$installed = $item;
				break;
			}
		}
		if ( ! is_array( $installed ) || (string) ( $installed['version'] ?? '' ) !== $version ) {
			return $this->error( 'plugin_install_postcondition_failed', 'Plugin installation completed without the expected plugin version being available.' );
		}
		$plugin   = $installed;
		$verified = true;

		return array(
			'status'                => 'installed',
			'operation'             => 'install',
			'plugin'                => $plugin,
			'verified'              => $verified,
			'changed'               => true,
			'context'               => $this->site_context(),
			'capabilities'          => $this->lifecycle_capabilities(),
			'capability_blockers'   => $this->capability_blockers(),
			'safety'                => $this->write_safety_metadata( 'install' ),
			'confirmation_required' => false,
		);
	}

	/**
	 * Update one installed plugin using cached WordPress update metadata.
	 *
	 * A remote update check is intentionally not forced by an assistant call.
	 * WordPress core or an administrator must first populate the update transient.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function update_plugin( array $args ): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update plugins.' );
		}

		$this->load_plugin_functions();
		$plugin_file = $this->requested_plugin_file( $args['plugin'] ?? '' );
		if ( is_array( $plugin_file ) ) {
			return $plugin_file;
		}

		$current = $this->plugin_inventory_item( $plugin_file );
		if ( null === $current ) {
			return $this->error( 'plugin_not_found', 'Requested plugin is not installed.' );
		}
		if ( is_multisite() ) {
			return $this->error( 'multisite_update_scope', 'Plugin updates are disabled on multisite because plugin files are shared across sites; use an explicitly network-scoped maintenance workflow.' );
		}

		$requires     = '';
		$requires_php = '';
		$binding      = $this->package_policy->requested_confirmation_binding( $args );
		if ( null !== $binding ) {
			$binding_error = $this->package_policy->validate_update_binding( $binding, $plugin_file, $current );
			if ( null !== $binding_error ) {
				return $binding_error;
			}

			$package      = (string) $binding['package'];
			$new_version  = (string) $binding['version'];
			$requires     = (string) ( $binding['requires'] ?? '' );
			$requires_php = (string) ( $binding['requires_php'] ?? '' );
		} else {
			$metadata = $this->plugin_update_metadata();
			$update   = $metadata['response'][ $plugin_file ] ?? null;
			if ( null === $update ) {
				return $this->error( 'update_unavailable', 'No cached WordPress update is available for this plugin.' );
			}

			$package_value = $this->metadata_string( $update, 'package' );
			if ( '' === $package_value ) {
				$package_value = $this->metadata_string( $update, 'download_link' );
			}
			if ( '' === $package_value ) {
				return $this->error( 'update_unavailable', 'Cached update metadata does not include a plugin package.' );
			}
			$package = $this->package_policy->requested_package_url( $package_value, $this->plugin_slug( $plugin_file ) );
			if ( is_array( $package ) ) {
				return $package;
			}

			$new_version = $this->bounded_text( $this->metadata_string( $update, 'new_version' ), 40 );
			if ( '' === $new_version ) {
				return $this->error( 'update_unavailable', 'Cached update metadata does not include a target version.' );
			}
			$requires           = $this->bounded_text( $this->metadata_string( $update, 'requires' ), 40 );
			$requires_php       = $this->bounded_text( $this->metadata_string( $update, 'requires_php' ), 40 );
			$requirements_error = $this->package_policy->requirements_error( 'update', $requires, $requires_php );
			if ( null !== $requirements_error ) {
				return $requirements_error;
			}
		}

		$target            = $this->plugin_target_summary( $current );
		$target['version'] = $new_version;
		if ( $this->is_dry_run( $args ) ) {
			$preview = $this->preview_response(
				'plugin_lifecycle.update_plugin',
				$args,
				$target,
				array( $this->change( 'version', $current['version'] ?? '', $new_version ) ),
				$this->plugin_update_warnings()
			);
			return $this->package_policy->with_confirmation_binding(
				$preview,
				$this->package_policy->update_binding( $plugin_file, $current, $new_version, $package, $requires, $requires_php )
			);
		}

		$result  = ( new PluginLifecyclePackageManager() )->update( $plugin_file, $package );
		$failure = $this->package_operation_failure( $result, 'update' );
		if ( null !== $failure ) {
			return $failure;
		}

		$updated = $this->plugin_inventory_item( $plugin_file );
		if ( null === $updated || (string) ( $updated['version'] ?? '' ) !== $new_version ) {
			return $this->error( 'plugin_update_postcondition_failed', 'Plugin update completed without the expected plugin version being available.' );
		}

		return array(
			'status'                => 'updated',
			'operation'             => 'update',
			'plugin'                => $updated,
			'verified'              => true,
			'changed'               => true,
			'context'               => $this->site_context(),
			'capabilities'          => $this->lifecycle_capabilities(),
			'capability_blockers'   => $this->capability_blockers(),
			'safety'                => $this->write_safety_metadata( 'update' ),
			'confirmation_required' => false,
		);
	}

	/**
	 * Activate one already-installed plugin on the current site.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function activate_plugin( array $args ): array {
		return $this->change_plugin_activation( $args, 'activate' );
	}

	/**
	 * Deactivate one already-installed plugin on the current site.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function deactivate_plugin( array $args ): array {
		return $this->change_plugin_activation( $args, 'deactivate' );
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
	 * Activate or deactivate one installed plugin using WordPress core APIs.
	 *
	 * @param array<string, mixed> $args      Tool arguments.
	 * @param string               $operation Supported operation: activate or deactivate.
	 * @return array<string, mixed>
	 */
	private function change_plugin_activation( array $args, string $operation ): array {
		$this->load_plugin_functions();

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to manage plugin activation on this site.' );
		}

		$plugin_file = $this->requested_plugin_file( $args['plugin'] ?? '' );
		if ( is_array( $plugin_file ) ) {
			return $plugin_file;
		}

		$plugins = get_plugins();
		if ( ! array_key_exists( $plugin_file, $plugins ) ) {
			return $this->error( 'plugin_not_found', 'Requested plugin is not installed.' );
		}

		$current = $this->plugin_inventory_item( $plugin_file );
		if ( null === $current ) {
			return $this->error( 'plugin_not_found', 'Requested plugin is not installed.' );
		}

		if ( 'activate' === $operation && true === $current['active'] ) {
			return $this->activation_noop_result( $current, 'already_active', 'Plugin is already active on this site.' );
		}

		if ( 'deactivate' === $operation && false === $current['site_active'] ) {
			if ( true === $current['network_active'] ) {
				return $this->error( 'network_active_plugin', 'Plugin is network active and cannot be deactivated from a site-scoped tool call.' );
			}

			return $this->activation_noop_result( $current, 'already_inactive', 'Plugin is already inactive on this site.' );
		}

		if ( $this->is_dry_run( $args ) ) {
			return $this->preview_response(
				'plugin_lifecycle.' . $operation . '_plugin',
				$args,
				$this->plugin_target_summary( $current ),
				$this->plugin_activation_changes( $current, $operation ),
				$this->plugin_activation_warnings( $operation )
			);
		}

		if ( 'activate' === $operation ) {
			$result = activate_plugin( $plugin_file, '', false, false );
			if ( is_wp_error( $result ) ) {
				return $this->error( (string) $result->get_error_code(), $result->get_error_message() );
			}
		} else {
			deactivate_plugins( array( $plugin_file ), false, false );
		}

		$updated = $this->plugin_inventory_item( $plugin_file );
		if ( null === $updated ) {
			return $this->error( 'plugin_not_found', 'Requested plugin is not installed.' );
		}
		$state_matches = 'activate' === $operation
			? ( true === ( $updated['active'] ?? false ) && true === ( $updated['site_active'] ?? false ) )
			: ( false === ( $updated['active'] ?? true ) && false === ( $updated['site_active'] ?? true ) );
		if ( ! $state_matches ) {
			return $this->error(
				'plugin_' . $operation . '_postcondition_failed',
				'Plugin ' . ( 'activate' === $operation ? 'activation' : 'deactivation' ) . ' completed without the expected site state being available.'
			);
		}

		return array(
			'status'                => 'activate' === $operation ? 'activated' : 'deactivated',
			'operation'             => $operation,
			'plugin'                => $updated,
			'changed'               => true,
			'context'               => $this->site_context(),
			'capabilities'          => $this->lifecycle_capabilities(),
			'capability_blockers'   => $this->capability_blockers(),
			'rollback'              => array(
				'operation' => 'activate' === $operation ? 'deactivate_plugin' : 'activate_plugin',
				'plugin'    => $plugin_file,
				'note'      => 'Repeat this workflow with a dry run and confirmation token before reversing the activation state.',
			),
			'safety'                => $this->write_safety_metadata( $operation ),
			'confirmation_required' => false,
		);
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
	 * Normalize a WordPress.org plugin slug.
	 *
	 * @param mixed $value Raw slug.
	 * @return string|array<string, string>
	 */
	private function requested_plugin_slug( mixed $value ): string|array {
		if ( ! is_scalar( $value ) ) {
			return $this->error( 'invalid_plugin_slug', 'Plugin slug must use lowercase letters, numbers, and hyphens.' );
		}

		$slug = strtolower( trim( (string) $value ) );
		if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/', $slug ) ) {
			return $this->error( 'invalid_plugin_slug', 'Plugin slug must use lowercase letters, numbers, and hyphens.' );
		}

		return $slug;
	}

	/**
	 * Return an installed plugin by its directory slug.
	 *
	 * @param string $slug WordPress.org plugin slug.
	 * @return mixed Installed plugin item or null.
	 */
	private function plugin_inventory_item_by_slug( string $slug ): mixed {
		foreach ( $this->plugin_inventory() as $item ) {
			if ( (string) ( $item['slug'] ?? '' ) === $slug ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Map plugin API failures without exposing remote payloads or paths.
	 *
	 * @param \WP_Error $error     WordPress error.
	 * @param string    $operation Install or update.
	 * @return array<string, mixed>
	 */
	private function package_api_error( \WP_Error $error, string $operation ): array {
		$code = sanitize_key( (string) $error->get_error_code() );

		return array(
			'error'        => 'plugin_' . $operation . '_information_failed',
			'message'      => 'WordPress could not retrieve plugin package information.',
			'failure_code' => '' !== $code ? $code : 'api_error',
			'operation'    => $operation,
			'safety'       => $this->write_safety_metadata( $operation ),
		);
	}

	/**
	 * Map a core upgrader result into a bounded public failure.
	 *
	 * @param mixed  $result    Core upgrader result.
	 * @param string $operation Install or update.
	 * @return array<string, mixed>|null
	 */
	private function package_operation_failure( mixed $result, string $operation ): ?array {
		if ( $result instanceof \WP_Error ) {
			$code = sanitize_key( (string) $result->get_error_code() );

			return array(
				'error'        => 'plugin_' . $operation . '_failed',
				'message'      => 'WordPress could not ' . $operation . ' the plugin.',
				'failure_code' => '' !== $code ? $code : 'upgrader_error',
				'operation'    => $operation,
				'safety'       => $this->write_safety_metadata( $operation ),
			);
		}

		if ( true !== $result ) {
			return array(
				'error'     => 'plugin_' . $operation . '_failed',
				'message'   => 'WordPress could not ' . $operation . ' the plugin.',
				'operation' => $operation,
				'safety'    => $this->write_safety_metadata( $operation ),
			);
		}

		return null;
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
		$value        = get_site_transient( 'update_plugins' );
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
	 * Return missing capabilities for lifecycle actions.
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
			'install_implemented'          => true,
			'update_implemented'           => true,
			'activate_implemented'         => true,
			'deactivate_implemented'       => true,
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
	 * Return write-slice safety metadata for activation changes.
	 *
	 * @param string $operation Current write operation.
	 * @return array<string, mixed>
	 */
	private function write_safety_metadata( string $operation ): array {
		$install_or_update = in_array( $operation, array( 'install', 'update' ), true );
		$install           = 'install' === $operation;
		$update            = 'update' === $operation;
		$activate          = 'activate' === $operation;
		$deactivate        = 'deactivate' === $operation;

		return array(
			'read_only'                      => false,
			'install_implemented'            => $install,
			'update_implemented'             => $update,
			'activate_implemented'           => $activate,
			'deactivate_implemented'         => $deactivate,
			'operation'                      => $operation,
			'site_scope_only'                => true,
			'network_scope_supported'        => false,
			'filesystem_credentials_used'    => false,
			'filesystem_writes'              => $install_or_update,
			'option_writes'                  => $install_or_update,
			'raw_plugin_code_included'       => false,
			'raw_update_payloads_included'   => false,
			'secret_values_included'         => false,
			'filesystem_paths_included'      => false,
			'rollback_requires_confirmation' => true,
		);
	}

	/**
	 * Return one inventory item for the requested plugin basename.
	 *
	 * @param string $plugin_file Plugin basename.
	 * @return array<string, mixed>|null
	 */
	private function plugin_inventory_item( string $plugin_file ): ?array {
		foreach ( $this->plugin_inventory() as $item ) {
			if ( $plugin_file === $item['plugin'] ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Build a target summary for activation previews.
	 *
	 * @param array<string, mixed> $plugin Current plugin item.
	 * @return array<string, mixed>
	 */
	private function plugin_target_summary( array $plugin ): array {
		return array(
			'type'   => 'plugin',
			'id'     => (string) $plugin['plugin'],
			'name'   => (string) $plugin['name'],
			'status' => (string) $plugin['status'],
		);
	}

	/**
	 * Build field-level changes for plugin activation previews.
	 *
	 * @param array<string, mixed> $plugin    Current plugin item.
	 * @param string               $operation Current write operation.
	 * @return array<int, array<string, mixed>|null>
	 */
	private function plugin_activation_changes( array $plugin, string $operation ): array {
		$activate = 'activate' === $operation;

		return array(
			$this->change( 'status', $plugin['status'] ?? '', $activate ? 'active' : 'inactive' ),
			$this->change( 'active', $plugin['active'] ?? false, $activate ),
			$this->change( 'site_active', $plugin['site_active'] ?? false, $activate ),
		);
	}

	/**
	 * Build bounded activation warnings for preview and confirmation.
	 *
	 * @param string $operation Current write operation.
	 * @return string[]
	 */
	private function plugin_activation_warnings( string $operation ): array {
		if ( 'activate' === $operation ) {
			return array(
				'Activation can change site behavior immediately and should be confirmed before execution.',
				'Rollback is available by deactivating the same plugin through the matching plugin lifecycle tool.',
				'This first beta slice changes the current site only; network activation remains out of scope.',
			);
		}

		return array(
			'Deactivation can remove frontend or admin functionality immediately and should be confirmed before execution.',
			'Rollback is available by activating the same plugin through the matching plugin lifecycle tool.',
			'This first beta slice changes the current site only; network deactivation remains out of scope.',
		);
	}

	/**
	 * Build bounded install warnings for the confirmation preview.
	 *
	 * @return string[]
	 */
	private function plugin_install_warnings(): array {
		return array(
			'Installing a plugin changes the site filesystem and should be confirmed before execution.',
			'The plugin is installed inactive; activation is a separate confirmed action.',
			'Only the WordPress.org package returned by core plugin information is accepted.',
		);
	}

	/**
	 * Build bounded update warnings for the confirmation preview.
	 *
	 * @return string[]
	 */
	private function plugin_update_warnings(): array {
		return array(
			'Updating a plugin changes the site filesystem and may change site behavior immediately.',
			'This call uses cached WordPress update metadata and does not force a remote update check.',
			'Rollback is not automatic; restore the plugin from a tested backup if the update causes a regression.',
		);
	}

	/**
	 * Build a deterministic no-op result for activation state requests.
	 *
	 * @param array<string, mixed> $plugin  Current plugin item.
	 * @param string               $status  Result status.
	 * @param string               $message User-facing message.
	 * @return array<string, mixed>
	 */
	private function activation_noop_result( array $plugin, string $status, string $message ): array {
		$operation = 'already_active' === $status ? 'activate' : 'deactivate';

		return array(
			'status'              => $status,
			'changed'             => false,
			'message'             => $message,
			'plugin'              => $plugin,
			'context'             => $this->site_context(),
			'capabilities'        => $this->lifecycle_capabilities(),
			'capability_blockers' => $this->capability_blockers(),
			'safety'              => $this->write_safety_metadata( $operation ),
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
