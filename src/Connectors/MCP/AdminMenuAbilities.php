<?php
/**
 * Admin menu intelligence abilities for MCP clients.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Reads WordPress admin navigation and settings surfaces safely.
 */
final class AdminMenuAbilities extends AbstractAbilityService {

	public const OPTION_SNAPSHOT = 'aculect_ai_companion_admin_menu_snapshot';

	private const MAX_MENU_ITEMS     = 120;
	private const MAX_SETTINGS       = 200;
	private const MAX_SETTING_GROUPS = 40;

	/**
	 * Return admin menu intelligence context.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_context( array $args = array() ): array {
		if ( ! $this->can_read_admin_surfaces() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect admin menu intelligence.' );
		}

		return $this->context_payload( $args );
	}

	/**
	 * Persist a plugin-owned admin menu intelligence snapshot.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function refresh_context( array $args = array() ): array {
		if ( ! $this->can_read_admin_surfaces() ) {
			return $this->error( 'forbidden', 'You do not have permission to refresh admin menu intelligence.' );
		}

		$context = $this->context_payload( array_merge( $args, array( 'context' => 'compact' ) ) );
		$stored  = array(
			'refreshed_at' => gmdate( 'c' ),
			'fingerprint'  => (string) ( $context['fingerprint'] ?? '' ),
			'context'      => $context,
		);

		if ( $this->is_dry_run( $args ) ) {
			return $this->preview_response(
				'admin_menu.refresh_context',
				$args,
				array(
					'type' => 'admin_menu_snapshot',
					'id'   => self::OPTION_SNAPSHOT,
				),
				array(
					$this->change( 'fingerprint', $this->stored_fingerprint(), (string) $stored['fingerprint'] ),
				),
				array( 'This stores only a plugin-owned snapshot for faster MCP planning. It does not update WordPress options or plugin settings.' )
			);
		}

		update_option( self::OPTION_SNAPSHOT, $stored, false );

		return array(
			'status'            => 'success',
			'message'           => 'Admin menu intelligence snapshot refreshed.',
			'snapshot'          => $this->snapshot_summary( $stored ),
			'memory_candidates' => $context['memory_candidates'] ?? array(),
			'next_actions'      => array(
				'Use admin_menu_get_context before planning WordPress core, plugin, or theme settings work.',
				'Use admin_menu_get_navigation_target to find the correct admin page for a task before asking the user to review a setting.',
				'Use memory_save with relevant memory_candidates when durable admin-navigation guidance should be reviewed.',
			),
		);
	}

	/**
	 * List visible admin pages and subpages.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_pages( array $args = array() ): array {
		if ( ! $this->can_read_admin_surfaces() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect admin pages.' );
		}

		$items   = $this->admin_pages();
		$search  = strtolower( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		$section = sanitize_key( (string) ( $args['section'] ?? '' ) );

		if ( '' !== $search || '' !== $section ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( array $item ) use ( $search, $section ): bool {
						$haystack = strtolower( implode( ' ', array( $item['title'] ?? '', $item['menu_title'] ?? '', $item['slug'] ?? '', $item['parent_slug'] ?? '' ) ) );
						if ( '' !== $section && (string) ( $item['section'] ?? '' ) !== $section ) {
							return false;
						}

						return '' === $search || str_contains( $haystack, $search );
					}
				)
			);
		}

		return array(
			'items'     => array_slice( $items, 0, self::MAX_MENU_ITEMS ),
			'total'     => count( $items ),
			'bounded'   => true,
			'max_items' => self::MAX_MENU_ITEMS,
			'context'   => $this->collection_context( $args ),
		);
	}

	/**
	 * Return a best-match admin navigation target for a task.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_navigation_target( array $args ): array {
		if ( ! $this->can_read_admin_surfaces() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect admin navigation targets.' );
		}

		$query = strtolower( sanitize_text_field( (string) ( $args['query'] ?? $args['task'] ?? '' ) ) );
		if ( '' === $query ) {
			return $this->error( 'invalid_query', 'Provide a query or task to locate an admin navigation target.' );
		}

		$candidates = array_merge( $this->admin_pages(), $this->known_core_surfaces(), $this->known_core_settings_surfaces() );
		$ranked     = array();
		foreach ( $candidates as $candidate ) {
			$score = $this->target_score( $query, $candidate );
			if ( $score <= 0 ) {
				continue;
			}

			$candidate['score'] = $score;
			$ranked[]           = $candidate;
		}

		usort(
			$ranked,
			static fn ( array $a, array $b ): int => (int) $b['score'] <=> (int) $a['score']
		);

		return array(
			'status'       => array() === $ranked ? 'not_found' : 'ready',
			'query'        => $query,
			'target'       => $ranked[0] ?? array(),
			'alternatives' => array_slice( $ranked, 1, 5 ),
			'guidance'     => array(
				'admin_only'   => true,
				'change_model' => 'Navigate to the admin page or use a future typed ability; do not edit files or arbitrary options.',
			),
		);
	}

	/**
	 * List registered settings metadata without exposing setting values.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_settings( array $args = array() ): array {
		if ( ! $this->can_read_admin_surfaces() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect registered settings.' );
		}

		$group  = sanitize_key( (string) ( $args['group'] ?? '' ) );
		$search = strtolower( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		$items  = $this->registered_settings();

		if ( '' !== $group || '' !== $search ) {
			$items = array_values(
				array_filter(
					$items,
					static function ( array $item ) use ( $group, $search ): bool {
						if ( '' !== $group && (string) ( $item['group'] ?? '' ) !== $group ) {
							return false;
						}

						$haystack = strtolower( implode( ' ', array( $item['name'] ?? '', $item['group'] ?? '', $item['description'] ?? '' ) ) );
						return '' === $search || str_contains( $haystack, $search );
					}
				)
			);
		}

		return array(
			'items'                => array_slice( $items, 0, self::MAX_SETTINGS ),
			'total'                => count( $items ),
			'bounded'              => true,
			'max_items'            => self::MAX_SETTINGS,
			'values_included'      => false,
			'raw_options_included' => false,
			'known_core_surfaces'  => $this->known_core_settings_surfaces(),
			'management_guidance'  => 'Use registered setting metadata for discovery only. Future writes must use typed abilities with capability checks, validation, dry-run, and confirmation.',
		);
	}

	/**
	 * Delete stored admin menu intelligence.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_SNAPSHOT );
	}

	/**
	 * Return the full admin menu context payload.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function context_payload( array $args ): array {
		$pages    = $this->admin_pages();
		$settings = $this->registered_settings();
		$groups   = array_values(
			array_unique(
				array_map(
					static fn ( array $setting ): string => (string) ( $setting['group'] ?? '' ),
					$settings
				)
			)
		);
		sort( $groups );

		$payload = array(
			'status'            => 'ready',
			'type'              => 'admin_menu',
			'label'             => 'Admin Menu Intelligence',
			'description'       => 'Admin-level WordPress navigation and settings-surface context for core, plugin, and theme admin screens.',
			'admin'             => array(
				'base_url'            => function_exists( 'admin_url' ) ? admin_url() : '',
				'required_capability' => 'manage_options',
				'change_model'        => 'Admin-level WordPress changes only; no filesystem or arbitrary wp_options writes.',
			),
			'menu'              => array(
				'total'       => count( $pages ),
				'items'       => array_slice( $pages, 0, 30 ),
				'sections'    => $this->section_counts( $pages ),
				'max_preview' => 30,
			),
			'settings'          => array(
				'total_registered'     => count( $settings ),
				'groups'               => array_slice( $groups, 0, self::MAX_SETTING_GROUPS ),
				'values_included'      => false,
				'raw_options_included' => false,
				'known_core_surfaces'  => $this->known_core_settings_surfaces(),
			),
			'navigation_tools'  => array(
				'list_pages'    => ( new AbilitiesRegistry() )->tool_name( 'admin_menu.list_pages' ),
				'find_target'   => ( new AbilitiesRegistry() )->tool_name( 'admin_menu.get_navigation_target' ),
				'list_settings' => ( new AbilitiesRegistry() )->tool_name( 'admin_menu.list_settings' ),
			),
			'fingerprint'       => $this->fingerprint(
				array(
					'pages'    => $pages,
					'settings' => $this->settings_fingerprint_rows( $settings ),
				)
			),
			'stored_snapshot'   => $this->snapshot_summary( (array) get_option( self::OPTION_SNAPSHOT, array() ) ),
			'memory_candidates' => $this->memory_candidates( $pages, $settings ),
			'next_actions'      => array(
				'Call admin_menu_get_navigation_target for task-specific admin navigation.',
				'Call admin_menu_list_settings to inspect registered setting metadata without reading option values.',
				'Use admin_menu_refresh_context after plugin/theme changes that add or remove admin menus.',
			),
			'safety'            => array(
				'filesystem_writes_allowed'             => false,
				'raw_wp_options_included'               => false,
				'secrets_included'                      => false,
				'future_writes_require_typed_abilities' => true,
			),
		);

		if ( 'full' === $this->collection_context( $args ) ) {
			$payload['menu']['items']       = array_slice( $pages, 0, self::MAX_MENU_ITEMS );
			$payload['settings']['preview'] = array_slice( $settings, 0, 50 );
		}

		return $payload;
	}

	/**
	 * Return current admin pages.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function admin_pages(): array {
		global $menu, $submenu;

		$items = array_merge(
			$this->known_core_surfaces(),
			$this->map_global_menu( is_array( $menu ?? null ) ? $menu : array() ),
			$this->map_global_submenu( is_array( $submenu ?? null ) ? $submenu : array() ),
			$this->known_core_submenu_surfaces()
		);

		$deduped = array();
		foreach ( $items as $item ) {
			$key = (string) ( $item['parent_slug'] ?? '' ) . '|' . (string) ( $item['slug'] ?? '' );
			if ( isset( $deduped[ $key ] ) ) {
				continue;
			}

			$deduped[ $key ] = $item;
		}

		return array_values(
			array_filter(
				$deduped,
				static fn ( array $item ): bool => true === ( $item['available'] ?? false )
			)
		);
	}

	/**
	 * Map WordPress top-level admin menu globals.
	 *
	 * @param array<int|string, mixed> $items Raw $menu.
	 * @return list<array<string, mixed>>
	 */
	private function map_global_menu( array $items ): array {
		$mapped = array();
		foreach ( $items as $position => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$mapped[] = $this->admin_page(
				$this->menu_text( $item[0] ?? '' ),
				$this->menu_text( $item[3] ?? $item[0] ?? '' ),
				(string) ( $item[2] ?? '' ),
				(string) ( $item[1] ?? 'read' ),
				'',
				$this->section_for_slug( (string) ( $item[2] ?? '' ) ),
				is_numeric( $position ) ? (int) $position : 0
			);
		}

		return $mapped;
	}

