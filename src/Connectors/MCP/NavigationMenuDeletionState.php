<?php
/**
 * Bounded native state reader for classic menu deletion.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Reads raw menu relationships and the filtered inventory before deletion. */
final class NavigationMenuDeletionState {

	private const MAX_ITEMS          = 500;
	private const MAX_LOCATIONS      = 100;
	private const MAX_MEMBERSHIPS    = 101;
	private const MAX_SNAPSHOT_NODES = 2048;
	private const MAX_SNAPSHOT_BYTES = 1048576;

	private int $snapshot_nodes = 0;
	private int $snapshot_bytes = 0;

	/**
	 * Check the fixed native APIs used by this reader.
	 */
	public function available(): bool {
		$required = array( 'wp_get_nav_menu_object', 'wp_get_nav_menu_items', 'get_registered_nav_menus', 'get_nav_menu_locations', 'get_theme_mods', 'get_current_blog_id', 'get_stylesheet', 'get_template', 'wp_json_encode', 'wp_salt', 'sanitize_key', 'sanitize_text_field', 'get_post' );
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) || ! is_callable( $function ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Capture one exact bounded deletion state.
	 *
	 * @param int $menu_id Exact menu ID.
	 * @return array<string, mixed>
	 */
	public function read( int $menu_id ): array {
		$menu = wp_get_nav_menu_object( $menu_id );
		if ( ! $menu instanceof \WP_Term || 'nav_menu' !== $menu->taxonomy || (int) $menu->term_id !== $menu_id ) {
			return $this->error( 'invalid_menu', 'menu_id must identify that exact existing classic navigation menu.' );
		}

		$locations = $this->location_state();
		if ( isset( $locations['error'] ) ) {
			return $locations;
		}
		if ( $this->assigned_locations( $locations['raw_locations'], $menu_id ) ) {
			return $this->error( 'assigned_menu', 'This menu is assigned to one or more theme locations. Explicitly unassign every location first; deletion never changes location mappings.' );
		}

		$raw_item_ids = $this->raw_menu_item_ids( $menu_id );
		if ( isset( $raw_item_ids['error'] ) ) {
			return $raw_item_ids;
		}
		$items = $this->menu_items( $menu, $raw_item_ids['ids'] );
		if ( isset( $items['error'] ) ) {
			return $items;
		}

		$term           = $this->term_snapshot( $menu );
		$payload        = array(
			'site_id'              => $locations['site_id'],
			'theme'                => $locations['theme'],
			'registered_locations' => $locations['registered_locations'],
			'raw_locations'        => $locations['raw_locations'],
			'term'                 => $term,
			'items'                => $items['items'],
		);
		$expected_state = $this->state_hash( $payload );
		if ( false === $expected_state ) {
			return $this->error( 'invalid_state', 'The native classic-menu state could not be keyed safely.' );
		}

		return array(
			'menu_id'              => $menu_id,
			'term'                 => $term,
			'items'                => $items['items'],
			'site_id'              => $locations['site_id'],
			'theme'                => $locations['theme'],
			'registered_locations' => $locations['registered_locations'],
			'raw_locations'        => $locations['raw_locations'],
			'expected_state'       => $expected_state,
		);
	}

	/**
	 * Read the full raw/effective active-theme location identity.
	 *
	 * @return array<string, mixed>
	 */
	public function location_state(): array {
		$site_id    = get_current_blog_id();
		$theme      = array(
			'stylesheet' => get_stylesheet(),
			'template'   => get_template(),
		);
		$registered = $this->registered_locations( get_registered_nav_menus() );
		$mods       = get_theme_mods();
		$raw        = is_array( $mods ) && array_key_exists( 'nav_menu_locations', $mods ) ? $mods['nav_menu_locations'] : array();
		$raw_map    = $this->location_map( $raw );
		$effective  = $this->location_map( get_nav_menu_locations() );

		if ( ! is_int( $site_id ) || $site_id < 1 || ! is_string( $theme['stylesheet'] ) || '' === $theme['stylesheet'] || strlen( $theme['stylesheet'] ) > 128 || ! is_string( $theme['template'] ) || '' === $theme['template'] || strlen( $theme['template'] ) > 128 || false === $registered || false === $raw_map || false === $effective || $raw_map !== $effective ) {
			return $this->error( 'invalid_location_state', 'Native theme locations are invalid, filtered, or exceed the 100-entry bound.' );
		}

		return array(
			'site_id'              => $site_id,
			'theme'                => $theme,
			'registered_locations' => $registered,
			'raw_locations'        => $raw_map,
		);
	}

	/**
	 * Read bounded raw menu-item relationship IDs and reject non-menu posts.
	 *
	 * @param int $menu_id Exact menu ID.
	 * @return array<string, mixed>
	 */
	private function raw_menu_item_ids( int $menu_id ): array {
		$ids = $this->raw_relationship_ids( 'menu', $menu_id );
		if ( isset( $ids['error'] ) ) {
			return $ids;
		}
		if ( count( $ids['ids'] ) > self::MAX_ITEMS ) {
			return $this->error( 'overbound_menu', 'Menus with more than 500 raw native item memberships are unsupported; no write was attempted.' );
		}
		foreach ( $ids['ids'] as $item_id ) {
			$post = get_post( $item_id );
			if ( ! $post instanceof \WP_Post || $post->ID !== $item_id || 'nav_menu_item' !== $post->post_type ) {
				return $this->error( 'non_menu_item_membership', 'The raw menu relationship set contains a missing or non-menu item; no write was attempted.' );
			}
		}
		return $ids;
	}

	/**
	 * Reconcile filtered native inventory with raw membership and item menus.
	 *
	 * @param \WP_Term   $menu         Exact menu term.
	 * @param array<int> $raw_item_ids Raw relationship IDs.
	 * @phpstan-param list<int> $raw_item_ids
	 * @return array<string, mixed>
	 */
	private function menu_items( \WP_Term $menu, array $raw_item_ids ): array {
		$items = wp_get_nav_menu_items(
			$menu,
			array(
				'post_status'    => 'any',
				'nopaging'       => false,
				'posts_per_page' => self::MAX_ITEMS + 1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		if ( ! is_array( $items ) ) {
			return $this->error( 'invalid_menu_state', 'Native menu items could not be read safely.' );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			return $this->error( 'overbound_menu', 'Menus with more than 500 native items are unsupported; no write was attempted.' );
		}

		$snapshot = array();
		$seen     = array();
		foreach ( $items as $item ) {
			$item_id = $this->item_id( $item );
			if ( false === $item_id || isset( $seen[ $item_id ] ) ) {
				return $this->error( 'invalid_menu_state', 'Native menu item IDs could not be read exactly.' );
			}
			$normalized = $this->item_snapshot( $item );
			if ( false === $normalized ) {
				return $this->error( 'invalid_menu_state', 'A native menu item has an unsupported state shape.' );
			}
			$seen[ $item_id ] = true;
			$snapshot[]       = array(
				'item_id' => $item_id,
				'item'    => $normalized,
			);
		}

		$presented_ids = array_keys( $seen );
		sort( $presented_ids, SORT_NUMERIC );
		if ( $presented_ids !== $raw_item_ids ) {
			return $this->error( 'raw_membership_mismatch', 'The filtered menu inventory does not exactly match raw native memberships; no write was attempted.' );
		}
		foreach ( $raw_item_ids as $item_id ) {
			$memberships = $this->raw_item_memberships( $item_id );
			if ( isset( $memberships['error'] ) ) {
				return $memberships;
			}
			if ( 1 !== count( $memberships['ids'] ) || $memberships['ids'][0] !== $menu->term_id ) {
				return $this->error( 'shared_membership', 'Every captured menu item must belong exclusively to this menu; shared memberships are not deleted automatically.' );
			}
			foreach ( $snapshot as &$captured ) {
				if ( $captured['item_id'] === $item_id ) {
					$captured['memberships'] = $memberships['ids'];
					break;
				}
			}
			unset( $captured );
		}

		usort( $snapshot, static fn ( array $left, array $right ): int => $left['item_id'] <=> $right['item_id'] );
		return array( 'items' => $snapshot );
	}

	/**
	 * Read raw menu memberships for one menu item.
	 *
	 * @param int $item_id Menu-item post ID.
	 * @return array<string, mixed>
	 */
	private function raw_item_memberships( int $item_id ): array {
		$ids = $this->raw_relationship_ids( 'item', $item_id );
		if ( isset( $ids['error'] ) ) {
			return $ids;
		}
		if ( count( $ids['ids'] ) > self::MAX_MEMBERSHIPS - 1 ) {
			return $this->error( 'overbound_membership', 'A menu item has too many native menu memberships to verify safely.' );
		}
		return $ids;
	}

	/**
	 * Execute one bounded prepared raw relationship query.
	 *
	 * @param string $kind   Menu or item query.
	 * @param int    $object Menu ID or item ID.
	 * @return array<string, mixed>
	 */
	private function raw_relationship_ids( string $kind, int $object ): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_col' ) || ! isset( $wpdb->term_relationships, $wpdb->term_taxonomy ) ) {
			return $this->error( 'navigation_unavailable', 'The raw native menu relationship reader is unavailable.' );
		}
		$limit = 'menu' === $kind ? self::MAX_ITEMS + 1 : self::MAX_MEMBERSHIPS;
		if ( 'menu' === $kind ) {
			$query = $wpdb->prepare( "SELECT tr.object_id FROM {$wpdb->term_relationships} AS tr INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d AND tt.taxonomy = %s ORDER BY tr.object_id ASC LIMIT %d", $object, 'nav_menu', $limit );
		} else {
			$query = $wpdb->prepare( "SELECT tt.term_id FROM {$wpdb->term_relationships} AS tr INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tt.term_id ASC LIMIT %d", $object, 'nav_menu', $limit );
		}
		if ( ! is_string( $query ) || '' === $query ) {
			return $this->error( 'navigation_unavailable', 'The raw native menu relationship query could not be prepared safely.' );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query was prepared immediately above and is validated as a string.
		$values = $wpdb->get_col( $query );
		if ( property_exists( $wpdb, 'last_error' ) && is_string( $wpdb->last_error ) && '' !== trim( $wpdb->last_error ) ) {
			return $this->error( 'invalid_membership', 'Raw native menu relationships could not be read exactly.' );
		}
		if ( ! is_array( $values ) ) {
			return $this->error( 'invalid_membership', 'Raw native menu relationships could not be read exactly.' );
		}
		$ids = array();
		foreach ( $values as $value ) {
			if ( ( ! is_int( $value ) && ! is_string( $value ) ) || ( is_string( $value ) && 1 !== preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) {
				return $this->error( 'invalid_membership', 'Raw native menu relationships have an ambiguous ID shape.' );
			}
			$id = is_int( $value ) ? $value : filter_var( $value, FILTER_VALIDATE_INT );
			if ( ! is_int( $id ) || $id < 1 || isset( $ids[ $id ] ) ) {
				return $this->error( 'invalid_membership', 'Raw native menu relationships contain an invalid or duplicate ID.' );
			}
			$ids[ $id ] = true;
		}
		$ids = array_keys( $ids );
		sort( $ids, SORT_NUMERIC );
		return array( 'ids' => $ids );
	}

	/**
	 * Capture stable term fields without arbitrary term metadata.
	 *
	 * @param \WP_Term $menu Exact menu term.
	 * @return array<string, mixed>
	 */
	private function term_snapshot( \WP_Term $menu ): array {
		$term = array(
			'term_id'     => (int) $menu->term_id,
			'taxonomy'    => (string) $menu->taxonomy,
			'name'        => (string) $menu->name,
			'slug'        => (string) $menu->slug,
			'description' => (string) $menu->description,
			'parent'      => (int) $menu->parent,
			'count'       => (int) $menu->count,
		);
		foreach ( array( 'term_group', 'term_taxonomy_id' ) as $field ) {
			if ( property_exists( $menu, $field ) && is_scalar( $menu->{$field} ) ) {
				$term[ $field ] = is_numeric( $menu->{$field} ) ? (int) $menu->{$field} : (string) $menu->{$field};
			}
		}
		ksort( $term, SORT_STRING );
		return $term;
	}

	/**
	 * Resolve a native menu-item identifier strictly.
	 *
	 * @param mixed $item Native item object or array.
	 * @return int|false
	 */
	private function item_id( mixed $item ): int|false {
		$ids = array();
		foreach ( array( 'ID', 'db_id', 'id' ) as $field ) {
			$value = is_array( $item ) ? ( $item[ $field ] ?? null ) : ( is_object( $item ) && isset( $item->{$field} ) ? $item->{$field} : null );
			if ( null !== $value ) {
				if ( ! is_int( $value ) || $value < 1 ) {
					return false;
				}
				$ids[] = $value;
			}
		}
		return array() !== $ids && 1 === count( array_unique( $ids ) ) ? $ids[0] : false;
	}

	/**
	 * Normalize all bounded native item fields for the opaque HMAC.
	 *
	 * @param mixed $item Native item object or array.
	 * @return array<string|int, mixed>|false
	 */
	private function item_snapshot( mixed $item ): array|false {
		$this->snapshot_nodes = 0;
		$this->snapshot_bytes = 0;
		$normalized           = $this->normalize_value( $item, 0 );
		return $normalized['valid'] && is_array( $normalized['value'] ) ? $normalized['value'] : false;
	}

	/**
	 * Normalize a bounded scalar/array/object value.
	 *
	 * @param mixed $value Candidate value.
	 * @param int   $depth Recursion depth.
	 * @return array{valid:bool,value:mixed}
	 */
	private function normalize_value( mixed $value, int $depth ): array {
		++$this->snapshot_nodes;
		if ( $this->snapshot_nodes > self::MAX_SNAPSHOT_NODES ) {
			return array(
				'valid' => false,
				'value' => null,
			);
		}
		if ( is_float( $value ) && ! is_finite( $value ) ) {
			return array(
				'valid' => false,
				'value' => null,
			);
		}
		if ( is_null( $value ) || is_scalar( $value ) ) {
			if ( is_string( $value ) ) {
				$this->snapshot_bytes += strlen( $value );
			}
			return array(
				'valid' => ! is_string( $value ) || ( strlen( $value ) <= 65535 && $this->snapshot_bytes <= self::MAX_SNAPSHOT_BYTES ),
				'value' => $value,
			);
		}
		if ( $depth > 5 || ( ! is_array( $value ) && ! is_object( $value ) ) || ( is_object( $value ) && ( 0 < $depth || ! ( $value instanceof \WP_Post ) ) ) ) {
			return array(
				'valid' => false,
				'value' => null,
			);
		}
		$values = is_array( $value ) ? $value : get_object_vars( $value );
		if ( count( $values ) > 256 ) {
			return array(
				'valid' => false,
				'value' => null,
			);
		}
		$normalized = array();
		foreach ( $values as $key => $child ) {
			$result = $this->normalize_value( $child, $depth + 1 );
			if ( ! $result['valid'] ) {
				return $result;
			}
			$normalized[ $key ] = $result['value'];
		}
		ksort( $normalized, SORT_STRING );
		return array(
			'valid' => true,
			'value' => $normalized,
		);
	}

	/**
	 * Validate registered location labels.
	 *
	 * @param mixed $value Native registered map.
	 * @return array<string, string>|false
	 */
	private function registered_locations( mixed $value ): array|false {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_LOCATIONS ) {
			return false;
		}
		$locations = array();
		foreach ( $value as $slug => $label ) {
			if ( ! is_string( $slug ) || '' === $slug || strlen( $slug ) > 100 || sanitize_key( $slug ) !== $slug || ! is_string( $label ) || strlen( $label ) > 255 ) {
				return false;
			}
			$locations[ $slug ] = $label;
		}
		ksort( $locations, SORT_STRING );
		return $locations;
	}

	/**
	 * Validate the complete raw/effective location map.
	 *
	 * @param mixed $value Native location map.
	 * @return array<string, int|string>|false
	 */
	private function location_map( mixed $value ): array|false {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_LOCATIONS ) {
			return false;
		}
		$map = array();
		foreach ( $value as $slug => $menu_id ) {
			if ( ! is_string( $slug ) || '' === $slug || strlen( $slug ) > 100 || sanitize_key( $slug ) !== $slug || ! $this->valid_location_id( $menu_id ) ) {
				return false;
			}
			$map[ $slug ] = $menu_id;
		}
		ksort( $map, SORT_STRING );
		return $map;
	}

	/**
	 * Accept only canonical nonnegative native menu IDs.
	 *
	 * @param mixed $value Native map value.
	 */
	private function valid_location_id( mixed $value ): bool {
		if ( is_int( $value ) ) {
			return $value >= 0;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return false;
		}
		$integer = filter_var( $value, FILTER_VALIDATE_INT );
		return is_int( $integer ) && $integer >= 0 && (string) $integer === $value;
	}

	/**
	 * Determine whether a menu appears in any raw location assignment.
	 *
	 * @param array<string, int|string> $locations Complete raw map.
	 * @param int                       $menu_id Target menu ID.
	 */
	private function assigned_locations( array $locations, int $menu_id ): bool {
		foreach ( $locations as $mapped_id ) {
			if ( ( is_int( $mapped_id ) && $mapped_id === $menu_id ) || ( is_string( $mapped_id ) && (int) $mapped_id === $menu_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the keyed state token.
	 *
	 * @param array<string, mixed> $payload Exact bounded state.
	 * @return string|false
	 */
	private function state_hash( array $payload ): string|false {
		$encoded = wp_json_encode( $payload );
		$salt    = wp_salt( 'auth' );
		if ( ! is_string( $encoded ) || ! is_string( $salt ) || '' === $salt ) {
			return false;
		}
		return hash_hmac( 'sha256', $encoded, $salt );
	}

	/**
	 * Return a structured state error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Safe message.
	 * @return array<string, string>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'error'   => $code,
			'message' => $message,
		);
	}
}
