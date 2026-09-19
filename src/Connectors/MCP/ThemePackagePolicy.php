<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Validates and binds WordPress.org theme package identities.
 */
final class ThemePackagePolicy {

	public const CONFIRMATION_BINDING_KEY = '_aculect_confirmation_binding';

	/**
	 * Validate a WordPress.org theme slug or installed stylesheet.
	 *
	 * @param mixed $value Requested identifier.
	 * @return string|array<string, string>
	 */
	public function requested_theme_slug( mixed $value ): string|array {
		if ( ! is_string( $value ) ) {
			return $this->error( 'invalid_theme_slug', 'Theme identifiers must use lowercase letters, numbers, and hyphens.' );
		}

		$slug = trim( $value );
		if ( ! $this->valid_slug( $slug ) ) {
			return $this->error( 'invalid_theme_slug', 'Theme identifiers must use lowercase letters, numbers, and hyphens.' );
		}

		return $slug;
	}

	/**
	 * Build the only accepted WordPress.org package URL for a theme version.
	 *
	 * @param string $slug    Theme slug.
	 * @param string $version Theme version.
	 * @return string|array<string, string>
	 */
	public function package_url( string $slug, string $version ): string|array {
		if ( ! $this->valid_slug( $slug ) || ! $this->valid_version( $version ) ) {
			return $this->error( 'invalid_package_identity', 'WordPress did not provide a valid theme package identity.' );
		}

		return 'https://downloads.wordpress.org/theme/' . $slug . '.' . $version . '.zip';
	}

	/**
	 * Require a package value to exactly match the canonical HTTPS URL.
	 *
	 * @param mixed  $value   Untrusted package value.
	 * @param string $slug    Theme slug.
	 * @param string $version Theme version.
	 * @return string|array<string, string>
	 */
	public function requested_package_url( mixed $value, string $slug, string $version ): string|array {
		$expected = $this->package_url( $slug, $version );
		if ( is_array( $expected ) || ! is_string( $value ) || ! hash_equals( $expected, $value ) ) {
			return $this->error( 'invalid_package_url', 'Theme packages must use the exact WordPress.org URL for the confirmed theme version.' );
		}

		return $expected;
	}

	/**
	 * Reject API metadata that could install an unpreviewed parent theme.
	 *
	 * @param mixed $metadata WordPress.org theme information.
	 * @return array<string, string>|null
	 */
	public function standalone_theme_error( mixed $metadata ): ?array {
		if ( ! is_array( $metadata ) && ! $metadata instanceof \stdClass ) {
			return $this->error( 'theme_parent_scope_unavailable', 'WordPress did not confirm that this is a standalone theme package.' );
		}
		$template = $this->optional_field( $metadata, 'template' );
		$parent   = $this->optional_field( $metadata, 'parent' );
		if ( null !== $template && false !== $template && ! is_string( $template ) ) {
			return $this->error( 'theme_parent_scope_unavailable', 'WordPress did not confirm that this is a standalone theme package.' );
		}
		if ( is_string( $template ) && '' !== trim( $template ) ) {
			return $this->error( 'theme_parent_required', 'Child themes are not installed because parent-theme installation is outside this operation.' );
		}
		if ( null === $parent || false === $parent || ( is_string( $parent ) && '' === trim( $parent ) ) || ( is_array( $parent ) && array() === $parent ) ) {
			return null;
		}
		if ( is_string( $parent ) || is_array( $parent ) ) {
			return $this->error( 'theme_parent_required', 'Child themes are not installed because parent-theme installation is outside this operation.' );
		}

		return $this->error( 'theme_parent_scope_unavailable', 'WordPress did not confirm that this is a standalone theme package.' );
	}

	/**
	 * Return a gateway-only binding from an ability call, when present.
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
	 * Attach an internal package identity to a preview response.
	 *
	 * @param array<string, mixed> $preview Preview payload.
	 * @param array<string, mixed> $binding Resolved identity.
	 * @return array<string, mixed>
	 */
	public function with_confirmation_binding( array $preview, array $binding ): array {
		$preview[ self::CONFIRMATION_BINDING_KEY ] = $binding;
		return $preview;
	}

