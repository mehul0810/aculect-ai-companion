<?php
/**
 * Theme lifecycle inventory and switch abilities.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Builds bounded, lifecycle-oriented theme status models and confirmed switch operations.
 */
final class ThemeLifecycleAbilities extends AbstractAbilityService {

	private const DEFAULT_PER_PAGE = 50;
	private const MAX_PER_PAGE     = 100;
	private const STATUSES         = array( 'all', 'active', 'inactive', 'child', 'parent', 'update_available', 'block', 'classic', 'hybrid' );

	/**
	 * List installed theme lifecycle status.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_themes( array $args ): array {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect theme lifecycle status.' );
		}

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
		$status   = $this->status_filter( $args['status'] ?? 'all' );
		$items    = array_values(
			array_filter(
				$this->theme_inventory(),
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
				'filesystem_paths_included'    => false,
			),
			'safety'              => $this->safety_metadata(),
		);
	}

	/**
	 * Return one installed theme lifecycle status record.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_theme( array $args ): array {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect theme lifecycle status.' );
		}

		$stylesheet = $this->requested_stylesheet( $args['stylesheet'] ?? '' );
		if ( is_array( $stylesheet ) ) {
			return $stylesheet;
		}

		foreach ( $this->theme_inventory() as $item ) {
			if ( $stylesheet === $item['stylesheet'] ) {
				return array(
					'theme'               => $item,
					'context'             => $this->site_context(),
					'capabilities'        => $this->lifecycle_capabilities(),
					'capability_blockers' => $this->capability_blockers(),
					'safety'              => $this->safety_metadata(),
				);
			}
		}

		return $this->error( 'theme_not_found', 'Requested theme is not installed.' );
	}

	/**
	 * Switch the current site to one already-installed theme.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function switch_theme( array $args ): array {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to switch themes on this site.' );
		}

		$stylesheet = $this->requested_stylesheet( $args['stylesheet'] ?? '' );
		if ( is_array( $stylesheet ) ) {
			return $stylesheet;
		}

		$current_active = $this->site_context();
		$current        = $this->theme_inventory_item( (string) $current_active['active_stylesheet'] );
		$target         = $this->theme_inventory_item( $stylesheet );
		if ( null === $target ) {
			return $this->error( 'theme_not_found', 'Requested theme is not installed.' );
		}

		if ( true === $target['active'] ) {
			return $this->switch_noop_result( $target, 'already_active', 'Theme is already active on this site.' );
		}

		if ( $this->is_dry_run( $args ) ) {
			return $this->preview_response(
				'theme_lifecycle.switch_theme',
				$args,
				$this->theme_target_summary( $target ),
				$this->theme_switch_changes( $current_active, $target ),
				$this->theme_switch_warnings()
			);
		}

		switch_theme( $stylesheet );

		$updated = $this->theme_inventory_item( $stylesheet );
		if ( null === $updated ) {
			return $this->error( 'theme_not_found', 'Requested theme is not installed.' );
		}

		return array(
			'status'                => 'switched',
			'operation'             => 'switch_theme',
			'theme'                 => $updated,
			'changed'               => true,
			'context'               => $this->site_context(),
			'capabilities'          => $this->lifecycle_capabilities(),
			'capability_blockers'   => $this->capability_blockers(),
			'rollback'              => array(
				'operation'  => 'switch_theme',
				'stylesheet' => null === $current ? (string) $current_active['active_stylesheet'] : (string) $current['stylesheet'],
				'note'       => 'Repeat this workflow with a dry run and confirmation token before switching back to the previous theme.',
			),
			'safety'                => $this->write_safety_metadata(),
			'confirmation_required' => false,
		);
	}

	/**
	 * Build deterministic installed theme inventory records.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function theme_inventory(): array {
		$themes          = wp_get_themes();
		$active          = wp_get_theme();
		$update_metadata = $this->theme_update_metadata();
		$child_map       = $this->child_theme_map( $themes );
		$items           = array();

		foreach ( $themes as $stylesheet => $theme ) {
			if ( ! $theme instanceof \WP_Theme ) {
				continue;
			}

			$stylesheet   = (string) $stylesheet;
			$template     = (string) $theme->get_template();
			$is_child     = $stylesheet !== $template;
			$children     = $child_map[ $stylesheet ] ?? array();
			$parent       = $theme->parent();
			$presentation = $this->presentation_signals( $theme );
			$items[]      = array(
				'stylesheet'   => $stylesheet,
				'name'         => $this->bounded_text( (string) $theme->get( 'Name' ), 120 ),
				'version'      => $this->bounded_text( (string) $theme->get( 'Version' ), 40 ),
				'description'  => $this->bounded_text( (string) $theme->get( 'Description' ), 240 ),
				'author'       => $this->bounded_text( (string) $theme->get( 'Author' ), 120 ),
				'template'     => $template,
				'active'       => $active->get_stylesheet() === $stylesheet,
				'status'       => $active->get_stylesheet() === $stylesheet ? 'active' : 'inactive',
				'relationship' => array(
					'role'              => $this->relationship_role( $is_child, array() !== $children ),
					'is_child'          => $is_child,
					'is_parent'         => array() !== $children,
					'parent_stylesheet' => $is_child ? $template : '',
					'parent_name'       => $parent instanceof \WP_Theme ? $this->bounded_text( (string) $parent->get( 'Name' ), 120 ) : '',
					'child_stylesheets' => $children,
					'child_count'       => count( $children ),
				),
				'presentation' => $presentation,
				'update'       => $this->theme_update_status( $stylesheet, $update_metadata ),
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$by_name = strnatcasecmp( (string) $a['name'], (string) $b['name'] );
				return 0 === $by_name ? strcmp( (string) $a['stylesheet'], (string) $b['stylesheet'] ) : $by_name;
			}
		);

		return $items;
	}

	/**
	 * Return a child-theme map keyed by parent stylesheet.
	 *
	 * @param array<string, \WP_Theme> $themes Installed themes.
	 * @return array<string, list<string>>
	 */
	private function child_theme_map( array $themes ): array {
		$map = array();

		foreach ( $themes as $stylesheet => $theme ) {
			if ( ! $theme instanceof \WP_Theme ) {
				continue;
			}

			$stylesheet = (string) $stylesheet;
			$template   = (string) $theme->get_template();
			if ( '' === $template || $stylesheet === $template ) {
				continue;
			}

			if ( ! array_key_exists( $template, $map ) ) {
				$map[ $template ] = array();
			}

			$map[ $template ][] = $stylesheet;
		}

		foreach ( $map as &$children ) {
			sort( $children, SORT_NATURAL | SORT_FLAG_CASE );
		}

		unset( $children );

		return $map;
	}