	/**
	 * Map WordPress submenu globals.
	 *
	 * @param array<string, mixed> $items Raw $submenu.
	 * @return list<array<string, mixed>>
	 */
	private function map_global_submenu( array $items ): array {
		$mapped = array();
		foreach ( $items as $parent_slug => $children ) {
			if ( ! is_array( $children ) ) {
				continue;
			}

			foreach ( $children as $position => $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$mapped[] = $this->admin_page(
					$this->menu_text( $item[0] ?? '' ),
					$this->menu_text( $item[3] ?? $item[0] ?? '' ),
					(string) ( $item[2] ?? '' ),
					(string) ( $item[1] ?? 'read' ),
					(string) $parent_slug,
					$this->section_for_slug( (string) $parent_slug ),
					is_numeric( $position ) ? (int) $position : 0
				);
			}
		}

		return $mapped;
	}

	/**
	 * Return core admin surfaces that exist even before dynamic menu globals load.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function known_core_surfaces(): array {
		return array(
			$this->admin_page( 'Dashboard', 'Dashboard', 'index.php', 'read', '', 'dashboard', 2 ),
			$this->admin_page( 'Posts', 'Posts', 'edit.php', 'edit_posts', '', 'content', 5 ),
			$this->admin_page( 'Media', 'Media Library', 'upload.php', 'upload_files', '', 'media', 10 ),
			$this->admin_page( 'Pages', 'Pages', 'edit.php?post_type=page', 'edit_pages', '', 'content', 20 ),
			$this->admin_page( 'Comments', 'Comments', 'edit-comments.php', 'moderate_comments', '', 'comments', 25 ),
			$this->admin_page( 'Appearance', 'Themes', 'themes.php', 'switch_themes', '', 'appearance', 60 ),
			$this->admin_page( 'Editor', 'Site Editor', 'site-editor.php', 'edit_theme_options', 'themes.php', 'appearance', 61 ),
			$this->admin_page( 'Plugins', 'Plugins', 'plugins.php', 'activate_plugins', '', 'plugins', 65 ),
			$this->admin_page( 'Users', 'Users', 'users.php', 'list_users', '', 'users', 70 ),
			$this->admin_page( 'Tools', 'Tools', 'tools.php', 'manage_options', '', 'tools', 75 ),
			$this->admin_page( 'Settings', 'General Settings', 'options-general.php', 'manage_options', '', 'settings', 80 ),
		);
	}

	/**
	 * Return core submenu surfaces when WordPress has not populated $submenu.
	 *
	 * Dynamic plugin and theme submenus are still added by map_global_submenu().
	 * These stable core entries keep MCP planning useful outside an admin page
	 * request without exposing option values or creating write paths.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function known_core_submenu_surfaces(): array {
		$is_block_theme = $this->is_block_theme();
		$items          = array(
			$this->admin_page( 'Home', 'Dashboard Home', 'index.php', 'read', 'index.php', 'dashboard', 2 ),
			$this->admin_page( 'Themes', 'Themes', 'themes.php', $this->appearance_capability(), 'themes.php', 'appearance', 60 ),
			$this->admin_page( 'Editor', 'Site Editor', 'site-editor.php', 'edit_theme_options', 'themes.php', 'appearance', 61 ),
		);

		if ( $this->is_multisite() ) {
			$items[] = $this->admin_page( 'My Sites', 'My Sites', 'my-sites.php', 'read', 'index.php', 'dashboard', 3 );
		} else {
			$items[] = $this->admin_page( 'Updates', 'WordPress Updates', 'update-core.php', $this->core_update_capability(), 'index.php', 'dashboard', 4 );
		}

		if ( $this->customize_surface_available( $is_block_theme ) ) {
			$items[] = $this->admin_page( 'Customize', 'Customize', 'customize.php', 'customize', 'themes.php', 'appearance', 62 );
		}

		if ( $this->theme_supports( 'menus' ) || $this->theme_supports( 'widgets' ) ) {
			$items[] = $this->admin_page( 'Menus', 'Menus', 'nav-menus.php', 'edit_theme_options', 'themes.php', 'appearance', 64 );
		}

		if ( $this->theme_supports( 'custom-header' ) && $this->capability_available( 'customize' ) ) {
			$items[] = $this->admin_page( 'Header', 'Header', 'customize.php?autofocus[control]=header_image', $this->appearance_capability(), 'themes.php', 'appearance', 65 );
		}

		if ( $this->theme_supports( 'custom-background' ) && $this->capability_available( 'customize' ) ) {
			$items[] = $this->admin_page( 'Background', 'Background', 'customize.php?autofocus[control]=background_image', $this->appearance_capability(), 'themes.php', 'appearance', 66 );
		}

		if ( ! $is_block_theme && ! $this->is_multisite() ) {
			$items[] = $this->admin_page( 'Theme File Editor', 'Theme File Editor', 'theme-editor.php', 'edit_themes', 'themes.php', 'appearance', 67 );
		}

		$items = array_merge(
			$items,
			array(
				$this->admin_page( 'Available Tools', 'Available Tools', 'tools.php', 'edit_posts', 'tools.php', 'tools', 75 ),
				$this->admin_page( 'Import', 'Import', 'import.php', 'import', 'tools.php', 'tools', 76 ),
				$this->admin_page( 'Export', 'Export', 'export.php', 'export', 'tools.php', 'tools', 77 ),
				$this->admin_page( 'Site Health', 'Site Health', 'site-health.php', 'view_site_health_checks', 'tools.php', 'tools', 78 ),
				$this->admin_page( 'Export Personal Data', 'Export Personal Data', 'export-personal-data.php', 'export_others_personal_data', 'tools.php', 'tools', 79 ),
				$this->admin_page( 'Erase Personal Data', 'Erase Personal Data', 'erase-personal-data.php', 'erase_others_personal_data', 'tools.php', 'tools', 80 ),
			)
		);

		if ( $is_block_theme && ! $this->is_multisite() ) {
			$items[] = $this->admin_page( 'Theme File Editor', 'Theme File Editor', 'theme-editor.php', 'edit_themes', 'tools.php', 'tools', 81 );
		}

		if ( $this->is_multisite() && function_exists( 'is_main_site' ) && ! is_main_site() ) {
			$items[] = $this->admin_page( 'Delete Site', 'Delete Site', 'ms-delete-site.php', 'delete_site', 'tools.php', 'tools', 82 );
		}

		if ( ! $this->is_multisite() && defined( 'WP_ALLOW_MULTISITE' ) && WP_ALLOW_MULTISITE ) {
			$items[] = $this->admin_page( 'Network Setup', 'Network Setup', 'network.php', 'setup_network', 'tools.php', 'tools', 83 );
		}

		return array_merge(
			$items,
			array(
				$this->admin_page( 'General', 'General Settings', 'options-general.php', 'manage_options', 'options-general.php', 'settings', 80 ),
				$this->admin_page( 'Writing', 'Writing Settings', 'options-writing.php', 'manage_options', 'options-general.php', 'settings', 81 ),
				$this->admin_page( 'Reading', 'Reading Settings', 'options-reading.php', 'manage_options', 'options-general.php', 'settings', 82 ),
				$this->admin_page( 'Discussion', 'Discussion Settings', 'options-discussion.php', 'manage_options', 'options-general.php', 'settings', 83 ),
				$this->admin_page( 'Media', 'Media Settings', 'options-media.php', 'manage_options', 'options-general.php', 'settings', 84 ),
				$this->admin_page( 'Permalinks', 'Permalink Settings', 'options-permalink.php', 'manage_options', 'options-general.php', 'settings', 85 ),
				$this->admin_page( 'Privacy', 'Privacy Settings', 'options-privacy.php', 'manage_privacy_options', 'options-general.php', 'settings', 86 ),
			)
		);
	}

	/**
	 * Return the capability WordPress uses for its updates submenu.
	 */
	private function core_update_capability(): string {
		foreach ( array( 'update_core', 'update_plugins', 'update_themes', 'update_languages' ) as $capability ) {
			if ( $this->capability_available( $capability ) ) {
				return $capability;
			}
		}

		return 'update_languages';
	}