	/**
	 * Build the identity confirmed for a new theme install.
	 *
	 * @param string $slug         Theme slug.
	 * @param string $name         Theme name.
	 * @param string $version      Theme version.
	 * @param string $package      Exact package URL.
	 * @param string $requires     Minimum WordPress version.
	 * @param string $requires_php Minimum PHP version.
	 * @param int    $blog_id      Current site ID.
	 * @return array<string, string>
	 */
	public function install_binding( string $slug, string $name, string $version, string $package, string $requires, string $requires_php, int $blog_id ): array {
		$binding                   = array(
			'operation'    => 'install',
			'slug'         => $slug,
			'name'         => $name,
			'version'      => $version,
			'package'      => $package,
			'requires'     => $requires,
			'requires_php' => $requires_php,
			'blog_id'      => (string) $blog_id,
			'source'       => 'wordpress.org',
		);
		$binding['package_digest'] = $this->binding_digest( $binding );
		return $binding;
	}

	/**
	 * Build the identity confirmed for an installed theme update.
	 *
	 * @param string $stylesheet      Theme stylesheet.
	 * @param string $current_version Installed version.
	 * @param string $version         Theme package version.
	 * @param string $package         Exact package URL.
	 * @param string $requires        Minimum WordPress version.
	 * @param string $requires_php    Minimum PHP version.
	 * @param int    $blog_id         Current site ID.
	 * @return array<string, string>
	 */
	public function update_binding( string $stylesheet, string $current_version, string $version, string $package, string $requires, string $requires_php, int $blog_id ): array {
		$binding                   = array(
			'operation'       => 'update',
			'stylesheet'      => $stylesheet,
			'version'         => $version,
			'current_version' => $current_version,
			'package'         => $package,
			'requires'        => $requires,
			'requires_php'    => $requires_php,
			'blog_id'         => (string) $blog_id,
			'source'          => 'wordpress.org',
		);
		$binding['package_digest'] = $this->binding_digest( $binding );
		return $binding;
	}

	/**
	 * Validate an install binding before the upgrader can write files.
	 *
	 * @param array<string, mixed> $binding Server-issued package identity.
	 * @param string               $slug    Requested theme slug.
	 * @param int                  $blog_id Current site ID.
	 * @return array<string, string>|null
	 */
	public function validate_install_binding( array $binding, string $slug, int $blog_id ): ?array {
		$version = $this->binding_string( $binding, 'version' );
		if ( 'install' !== $this->binding_string( $binding, 'operation' ) || ! hash_equals( $slug, $this->binding_string( $binding, 'slug' ) ) || '' === $this->binding_string( $binding, 'name' ) || 'wordpress.org' !== $this->binding_string( $binding, 'source' ) || ! hash_equals( (string) $blog_id, $this->binding_string( $binding, 'blog_id' ) ) || ! $this->valid_version( $version ) || ! $this->binding_digest_matches( $binding ) ) {
			return $this->invalid_binding();
		}

		$package = $this->requested_package_url( $binding['package'] ?? null, $slug, $version );
		if ( is_array( $package ) || ! hash_equals( $this->binding_string( $binding, 'package' ), $package ) ) {
			return $this->invalid_binding();
		}

		return $this->requirements_error( 'install', $this->binding_string( $binding, 'requires' ), $this->binding_string( $binding, 'requires_php' ) );
	}

	/**
	 * Validate an update binding against the installed theme's current state.
	 *
	 * @param array<string, mixed> $binding         Server-issued package identity.
	 * @param string               $stylesheet      Requested theme stylesheet.
	 * @param string               $current_version Current installed version.
	 * @param int                  $blog_id         Current site ID.
	 * @return array<string, string>|null
	 */
	public function validate_update_binding( array $binding, string $stylesheet, string $current_version, int $blog_id ): ?array {
		$version = $this->binding_string( $binding, 'version' );
		if ( 'update' !== $this->binding_string( $binding, 'operation' ) || ! hash_equals( $stylesheet, $this->binding_string( $binding, 'stylesheet' ) ) || ! hash_equals( $current_version, $this->binding_string( $binding, 'current_version' ) ) || ! hash_equals( (string) $blog_id, $this->binding_string( $binding, 'blog_id' ) ) || ! $this->valid_version( $version ) || ! $this->binding_digest_matches( $binding ) ) {
			return $this->invalid_binding();
		}

		$package = $this->requested_package_url( $binding['package'] ?? null, $stylesheet, $version );
		if ( is_array( $package ) || ! hash_equals( $this->binding_string( $binding, 'package' ), $package ) ) {
			return $this->invalid_binding();
		}

		return $this->requirements_error( 'update', $this->binding_string( $binding, 'requires' ), $this->binding_string( $binding, 'requires_php' ) );
	}

