<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Throwable;

/**
 * Confirmed install and update operations for standalone WordPress.org themes.
 */
final class ThemePackageAbilities extends AbstractAbilityService {

	private ThemePackagePolicy $policy;
	private ThemePackageManager $manager;
	private ThemePackageMetadata $metadata;

	/**
	 * Construct the theme package operation service.
	 *
	 * @param ThemePackagePolicy|null  $policy  Theme package policy.
	 * @param ThemePackageManager|null $manager Theme package manager.
	 */
	public function __construct( ?ThemePackagePolicy $policy = null, ?ThemePackageManager $manager = null ) {
		$this->policy   = $policy ?? new ThemePackagePolicy();
		$this->manager  = $manager ?? new ThemePackageManager();
		$this->metadata = new ThemePackageMetadata( $this->policy, $this->manager );
	}

	/**
	 * Install one standalone WordPress.org theme after an exact-package preview.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function install_theme( array $args ): array {
		try {
			return $this->install( $args );
		} catch ( Throwable ) {
			return $this->error( 'theme_install_failed', 'WordPress could not install the theme.' );
		}
	}

	/**
	 * Update one installed standalone theme from cached WordPress update metadata.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function update_theme( array $args ): array {
		try {
			return $this->update( $args );
		} catch ( Throwable ) {
			return $this->error( 'theme_update_failed', 'WordPress could not update the theme.' );
		}
	}

	/**
	 * Resolve a preview or consume the server-issued install binding.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function install( array $args ): array {
		$slug = $this->policy->requested_theme_slug( $args['slug'] ?? null );
		if ( is_array( $slug ) ) {
			return $slug;
		}
		$authorization = $this->authorize( 'install_themes', $slug );
		if ( null !== $authorization ) {
			return $authorization;
		}
		$binding = $this->policy->requested_confirmation_binding( $args );
		if ( ! $this->is_dry_run( $args ) && null === $binding ) {
			return $this->error( 'confirmation_required', 'Preview the exact theme package and confirm it before installation.' );
		}

		if ( null === $binding ) {
			if ( $this->theme_exists( $slug ) ) {
				return $this->error( 'theme_already_installed', 'A theme with this stylesheet is already installed.' );
			}
			$identity = $this->metadata->install_identity( $slug );
			return is_array( $identity ) && isset( $identity['error'] ) ? $identity : $this->install_preview( $args, $identity );
		}

		$binding_error = $this->policy->validate_install_binding( $binding, $slug, get_current_blog_id() );
		if ( null !== $binding_error ) {
			return $binding_error;
		}
		if ( $this->theme_exists( $slug ) ) {
			return $this->error( 'theme_state_changed', 'The theme installation state changed after preview; create a new preview.' );
		}
		if ( $this->is_dry_run( $args ) ) {
			return $this->install_preview( $args, $binding );
		}

		return $this->execute_install( $binding );
	}

	/**
	 * Resolve a preview or consume the server-issued update binding.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function update( array $args ): array {
		$stylesheet = $this->policy->requested_theme_slug( $args['stylesheet'] ?? null );
		if ( is_array( $stylesheet ) ) {
			return $stylesheet;
		}
		$authorization = $this->authorize( 'update_themes', $stylesheet );
		if ( null !== $authorization ) {
			return $authorization;
		}
		$theme = $this->installed_theme( $stylesheet );
		if ( is_array( $theme ) ) {
			return $theme;
		}
		if ( null === $theme ) {
			return $this->error( 'theme_not_found', 'Requested theme is not installed.' );
		}
		$binding = $this->policy->requested_confirmation_binding( $args );
		if ( ! $this->is_dry_run( $args ) && null === $binding ) {
			return $this->error( 'confirmation_required', 'Preview the exact theme update and confirm it before writing theme files.' );
		}

		if ( null === $binding ) {
			$identity = $this->metadata->update_identity( $stylesheet, $theme );
			return is_array( $identity ) && isset( $identity['error'] ) ? $identity : $this->update_preview( $args, $theme, $identity );
		}

		$binding_error = $this->policy->validate_update_binding( $binding, $stylesheet, $theme->get( 'Version' ), get_current_blog_id() );
		if ( null !== $binding_error ) {
			return $binding_error;
		}
		if ( $this->is_dry_run( $args ) ) {
			return $this->update_preview( $args, $theme, $binding );
		}
		return $this->execute_update( $binding, $theme );
	}

	/**
	 * Return the filesystem and capability guard for a package write.
	 *
	 * @param string $capability Required theme capability.
	 * @param string $stylesheet Destination theme stylesheet.
	 * @return array<string, mixed>|null
	 */
	private function authorize( string $capability, string $stylesheet ): ?array {
		if ( ! current_user_can( $capability ) ) {
			return $this->error( 'forbidden', 'You do not have permission to modify themes.' );
		}
		$filesystem_error = $this->manager->filesystem_error( $stylesheet );
		if ( ! $filesystem_error instanceof \WP_Error ) {
			return null;
		}

		$code    = sanitize_key( (string) $filesystem_error->get_error_code() );
		$message = match ( $code ) {
			'theme_multisite_filesystem_scope' => 'Theme package writes are disabled on multisite.',
			'theme_file_modifications_disallowed' => 'WordPress has disabled theme file modifications.',
			default => 'Theme package operations require direct filesystem access; no credentials will be requested.',
		};
		return $this->error( '' !== $code ? $code : 'theme_filesystem_unavailable', $message );
	}

