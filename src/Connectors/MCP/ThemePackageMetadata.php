<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Resolves safe, bounded theme package metadata for preview operations. */
final class ThemePackageMetadata extends AbstractAbilityService {

	private ThemePackagePolicy $policy;
	private ThemePackageManager $manager;

	/**
	 * Construct the metadata resolver.
	 *
	 * @param ThemePackagePolicy  $policy  Theme package policy.
	 * @param ThemePackageManager $manager Theme package manager.
	 */
	public function __construct( ThemePackagePolicy $policy, ThemePackageManager $manager ) {
		$this->policy  = $policy;
		$this->manager = $manager;
	}

	/**
	 * Resolve and validate install facts from WordPress.org.
	 *
	 * @param string $slug WordPress.org theme slug.
	 * @return array<string, mixed>
	 */
	public function install_identity( string $slug ): array {
		$metadata = $this->manager->information( $slug );
		if ( $metadata instanceof \WP_Error ) {
			return $this->api_failure( 'install' );
		}
		if ( ! is_array( $metadata ) && ! $metadata instanceof \stdClass ) {
			return $this->error( 'theme_install_information_unavailable', 'WordPress did not return usable theme information.' );
		}

		$metadata_slug = $this->metadata_string( $metadata, 'slug', true, 'theme_install_information_unavailable' );
		if ( is_array( $metadata_slug ) ) {
			return $metadata_slug;
		}
		if ( ! hash_equals( $slug, $metadata_slug ) ) {
			return $this->error( 'theme_source_mismatch', 'WordPress returned information for a different theme.' );
		}
		$parent_error = $this->policy->standalone_theme_error( $metadata );
		if ( null !== $parent_error ) {
			return $parent_error;
		}

		$name = $this->metadata_string( $metadata, 'name', true, 'theme_install_information_unavailable' );
		if ( is_array( $name ) ) {
			return $name;
		}
		$version = $this->metadata_string( $metadata, 'version', true, 'theme_install_information_unavailable' );
		if ( is_array( $version ) ) {
			return $version;
		}
		$requires = $this->metadata_string( $metadata, 'requires', false, 'theme_install_information_unavailable' );
		if ( is_array( $requires ) ) {
			return $requires;
		}
		$requires_php = $this->metadata_string( $metadata, 'requires_php', false, 'theme_install_information_unavailable' );
		if ( is_array( $requires_php ) ) {
			return $requires_php;
		}
		if ( ! $this->valid_package_version( $version ) ) {
			return $this->error( 'theme_install_information_unavailable', 'WordPress did not return a valid target theme version.' );
		}
		$requirements = $this->policy->requirements_error( 'install', $requires, $requires_php );
		if ( null !== $requirements ) {
			return $requirements;
		}
		$package = $this->policy->package_url( $slug, $version );
		if ( is_array( $package ) ) {
			return $package;
		}

		return array(
			'slug'         => $slug,
			'name'         => $this->bounded_text( $name, 120 ),
			'version'      => $version,
			'package'      => $package,
			'requires'     => $requires,
			'requires_php' => $requires_php,
		);
	}

