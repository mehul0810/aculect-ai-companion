<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Validates and binds WordPress.org plugin packages before lifecycle writes.
 *
 * Keeping package identity and compatibility checks outside the lifecycle
 * service leaves that service focused on inventory and state transitions.
 */
final class PluginLifecyclePackagePolicy {

	public const CONFIRMATION_BINDING_KEY = '_aculect_confirmation_binding';

	/**
	 * Validate a package URL before WordPress core downloads it.
	 *
	 * @param mixed  $value Package URL.
	 * @param string $slug  Expected WordPress.org plugin slug.
	 * @return string|array<string, string>
	 */
	public function requested_package_url( mixed $value, string $slug = '' ): string|array {
		if ( ! is_scalar( $value ) ) {
			return $this->error( 'invalid_package_url', 'WordPress did not provide a usable plugin package URL.' );
		}

		$url    = esc_url_raw( trim( (string) $value ) );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		$user   = wp_parse_url( $url, PHP_URL_USER );
		$pass   = wp_parse_url( $url, PHP_URL_PASS );
		$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
		$file   = strtolower( basename( rawurldecode( $path ) ) );
		$slug   = sanitize_key( $slug );
		if (
			'https' !== $scheme
			|| ! in_array( $host, array( 'downloads.wordpress.org', 'downloads.wordpress.net' ), true )
			|| null !== $port
			|| null !== $user
			|| null !== $pass
			|| ! preg_match( '#^/plugin/[^/]+\.zip$#i', $path )
			|| ( '' !== $slug && ! str_starts_with( $file, strtolower( $slug ) . '.' ) )
		) {
			return $this->error( 'invalid_package_url', 'Plugin packages must come from the matching WordPress.org download host.' );
		}

		return $url;
	}

	/**
	 * Return the gateway-only confirmation binding from a call, when present.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|null
	 */
	public function requested_confirmation_binding( array $args ): ?array {
		if ( ! array_key_exists( self::CONFIRMATION_BINDING_KEY, $args ) ) {
			return null;
		}

		return is_array( $args[ self::CONFIRMATION_BINDING_KEY ] ) ? $args[ self::CONFIRMATION_BINDING_KEY ] : array();
	}

	/**
	 * Attach a gateway-only binding to a preview result.
	 *
	 * @param array<string, mixed> $preview Preview payload.
	 * @param array<string, mixed> $binding Resolved package identity.
	 * @return array<string, mixed>
	 */
	public function with_confirmation_binding( array $preview, array $binding ): array {
		$preview[ self::CONFIRMATION_BINDING_KEY ] = $binding;

		return $preview;
	}

	/**
	 * Build the exact package identity for an install confirmation.
	 *
	 * @param string $slug        WordPress.org plugin slug.
	 * @param string $name        Plugin display name.
	 * @param string $version     Candidate plugin version.
	 * @param string $package     Exact package URL.
	 * @param string $requires    Minimum WordPress version.
	 * @param string $requires_php Minimum PHP version.
	 * @return array<string, string>
	 */
	public function install_binding( string $slug, string $name, string $version, string $package, string $requires, string $requires_php ): array {
		$binding                   = array(
			'operation'    => 'install',
			'plugin'       => $slug,
			'slug'         => $slug,
			'name'         => $name,
			'package'      => $package,
			'version'      => $version,
			'requires'     => $requires,
			'requires_php' => $requires_php,
			'source'       => 'wordpress.org',
		);
		$binding['package_digest'] = $this->binding_digest( $binding );

		return $binding;
	}

	/**
	 * Build the exact package identity for an update confirmation.
	 *
	 * @param string               $plugin_file Installed plugin basename.
	 * @param array<string, mixed> $current Current inventory item.
	 * @param string               $version Candidate plugin version.
	 * @param string               $package Exact package URL.
	 * @param string               $requires Minimum WordPress version.
	 * @param string               $requires_php Minimum PHP version.
	 * @return array<string, string>
	 */
	public function update_binding( string $plugin_file, array $current, string $version, string $package, string $requires, string $requires_php ): array {
		$binding                   = array(
			'operation'       => 'update',
			'plugin'          => $plugin_file,
			'slug'            => $this->plugin_slug( $plugin_file ),
			'package'         => $package,
			'version'         => $version,
			'current_version' => (string) ( $current['version'] ?? '' ),
			'requires'        => $requires,
			'requires_php'    => $requires_php,
			'source'          => 'wordpress.org',
		);
		$binding['package_digest'] = $this->binding_digest( $binding );

		return $binding;
	}

	/**
	 * Validate an install binding before consuming a confirmation token.
	 *
	 * @param array<string, mixed> $binding Server-issued binding.
	 * @param string               $slug    Expected plugin slug.
	 * @return array<string, mixed>|null
	 */
	public function validate_install_binding( array $binding, string $slug ): ?array {
		if (
			'install' !== (string) ( $binding['operation'] ?? '' )
			|| ! hash_equals( $slug, (string) ( $binding['slug'] ?? '' ) )
			|| ! hash_equals( $slug, (string) ( $binding['plugin'] ?? '' ) )
			|| 'wordpress.org' !== (string) ( $binding['source'] ?? '' )
			|| '' === (string) ( $binding['name'] ?? '' )
			|| '' === (string) ( $binding['version'] ?? '' )
			|| ! $this->binding_digest_matches( $binding )
		) {
			return $this->error( 'invalid_confirmation_binding', 'The confirmed plugin package identity is no longer valid.' );
		}

		$package = $this->requested_package_url( $binding['package'] ?? null, $slug );
		if ( is_array( $package ) || ! hash_equals( (string) $binding['package'], $package ) ) {
			return $this->error( 'invalid_confirmation_binding', 'The confirmed plugin package identity is no longer valid.' );
		}

		return $this->requirements_error( 'install', (string) ( $binding['requires'] ?? '' ), (string) ( $binding['requires_php'] ?? '' ) );
	}