	/**
	 * Return a confirmation preview and hidden binding for an install.
	 *
	 * @param array<string, mixed> $args     Tool arguments.
	 * @param array<string, mixed> $identity Resolved install identity.
	 * @return array<string, mixed>
	 */
	private function install_preview( array $args, array $identity ): array {
		$preview = $this->preview_response(
			'theme_lifecycle.install_theme',
			$args,
			array(
				'type'    => 'theme',
				'id'      => $identity['slug'],
				'name'    => $identity['name'],
				'version' => $identity['version'],
				'source'  => 'wordpress.org',
			),
			array( $this->change( 'installed', false, true ), $this->change( 'version', '', $identity['version'] ) ),
			array( 'Theme files will be installed without activation.', 'Only the confirmed standalone WordPress.org package will be written.', 'Back up the site before installing executable theme code.' )
		);
		return $this->policy->with_confirmation_binding( $preview, $this->policy->install_binding( $identity['slug'], $identity['name'], $identity['version'], $identity['package'], $identity['requires'], $identity['requires_php'], get_current_blog_id() ) );
	}

	/**
	 * Return a confirmation preview and hidden binding for an update.
	 *
	 * @param array<string, mixed> $args     Tool arguments.
	 * @param \WP_Theme            $theme    Installed theme.
	 * @param array<string, mixed> $identity Resolved update identity.
	 * @return array<string, mixed>
	 */
	private function update_preview( array $args, \WP_Theme $theme, array $identity ): array {
		$preview = $this->preview_response(
			'theme_lifecycle.update_theme',
			$args,
			array(
				'type'    => 'theme',
				'id'      => $identity['stylesheet'],
				'name'    => $this->metadata->bounded_text( $theme->get( 'Name' ), 120 ),
				'version' => $identity['version'],
				'source'  => 'wordpress.org',
			),
			array( $this->change( 'version', $identity['current_version'], $identity['version'] ) ),
			array( 'The update replaces files for this installed standalone theme.', 'Theme updates are not allowed to install parent themes.', 'Back up the site before updating executable theme code.' )
		);
		return $this->policy->with_confirmation_binding( $preview, $this->policy->update_binding( $identity['stylesheet'], $identity['current_version'], $identity['version'], $identity['package'], $identity['requires'], $identity['requires_php'], get_current_blog_id() ) );
	}

	/**
	 * Install and verify the exact internally bound theme package.
	 *
	 * @param array<string, mixed> $binding Confirmed install identity.
	 * @return array<string, mixed>
	 */
	private function execute_install( array $binding ): array {
		$slug          = (string) $binding['slug'];
		$version       = (string) $binding['version'];
		$active_before = function_exists( 'get_stylesheet' ) ? get_stylesheet() : '';
		try {
			$result = $this->manager->install( $slug, $version, (string) $binding['package'] );
		} catch ( Throwable ) {
			return $this->partial_write( 'install' );
		}
		$failure = $this->operation_failure( $result, 'install' );
		if ( null !== $failure ) {
			return $failure;
		}
		try {
			$theme = $this->installed_theme( $slug );
		} catch ( Throwable ) {
			return $this->partial_write( 'install' );
		}
		if ( ! $theme instanceof \WP_Theme || $version !== $theme->get( 'Version' ) || $slug !== $theme->get_template() ) {
			return $this->partial_write( 'install' );
		}
		if ( function_exists( 'get_stylesheet' ) && ! hash_equals( $active_before, get_stylesheet() ) ) {
			return $this->partial_write( 'install' );
		}
		return $this->success( 'install', 'installed', $slug, $theme, $version );
	}

