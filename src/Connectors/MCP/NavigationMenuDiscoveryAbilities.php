<?php
/**
 * Read-only navigation and menu discovery for MCP clients.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Exposes bounded classic-menu and block-navigation inventory without writes.
 */
final class NavigationMenuDiscoveryAbilities extends AbstractAbilityService {
	private const DEFAULT_PER_PAGE       = 20;
	private const MAX_PER_PAGE           = 100;
	private const MAX_UNSUPPORTED_BLOCKS = 25;

	/**
	 * Return active navigation context for the current theme and readable menus.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_context( array $args = array() ): array {
		if ( ! $this->can_read_navigation() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect WordPress navigation or menus.' );
		}

		$context           = $this->collection_context( $args );
		$theme             = $this->theme_context();
		$classic_menus     = $this->classic_menu_inventory( 'compact' );
		$classic_locations = $this->classic_location_inventory();
		$wp_navigation     = $this->wp_navigation_inventory( 'compact' );
		$summary           = $this->navigation_summary( $theme, $classic_menus, $classic_locations, $wp_navigation );

		$result = array(
			'status'        => 'ready',
			'type'          => 'navigation_menu',
			'label'         => 'Navigation Intelligence',
			'description'   => 'Read-only WordPress navigation context and inventory for classic menus, classic menu locations, and wp_navigation entities. No writes are implemented in this slice.',
			'theme'         => $theme,
			'navigation'    => $summary,
			'capabilities'  => array(
				'can_read'            => true,
				'can_write'           => false,
				'required_capability' => 'edit_theme_options',
			),
			'write_support' => $this->write_support_policy(),
			'safety'        => array(
				'writes_implemented'                  => false,
				'raw_string_navigation_edits_allowed' => false,
				'explicit_location_reassignment_only_for_writes' => true,
				'preserve_unknown_blocks_for_future_writes' => true,
				'validate_parsed_block_structure_before_save' => true,
				'fail_closed_on_unsupported_write_structures' => true,
			),
			'next_actions'  => array(
				'Use navigation_list_menus to inventory readable classic menus and wp_navigation entities.',
				'Use navigation_list_locations to inspect registered classic menu locations before planning any explicit reassignment.',
				'Use navigation_list_items with menu_id, navigation_id, or location for bounded item-level inventory only.',
			),
			'read_only'     => true,
		);

		if ( 'full' === $context ) {
			$result['inventory_preview'] = array(
				'classic_menus'     => array_slice( $classic_menus, 0, 5 ),
				'classic_locations' => array_slice( $classic_locations, 0, 5 ),
				'wp_navigation'     => array_slice( $wp_navigation, 0, 5 ),
			);
		}

		return $result;
	}

	/**
	 * List readable classic menus and wp_navigation entities.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_menus( array $args = array() ): array {
		if ( ! $this->can_read_navigation() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect WordPress navigation or menus.' );
		}

		$page        = $this->page( $args );
		$per_page    = $this->per_page( $args );
		$context     = $this->collection_context( $args );
		$search      = $this->search_term( $args['search'] ?? '' );
		$source_type = sanitize_key( (string) ( $args['source_type'] ?? 'all' ) );
		$items       = array_merge(
			'classic_menu' === $source_type ? array() : $this->classic_menu_inventory( $context ),
			'classic_menu' !== $source_type && 'all' !== $source_type && 'wp_navigation' !== $source_type
				? array()
				: ( 'classic_menu' === $source_type ? array() : $this->wp_navigation_inventory( $context ) )
		);

		if ( 'classic_menu' === $source_type ) {
			$items = $this->classic_menu_inventory( $context );
		} elseif ( 'wp_navigation' === $source_type ) {
			$items = $this->wp_navigation_inventory( $context );
		}

		$items = $this->filter_items_by_search( $items, $search, array( 'name', 'title', 'slug', 'source_type' ) );
		$items = $this->slice_items( $items, $page, $per_page );

		return array(
			'items'        => $items['items'],
			'total'        => $items['total'],
			'page'         => $page,
			'per_page'     => $per_page,
			'has_more'     => $items['has_more'],
			'context'      => $context,
			'read_only'    => true,
			'capabilities' => array(
				'can_read'            => true,
				'can_write'           => false,
				'required_capability' => 'edit_theme_options',
			),
			'summary'      => array(
				'source_filter'            => in_array( $source_type, array( 'classic_menu', 'wp_navigation' ), true ) ? $source_type : 'all',
				'returns_write_operations' => false,
				'write_support'            => $this->write_support_policy(),
			),
		);
	}

	/**
	 * List registered classic menu locations.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_locations( array $args = array() ): array {
		if ( ! $this->can_read_navigation() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect WordPress navigation or menus.' );
		}

		$page          = $this->page( $args );
		$per_page      = $this->per_page( $args );
		$search        = $this->search_term( $args['search'] ?? '' );
		$assigned_only = ! empty( $args['assigned_only'] );
		$items         = $this->classic_location_inventory();

		if ( $assigned_only ) {
			$items = array_values(
				array_filter(
					$items,
					static fn ( array $item ): bool => ! empty( $item['has_assignment'] )
				)
			);
		}

		$items = $this->filter_items_by_search( $items, $search, array( 'id', 'label', 'menu_name', 'menu_slug' ) );
		$items = $this->slice_items( $items, $page, $per_page );

		return array(
			'items'        => $items['items'],
			'total'        => $items['total'],
			'page'         => $page,
			'per_page'     => $per_page,
			'has_more'     => $items['has_more'],
			'context'      => 'compact',
			'read_only'    => true,
			'capabilities' => array(
				'can_read'                     => true,
				'can_write'                    => false,
				'required_capability'          => 'edit_theme_options',
				'future_reassignment_policy'   => 'explicit_only',
				'future_reassignment_confirms' => true,
			),
			'summary'      => array(
				'registered_locations'       => $items['total'],
				'returns_write_operations'   => false,
				'future_reassignment_policy' => 'Classic and hybrid location reassignment must remain explicit-only with confirmation and audit.',
			),
		);
	}

	/**
	 * List items for one classic menu, location, or wp_navigation entity.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_items( array $args ): array {
		if ( ! $this->can_read_navigation() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect WordPress navigation or menus.' );
		}

		$page     = $this->page( $args );
		$per_page = $this->per_page( $args );
		$context  = $this->collection_context( $args );
		$target   = $this->resolve_item_target( $args );

		if ( isset( $target['error'] ) ) {
			return $target;
		}

		$items   = array();
		$summary = array(
			'returns_write_operations' => false,
			'write_support'            => $this->write_support_policy(),
		);

		if ( 'wp_navigation' === $target['source_type'] ) {
			$parsed  = $this->parse_navigation_post( $target['post'], $context );
			$items   = $parsed['items'];
			$summary = array_merge( $summary, $parsed['summary'] );
		} else {
			$items   = $this->classic_menu_items( $target['menu'] );
			$summary = array_merge(
				$summary,
				array(
					'source_type'                => $target['requested_source_type'],
					'resolved_source_type'       => 'classic_menu',
					'resolved_menu_id'           => (int) $target['menu']->term_id,
					'resolved_menu_name'         => sanitize_text_field( (string) $target['menu']->name ),
					'location'                   => $target['location'],
					'future_reassignment_policy' => 'explicit_only',
				)
			);
		}

		$paginated = $this->slice_items( $items, $page, $per_page );

		return array(
			'items'        => $paginated['items'],
			'total'        => $paginated['total'],
			'page'         => $page,
			'per_page'     => $per_page,
			'has_more'     => $paginated['has_more'],
			'context'      => $context,
			'read_only'    => true,
			'capabilities' => array(
				'can_read'            => true,
				'can_write'           => false,
				'required_capability' => 'edit_theme_options',
			),
			'summary'      => $summary,
		);
	}

	/**
	 * Return active theme navigation context.
	 *
	 * @return array<string, mixed>
	 */
	private function theme_context(): array {
		$theme           = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$is_block_theme  = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$registered      = function_exists( 'get_registered_nav_menus' ) ? get_registered_nav_menus() : array();
		$classic_support = $is_block_theme ? false : ( function_exists( 'current_theme_supports' ) ? current_theme_supports( 'menus' ) : array() !== $registered );

		return array(
			'name'                      => is_object( $theme ) && method_exists( $theme, 'get' ) ? sanitize_text_field( (string) $theme->get( 'Name' ) ) : '',
			'stylesheet'                => is_object( $theme ) && method_exists( $theme, 'get_stylesheet' ) ? sanitize_key( (string) $theme->get_stylesheet() ) : '',
			'template'                  => is_object( $theme ) && method_exists( $theme, 'get_template' ) ? sanitize_key( (string) $theme->get_template() ) : '',
			'version'                   => is_object( $theme ) && method_exists( $theme, 'get' ) ? sanitize_text_field( (string) $theme->get( 'Version' ) ) : '',
			'is_block_theme'            => $is_block_theme,
			'supports_classic_menus'    => $classic_support,
			'registered_location_count' => count( $registered ),
		);
	}