	/**
	 * Validate an update binding before consuming a confirmation token.
	 *
	 * @param array<string, mixed> $binding     Server-issued binding.
	 * @param string               $plugin_file Installed plugin basename.
	 * @param array<string, mixed> $current     Current inventory item.
	 * @return array<string, mixed>|null
	 */
	public function validate_update_binding( array $binding, string $plugin_file, array $current ): ?array {
		$slug = $this->plugin_slug( $plugin_file );
		if (
			'update' !== (string) ( $binding['operation'] ?? '' )
			|| ! hash_equals( $plugin_file, (string) ( $binding['plugin'] ?? '' ) )
			|| ! hash_equals( $slug, (string) ( $binding['slug'] ?? '' ) )
			|| (string) ( $current['version'] ?? '' ) !== (string) ( $binding['current_version'] ?? '' )
			|| '' === (string) ( $binding['version'] ?? '' )
			|| 'wordpress.org' !== (string) ( $binding['source'] ?? '' )
			|| ! $this->binding_digest_matches( $binding )
		) {
			return $this->error( 'invalid_confirmation_binding', 'The confirmed plugin package identity is no longer valid.' );
		}

		$package = $this->requested_package_url( $binding['package'] ?? null, $slug );
		if ( is_array( $package ) || ! hash_equals( (string) $binding['package'], $package ) ) {
			return $this->error( 'invalid_confirmation_binding', 'The confirmed plugin package identity is no longer valid.' );
		}

		return $this->requirements_error( 'update', (string) ( $binding['requires'] ?? '' ), (string) ( $binding['requires_php'] ?? '' ) );
	}

	/**
	 * Check candidate WordPress/PHP requirements before preview or execution.
	 *
	 * @param string $operation   Lifecycle operation being checked.
	 * @param string $requires    Minimum WordPress version.
	 * @param string $requires_php Minimum PHP version.
	 * @return array<string, string>|null
	 */
	public function requirements_error( string $operation, string $requires, string $requires_php ): ?array {
		unset( $operation );
		$wordpress = get_bloginfo( 'version' );
		if ( '' !== $requires && ! $this->valid_version_requirement( $requires ) ) {
			return $this->error( 'plugin_invalid_wordpress_requirement', 'The plugin declares an invalid WordPress requirement; the operation was blocked.' );
		}
		if ( '' !== $requires_php && ! $this->valid_version_requirement( $requires_php ) ) {
			return $this->error( 'plugin_invalid_php_requirement', 'The plugin declares an invalid PHP requirement; the operation was blocked.' );
		}
		if ( '' !== $requires && version_compare( $wordpress, $requires, '<' ) ) {
			return $this->error( 'plugin_requires_wordpress', 'The plugin requires a newer WordPress version than this site provides.' );
		}
		if ( '' !== $requires_php && version_compare( PHP_VERSION, $requires_php, '<' ) ) {
			return $this->error( 'plugin_requires_php', 'The plugin requires a newer PHP version than this site provides.' );
		}

		return null;
	}

	/**
	 * Restrict requirements to version strings understood by version_compare().
	 *
	 * @param string $version Version requirement.
	 * @return bool
	 */
	private function valid_version_requirement( string $version ): bool {
		return (bool) preg_match( '/^[0-9]+(?:\.[0-9]+)*(?:[-+][0-9A-Za-z.-]+)?$/', trim( $version ) );
	}

	/**
	 * Verify the binding digest over its canonical package identity fields.
	 *
	 * @param array<string, mixed> $binding Server-issued binding.
	 */
	private function binding_digest_matches( array $binding ): bool {
		$provided = (string) ( $binding['package_digest'] ?? '' );

		return '' !== $provided && hash_equals( $this->binding_digest( $binding ), $provided );
	}

	/**
	 * Create a stable digest without including the digest field itself.
	 *
	 * @param array<string, mixed> $binding Binding data.
	 */
	private function binding_digest( array $binding ): string {
		$payload = array();
		foreach ( array( 'operation', 'plugin', 'slug', 'name', 'package', 'version', 'current_version', 'requires', 'requires_php', 'source' ) as $key ) {
			if ( array_key_exists( $key, $binding ) ) {
				$payload[ $key ] = (string) $binding[ $key ];
			}
		}

		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * Return a plugin slug from a basename.
	 *
	 * @param string $file Plugin basename.
	 * @return string
	 */
	private function plugin_slug( string $file ): string {
		$parts = explode( '/', $file );
		$slug  = 1 < count( $parts ) ? $parts[0] : preg_replace( '/\.php$/', '', $file );

		return sanitize_key( (string) $slug );
	}

	/**
	 * Return a structured package-policy error.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Human-readable error message.
	 * @return array<string, string>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'error'   => $code,
			'message' => $message,
		);
	}
}