	/**
	 * Update and verify the exact internally bound theme package.
	 *
	 * @param array<string, mixed> $binding Confirmed update identity.
	 * @param \WP_Theme            $current Installed theme before update.
	 * @return array<string, mixed>
	 */
	private function execute_update( array $binding, \WP_Theme $current ): array {
		$stylesheet    = (string) $binding['stylesheet'];
		$version       = (string) $binding['version'];
		$active_before = function_exists( 'get_stylesheet' ) ? get_stylesheet() : '';
		try {
			$result = $this->manager->update( $stylesheet, $version, (string) $binding['package'] );
		} catch ( Throwable ) {
			return $this->partial_write( 'update' );
		}
		$failure = $this->operation_failure( $result, 'update' );
		if ( null !== $failure ) {
			return $failure;
		}
		try {
			$updated = $this->installed_theme( $stylesheet );
		} catch ( Throwable ) {
			return $this->partial_write( 'update' );
		}
		if ( ! $updated instanceof \WP_Theme || $version !== $updated->get( 'Version' ) || $stylesheet !== $updated->get_template() ) {
			return $this->partial_write( 'update' );
		}
		if ( $current->get_stylesheet() !== $updated->get_stylesheet() || ( function_exists( 'get_stylesheet' ) && ! hash_equals( $active_before, get_stylesheet() ) ) ) {
			return $this->partial_write( 'update' );
		}
		return $this->success( 'update', 'updated', $stylesheet, $updated, $version );
	}

	/**
	 * Build a bounded success response without activation or filesystem details.
	 *
	 * @param string    $operation  Operation name.
	 * @param string    $status     Result status.
	 * @param string    $stylesheet Theme stylesheet.
	 * @param \WP_Theme $theme     Verified installed theme.
	 * @param string    $version    Verified version.
	 * @return array<string, mixed>
	 */
	private function success( string $operation, string $status, string $stylesheet, \WP_Theme $theme, string $version ): array {
		$active_stylesheet = function_exists( 'get_stylesheet' ) ? get_stylesheet() : '';
		return array(
			'status'                      => $status,
			'operation'                   => $operation,
			'theme'                       => array(
				'stylesheet' => $stylesheet,
				'name'       => $this->metadata->bounded_text( $theme->get( 'Name' ), 120 ),
				'version'    => $version,
				'active'     => '' !== $active_stylesheet && hash_equals( $stylesheet, $active_stylesheet ),
			),
			'verified'                    => true,
			'changed'                     => true,
			'filesystem_credentials_used' => false,
			'parent_theme_installed'      => false,
			'activation_changed'          => false,
			'confirmation_required'       => false,
		);
	}

	/**
	 * Validate the current installed theme and reject child themes.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return \WP_Theme|array<string, string>|null
	 */
	private function installed_theme( string $stylesheet ): \WP_Theme|array|null {
		$themes = wp_get_themes();
		$theme  = $themes[ $stylesheet ] ?? null;
		if ( null === $theme ) {
			return null;
		}
		if ( ! $theme instanceof \WP_Theme || ! $theme->exists() || $theme->errors() instanceof \WP_Error ) {
			return $this->error( 'theme_invalid', 'Requested theme is not a valid installed theme.' );
		}
		if ( $stylesheet !== $theme->get_stylesheet() ) {
			return $this->error( 'theme_invalid', 'Requested theme is not a valid installed theme.' );
		}
		if ( $stylesheet !== $theme->get_template() ) {
			return $this->error( 'theme_parent_required', 'Child themes are outside this theme package operation.' );
		}
		return $theme;
	}

	/**
	 * Return whether the stylesheet key already exists, including invalid themes.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 */
	private function theme_exists( string $stylesheet ): bool {
		$themes = wp_get_themes();
		return is_array( $themes ) && array_key_exists( $stylesheet, $themes );
	}

	/**
	 * Convert a core upgrader result into a deterministic failure payload.
	 *
	 * @param mixed  $result    Core upgrader result.
	 * @param string $operation Operation name.
	 * @return array<string, mixed>|null
	 */
	private function operation_failure( mixed $result, string $operation ): ?array {
		if ( true === $result ) {
			return null;
		}
		$failure_code = $result instanceof \WP_Error ? sanitize_key( (string) $result->get_error_code() ) : '';
		if ( in_array( $failure_code, array( 'theme_multisite_filesystem_scope', 'theme_file_modifications_disallowed', 'theme_direct_filesystem_unavailable', 'theme_upgrader_unavailable' ), true ) ) {
			return $this->error( $failure_code, 'Theme package writing is not available under the current site filesystem policy.' );
		}

		return $this->partial_write( $operation );
	}

	/**
	 * Return a terminal, bounded result when a package write may have started.
	 *
	 * @param string $operation Operation name.
	 * @return array<string, mixed>
	 */
	private function partial_write( string $operation ): array {
		return array(
			'error'     => 'partial_write',
			'operation' => $operation,
			'message'   => 'WordPress may have changed theme files before reporting failure. Inspect the installed theme before trying again.',
			'terminal'  => true,
		);
	}
}
