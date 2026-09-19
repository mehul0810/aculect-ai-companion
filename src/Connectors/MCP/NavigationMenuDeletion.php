<?php
/**
 * Confirmed deletion of one classic navigation menu through native WordPress.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Deletes only an unassigned, bounded classic menu after an exact recheck. */
final class NavigationMenuDeletion extends AbstractAbilityService {

	/**
	 * Inspect one existing classic menu without exposing item or metadata values.
	 *
	 * @param array<string, mixed> $args Target arguments.
	 * @return array<string, mixed>
	 */
	public function inspect_menu_deletion( array $args ): array {
		try {
			$state = $this->state( $args );
			if ( isset( $state['error'] ) ) {
				return $state;
			}

			$count = count( $state['items'] );
			return array(
				'menu_id'        => $state['menu_id'],
				'menu_name'      => sanitize_text_field( (string) $state['term']['name'] ),
				'item_count'     => $count,
				'warning'        => $this->deletion_warning( (string) $state['term']['name'], (int) $state['menu_id'], $count ),
				'expected_state' => $state['expected_state'],
			);
		} catch ( \Throwable ) {
			return $this->error( 'navigation_unavailable', 'The classic menu could not be inspected safely.' );
		}
	}

	/**
	 * Preview or permanently delete one exact unassigned classic menu.
	 *
	 * @param array<string, mixed> $args Menu ID, expected state and safety controls.
	 * @return array<string, mixed>
	 */
	public function delete_menu( array $args ): array {
		$expected = $args['expected_state'] ?? null;
		if ( ! is_string( $expected ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) {
			return $this->error( 'invalid_state', 'Provide the expected_state returned by navigation.inspect_menu_deletion.' );
		}

		try {
			$state = $this->state( $args );
			if ( isset( $state['error'] ) ) {
				return $state;
			}
			if ( ! hash_equals( (string) $state['expected_state'], $expected ) ) {
				return $this->error( 'stale_state', 'The classic menu changed; inspect and preview it again before deletion.' );
			}

			if ( $this->is_dry_run( $args ) ) {
				return $this->preview( $args, $state );
			}

			$fresh = $this->state( $args );
			if ( isset( $fresh['error'] ) ) {
				return $this->fresh_state_error( $fresh );
			}
			if ( ! hash_equals( (string) $fresh['expected_state'], $expected ) ) {
				return $this->error( 'stale_state', 'The classic menu changed before deletion; inspect it again.' );
			}

			return $this->persist( $fresh );
		} catch ( \Throwable ) {
			return $this->uncertain();
		}
	}

	/**
	 * Validate capability/input and delegate bounded state capture.
	 *
	 * @param array<string, mixed> $args Target arguments.
	 * @return array<string, mixed>
	 */
	private function state( array $args ): array {
		if ( ! $this->runtime_available() ) {
			return $this->error( 'navigation_unavailable', 'Required native classic-menu APIs are unavailable.' );
		}
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error( 'forbidden', 'Classic menu deletion requires edit_theme_options.' );
		}
		$menu_id = $args['menu_id'] ?? null;
		if ( ! is_int( $menu_id ) || $menu_id < 1 ) {
			return $this->error( 'invalid_target', 'menu_id must be one positive integer.' );
		}
		return ( new NavigationMenuDeletionState() )->read( $menu_id );
	}

