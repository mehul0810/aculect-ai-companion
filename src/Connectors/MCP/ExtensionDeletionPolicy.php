<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Resolves safe, installed extension deletion targets and confirmation state.
 */
class ExtensionDeletionPolicy {

	/**
	 * Resolve an installed target and bind its current identity and state.
	 *
	 * @param string $kind        Extension kind.
	 * @param mixed  $raw_target  Requested plugin basename or theme stylesheet.
	 * @return array<string, mixed>
	 */
	public function inspect( string $kind, mixed $raw_target ): array {
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) {
			return $this->error( 'invalid_extension_kind', 'Only plugins and themes can be deleted through this workflow.' );
		}

		$target = $this->validated_target( $kind, $raw_target );
		if ( null === $target ) {
			return $this->error( 'invalid_extension_target', 'Provide an exact installed plugin basename or theme stylesheet.' );
		}

		$this->load_admin_api( $kind );
		return 'plugin' === $kind ? $this->inspect_plugin( $target ) : $this->inspect_theme( $target );
	}

	/**
	 * Read the gateway-only binding attached to a confirmed tool execution.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|null
	 */
	public function requested_confirmation_binding( array $args ): ?array {
		$key = PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY;
		if ( ! array_key_exists( $key, $args ) ) {
			return null;
		}

		return is_array( $args[ $key ] ) ? $args[ $key ] : array();
	}

	/**
	 * Attach the current target binding to a dry-run response for the gateway.
	 *
	 * @param array<string, mixed> $preview Preview response.
	 * @param array<string, mixed> $binding Current target binding.
	 * @return array<string, mixed>
	 */
	public function with_confirmation_binding( array $preview, array $binding ): array {
		$preview[ PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY ] = $binding;
		return $preview;
	}

	/**
	 * Compare the entire current binding with the binding issued at preview time.
	 *
	 * @param mixed                $provided Provided gateway binding.
	 * @param array<string, mixed> $expected Current state binding.
	 */
	public function binding_matches( mixed $provided, array $expected ): bool {
		if ( ! is_array( $provided ) ) {
			return false;
		}
		ksort( $provided );
		ksort( $expected );
		return $expected === $provided;
	}

	/**
	 * Verify that the requested extension is absent after WordPress deletion.
	 *
	 * @param string $kind   Extension kind.
	 * @param string $target Exact installed identifier.
	 */
	public function is_installed( string $kind, string $target ): bool {
		if ( 'plugin' === $kind ) {
			$this->load_admin_api( 'plugin' );
			if ( function_exists( 'wp_clean_plugins_cache' ) ) {
				wp_clean_plugins_cache( false );
			}
			$plugins  = function_exists( 'get_plugins' ) ? get_plugins() : array();
			$relative = '.' !== dirname( $target ) ? dirname( $target ) : $target;
			$path     = defined( 'WP_PLUGIN_DIR' ) ? rtrim( WP_PLUGIN_DIR, '/\\' ) . DIRECTORY_SEPARATOR . $relative : '';
			return ( is_array( $plugins ) && array_key_exists( $target, $plugins ) ) || ( '' !== $path && ( file_exists( $path ) || is_link( $path ) ) );
		}

		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( false );
		}
		$this->load_admin_api( 'theme' );
		$themes = function_exists( 'wp_get_themes' ) ? wp_get_themes() : array();
		$root   = function_exists( 'get_theme_root' ) ? (string) get_theme_root( $target ) : '';
		$path   = '' !== $root ? rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . $target : '';
		return ( is_array( $themes ) && array_key_exists( $target, $themes ) ) || ( '' !== $path && ( file_exists( $path ) || is_link( $path ) ) );
	}

	/**
	 * Inspect one plugin and reject unsafe, shared, active, or required targets.
	 *
	 * @param string $plugin Installed plugin basename.
	 * @return array<string, mixed>
	 */
	private function inspect_plugin( string $plugin ): array {
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( false );
		}
		if ( array_key_exists( $plugin, get_mu_plugins() ) ) {
			return $this->error( 'must_use_plugin', 'Must-use plugins cannot be deleted through this workflow.' );
		}
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) {
			return $this->error( 'plugin_not_found', 'Requested plugin is not installed.' );
		}
		if ( is_plugin_active( $plugin ) || ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin ) ) ) {
			return $this->error( 'active_plugin', 'Deactivate the plugin in WordPress before deleting it.' );
		}
		if ( defined( 'ACULECT_AI_COMPANION_PLUGIN_FILE' ) && plugin_basename( ACULECT_AI_COMPANION_PLUGIN_FILE ) === $plugin ) {
			return $this->error( 'protected_plugin', 'Aculect AI Companion cannot be deleted through its own assistant connection.' );
		}

		$slug         = $this->plugin_slug( $plugin );
		$dependents   = $this->plugin_dependents( $plugin, $slug, $plugins );
		$plugin_dir   = dirname( $plugin );
		$shared_files = '.' === $plugin_dir ? array() : array_values(
			array_filter(
				array_map( 'strval', array_keys( $plugins ) ),
				static fn ( string $file ): bool => $file !== $plugin && str_starts_with( $file, $plugin_dir . '/' )
			)
		);
		if ( array() !== $dependents ) {
			return $this->error( 'plugin_dependency_blocked', 'Other installed plugins require this plugin; remove those dependencies first.' );
		}
		if ( array() !== $shared_files ) {
			return $this->error( 'shared_plugin_directory', 'This plugin shares its directory with another installed plugin and cannot be safely deleted.' );
		}

		$root     = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '';
		$relative = '.' === $plugin_dir ? $plugin : $plugin_dir;
		if ( ! $this->safe_target_path( $root, $relative, '.' !== $plugin_dir ) ) {
			return $this->error( 'unsafe_extension_path', 'The plugin files are missing or do not resolve to a safe installed directory.' );
		}

		$version = $this->bounded_version( $plugins[ $plugin ]['Version'] ?? '' );
		$state   = array(
			'active'     => false,
			'dependents' => $dependents,
			'directory'  => $plugin_dir,
			'shared'     => $shared_files,
		);
		return $this->plan( 'plugin', $plugin, $version, 'inactive', $state, $root );
	}

	/**
	 * Inspect one theme and reject active themes and themes with installed children.
	 *
	 * @param string $stylesheet Installed theme stylesheet.
	 * @return array<string, mixed>
	 */
	private function inspect_theme( string $stylesheet ): array {
		if ( function_exists( 'wp_clean_themes_cache' ) ) {
			wp_clean_themes_cache( false );
		}
		$themes = wp_get_themes();
		$theme  = $themes[ $stylesheet ] ?? null;
		if ( ! $theme instanceof \WP_Theme || ! $theme->exists() || $theme->errors() ) {
			return $this->error( 'theme_not_found', 'Requested theme is not installed or valid.' );
		}

		$active   = wp_get_theme();
		$template = (string) $theme->get_template();
		$children = array();
		foreach ( $themes as $installed_stylesheet => $installed_theme ) {
			if ( ! $installed_theme instanceof \WP_Theme || (string) $installed_stylesheet === $stylesheet ) {
				continue;
			}
			if ( (string) $installed_theme->get_template() === $stylesheet ) {
				$children[] = (string) $installed_stylesheet;
			}
		}
		sort( $children, SORT_NATURAL | SORT_FLAG_CASE );
		if ( $active->get_stylesheet() === $stylesheet || $active->get_template() === $stylesheet ) {
			return $this->error( 'active_theme', 'The active theme or its parent cannot be deleted.' );
		}
		if ( array() !== $children ) {
			return $this->error( 'parent_theme_in_use', 'This theme is the parent of an installed child theme and cannot be deleted.' );
		}

		$root      = function_exists( 'get_theme_root' ) ? (string) get_theme_root( $stylesheet ) : '';
		$core_root = function_exists( 'get_theme_root' ) ? realpath( (string) get_theme_root() ) : false;
		if ( false === $core_root || realpath( $root ) !== $core_root ) {
			return $this->error( 'unsupported_theme_root', 'Theme deletion is limited to the default WordPress theme directory; use native maintenance for other registered roots.' );
		}
		if ( ! $this->safe_target_path( $root, $stylesheet, true ) ) {
			return $this->error( 'unsafe_extension_path', 'The theme files are missing or do not resolve to a safe installed directory.' );
		}

		$version = $this->bounded_version( $theme->get( 'Version' ) );
		$state   = array(
			'active_stylesheet' => (string) $active->get_stylesheet(),
			'active_template'   => (string) $active->get_template(),
			'children'          => $children,
			'template'          => $template,
		);
		return $this->plan( 'theme', $stylesheet, $version, 'inactive', $state, $root );
	}

	/**
	 * Build the public target, hidden binding, and filesystem context.
	 *
	 * @param string               $kind    Extension kind.
	 * @param string               $target  Exact extension identifier.
	 * @param string               $version Installed version.
	 * @param string               $status  Current site status.
	 * @param array<string, mixed> $state   Relevant current state.
	 * @param string               $root    Trusted extension root.
	 * @return array<string, mixed>
	 * @throws \RuntimeException If current state cannot be encoded safely.
	 */
	private function plan( string $kind, string $target, string $version, string $status, array $state, string $root ): array {
		$target_summary = array(
			'type'    => $kind,
			'id'      => $target,
			'version' => $version,
			'status'  => $status,
			'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
		);
		$state_json     = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $state_json ) ) {
			throw new \RuntimeException( 'Extension deletion state could not be encoded.' );
		}
		$binding = array(
			'operation' => 'delete',
			'kind'      => $kind,
			'target'    => $target,
			'version'   => $version,
			'blog_id'   => (string) $target_summary['blog_id'],
			'state'     => hash( 'sha256', $state_json ),
			'root'      => hash( 'sha256', (string) realpath( $root ) ),
		);

		return array(
			'target'             => $target_summary,
			'binding'            => $binding,
			'filesystem_context' => $root,
		);
	}

	/**
	 * Validate a canonical WordPress plugin basename or theme stylesheet.
	 *
	 * @param string $kind   Extension kind.
	 * @param mixed  $target Requested identifier.
	 */
	private function validated_target( string $kind, mixed $target ): ?string {
		if ( ! is_string( $target ) || '' === $target || trim( $target ) !== $target || strlen( $target ) > 240 ) {
			return null;
		}
		$pattern = 'plugin' === $kind
			? '/^[A-Za-z0-9][A-Za-z0-9._-]*(?:\/[A-Za-z0-9][A-Za-z0-9._-]*\.php)?$/D'
			: '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D';
		return 1 === preg_match( $pattern, $target ) ? $target : null;
	}

	/**
	 * Ensure the deletion target is contained by its configured root and has no symlinks.
	 *
	 * @param string $root      Trusted WordPress extension root.
	 * @param string $relative  Validated relative target.
	 * @param bool   $directory Whether WordPress recursively deletes a directory.
	 */
	private function safe_target_path( string $root, string $relative, bool $directory ): bool {
		if ( '' === $root || ! is_dir( $root ) || str_contains( $relative, '..' ) ) {
			return false;
		}
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return false;
		}
		$path     = rtrim( $root, '/\\' );
		$segments = explode( '/', $relative );
		foreach ( $segments as $segment ) {
			$path .= DIRECTORY_SEPARATOR . $segment;
			if ( is_link( $path ) || false === realpath( $path ) ) {
				return false;
			}
		}
		$root_boundary = rtrim( $root_real, '/\\' ) . DIRECTORY_SEPARATOR;
		if ( ! $this->path_is_contained( $path, $root_boundary ) ) {
			return false;
		}
		if ( $directory ? ! is_dir( $path ) : ! is_file( $path ) ) {
			return false;
		}

		return ! is_dir( $path ) || ! $this->directory_contains_symlink( $path );
	}

	/**
	 * Confirm that one existing path resolves beneath its canonical root boundary.
	 *
	 * @param string $path          Existing extension path.
	 * @param string $root_boundary Canonical root prefix including its separator.
	 */
	private function path_is_contained( string $path, string $root_boundary ): bool {
		$resolved = realpath( $path );
		return is_string( $resolved ) && str_starts_with( $resolved, $root_boundary );
	}

	/**
	 * Detect symlinks below a recursively deleted extension directory.
	 *
	 * @param string $directory Installed extension directory.
	 */
	private function directory_contains_symlink( string $directory ): bool {
		if ( ! is_readable( $directory ) ) {
			return true;
		}
		try {
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::SELF_FIRST
			);
			$count    = 0;
			foreach ( $iterator as $entry ) {
				if ( ++$count > 10000 || ! $entry->isReadable() || $entry->isLink() ) {
					return true;
				}
			}
		} catch ( \Throwable ) {
			return true;
		}
		return false;
	}

	/**
	 * Return other installed plugins that declare a dependency on this plugin.
	 *
	 * @param string               $target  Target basename.
	 * @param string               $slug    Target slug.
	 * @param array<string, mixed> $plugins Installed plugin metadata.
	 * @return list<string>
	 */
	private function plugin_dependents( string $target, string $slug, array $plugins ): array {
		$dependents = array();
		foreach ( $plugins as $file => $metadata ) {
			$file = (string) $file;
			if ( $file === $target || ! is_array( $metadata ) ) {
				continue;
			}
			$required = $metadata['RequiresPlugins'] ?? '';
			if ( ! is_string( $required ) ) {
				continue;
			}
			foreach ( explode( ',', $required ) as $required_slug ) {
				if ( sanitize_key( trim( $required_slug ) ) === $slug ) {
					$dependents[] = $file;
					break;
				}
			}
		}
		sort( $dependents, SORT_NATURAL | SORT_FLAG_CASE );
		return $dependents;
	}

	/**
	 * Derive a plugin slug from its canonical WordPress basename.
	 *
	 * @param string $plugin Plugin basename.
	 */
	private function plugin_slug( string $plugin ): string {
		if ( 'hello.php' === $plugin ) {
			return 'hello-dolly';
		}
		$directory = dirname( $plugin );
		return '.' !== $directory ? sanitize_key( $directory ) : sanitize_key( basename( $plugin, '.php' ) );
	}

	/**
	 * Load WordPress administration APIs used for core extension lifecycle operations.
	 *
	 * @param string $kind Extension kind.
	 */
	private function load_admin_api( string $kind ): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}
		$file = 'plugin' === $kind ? 'plugin.php' : 'theme.php';
		$path = ABSPATH . 'wp-admin/includes/' . $file;
		if ( is_file( $path ) ) {
			require_once $path;
		}
		$filesystem_file = ABSPATH . 'wp-admin/includes/file.php';
		if ( is_file( $filesystem_file ) ) {
			require_once $filesystem_file;
		}
	}

	/**
	 * Bound extension version metadata for responses and confirmation identity.
	 *
	 * @param mixed $version Plugin or theme version header.
	 */
	private function bounded_version( mixed $version ): string {
		return is_scalar( $version ) ? substr( sanitize_text_field( (string) $version ), 0, 40 ) : '';
	}

	/**
	 * Return a deterministic safe error payload.
	 *
	 * @param string $code    Error identifier.
	 * @param string $message Safe public message.
	 * @return array<string, string>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'error'   => $code,
			'message' => $message,
		);
	}
}