	/**
	 * Return the capability WordPress uses for its Appearance menu.
	 */
	private function appearance_capability(): string {
		return function_exists( 'current_user_can' ) && current_user_can( 'switch_themes' ) ? 'switch_themes' : 'edit_theme_options';
	}

	/**
	 * Check whether the active theme exposes a feature.
	 *
	 * @param string $feature Theme feature.
	 */
	private function theme_supports( string $feature ): bool {
		return function_exists( 'current_theme_supports' ) && current_theme_supports( $feature );
	}

	/**
	 * Return whether the active site uses a block theme.
	 */
	private function is_block_theme(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}

	/**
	 * Return whether the site is multisite.
	 */
	private function is_multisite(): bool {
		return function_exists( 'is_multisite' ) && is_multisite();
	}

	/**
	 * Check the conditional Customize submenu rule used by WordPress core.
	 *
	 * @param bool $is_block_theme Whether the active theme is a block theme.
	 */
	private function customize_surface_available( bool $is_block_theme ): bool {
		return ! $is_block_theme || ( function_exists( 'has_action' ) && false !== has_action( 'customize_register' ) );
	}

	/**
	 * Return known core settings pages.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function known_core_settings_surfaces(): array {
		return array(
			$this->settings_surface( 'general', 'General', 'options-general.php', 'manage_options' ),
			$this->settings_surface( 'writing', 'Writing', 'options-writing.php', 'manage_options' ),
			$this->settings_surface( 'reading', 'Reading', 'options-reading.php', 'manage_options' ),
			$this->settings_surface( 'discussion', 'Discussion', 'options-discussion.php', 'manage_options' ),
			$this->settings_surface( 'media', 'Media', 'options-media.php', 'manage_options' ),
			$this->settings_surface( 'permalink', 'Permalinks', 'options-permalink.php', 'manage_options' ),
			$this->settings_surface( 'privacy', 'Privacy', 'options-privacy.php', 'manage_privacy_options' ),
		);
	}

	/**
	 * Build one settings surface entry.
	 *
	 * @param string $group      Settings group.
	 * @param string $title      Page title.
	 * @param string $slug       Admin slug.
	 * @param string $capability Required capability.
	 * @return array<string, mixed>
	 */
	private function settings_surface( string $group, string $title, string $slug, string $capability ): array {
		return array(
			'group'      => $group,
			'title'      => $title,
			'slug'       => $slug,
			'url'        => $this->admin_page_url( $slug ),
			'capability' => $capability,
			'available'  => $this->capability_available( $capability ),
		);
	}