	/**
	 * Resolve only previously cached update_themes metadata.
	 *
	 * @param string    $stylesheet Theme stylesheet.
	 * @param \WP_Theme $theme      Installed theme.
	 * @return array<string, mixed>
	 */
	public function update_identity( string $stylesheet, \WP_Theme $theme ): array {
		$metadata = get_site_transient( 'update_themes' );
		$response = $this->metadata_value( $metadata, 'response' );
		$response = is_object( $response ) ? get_object_vars( $response ) : $response;
		$update   = is_array( $response ) ? ( $response[ $stylesheet ] ?? null ) : null;
		if ( ! is_array( $update ) && ! $update instanceof \stdClass ) {
			return $this->error( 'theme_update_unavailable', 'No cached WordPress theme update is available.' );
		}

		$version = $this->metadata_string( $update, 'new_version', true, 'theme_update_unavailable' );
		if ( is_array( $version ) ) {
			return $version;
		}
		$package_value = $this->metadata_string( $update, 'package', true, 'theme_update_unavailable' );
		if ( is_array( $package_value ) ) {
			return $package_value;
		}
		$requires = $this->metadata_string( $update, 'requires', false, 'theme_update_unavailable' );
		if ( is_array( $requires ) ) {
			return $requires;
		}
		$requires_php = $this->metadata_string( $update, 'requires_php', false, 'theme_update_unavailable' );
		if ( is_array( $requires_php ) ) {
			return $requires_php;
		}
		$current_version = $theme->get( 'Version' );
		if ( ! $this->valid_package_version( $version ) || version_compare( $version, $current_version, '<=' ) ) {
			return $this->error( 'theme_update_unavailable', 'Cached theme metadata does not contain a newer valid version.' );
		}
		$package = $this->policy->requested_package_url( $package_value, $stylesheet, $version );
		if ( is_array( $package ) ) {
			return $package;
		}
		$requirements = $this->policy->requirements_error( 'update', $requires, $requires_php );
		if ( null !== $requirements ) {
			return $requirements;
		}

		return array(
			'stylesheet'      => $stylesheet,
			'name'            => $this->bounded_text( $theme->get( 'Name' ), 120 ),
			'current_version' => $current_version,
			'version'         => $version,
			'package'         => $package,
			'requires'        => $requires,
			'requires_php'    => $requires_php,
		);
	}

	/**
	 * Read a string field from a plain API object without invoking magic accessors.
	 *
	 * @param mixed  $metadata   API metadata.
	 * @param string $key        Requested field.
	 * @param bool   $required   Whether the field must be present.
	 * @param string $error_code Fixed error code.
	 * @return string|array<string, string>
	 */
	private function metadata_string( mixed $metadata, string $key, bool $required, string $error_code ): string|array {
		if ( is_array( $metadata ) ) {
			$present = array_key_exists( $key, $metadata );
			$value   = $metadata[ $key ] ?? null;
		} elseif ( $metadata instanceof \stdClass ) {
			$present = property_exists( $metadata, $key );
			$value   = get_object_vars( $metadata )[ $key ] ?? null;
		} else {
			$present = false;
			$value   = null;
		}
		if ( ! $present || null === $value || false === $value ) {
			return $required ? $this->error( $error_code, 'WordPress did not return required theme package metadata.' ) : '';
		}
		if ( ! is_string( $value ) || ( $required && '' === trim( $value ) ) ) {
			return $this->error( $error_code, 'WordPress returned malformed theme package metadata.' );
		}
		return trim( $value );
	}

	/**
	 * Read a field from a plain array or stdClass transient.
	 *
	 * @param mixed  $metadata Metadata container.
	 * @param string $key      Field name.
	 */
	private function metadata_value( mixed $metadata, string $key ): mixed {
		return is_array( $metadata ) ? ( $metadata[ $key ] ?? null ) : ( $metadata instanceof \stdClass ? ( get_object_vars( $metadata )[ $key ] ?? null ) : null );
	}

	/**
	 * Ensure a version can form the exact download package identity.
	 *
	 * @param string $version Theme version.
	 */
	private function valid_package_version( string $version ): bool {
		return is_string( $this->policy->package_url( 'valid-theme', $version ) );
	}

	/**
	 * Return bounded plain text for theme names.
	 *
	 * @param string $text       Text to sanitize.
	 * @param int    $max_length Maximum output length.
	 */
	public function bounded_text( string $text, int $max_length ): string {
		$text = sanitize_text_field( wp_strip_all_tags( $text ) );
		return strlen( $text ) <= $max_length ? $text : rtrim( substr( $text, 0, max( 1, $max_length - 3 ) ) ) . '...';
	}

	/**
	 * Map an upstream API failure without exposing its details.
	 *
	 * @param string $operation Operation name.
	 * @return array<string, mixed>
	 */
	private function api_failure( string $operation ): array {
		return array(
			'error'        => 'theme_' . $operation . '_information_failed',
			'message'      => 'WordPress could not retrieve theme package information.',
			'failure_code' => 'api_error',
		);
	}
}