	/**
	 * Persist through wp_delete_nav_menu and verify every postcondition.
	 *
	 * @param array<string, mixed> $state Fresh validated state.
	 * @return array<string, mixed>
	 */
	private function persist( array $state ): array {
		$native_ids = $this->native_delete_item_ids( (int) $state['menu_id'] );
		if ( false === $native_ids ) {
			return $this->error( 'native_membership_unverified', 'WordPress could not expose the exact native item set that deletion would consume; no write was attempted.' );
		}
		$captured_ids = array();
		foreach ( $state['items'] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['item_id'] ) || ! is_int( $item['item_id'] ) ) {
				return $this->error( 'stale_state', 'The captured native menu item set is no longer verifiable; inspect the menu again.' );
			}
			$captured_ids[] = $item['item_id'];
		}
		sort( $captured_ids, SORT_NUMERIC );
		if ( $native_ids !== $captured_ids ) {
			return $this->error( 'stale_state', 'The native deletion item set changed before deletion; inspect the menu again.' );
		}

		try {
			$deleted = wp_delete_nav_menu( (int) $state['menu_id'] );
			$proof   = $this->postcondition( $state );
		} catch ( \Throwable ) {
			return $this->uncertain();
		}
		if ( true !== $deleted || ! $proof['menu_absent'] || ! $proof['items_absent'] || ! $proof['location_state_preserved'] ) {
			return $this->uncertain();
		}

		$count = count( $state['items'] );
		return array(
			'success'       => true,
			'changed'       => true,
			'menu_id'       => $state['menu_id'],
			'item_count'    => $count,
			'postcondition' => $proof,
			'warnings'      => $this->warnings( (string) $state['term']['name'], (int) $state['menu_id'], $count ),
		);
	}

	/**
	 * Re-read the exact object IDs consumed by wp_delete_nav_menu().
	 *
	 * @param int $menu_id Exact menu ID.
	 * @return list<int>|false
	 */
	private function native_delete_item_ids( int $menu_id ): array|false {
		/**
		 * Native raw membership query result.
		 *
		 * @var array<int, int|string>|\WP_Error $objects
		 */
		$objects = get_objects_in_term( $menu_id, 'nav_menu' );
		if ( is_wp_error( $objects ) || ! is_array( $objects ) || count( $objects ) > 500 ) {
			return false;
		}

		$ids = array();
		foreach ( $objects as $object_id ) {
			if ( is_int( $object_id ) ) {
				$id = $object_id;
			} elseif ( is_string( $object_id ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $object_id ) ) {
				$id = filter_var( $object_id, FILTER_VALIDATE_INT );
			} else {
				return false;
			}
			if ( ! is_int( $id ) || $id < 1 || isset( $ids[ $id ] ) ) {
				return false;
			}
			$ids[ $id ] = true;
		}

		$ids = array_keys( $ids );
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	/**
	 * Verify target absence, all captured items, and full location identity.
	 *
	 * @param array<string, mixed> $state Fresh pre-delete state.
	 * @return array<string, bool>
	 */
	private function postcondition( array $state ): array {
		/**
		 * Native resolver may return an absent false/null or a WP_Error.
		 *
		 * @var mixed $menu_after
		 */
		$menu_after   = wp_get_nav_menu_object( (int) $state['menu_id'] );
		$menu_absent  = null === $menu_after || false === $menu_after;
		$items_absent = true;
		foreach ( $state['items'] as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['item_id'] ) || null !== get_post( (int) $item['item_id'] ) ) {
				$items_absent = false;
				break;
			}
		}

		$locations                = ( new NavigationMenuDeletionState() )->location_state();
		$location_state_preserved = ! isset( $locations['error'] ) && $locations['site_id'] === $state['site_id'] && $locations['theme'] === $state['theme'] && $locations['registered_locations'] === $state['registered_locations'] && $locations['raw_locations'] === $state['raw_locations'];
		return array(
			'menu_absent'              => $menu_absent,
			'items_absent'             => $items_absent,
			'location_state_preserved' => $location_state_preserved,
			'location_map_preserved'   => $location_state_preserved,
		);
	}

	/**
	 * Return a mandatory destructive preview.
	 *
	 * @param array<string, mixed> $args  Original arguments.
	 * @param array<string, mixed> $state Validated state.
	 * @return array<string, mixed>
	 */
	private function preview( array $args, array $state ): array {
		$count = count( $state['items'] );
		return $this->preview_response(
			'navigation.delete_menu',
			$args,
			array(
				'menu_id'    => $state['menu_id'],
				'menu_name'  => sanitize_text_field( (string) $state['term']['name'] ),
				'item_count' => $count,
			),
			array( $this->change( 'menu', 'present', 'permanently deleted' ), $this->change( 'item_count', $count, 0 ) ),
			$this->warnings( (string) $state['term']['name'], (int) $state['menu_id'], $count )
		);
	}

	/**
	 * Map a fresh-state failure to a safe stale response where appropriate.
	 *
	 * @param array<string, mixed> $state Fresh state result.
	 * @return array<string, mixed>
	 */
	private function fresh_state_error( array $state ): array {
		if ( in_array( $state['error'] ?? '', array( 'forbidden', 'navigation_unavailable' ), true ) ) {
			return $state;
		}
		return $this->error( 'stale_state', 'The classic menu or its location state changed before deletion; inspect it again.' );
	}

	/**
	 * Build the exact permanent-deletion warnings.
	 *
	 * @param string $name    Menu name.
	 * @param int    $menu_id Menu ID.
	 * @param int    $count   Native item count.
	 * @return list<string>
	 */
	private function warnings( string $name, int $menu_id, int $count ): array {
		return array(
			$this->deletion_warning( $name, $menu_id, $count ),
			'Back up the menu or plan manual recreation before confirming; WordPress provides no trash or automatic compensation for this deletion.',
			'Only the active theme classic nav_menu_locations map was checked for assignments. Widgets, block-navigation references, inactive themes and third-party references were not exhaustively scanned and may break after permanent deletion.',
		);
	}

	/**
	 * Format the inspect/preview permanent-deletion warning.
	 *
	 * @param string $name    Menu name.
	 * @param int    $menu_id Menu ID.
	 * @param int    $count   Native item count.
	 */
	private function deletion_warning( string $name, int $menu_id, int $count ): string {
		return sprintf( 'Menu "%s" (ID %d) and exactly %d native menu item(s) will be permanently removed with no trash.', sanitize_text_field( $name ), $menu_id, $count );
	}

	/**
	 * Load fixed native APIs required for the execution boundary.
	 */
	private function runtime_available(): bool {
		$required = array( 'current_user_can', 'wp_delete_nav_menu', 'get_objects_in_term', 'is_wp_error', 'get_post' );
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) || ! is_callable( $function ) ) {
				return false;
			}
		}
		return ( new NavigationMenuDeletionState() )->available();
	}

	/**
	 * Return a terminal result whenever native deletion or proof is uncertain.
	 *
	 * @return array<string, mixed>
	 */
	private function uncertain(): array {
		return array(
			'error'         => 'partial_write',
			'status'        => 'partial_write',
			'partial_write' => true,
			'terminal'      => true,
			'message'       => 'The native menu deletion or postcondition could not be verified. Inspect the menu, captured items and location map manually; do not retry automatically.',
		);
	}
}