	/**
	 * Return registered settings metadata.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function registered_settings(): array {
		$settings = array();
		if ( function_exists( 'get_registered_settings' ) ) {
			$settings = get_registered_settings();
		} else {
			global $wp_registered_settings;
			$settings = is_array( $wp_registered_settings ?? null ) ? $wp_registered_settings : array();
		}

		$items = array();
		foreach ( $settings as $name => $setting ) {
			if ( ! is_array( $setting ) ) {
				continue;
			}

			$show_in_rest = $setting['show_in_rest'] ?? false;
			$items[]      = array(
				'name'           => sanitize_key( (string) $name ),
				'group'          => sanitize_key( (string) ( $setting['group'] ?? '' ) ),
				'type'           => sanitize_key( (string) ( $setting['type'] ?? '' ) ),
				'description'    => sanitize_text_field( (string) ( $setting['description'] ?? '' ) ),
				'show_in_rest'   => ! empty( $show_in_rest ),
				'has_default'    => array_key_exists( 'default', $setting ),
				'has_callback'   => ! empty( $setting['sanitize_callback'] ),
				'value_included' => false,
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$group_compare = strcmp( (string) $a['group'], (string) $b['group'] );
				return 0 !== $group_compare ? $group_compare : strcmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		return $items;
	}

	/**
	 * Build one admin page entry.
	 *
	 * @param string $menu_title  Menu title.
	 * @param string $title       Page title.
	 * @param string $slug        Menu slug/path.
	 * @param string $capability  Required capability.
	 * @param string $parent_slug Parent slug.
	 * @param string $section     High-level section.
	 * @param int    $position    Menu position.
	 * @return array<string, mixed>
	 */
	private function admin_page( string $menu_title, string $title, string $slug, string $capability, string $parent_slug, string $section, int $position ): array {
		$slug = sanitize_text_field( $slug );

		return array(
			'menu_title'  => sanitize_text_field( $menu_title ),
			'title'       => sanitize_text_field( '' === $title ? $menu_title : $title ),
			'slug'        => $slug,
			'parent_slug' => sanitize_text_field( $parent_slug ),
			'section'     => sanitize_key( $section ),
			'url'         => $this->admin_page_url( $slug ),
			'capability'  => sanitize_key( $capability ),
			'available'   => $this->capability_available( $capability ),
			'position'    => $position,
		);
	}