	/**
	 * Check optional WordPress and PHP version requirements.
	 *
	 * @param string $operation Operation name.
	 * @param string $requires  Minimum WordPress version.
	 * @param string $requires_php Minimum PHP version.
	 * @return array<string, string>|null
	 */
	public function requirements_error( string $operation, string $requires, string $requires_php ): ?array {
		$prefix = 'install' === $operation ? 'theme_install' : 'theme_update';
		if ( '' !== $requires && ! $this->valid_version( $requires ) ) {
			return $this->error( $prefix . '_invalid_wordpress_requirement', 'Theme package metadata contains an invalid WordPress requirement.' );
		}
		if ( '' !== $requires_php && ! $this->valid_version( $requires_php ) ) {
			return $this->error( $prefix . '_invalid_php_requirement', 'Theme package metadata contains an invalid PHP requirement.' );
		}
		if ( '' !== $requires && version_compare( get_bloginfo( 'version' ), $requires, '<' ) ) {
			return $this->error( $prefix . '_requires_wordpress', 'The theme requires a newer WordPress version than this site provides.' );
		}
		if ( '' !== $requires_php && version_compare( PHP_VERSION, $requires_php, '<' ) ) {
			return $this->error( $prefix . '_requires_php', 'The theme requires a newer PHP version than this site provides.' );
		}

		return null;
	}

	/**
	 * Validate a directory slug.
	 *
	 * @param string $slug Theme slug.
	 */
	private function valid_slug( string $slug ): bool {
		return (bool) preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/D', $slug );
	}

	/**
	 * Validate a package version suitable for an exact download path.
	 *
	 * @param string $version Theme version.
	 */
	private function valid_version( string $version ): bool {
		return (bool) preg_match( '/^[0-9]+(?:\.[0-9]+)*(?:[-+][0-9A-Za-z.-]+)?$/D', $version );
	}

	/**
	 * Read an optional metadata field without invoking dynamic accessors.
	 *
	 * @param mixed  $metadata Metadata container.
	 * @param string $key      Field name.
	 */
	private function optional_field( mixed $metadata, string $key ): mixed {
		if ( is_array( $metadata ) ) {
			return $metadata[ $key ] ?? null;
		}
		return $metadata instanceof \stdClass ? ( get_object_vars( $metadata )[ $key ] ?? null ) : null;
	}

	/**
	 * Read an internal binding string without coercing malformed values.
	 *
	 * @param array<string, mixed> $binding Internal confirmation binding.
	 * @param string               $key     Binding field.
	 */
	private function binding_string( array $binding, string $key ): string {
		$value = $binding[ $key ] ?? null;
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Verify the digest over the package identity fields.
	 *
	 * @param array<string, mixed> $binding Internal confirmation binding.
	 */
	private function binding_digest_matches( array $binding ): bool {
		$provided = $this->binding_string( $binding, 'package_digest' );
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $provided ) && hash_equals( $this->binding_digest( $binding ), $provided );
	}

	/**
	 * Create a stable digest without including the digest field itself.
	 *
	 * @param array<string, mixed> $binding Internal confirmation binding.
	 */
	private function binding_digest( array $binding ): string {
		$payload = array();
		foreach ( array( 'operation', 'slug', 'name', 'stylesheet', 'version', 'current_version', 'package', 'requires', 'requires_php', 'blog_id', 'source' ) as $key ) {
			$value = $binding[ $key ] ?? null;
			if ( is_string( $value ) ) {
				$payload[ $key ] = $value;
			}
		}

		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * Return the fixed error for any invalid server-issued identity.
	 *
	 * @return array<string, string>
	 */
	private function invalid_binding(): array {
		return $this->error( 'invalid_confirmation_binding', 'The confirmed theme package identity is no longer valid.' );
	}

	/**
	 * Return a deterministic policy error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Fixed error message.
	 * @return array<string, string>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'error'   => $code,
			'message' => $message,
		);
	}
}