	/**
	 * Return classic menu inventory.
	 *
	 * @param string $context Compact or full.
	 * @return list<array<string, mixed>>
	 */
	private function classic_menu_inventory( string $context ): array {
		if ( ! function_exists( 'wp_get_nav_menus' ) ) {
			return array();
		}

		$registered_locations = function_exists( 'get_registered_nav_menus' ) ? get_registered_nav_menus() : array();
		$location_assignments = function_exists( 'get_nav_menu_locations' ) ? get_nav_menu_locations() : array();
		$menus                = wp_get_nav_menus();
		$items                = array();

		foreach ( $menus as $menu ) {
			if ( ! $menu instanceof \WP_Term ) {
				continue;
			}

			$assigned = array();
			foreach ( $location_assignments as $location => $menu_id ) {
				if ( (int) $menu_id !== (int) $menu->term_id ) {
					continue;
				}

				$assigned[] = array(
					'slug'  => sanitize_key( (string) $location ),
					'label' => sanitize_text_field( (string) ( $registered_locations[ $location ] ?? $location ) ),
				);
			}

			$menu_items = $this->wp_nav_menu_items( $menu );
			$item       = array(
				'id'                      => (int) $menu->term_id,
				'source_type'             => 'classic_menu',
				'name'                    => sanitize_text_field( $menu->name ),
				'slug'                    => sanitize_key( $menu->slug ),
				'status'                  => array() === $assigned ? 'registered' : 'assigned',
				'item_count'              => count( $menu_items ),
				'assigned_location_slugs' => array_values( array_map( static fn ( array $location ): string => (string) $location['slug'], $assigned ) ),
				'locations'               => $assigned,
				'edit_url'                => function_exists( 'admin_url' ) ? admin_url( 'nav-menus.php?action=edit&menu=' . (int) $menu->term_id ) : '',
			);

			if ( 'full' === $context ) {
				$item['location_labels'] = array_values( array_map( static fn ( array $location ): string => (string) $location['label'], $assigned ) );
			}

			$items[] = $item;
		}

		usort(
			$items,
			static fn ( array $a, array $b ): int => strcmp( (string) $a['name'], (string) $b['name'] )
		);

		return $items;
	}