	/**
	 * Return an admin URL for a menu slug.
	 *
	 * @param string $slug Menu slug or path.
	 */
	private function admin_page_url( string $slug ): string {
		if ( '' === $slug || ! function_exists( 'admin_url' ) ) {
			return '';
		}

		return admin_url( ltrim( $slug, '/' ) );
	}

	/**
	 * Strip common menu count markup.
	 *
	 * @param mixed $value Raw menu text.
	 */
	private function menu_text( mixed $value ): string {
		$text = is_scalar( $value ) ? (string) $value : '';
		$text = preg_replace( '/<span[^>]*>.*?<\/span>/i', '', $text ) ?? $text;

		return trim( wp_strip_all_tags( $text ) );
	}

	/**
	 * Infer the high-level admin section for a slug.
	 *
	 * @param string $slug Admin slug.
	 */
	private function section_for_slug( string $slug ): string {
		return match ( true ) {
			str_starts_with( $slug, 'edit.php' ) => 'content',
			str_starts_with( $slug, 'upload.php' ) => 'media',
			str_starts_with( $slug, 'edit-comments.php' ) => 'comments',
			str_starts_with( $slug, 'themes.php' ), str_starts_with( $slug, 'site-editor.php' ) => 'appearance',
			str_starts_with( $slug, 'plugins.php' ) => 'plugins',
			str_starts_with( $slug, 'users.php' ), str_starts_with( $slug, 'profile.php' ) => 'users',
			str_starts_with( $slug, 'tools.php' ) => 'tools',
			str_starts_with( $slug, 'options-' ) => 'settings',
			default => 'plugin',
		};
	}

