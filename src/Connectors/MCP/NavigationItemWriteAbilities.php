<?php
/**
 * Guarded updates to existing classic menu items.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Applies bounded, optimistic navigation edits with verified compensation.
 */
final class NavigationItemWriteAbilities extends AbstractAbilityService {

	/**
	 * Read an editable item and its menu-wide concurrency token.
	 *
	 * @param array<string, mixed> $args Target arguments.
	 * @return array<string, mixed>
	 */
	public function read_item( array $args ): array {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return $this->error( 'forbidden', 'Navigation editing requires edit_theme_options.' );
		}
		foreach ( array( 'menu_id', 'item_id' ) as $key ) {
			if ( ! isset( $args[ $key ] ) || ! is_int( $args[ $key ] ) || $args[ $key ] < 1 ) {
				return $this->error( 'invalid_target', 'menu_id and item_id must be positive integers.' );
			}
		}
		try {
			$state = new NavigationItemState();
			$items = $state->read( $args['menu_id'] );
			if ( null === $items || ! isset( $items[ $args['item_id'] ] ) ) {
				return $this->error( 'unsupported_target', 'An existing classic menu item in a menu of at most 500 items is required.' );
			}
			return array(
				'menu_id'        => $args['menu_id'],
				'item_id'        => $args['item_id'],
				'item'           => $items[ $args['item_id'] ],
				'expected_state' => $state->hash( $args['menu_id'], $items ),
			);
		} catch ( \Throwable ) {
			return $this->error( 'read_failed', 'Navigation state could not be read.' );
		}
	}

	/**
	 * Preview or update one existing classic menu item.
	 *
	 * @param array<string, mixed> $args Update arguments.
	 * @return array<string, mixed>
	 */
	public function update_item( array $args ): array {
		$before = $this->read_item( $args );
		if ( isset( $before['error'] ) ) {
			return $before;
		}
		if ( ! isset( $args['expected_state'] ) || ! is_string( $args['expected_state'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $args['expected_state'] ) ) {
			return $this->error( 'invalid_state', 'Provide the expected_state from navigation read_item.' );
		}
		if ( ! hash_equals( $before['expected_state'], $args['expected_state'] ) ) {
			return $this->error( 'stale_state', 'Navigation changed; read and preview again before editing.' );
		}
		$changes = $this->validate_changes( $args['changes'] ?? null, $before['item'] );
		if ( isset( $changes['error'] ) ) {
			return $changes;
		}
		$after = array_replace( $before['item'], $changes );
		try {
			$state = new NavigationItemState();
			$items = $state->read( $args['menu_id'] );
			if ( null === $items || ! hash_equals( $args['expected_state'], $state->hash( $args['menu_id'], $items ) ) ) {
				return $this->error( 'stale_state', 'Navigation changed before the update.' );
			}
			if ( ! $this->valid_parent( $items, $args['item_id'], $after['parent_id'] ) ) {
				return $this->error( 'invalid_parent', 'Parent must be in this menu without cycles or chains over 100 items.' );
			}
			$diff = array();
			foreach ( $changes as $field => $value ) {
				$diff[] = $this->change( $field, $before['item'][ $field ], $value );
			}
			if ( $this->is_dry_run( $args ) ) {
				return $this->preview_response( 'navigation.update_item', $args, $before, $diff );
			}
			return $this->persist( $state, $args['menu_id'], $args['item_id'], $items, $after );
		} catch ( \Throwable ) {
			return $this->error( 'read_failed', 'Navigation could not be prepared; no write was attempted.' );
		}
	}

	/**
	 * Enforce the closed field contract before any mutation.
	 *
	 * @param mixed                $changes Requested fields.
	 * @param array<string, mixed> $before Existing item.
	 * @return array<string, mixed>
	 */
	private function validate_changes( mixed $changes, array $before ): array {
		if ( ! is_array( $changes ) || array_is_list( $changes ) || array_diff( array_keys( $changes ), array( 'label', 'url', 'parent_id', 'order', 'target', 'rel' ) ) ) {
			return $this->error( 'invalid_changes', 'Provide a nonempty object containing only label, url, parent_id, order, target, or rel.' );
		}
		foreach ( $changes as $field => $value ) {
			if ( in_array( $field, array( 'parent_id', 'order' ), true ) ) {
				if ( ! is_int( $value ) || $value < ( 'order' === $field ? 1 : 0 ) || ( 'order' === $field && $value > 500 ) ) {
					return $this->error( 'invalid_changes', 'parent_id must be nonnegative; order must be 1 through 500.' );
				}
				continue;
			}
			if ( ! is_string( $value ) || strlen( $value ) > ( 'url' === $field ? 2048 : 255 ) ) {
				return $this->error( 'invalid_changes', 'Text fields must be bounded strings.' );
			}
			if ( 'url' === $field ) {
				if ( 'custom' !== $before['type'] || ! $this->safe_url( $value ) ) {
					return $this->error( 'invalid_url', 'Only custom links accept safe HTTP, HTTPS, root-relative, or fragment URLs.' );
				}
			} elseif ( 'target' === $field ) {
				if ( ! in_array( $value, array( '', '_self', '_blank' ), true ) ) {
					return $this->error( 'invalid_target', 'Target must be empty, _self, or _blank.' );
				}
			} else {
				$changes[ $field ] = sanitize_text_field( $value );
				if ( ( 'label' === $field && '' === $changes[ $field ] ) || ( 'rel' === $field && ! preg_match( '/^[a-zA-Z0-9 _-]*$/D', $changes[ $field ] ) ) ) {
					return $this->error( 'invalid_changes', 'Label must be nonempty and rel must contain relationship tokens.' );
				}
			}
		}
		return $changes;
	}

	/**
	 * Reject ambiguous schemes, protocol-relative URLs and control characters.
	 *
	 * @param string $url Requested URL.
	 */
	private function safe_url( string $url ): bool {
		if ( '' === $url || preg_match( '/[\x00-\x20\x7f\\\\<>]/', $url ) || preg_match( '/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url ) ) {
			return false;
		}
		if ( str_starts_with( $url, '#' ) || ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) ) {
			return esc_url_raw( $url, array( 'http', 'https' ) ) === $url;
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && esc_url_raw( $url, array( 'http', 'https' ) ) === $url;
	}

	/**
	 * Validate ancestry using only the bounded menu snapshot.
	 *
	 * @param array<int, array<string, mixed>> $items Menu items.
	 * @param int                              $item_id Updated item.
	 * @param int                              $parent Parent item.
	 */
	private function valid_parent( array $items, int $item_id, int $parent ): bool {
		$seen = array( $item_id => true );
		for ( $depth = 0; $parent > 0 && $depth < 100; ++$depth ) {
			if ( isset( $seen[ $parent ] ) || ! isset( $items[ $parent ] ) ) {
				return false;
			}
			$seen[ $parent ] = true;
			$parent          = $items[ $parent ]['parent_id'];
		}
		return 0 === $parent;
	}

	/**
	 * Verify success, or compensate once and verify restoration without retries.
	 *
	 * @param NavigationItemState              $state Native boundary.
	 * @param int                              $menu_id Menu ID.
	 * @param int                              $item_id Item ID.
	 * @param array<int, array<string, mixed>> $before Original snapshot.
	 * @param array<string, mixed>             $after Intended item.
	 * @return array<string, mixed>
	 */
	private function persist( NavigationItemState $state, int $menu_id, int $item_id, array $before, array $after ): array {
		if ( $before[ $item_id ] === $after ) {
			return array(
				'success'        => true,
				'unchanged'      => true,
				'menu_id'        => $menu_id,
				'item_id'        => $item_id,
				'item'           => $after,
				'expected_state' => $state->hash( $menu_id, $before ),
			);
		}
		$expected             = $before;
		$expected[ $item_id ] = $after;
		try {
			$saved  = $state->save( $menu_id, $item_id, $after );
			$actual = $state->read( $menu_id );
			if ( $saved && $actual === $expected ) {
				return array(
					'success'        => true,
					'menu_id'        => $menu_id,
					'item_id'        => $item_id,
					'item'           => $after,
					'expected_state' => $state->hash( $menu_id, $actual ),
				);
			}
			if ( $actual === $before ) {
				return $this->error( 'write_failed', 'Navigation update failed without a persisted change.' );
			}
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Native hooks can throw after partial persistence; compensation follows.
		}
		try {
			$state->save( $menu_id, $item_id, $before[ $item_id ] );
			if ( $state->read( $menu_id ) === $before ) {
				return $this->error( 'write_failed', 'Navigation update failed and original state was restored.' );
			}
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Unverifiable restoration is the terminal partial_write below.
		}
		return array_merge(
			$this->error( 'partial_write', 'Navigation state could not be restored. Inspect it manually; do not retry this operation.' ),
			array(
				'status'   => 'partial_write',
				'terminal' => true,
			)
		);
	}
}