	/**
	 * Return classic location inventory.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function classic_location_inventory(): array {
		if ( ! function_exists( 'get_registered_nav_menus' ) ) {
			return array();
		}

		$registered = get_registered_nav_menus();
		$assigned   = function_exists( 'get_nav_menu_locations' ) ? get_nav_menu_locations() : array();
		$items      = array();

		foreach ( $registered as $slug => $label ) {
			$menu_id = absint( $assigned[ $slug ] ?? 0 );
			$menu    = $menu_id > 0 ? $this->resolve_nav_menu( $menu_id ) : null;

			$items[] = array(
				'id'             => sanitize_key( (string) $slug ),
				'source_type'    => 'classic_location',
				'label'          => sanitize_text_field( (string) $label ),
				'status'         => $menu instanceof \WP_Term ? 'assigned' : 'unassigned',
				'has_assignment' => $menu instanceof \WP_Term,
				'menu_id'        => $menu instanceof \WP_Term ? (int) $menu->term_id : 0,
				'menu_name'      => $menu instanceof \WP_Term ? sanitize_text_field( $menu->name ) : '',
				'menu_slug'      => $menu instanceof \WP_Term ? sanitize_key( $menu->slug ) : '',
				'edit_url'       => function_exists( 'admin_url' ) ? admin_url( 'nav-menus.php?action=locations' ) : '',
			);
		}

		return $items;
	}

	/**
	 * Return readable wp_navigation inventory.
	 *
	 * @param string $context Compact or full.
	 * @return list<array<string, mixed>>
	 */
	private function wp_navigation_inventory( string $context ): array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'post_type_exists' ) || ! post_type_exists( 'wp_navigation' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => self::MAX_PER_PAGE,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$items = array();

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'read_post', (int) $post->ID ) ) {
				continue;
			}

			$parsed = $this->parse_navigation_post( $post, $context );
			$item   = array(
				'id'                 => (int) $post->ID,
				'source_type'        => 'wp_navigation',
				'title'              => sanitize_text_field( (string) $post->post_title ),
				'slug'               => sanitize_key( (string) $post->post_name ),
				'status'             => sanitize_key( (string) $post->post_status ),
				'item_count'         => (int) ( $parsed['summary']['link_item_count'] ?? 0 ),
				'navigation_blocks'  => (int) ( $parsed['summary']['navigation_block_count'] ?? 0 ),
				'mixed_blocks'       => ! empty( $parsed['summary']['mixed_blocks_detected'] ),
				'unsupported_blocks' => (int) ( $parsed['summary']['unsupported_block_count'] ?? 0 ),
				'modified_gmt'       => sanitize_text_field( (string) $post->post_modified_gmt ),
				'edit_url'           => function_exists( 'get_edit_post_link' ) ? get_edit_post_link( (int) $post->ID, 'display' ) : '',
			);

			if ( 'full' === $context ) {
				$item['structure'] = $parsed['summary'];
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Summarize active navigation mode.
	 *
	 * @param array<string, mixed>       $theme Theme context.
	 * @param list<array<string, mixed>> $classic_menus Classic menu inventory.
	 * @param list<array<string, mixed>> $classic_locations Classic location inventory.
	 * @param list<array<string, mixed>> $wp_navigation wp_navigation inventory.
	 * @return array<string, mixed>
	 */
	private function navigation_summary( array $theme, array $classic_menus, array $classic_locations, array $wp_navigation ): array {
		$is_block_theme   = ! empty( $theme['is_block_theme'] );
		$classic_support  = ! empty( $theme['supports_classic_menus'] );
		$has_locations    = array() !== $classic_locations;
		$has_classic      = array() !== $classic_menus;
		$has_navigation   = array() !== $wp_navigation;
		$theme_mode       = 'unsupported';
		$primary_surface  = 'unsupported';
		$unsupported      = array();
		$dynamic_possible = false;

		if ( $is_block_theme ) {
			$theme_mode      = 'block_theme';
			$primary_surface = $has_navigation ? 'wp_navigation' : 'unsupported';
			if ( ! $has_navigation ) {
				$unsupported[]    = 'Block theme detected, but no readable wp_navigation entities were found.';
				$dynamic_possible = true;
			}
		} elseif ( $classic_support || $has_locations || $has_classic ) {
			$theme_mode      = $has_navigation ? 'hybrid_theme' : 'classic_theme';
			$primary_surface = $has_navigation ? 'mixed' : 'classic_menu';
		} elseif ( $has_navigation ) {
			$theme_mode      = 'hybrid_theme';
			$primary_surface = 'wp_navigation';
			$unsupported[]   = 'Readable wp_navigation entities exist without classic menu support. Treat navigation as mixed or custom-managed.';
		} else {
			$unsupported[]    = 'No registered classic menu locations or readable wp_navigation entities were found. Dynamic or theme-generated navigation may need manual admin review.';
			$dynamic_possible = true;
		}

		return array(
			'theme_mode'                  => $theme_mode,
			'primary_surface'             => $primary_surface,
			'classic_menu_support'        => $classic_support,
			'registered_location_count'   => count( $classic_locations ),
			'classic_menu_count'          => count( $classic_menus ),
			'wp_navigation_count'         => count( $wp_navigation ),
			'dynamic_navigation_possible' => $dynamic_possible,
			'unsupported_reasons'         => $unsupported,
			'required_capability'         => 'edit_theme_options',
			'returns_write_operations'    => false,
		);
	}

	/**
	 * Return policy metadata for future writes.
	 *
	 * @return array<string, mixed>
	 */
	private function write_support_policy(): array {
		return array(
			'implemented'                          => false,
			'current_slice'                        => 'read_only_inventory',
			'classic_location_reassignment'        => 'explicit_only_with_confirmation_and_audit',
			'block_navigation_write_model'         => 'nested_mixed_block_capable',
			'preserve_unknown_custom_blocks_attrs' => true,
			'validate_parsed_block_structure'      => true,
			'raw_string_navigation_edits_allowed'  => false,
			'fail_closed_with_recovery_guidance'   => true,
		);
	}

	/**
	 * Resolve a list-items target from menu_id, navigation_id, or location.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function resolve_item_target( array $args ): array {
		$source_type   = sanitize_key( (string) ( $args['source_type'] ?? '' ) );
		$menu_id       = absint( $args['menu_id'] ?? ( 'classic_menu' === $source_type ? ( $args['id'] ?? 0 ) : 0 ) );
		$navigation_id = absint( $args['navigation_id'] ?? ( 'wp_navigation' === $source_type ? ( $args['id'] ?? 0 ) : 0 ) );
		$location      = sanitize_key( (string) ( $args['location'] ?? '' ) );

		if ( $navigation_id > 0 ) {
			$post = get_post( $navigation_id );
			if ( ! $post instanceof \WP_Post || 'wp_navigation' !== $post->post_type ) {
				return $this->error( 'not_found', 'Navigation entity not found.' );
			}

			if ( ! current_user_can( 'read_post', $navigation_id ) ) {
				return $this->error( 'forbidden', 'You do not have permission to inspect this navigation entity.' );
			}

			return array(
				'source_type' => 'wp_navigation',
				'post'        => $post,
			);
		}

		if ( '' !== $location ) {
			$registered = function_exists( 'get_registered_nav_menus' ) ? get_registered_nav_menus() : array();
			if ( ! array_key_exists( $location, $registered ) ) {
				return $this->error( 'unsupported', 'Classic navigation location not found.' );
			}

			$locations = function_exists( 'get_nav_menu_locations' ) ? get_nav_menu_locations() : array();
			$menu_id   = absint( $locations[ $location ] ?? 0 );
			if ( $menu_id <= 0 ) {
				return array(
					'items'        => array(),
					'total'        => 0,
					'page'         => $this->page( $args ),
					'per_page'     => $this->per_page( $args ),
					'has_more'     => false,
					'context'      => $this->collection_context( $args ),
					'read_only'    => true,
					'capabilities' => array(
						'can_read'            => true,
						'can_write'           => false,
						'required_capability' => 'edit_theme_options',
					),
					'summary'      => array(
						'source_type'                => 'classic_location',
						'location'                   => $location,
						'status'                     => 'unassigned',
						'returns_write_operations'   => false,
						'future_reassignment_policy' => 'explicit_only',
					),
				);
			}

			$menu = $this->resolve_nav_menu( $menu_id );
			if ( ! $menu instanceof \WP_Term ) {
				return $this->error( 'not_found', 'Assigned classic menu not found for this location.' );
			}

			return array(
				'source_type'           => 'classic_menu',
				'requested_source_type' => 'classic_location',
				'location'              => $location,
				'menu'                  => $menu,
			);
		}

		if ( $menu_id > 0 ) {
			$menu = $this->resolve_nav_menu( $menu_id );
			if ( ! $menu instanceof \WP_Term ) {
				return $this->error( 'not_found', 'Classic menu not found.' );
			}

			return array(
				'source_type'           => 'classic_menu',
				'requested_source_type' => 'classic_menu',
				'location'              => '',
				'menu'                  => $menu,
			);
		}

		return $this->error( 'missing_target', 'Provide menu_id, navigation_id, or location to inspect navigation items.' );
	}

	/**
	 * Return readable classic menu items.
	 *
	 * @param \WP_Term $menu Menu term.
	 * @return list<array<string, mixed>>
	 */
	private function classic_menu_items( \WP_Term $menu ): array {
		$items      = array();
		$menu_items = $this->wp_nav_menu_items( $menu );

		foreach ( $menu_items as $index => $menu_item ) {
			$item_id   = $this->object_int( $menu_item, 'ID' );
			$parent_id = $this->object_int( $menu_item, 'menu_item_parent' );
			$order     = $this->object_int( $menu_item, 'menu_order' );
			$items[]   = array(
				'id'          => $item_id,
				'item_id'     => $item_id,
				'source_type' => 'classic_menu',
				'menu_id'     => (int) $menu->term_id,
				'label'       => sanitize_text_field( $this->object_string( $menu_item, array( 'title', 'post_title' ) ) ),
				'url'         => $this->sanitize_url( $this->object_string( $menu_item, array( 'url' ) ) ),
				'parent_id'   => $parent_id,
				'order'       => $order > 0 ? $order : $index + 1,
				'status'      => sanitize_key(
					'' !== $this->object_string( $menu_item, array( 'post_status' ) )
						? $this->object_string( $menu_item, array( 'post_status' ) )
						: 'publish'
				),
				'type'        => sanitize_key( $this->object_string( $menu_item, array( 'type' ) ) ),
				'object'      => sanitize_key( $this->object_string( $menu_item, array( 'object' ) ) ),
				'object_id'   => $this->object_int( $menu_item, 'object_id' ),
				'target'      => sanitize_text_field( $this->object_string( $menu_item, array( 'target' ) ) ),
				'rel'         => sanitize_text_field( $this->object_string( $menu_item, array( 'xfn' ) ) ),
			);
		}

		return $items;
	}

	/**
	 * Parse a wp_navigation post into bounded link items and structure metadata.
	 *
	 * @param \WP_Post $post Navigation post.
	 * @param string   $context Compact or full.
	 * @return array{items:list<array<string,mixed>>,summary:array<string,mixed>}
	 */
	private function parse_navigation_post( \WP_Post $post, string $context ): array {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return array(
				'items'   => array(),
				'summary' => array(
					'source_type'             => 'wp_navigation',
					'navigation_id'           => (int) $post->ID,
					'navigation_block_count'  => 0,
					'link_item_count'         => 0,
					'mixed_blocks_detected'   => false,
					'unsupported_block_count' => 0,
					'parse_status'            => 'unavailable',
				),
			);
		}

		$blocks             = $this->parsed_blocks( (string) $post->post_content );
		$items              = array();
		$navigation_blocks  = 0;
		$unsupported_blocks = array();
		$unsupported_count  = 0;
		$mixed_blocks       = false;
		$order              = 0;

		$this->collect_navigation_blocks(
			$blocks,
			'',
			null,
			0,
			false,
			(int) $post->ID,
			$items,
			$navigation_blocks,
			$unsupported_blocks,
			$unsupported_count,
			$mixed_blocks,
			$order,
			'full' === $context
		);

		return array(
			'items'   => $items,
			'summary' => array(
				'source_type'              => 'wp_navigation',
				'navigation_id'            => (int) $post->ID,
				'navigation_block_count'   => $navigation_blocks,
				'link_item_count'          => count( $items ),
				'mixed_blocks_detected'    => $mixed_blocks,
				'unsupported_block_count'  => $unsupported_count,
				'unsupported_blocks'       => $unsupported_blocks,
				'unknown_blocks_preserved' => true,
				'parse_status'             => 0 === $navigation_blocks && '' !== trim( (string) $post->post_content ) ? 'no_navigation_blocks_found' : 'parsed',
			),
		);
	}

	/**
	 * Walk parsed blocks and collect navigation metadata.
	 *
	 * @param list<array<string, mixed>> $blocks Parsed blocks.
	 * @param string                     $path Current path prefix.
	 * @param string|null                $parent_item_id Parent navigation item ID.
	 * @param int                        $depth Current tree depth.
	 * @param bool                       $within_navigation Whether traversal is inside a navigation surface.
	 * @param int                        $navigation_id Navigation post ID.
	 * @param list<array<string, mixed>> $items Collected items.
	 * @param int                        $navigation_blocks Navigation block count.
	 * @param list<array<string, mixed>> $unsupported_blocks Bounded unsupported block metadata.
	 * @param int                        $unsupported_count Total unsupported block count.
	 * @param bool                       $mixed_blocks Whether mixed/unknown blocks were found.
	 * @param int                        $order Stable order counter.
	 * @param bool                       $include_attrs Whether to include bounded attrs.
	 */
	private function collect_navigation_blocks(
		array $blocks,
		string $path,
		?string $parent_item_id,
		int $depth,
		bool $within_navigation,
		int $navigation_id,
		array &$items,
		int &$navigation_blocks,
		array &$unsupported_blocks,
		int &$unsupported_count,
		bool &$mixed_blocks,
		int &$order,
		bool $include_attrs
	): void {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_path   = '' === $path ? (string) $index : $path . '.' . $index;
			$block_name   = sanitize_text_field( (string) ( $block['blockName'] ?? '' ) );
			$inner_blocks = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();

			if ( 'core/navigation' === $block_name ) {
				++$navigation_blocks;
				$this->collect_navigation_blocks( $inner_blocks, $block_path, $parent_item_id, $depth, true, $navigation_id, $items, $navigation_blocks, $unsupported_blocks, $unsupported_count, $mixed_blocks, $order, $include_attrs );
				continue;
			}

			if ( 'core/navigation-link' === $block_name ) {
				$item_id = 'block:' . $block_path;
				$attrs   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
				$item    = array(
					'id'               => $item_id,
					'item_id'          => null,
					'source_type'      => 'block_navigation',
					'navigation_id'    => $navigation_id,
					'label'            => sanitize_text_field( (string) ( $attrs['label'] ?? $attrs['title'] ?? '' ) ),
					'url'              => $this->sanitize_url( (string) ( $attrs['url'] ?? '' ) ),
					'parent_id'        => $parent_item_id,
					'order'            => ++$order,
					'status'           => 'parsed',
					'type'             => 'core/navigation-link',
					'kind'             => sanitize_key( (string) ( $attrs['kind'] ?? '' ) ),
					'object'           => sanitize_key( (string) ( $attrs['type'] ?? '' ) ),
					'object_id'        => absint( $attrs['id'] ?? 0 ),
					'depth'            => $depth,
					'opens_in_new_tab' => ! empty( $attrs['opensInNewTab'] ),
					'rel'              => sanitize_text_field( (string) ( $attrs['rel'] ?? '' ) ),
				);

				if ( $include_attrs ) {
					$item['attrs'] = array_intersect_key(
						$attrs,
						array(
							'label'         => true,
							'url'           => true,
							'kind'          => true,
							'type'          => true,
							'id'            => true,
							'opensInNewTab' => true,
							'rel'           => true,
						)
					);
				}

				$items[] = $item;
				$this->collect_navigation_blocks( $inner_blocks, $block_path, $item_id, $depth + 1, true, $navigation_id, $items, $navigation_blocks, $unsupported_blocks, $unsupported_count, $mixed_blocks, $order, $include_attrs );
				continue;
			}

			if ( $within_navigation && '' !== $block_name ) {
				$mixed_blocks = true;
				++$unsupported_count;
				if ( count( $unsupported_blocks ) < self::MAX_UNSUPPORTED_BLOCKS ) {
					$unsupported_blocks[] = array(
						'id'          => 'block:' . $block_path,
						'source_type' => 'unsupported',
						'block_name'  => $block_name,
						'parent_id'   => $parent_item_id,
						'depth'       => $depth,
					);
				}
			}

			if ( array() !== $inner_blocks ) {
				$this->collect_navigation_blocks( $inner_blocks, $block_path, $parent_item_id, $depth + 1, $within_navigation, $navigation_id, $items, $navigation_blocks, $unsupported_blocks, $unsupported_count, $mixed_blocks, $order, $include_attrs );
			}
		}
	}

	/**
	 * Return whether the current user can inspect navigation surfaces.
	 */
	private function can_read_navigation(): bool {
		return ! function_exists( 'current_user_can' ) || current_user_can( 'edit_theme_options' );
	}

	/**
	 * Parse serialized navigation markup into a normalized block list.
	 *
	 * @param string $content Serialized block content.
	 * @return list<array<string, mixed>>
	 */
	private function parsed_blocks( string $content ): array {
		$blocks = parse_blocks( $content );

		return array_values(
			array_filter(
				$blocks,
				static fn ( $block ): bool => is_array( $block )
			)
		);
	}

	/**
	 * Normalize the current result page.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function page( array $args ): int {
		return max( 1, (int) ( $args['page'] ?? 1 ) );
	}

	/**
	 * Normalize per-page limits.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function per_page( array $args ): int {
		return max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
	}

	/**
	 * Return a paginated slice of items.
	 *
	 * @param list<array<string, mixed>> $items Items to paginate.
	 * @param int                        $page Page number.
	 * @param int                        $per_page Page size.
	 * @return array{items:list<array<string,mixed>>,total:int,has_more:bool}
	 */
	private function slice_items( array $items, int $page, int $per_page ): array {
		$total  = count( $items );
		$offset = ( $page - 1 ) * $per_page;

		return array(
			'items'    => array_values( array_slice( $items, $offset, $per_page ) ),
			'total'    => $total,
			'has_more' => $offset + $per_page < $total,
		);
	}

	/**
	 * Filter a list of mapped items by a search term.
	 *
	 * @param list<array<string, mixed>> $items  Items to filter.
	 * @param string                     $search Search term.
	 * @param array<int, string>         $fields Searchable field names.
	 * @phpstan-param list<string> $fields
	 * @return list<array<string, mixed>>
	 */
	private function filter_items_by_search( array $items, string $search, array $fields ): array {
		if ( '' === $search ) {
			return $items;
		}

		return array_values(
			array_filter(
				$items,
				static function ( array $item ) use ( $search, $fields ): bool {
					foreach ( $fields as $field ) {
						$value = $item[ $field ] ?? '';
						if ( is_array( $value ) ) {
							$value = wp_json_encode( $value );
						}

						if ( is_scalar( $value ) && '' !== $value && str_contains( strtolower( (string) $value ), $search ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);
	}

	/**
	 * Normalize a search term.
	 *
	 * @param mixed $value Raw value.
	 */
	private function search_term( mixed $value ): string {
		return strtolower( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ) );
	}

	/**
	 * Resolve a nav menu term by ID or slug.
	 *
	 * @param int|string $menu Menu identifier.
	 */
	private function resolve_nav_menu( int|string $menu ): ?\WP_Term {
		if ( function_exists( 'wp_get_nav_menu_object' ) ) {
			$resolved = wp_get_nav_menu_object( $menu );
			if ( $resolved instanceof \WP_Term ) {
				return $resolved;
			}
		}

		return null;
	}

	/**
	 * Return menu items for one classic menu.
	 *
	 * @param \WP_Term $menu Menu term.
	 * @return list<object|array<string, mixed>>
	 */
	private function wp_nav_menu_items( \WP_Term $menu ): array {
		if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return array();
		}

		$items = wp_get_nav_menu_items(
			$menu,
			array(
				'post_status' => 'any',
			)
		);

		return is_array( $items ) ? array_values( $items ) : array();
	}

	/**
	 * Read a string property from an array or object.
	 *
	 * @param array<string, mixed>|object $value Source value.
	 * @param array<int, string>          $keys Candidate keys.
	 * @phpstan-param list<string> $keys
	 */
	private function object_string( array|object $value, array $keys ): string {
		foreach ( $keys as $key ) {
			$current = is_array( $value ) ? ( $value[ $key ] ?? null ) : ( $value->{$key} ?? null );
			if ( is_scalar( $current ) ) {
				return (string) $current;
			}
		}

		return '';
	}

	/**
	 * Read an integer property from an array or object.
	 *
	 * @param array<string, mixed>|object $value Source value.
	 * @param string                      $key Property name.
	 */
	private function object_int( array|object $value, string $key ): int {
		$current = is_array( $value ) ? ( $value[ $key ] ?? null ) : ( $value->{$key} ?? null );

		return absint( $current );
	}

	/**
	 * Normalize a URL field.
	 *
	 * @param string $url Raw URL.
	 */
	private function sanitize_url( string $url ): string {
		return function_exists( 'esc_url_raw' ) ? esc_url_raw( $url ) : sanitize_text_field( $url );
	}
}