	/**
	 * Count pages by high-level section.
	 *
	 * @param list<array<string, mixed>> $pages Admin pages.
	 * @return array<string, int>
	 */
	private function section_counts( array $pages ): array {
		$counts = array();
		foreach ( $pages as $page ) {
			$section            = (string) ( $page['section'] ?? 'plugin' );
			$counts[ $section ] = (int) ( $counts[ $section ] ?? 0 ) + 1;
		}
		ksort( $counts );

		return $counts;
	}

	/**
	 * Rank a target for a query.
	 *
	 * @param string               $query Query.
	 * @param array<string, mixed> $target Target entry.
	 */
	private function target_score( string $query, array $target ): int {
		$haystack = strtolower( implode( ' ', array( $target['title'] ?? '', $target['menu_title'] ?? '', $target['slug'] ?? '', $target['section'] ?? '', $target['group'] ?? '' ) ) );
		$score    = 0;
		$tokens   = preg_split( '/\s+/', $query );
		$tokens   = is_array( $tokens ) ? $tokens : array();

		foreach ( $tokens as $token ) {
			$token = trim( (string) $token );
			if ( strlen( $token ) < 3 ) {
				continue;
			}

			$matches = substr_count( $haystack, $token );
			if ( $matches > 0 ) {
				$score += 10 * $matches;
			}

			if ( (string) ( $target['group'] ?? '' ) === $token || str_contains( (string) ( $target['slug'] ?? '' ), '-' . $token ) ) {
				$score += 25;
			}
		}

		return $score;
	}

