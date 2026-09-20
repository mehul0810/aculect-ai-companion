<?php
/**
 * Isolated native classic-menu deletion fixture.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- WordPress API test doubles.
if ( ! class_exists( 'NavigationMenuDeletionWpdbStub' ) ) {
	/** Minimal prepared-query boundary for raw term-relationship assertions. */
	final class NavigationMenuDeletionWpdbStub {
		public string $term_relationships = 'wp_term_relationships';
		public string $term_taxonomy      = 'wp_term_taxonomy';
		public string $last_error         = '';

		/**
		 * Preserve the query kind and bound arguments for get_col().
		 *
		 * @param string $query SQL template.
		 * @param mixed  ...$args Prepared values.
		 */
		public function prepare( string $query, mixed ...$args ): string {
			$kind = str_contains( $query, 'tt.term_id = %d' ) ? 'menu' : 'item';
			return $kind . ':' . implode( ',', array_map( 'strval', $args ) );
		}

		/**
		 * Return bounded fixture relationships for one prepared query.
		 *
		 * @param string $query Prepared query marker.
		 * @return list<int>
		 */
		public function get_col( string $query ): array {
			$parts     = explode( ':', $query, 2 );
			$args      = isset( $parts[1] ) ? explode( ',', $parts[1] ) : array();
			$object    = isset( $args[0] ) ? (int) $args[0] : 0;
			$relations = $GLOBALS['aculect_ai_companion_test_nav_menu_relationships'] ?? array();
			if ( 'menu' === ( $parts[0] ?? '' ) ) {
				return array_values( array_map( 'intval', (array) ( $relations[ $object ] ?? array() ) ) );
			}

			$ids = array();
			foreach ( is_array( $relations ) ? $relations : array() as $menu_id => $item_ids ) {
				if ( in_array( $object, array_map( 'intval', (array) $item_ids ), true ) ) {
					$ids[] = (int) $menu_id;
				}
			}
			sort( $ids, SORT_NUMERIC );
			return $ids;
		}
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Return a deterministic fixture salt.
	 *
	 * @param string $scheme Salt scheme.
	 */
	function wp_salt( string $scheme = 'auth' ): string {
		unset( $scheme );
		return 'navigation-menu-deletion-test-secret';
	}
}

if ( ! function_exists( 'get_theme_mods' ) ) {
	/**
	 * Return the raw fixture theme-mod map.
	 *
	 * @return array<string, mixed>|false
	 */
	function get_theme_mods(): array|false {
		$mods = $GLOBALS['aculect_ai_companion_test_theme_mods'] ?? array();
		return is_array( $mods ) ? $mods : false;
	}
}

if ( ! function_exists( 'get_objects_in_term' ) ) {
	/**
	 * Return the exact raw object IDs consumed by native menu deletion.
	 *
	 * @param int|string|array<int|string> $term_ids Term identifier(s).
	 * @param string|array<int, string>    $taxonomies Taxonomy name(s).
	 * @param array<string, mixed>         $args Optional query arguments.
	 * @return list<int>|WP_Error
	 */
	function get_objects_in_term( int|string|array $term_ids, string|array $taxonomies, array $args = array() ): array|WP_Error {
		unset( $args );
		$callback = $GLOBALS['aculect_ai_companion_test_nav_menu_objects_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$result = $callback( $term_ids, $taxonomies );
			return $result instanceof WP_Error || is_array( $result ) ? $result : new WP_Error( 'invalid_fixture_objects' );
		}
		if ( ! in_array( 'nav_menu', array_map( 'strval', (array) $taxonomies ), true ) ) {
			return array();
		}
		$menu_id    = is_array( $term_ids ) ? (int) reset( $term_ids ) : (int) $term_ids;
		$relations  = $GLOBALS['aculect_ai_companion_test_nav_menu_relationships'] ?? array();
		$object_ids = $relations[ $menu_id ] ?? array();
		return array_values( array_map( 'intval', is_array( $object_ids ) ? $object_ids : array() ) );
	}
}

if ( ! function_exists( 'wp_delete_nav_menu' ) ) {
	/**
	 * Apply a deterministic native menu deletion to the fixture store.
	 *
	 * @param int|string|WP_Term $menu Menu identifier.
	 */
	function wp_delete_nav_menu( int|string|WP_Term $menu ): bool {
		$term = wp_get_nav_menu_object( $menu );
		$id   = $term instanceof WP_Term ? (int) $term->term_id : 0;
		$GLOBALS['aculect_ai_companion_test_nav_menu_delete_calls'][] = $id;
		$callback = $GLOBALS['aculect_ai_companion_test_nav_menu_delete_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$result = $callback( $id );
			if ( is_bool( $result ) ) {
				return $result;
			}
		}
		if ( ! $term instanceof WP_Term || 'nav_menu' !== $term->taxonomy || $id < 1 ) {
			return false;
		}

		$items = wp_get_nav_menu_items( $term, array( 'post_status' => 'any' ) );
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$item_id = is_array( $item ) ? ( $item['ID'] ?? $item['db_id'] ?? null ) : ( $item->ID ?? $item->db_id ?? null );
			if ( is_int( $item_id ) ) {
				unset( $GLOBALS['aculect_ai_companion_test_posts'][ $item_id ] );
				unset( $GLOBALS['aculect_ai_companion_test_object_terms'][ $item_id ] );
			}
		}
		unset( $GLOBALS['aculect_ai_companion_test_nav_menu_items'][ $id ] );
		$menus = array();
		foreach ( (array) ( $GLOBALS['aculect_ai_companion_test_nav_menus'] ?? array() ) as $candidate ) {
			$candidate_id = $candidate instanceof WP_Term ? $candidate->term_id : ( is_array( $candidate ) ? ( $candidate['term_id'] ?? 0 ) : 0 );
			if ( (int) $candidate_id !== $id ) {
				$menus[] = $candidate;
			}
		}
		$GLOBALS['aculect_ai_companion_test_nav_menus'] = $menus;
		unset( $GLOBALS['aculect_ai_companion_test_nav_menu_relationships'][ $id ] );
		return true;
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