	/**
	 * Return presentation signals for one theme.
	 *
	 * @param \WP_Theme $theme Installed theme.
	 * @return array<string, mixed>
	 */
	private function presentation_signals( \WP_Theme $theme ): array {
		$block_detection_available = method_exists( $theme, 'is_block_theme' );
		$is_block                  = $block_detection_available ? (bool) $theme->is_block_theme() : false;
		$theme_json_present        = $this->theme_has_theme_json( $theme );
		$classification            = $is_block ? 'block' : ( $theme_json_present ? 'hybrid' : 'classic' );

		return array(
			'classification'              => $classification,
			'is_block'                    => $is_block,
			'is_classic'                  => 'classic' === $classification,
			'is_hybrid'                   => 'hybrid' === $classification,
			'block_theme_detection'       => $block_detection_available ? 'available' : 'unavailable',
			'theme_json_present'          => $theme_json_present,
			'hybrid_signal_source'        => 'theme_json',
			'filesystem_paths_included'   => false,
			'raw_template_files_included' => false,
		);
	}

	/**
	 * Return whether the theme ships its own theme.json file.
	 *
	 * @param \WP_Theme $theme Installed theme.
	 */
	private function theme_has_theme_json( \WP_Theme $theme ): bool {
		if ( ! method_exists( $theme, 'get_files' ) ) {
			return false;
		}

		$files = $theme->get_files( 'json', 0, false );
		return is_array( $files ) && array_key_exists( 'theme.json', $files );
	}

