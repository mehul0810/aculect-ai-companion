<?php
/**
 * Targeted edits of existing database navigation links and submenus.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Reuses the editor record's authority, recovery and persistence boundary. */
final class NavigationBlockItemAbilities extends AbstractAbilityService {

	/**
	 * Read one concrete item with a token for the entire navigation record.
	 *
	 * @param array<string,mixed> $args Navigation ID and structural block path.
	 * @return array<string,mixed>
	 */
	public function read_item( array $args ): array {
		try {
			$state = $this->load( $args );
			if ( isset( $state['error'] ) ) {
				return $state;
			}
			$tree = ( new NavigationBlockTree() )->load( $state['post']->post_content, $args['block_path'] );
			if ( isset( $tree['error'] ) ) {
				return $tree;
			}
			return array(
				'navigation_id'  => $state['post']->ID,
				'source_type'    => 'wp_navigation',
				'block_path'     => $args['block_path'],
				'item'           => $this->present( $tree['item'], $args['block_path'] ),
				'expected_state' => $state['expected_state'],
				'warnings'       => $this->warnings(),
			);
		} catch ( \Throwable ) {
			return $this->error( 'navigation_unavailable', 'The navigation item could not be inspected safely.' );
		}
	}

	/**
	 * Derive content from closed item changes; never accept replacement markup.
	 *
	 * @param array<string,mixed> $args Target, freshness token and item changes.
	 * @return array<string,mixed>
	 */
	public function update_item( array $args ): array {
		try {
			$state = $this->load( $args );
			if ( isset( $state['error'] ) ) {
				return $state;
			}
			if ( ! is_string( $args['expected_state'] ?? null ) || ! hash_equals( $state['expected_state'], $args['expected_state'] ) ) {
				return $this->error( 'stale_editor_state', 'Navigation changed; read this item and preview again before editing.' );
			}
			if ( ! is_array( $args['changes'] ?? null ) ) {
				return $this->error( 'invalid_changes', 'Provide a nonempty object of supported item changes.' );
			}
			$change = ( new NavigationBlockTree() )->apply( $state['post']->post_content, $args['block_path'], $args['changes'] );
			if ( isset( $change['error'] ) ) {
				return $change;
			}
			$result = ( new EditorRecordAbilities() )->write(
				'update_record',
				array(
					'post_id'        => $state['post']->ID,
					'expected_state' => $args['expected_state'],
					'changes'        => array( 'content' => $change['content'] ),
					'dry_run'        => $this->is_dry_run( $args ),
				)
			);
			if ( isset( $result['error'] ) ) {
				return $result;
			}
			return $this->result( $args, $change, $result );
		} catch ( \Throwable ) {
			return $this->error( 'navigation_unavailable', 'The navigation item change could not be prepared safely.' );
		}
	}

	/**
	 * Check native features and object authority without relying on theme labels.
	 *
	 * @param array<string,mixed> $args Target arguments.
	 * @return array<string,mixed>
	 */
	private function load( array $args ): array {
		if ( ! is_int( $args['navigation_id'] ?? null ) || $args['navigation_id'] < 1 || ! is_string( $args['block_path'] ?? null ) ) {
			return $this->error( 'invalid_target', 'Provide a positive navigation_id and a block_path from navigation.list_items.' );
		}
		$state = ( new EditorRecordState() )->load( array( 'post_id' => $args['navigation_id'] ) );
		if ( isset( $state['error'] ) ) {
			return $state;
		}
		if ( 'wp_navigation' !== $state['post']->post_type || ! post_type_exists( 'wp_navigation' ) || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $this->error( 'unsupported_navigation', 'An existing native wp_navigation database record and block APIs are required.' );
		}
		if ( ! ( new EditorContentPolicy() )->valid( $state['post']->post_content, true ) ) {
			return $this->error( 'unsupported_navigation', 'This record contains unsupported or unsafe content. Review it in the native editor; no blocks will be removed or converted.' );
		}
		return $state;
	}

	/**
	 * Project only editable fields, retaining structural identity for previews.
	 *
	 * @param array<string,mixed> $block Selected parsed block.
	 * @param string              $path Structural path in the current record.
	 * @return array<string,mixed>
	 */
	private function present( array $block, string $path ): array {
		$attrs   = $block['attrs'];
		$indices = explode( '.', $path );
		$order   = (int) array_pop( $indices ) + 1;
		return array(
			'block_path'  => $path,
			'type'        => $block['blockName'],
			'label'       => $attrs['label'] ?? '',
			'url'         => $attrs['url'] ?? '',
			'target'      => ! empty( $attrs['opensInNewTab'] ) ? '_blank' : '_self',
			'rel'         => $attrs['rel'] ?? '',
			'parent_path' => implode( '.', $indices ),
			'order'       => $order,
		);
	}

	/**
	 * Keep proposed and completed states distinct and expose item-level diffs.
	 *
	 * @param array<string,mixed> $args Original arguments.
	 * @param array<string,mixed> $change Derived item transformation.
	 * @param array<string,mixed> $saved Editor preview or verified write result.
	 * @return array<string,mixed>
	 */
	private function result( array $args, array $change, array $saved ): array {
		$before  = $this->present( $change['before'], $args['block_path'] );
		$after   = $this->present( $change['after'], $change['new_block_path'] );
		$changes = array();
		foreach ( $before as $key => $value ) {
			$diff = $this->change( $key, $value, $after[ $key ] );
			if ( null !== $diff ) {
				$changes[] = $diff;
			}
		}
		$identity = array(
			'navigation_id' => $args['navigation_id'],
			'source_type'   => 'wp_navigation',
			'block_path'    => $args['block_path'],
		);
		if ( $this->is_dry_run( $args ) ) {
			return $this->preview_response( 'navigation.update_block_item', $args, $identity + array( 'item' => $before ), $changes, $this->warnings() );
		}
		return $saved + $identity + array(
			'new_block_path' => $change['new_block_path'],
			'item'           => $after,
			'changes'        => $changes,
		);
	}

	/**
	 * Explain record-wide and immediate-publication constraints.
	 *
	 * @return list<string>
	 */
	private function warnings(): array {
		return array(
			'Published navigation changes affect the live site immediately, including every template that references this record.',
			'Paths and order use all parsed sibling blocks. Read again after any edit; no menu assignment, template or theme file is changed.',
			'Dynamic page lists, inline template navigation, custom blocks and menu/item creation are not edited by this tool.',
		);
	}
}