	/**
	 * Return reduced settings rows for fingerprinting.
	 *
	 * @param list<array<string, mixed>> $settings Settings rows.
	 * @return list<array<string, mixed>>
	 */
	private function settings_fingerprint_rows( array $settings ): array {
		return array_map(
			static fn ( array $setting ): array => array(
				'name'         => (string) ( $setting['name'] ?? '' ),
				'group'        => (string) ( $setting['group'] ?? '' ),
				'type'         => (string) ( $setting['type'] ?? '' ),
				'show_in_rest' => ! empty( $setting['show_in_rest'] ),
			),
			$settings
		);
	}

	/**
	 * Return memory candidates for durable review.
	 *
	 * @param list<array<string, mixed>> $pages    Admin pages.
	 * @param list<array<string, mixed>> $settings Registered settings.
	 * @return list<array<string, string>>
	 */
	private function memory_candidates( array $pages, array $settings ): array {
		$sections = $this->section_counts( $pages );
		$groups   = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ( array $setting ): string => (string) ( $setting['group'] ?? '' ),
						$settings
					)
				)
			)
		);
		sort( $groups );

		return array(
			array(
				'key'        => 'admin_menu.sections.available',
				'domain'     => 'site',
				'value'      => sprintf( 'Admin menu intelligence can see %d admin pages across sections: %s.', count( $pages ), implode( ', ', array_keys( $sections ) ) ),
				'evidence'   => 'Admin menu intelligence summarized visible menu pages for the connected administrator.',
				'confidence' => 'medium',
				'source'     => 'admin_menu',
			),
			array(
				'key'        => 'admin_menu.settings.groups',
				'domain'     => 'workflow',
				'value'      => sprintf( 'Registered settings metadata is available for groups: %s.', array() === $groups ? 'none detected' : implode( ', ', array_slice( $groups, 0, self::MAX_SETTING_GROUPS ) ) ),
				'evidence'   => 'Admin menu intelligence read registered settings metadata without setting values.',
				'confidence' => array() === $groups ? 'low' : 'medium',
				'source'     => 'admin_menu',
			),
		);
	}

	/**
	 * Check a capability.
	 *
	 * @param string $capability Capability name.
	 */
	private function capability_available( string $capability ): bool {
		return '' === $capability || ! function_exists( 'current_user_can' ) || current_user_can( $capability );
	}

	/**
	 * Return the current stored fingerprint.
	 */
	private function stored_fingerprint(): string {
		$stored = get_option( self::OPTION_SNAPSHOT, array() );

		return is_array( $stored ) ? (string) ( $stored['fingerprint'] ?? '' ) : '';
	}

	/**
	 * Return a bounded snapshot summary.
	 *
	 * @param array<string, mixed> $stored Stored snapshot.
	 * @return array<string, mixed>
	 */
	private function snapshot_summary( array $stored ): array {
		return array(
			'available'    => array() !== $stored,
			'refreshed_at' => (string) ( $stored['refreshed_at'] ?? '' ),
			'fingerprint'  => (string) ( $stored['fingerprint'] ?? '' ),
		);
	}

	/**
	 * Compute a stable fingerprint.
	 *
	 * @param mixed $value Payload.
	 */
	private function fingerprint( mixed $value ): string {
		$json = wp_json_encode( $value );

		return false === $json ? '' : hash( 'sha256', $json );
	}

	/**
	 * Check read permission for admin surfaces.
	 */
	private function can_read_admin_surfaces(): bool {
		return ! function_exists( 'current_user_can' ) || current_user_can( 'manage_options' );
	}
}