	/**
	 * Return cached theme update metadata without forcing remote checks.
	 *
	 * @return array{available: bool, age_hours: int, response: array<string, mixed>}
	 */
	private function theme_update_metadata(): array {
		$value        = get_site_option( '_site_transient_update_themes', false );
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
	 * Return safe update status for one theme.
	 *
	 * @param string                                                                 $stylesheet Theme stylesheet slug.
	 * @param array{available: bool, age_hours: int, response: array<string, mixed>} $metadata   Update metadata.
	 * @return array<string, mixed>
	 */
	private function theme_update_status( string $stylesheet, array $metadata ): array {
		$update = $metadata['response'][ $stylesheet ] ?? null;

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
	 * Return theme lifecycle capabilities for the connected user.
	 *
	 * @return array<string, bool>
	 */
	private function lifecycle_capabilities(): array {
		return array(
			'can_manage_themes'         => current_user_can( 'switch_themes' ),
			'can_install_themes'        => current_user_can( 'install_themes' ),
			'can_update_themes'         => current_user_can( 'update_themes' ),
			'can_switch_themes'         => current_user_can( 'switch_themes' ),
			'can_delete_themes'         => current_user_can( 'delete_themes' ),
			'can_manage_network_themes' => is_multisite() && current_user_can( 'manage_network_themes' ),
		);
	}

	/**
	 * Return missing capabilities for lifecycle actions not implemented in this slice.
	 *
	 * @return array<string, array{capability: string}>
	 */
	private function capability_blockers(): array {
		$requirements = array(
			'install' => 'install_themes',
			'update'  => 'update_themes',
			'switch'  => 'switch_themes',
			'delete'  => 'delete_themes',
		);

		if ( is_multisite() ) {
			$requirements['network_enable']  = 'manage_network_themes';
			$requirements['network_disable'] = 'manage_network_themes';
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
		$active = wp_get_theme();

		return array(
			'multisite'                      => is_multisite(),
			'blog_id'                        => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0,
			'network_admin'                  => function_exists( 'is_network_admin' ) ? is_network_admin() : false,
			'active_stylesheet'              => $active->get_stylesheet(),
			'active_template'                => $active->get_template(),
			'active_theme_name'              => $this->bounded_text( (string) $active->get( 'Name' ), 120 ),
			'network_theme_status_available' => is_multisite(),
		);
	}

	/**
	 * Return safety metadata shared by list/get responses.
	 *
	 * @return array<string, mixed>
	 */
	private function safety_metadata(): array {
		return array(
			'read_only'                    => true,
			'install_implemented'          => false,
			'update_implemented'           => false,
			'switch_implemented'           => true,
			'delete_implemented'           => false,
			'deactivate_implemented'       => false,
			'deactivate_supported'         => false,
			'option_writes'                => false,
			'transient_writes'             => false,
			'filesystem_writes'            => false,
			'forced_update_checks'         => false,
			'raw_update_payloads_included' => false,
			'filesystem_paths_included'    => false,
			'secret_values_included'       => false,
			'unsupported_operations'       => array( 'deactivate' ),
		);
	}

	/**
	 * Return write-slice safety metadata for theme switching.
	 *
	 * @return array<string, mixed>
	 */
	private function write_safety_metadata(): array {
		return array(
			'read_only'                      => false,
			'install_implemented'            => false,
			'update_implemented'             => false,
			'switch_implemented'             => true,
			'delete_implemented'             => false,
			'deactivate_implemented'         => false,
			'deactivate_supported'           => false,
			'operation'                      => 'switch_theme',
			'site_scope_only'                => true,
			'network_scope_supported'        => false,
			'option_writes'                  => true,
			'transient_writes'               => false,
			'filesystem_writes'              => false,
			'forced_update_checks'           => false,
			'raw_update_payloads_included'   => false,
			'filesystem_paths_included'      => false,
			'secret_values_included'         => false,
			'rollback_requires_confirmation' => true,
			'unsupported_operations'         => array( 'install', 'update', 'delete', 'deactivate' ),
		);
	}

	/**
	 * Return one inventory item for the requested theme stylesheet.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return array<string, mixed>|null
	 */
	private function theme_inventory_item( string $stylesheet ): ?array {
		foreach ( $this->theme_inventory() as $item ) {
			if ( $stylesheet === $item['stylesheet'] ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Build a target summary for theme switch previews.
	 *
	 * @param array<string, mixed> $theme Current theme item.
	 * @return array<string, mixed>
	 */
	private function theme_target_summary( array $theme ): array {
		return array(
			'type'       => 'theme',
			'id'         => (string) $theme['stylesheet'],
			'name'       => (string) $theme['name'],
			'status'     => (string) $theme['status'],
			'template'   => (string) $theme['template'],
			'style_type' => (string) ( $theme['presentation']['classification'] ?? '' ),
		);
	}

	/**
	 * Build field-level changes for theme switch previews.
	 *
	 * @param array<string, mixed> $context Current active theme context.
	 * @param array<string, mixed> $target  Target theme item.
	 * @return array<int, array<string, mixed>|null>
	 */
	private function theme_switch_changes( array $context, array $target ): array {
		return array(
			$this->change( 'active_stylesheet', $context['active_stylesheet'] ?? '', $target['stylesheet'] ?? '' ),
			$this->change( 'active_template', $context['active_template'] ?? '', $target['template'] ?? '' ),
			$this->change( 'active_theme_name', $context['active_theme_name'] ?? '', $target['name'] ?? '' ),
		);
	}

	/**
	 * Build bounded switch warnings for preview and confirmation.
	 *
	 * @return string[]
	 */
	private function theme_switch_warnings(): array {
		return array(
			'Switching themes can change frontend templates, navigation rendering, widgets, and block theme settings immediately.',
			'Rollback is available by switching back to the previous theme through the same theme lifecycle tool.',
			'This first beta slice switches to an already-installed theme only; install, update, delete, and deactivate remain out of scope.',
		);
	}

	/**
	 * Build a deterministic no-op result for theme switch requests.
	 *
	 * @param array<string, mixed> $theme   Current theme item.
	 * @param string               $status  Result status.
	 * @param string               $message User-facing message.
	 * @return array<string, mixed>
	 */
	private function switch_noop_result( array $theme, string $status, string $message ): array {
		return array(
			'status'              => $status,
			'changed'             => false,
			'message'             => $message,
			'theme'               => $theme,
			'context'             => $this->site_context(),
			'capabilities'        => $this->lifecycle_capabilities(),
			'capability_blockers' => $this->capability_blockers(),
			'safety'              => $this->write_safety_metadata(),
		);
	}

	/**
	 * Normalize one requested theme stylesheet.
	 *
	 * @param mixed $value Raw requested stylesheet.
	 * @return string|array<string, string>
	 */
	private function requested_stylesheet( mixed $value ): string|array {
		if ( ! is_scalar( $value ) ) {
			return $this->error( 'invalid_theme', 'Theme must be an installed theme stylesheet slug.' );
		}

		$stylesheet = trim( (string) $value );
		if (
			'' === $stylesheet
			|| ! preg_match( '/^[A-Za-z0-9._-]+$/', $stylesheet )
			|| str_contains( $stylesheet, '..' )
		) {
			return $this->error( 'invalid_theme', 'Theme must be an installed theme stylesheet slug.' );
		}

		return $stylesheet;
	}

	/**
	 * Return a relationship role for one theme.
	 *
	 * @param bool $is_child  Whether the theme is a child theme.
	 * @param bool $is_parent Whether the theme has child themes.
	 */
	private function relationship_role( bool $is_child, bool $is_parent ): string {
		if ( $is_child && $is_parent ) {
			return 'parent_and_child';
		}

		if ( $is_child ) {
			return 'child';
		}

		if ( $is_parent ) {
			return 'parent';
		}

		return 'standalone';
	}

	/**
	 * Check a theme against a list status filter.
	 *
	 * @param array<string, mixed> $item   Theme inventory item.
	 * @param string               $status Status filter.
	 */
	private function matches_status( array $item, string $status ): bool {
		return match ( $status ) {
			'active' => true === $item['active'],
			'inactive' => false === $item['active'],
			'child' => ! empty( $item['relationship']['is_child'] ),
			'parent' => ! empty( $item['relationship']['is_parent'] ),
			'update_available' => ! empty( $item['update']['available'] ),
			'block' => 'block' === ( $item['presentation']['classification'] ?? '' ),
			'classic' => 'classic' === ( $item['presentation']['classification'] ?? '' ),
			'hybrid' => 'hybrid' === ( $item['presentation']['classification'] ?? '' ),
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
	 * @param mixed  $value Transient payload.
	 * @param string $key   Metadata key.
	 * @return mixed
	 */
	private function metadata_value( mixed $value, string $key ): mixed {
		if ( is_array( $value ) ) {
			return $value[ $key ] ?? null;
		}

		if ( is_object( $value ) && isset( $value->{$key} ) ) {
			return $value->{$key};
		}

		return null;
	}

	/**
	 * Return a metadata string from an object or array.
	 *
	 * @param mixed  $value Metadata object.
	 * @param string $key   Metadata key.
	 */
	private function metadata_string( mixed $value, string $key ): string {
		$raw = $this->metadata_value( $value, $key );
		return is_scalar( $raw ) ? (string) $raw : '';
	}

	/**
	 * Return an integer metadata value from an object or array.
	 *
	 * @param mixed  $value Metadata object.
	 * @param string $key   Metadata key.
	 */
	private function metadata_int( mixed $value, string $key ): int {
		$raw = $this->metadata_value( $value, $key );
		return is_numeric( $raw ) ? (int) $raw : 0;
	}
}
