<?php
/**
 * Native classic navigation state and persistence boundary.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Reads bounded raw state without presentation filters or menu-order rewriting.
 */
final class NavigationItemState {

	/**
	 * Resolve a complete bounded menu snapshot.
	 *
	 * @param int $menu_id Menu ID.
	 * @return array<int, array<string, mixed>>|null
	 */
	public function read( int $menu_id ): ?array {
		$menu = get_term( $menu_id, 'nav_menu' );
		if ( ! $menu instanceof \WP_Term || 'nav_menu' !== $menu->taxonomy ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => 'nav_menu_item',
				'post_status'    => 'any',
				'posts_per_page' => 501, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Hard menu-size ceiling plus one overflow sentinel.
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Bounded native menu membership lookup.
					array(
			'taxonomy' => 'nav_menu',
			'field'    => 'term_id',
			'terms'    => $menu_id,
				),
				),
			)
		);
		if ( count( $posts ) > 500 ) {
			return null;
		}
		$items = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || 'nav_menu_item' !== $post->post_type ) {
				return null;
			}
			$item = $this->item( $post );
			if ( ! $this->roundtrippable( $item ) ) {
				return null;
			}
			$items[ $post->ID ] = $item;
		}
		ksort( $items );
		return $items;
	}

	/**
	 * Preserve the complete native update payload, including non-editable fields.
	 *
	 * @param \WP_Post $post Raw menu-item post.
	 * @return array<string, mixed>
	 */
	private function item( \WP_Post $post ): array {
		$item = array(
			'label'       => $post->post_title,
			'order'       => (int) $post->menu_order,
			'status'      => $post->post_status,
			'description' => $post->post_content,
			'attr_title'  => $post->post_excerpt,
			'date'        => $post->post_date,
			'date_gmt'    => $post->post_date_gmt,
		);
		foreach ( array(
			'url'       => 'url',
			'parent_id' => 'menu_item_parent',
			'target'    => 'target',
			'rel'       => 'xfn',
			'classes'   => 'classes',
			'type'      => 'type',
			'object'    => 'object',
			'object_id' => 'object_id',
		) as $field => $meta ) {
			$item[ $field ] = get_post_meta( $post->ID, '_menu_item_' . $meta, true );
		}
		$item['parent_id'] = (int) $item['parent_id'];
		$item['object_id'] = (int) $item['object_id'];
		return $item;
	}

	/**
	 * Reject stored shapes which the native writer would silently normalize.
	 *
	 * @param array<string, mixed> $item Raw native item.
	 */
	private function roundtrippable( array $item ): bool {
		if ( ! in_array( $item['status'], array( 'publish', 'draft' ), true ) || $item['order'] < 1 || ! is_array( $item['classes'] ) || ! array_is_list( $item['classes'] ) ) {
			return false;
		}
		foreach ( $item['classes'] as $class ) {
			if ( ! is_string( $class ) || sanitize_html_class( $class ) !== $class ) {
				return false;
			}
		}
		if ( explode( ' ', implode( ' ', $item['classes'] ) ) !== $item['classes'] ) {
			return false;
		}
		foreach ( array( 'url', 'target', 'rel', 'type', 'object' ) as $key ) {
			if ( ! is_string( $item[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Hash all preserved fields and the parent graph to detect stale plans.
	 *
	 * @param int                              $menu_id Menu ID.
	 * @param array<int, array<string, mixed>> $items Menu snapshot.
	 */
	public function hash( int $menu_id, array $items ): string {
		return hash( 'sha256', serialize( array( $menu_id, $items ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Internal deterministic digest, never deserialized.
	}

	/**
	 * Save exactly one existing native item.
	 *
	 * @param int                  $menu_id Menu ID.
	 * @param int                  $item_id Existing item ID.
	 * @param array<string, mixed> $item Complete state.
	 */
	public function save( int $menu_id, int $item_id, array $item ): bool {
		$payload = array();
		foreach ( array(
			'label'       => 'title',
			'order'       => 'position',
			'parent_id'   => 'parent-id',
			'object_id'   => 'object-id',
			'rel'         => 'xfn',
			'url'         => 'url',
			'target'      => 'target',
			'classes'     => 'classes',
			'type'        => 'type',
			'object'      => 'object',
			'status'      => 'status',
			'description' => 'description',
			'attr_title'  => 'attr-title',
			'date'        => 'post-date',
			'date_gmt'    => 'post-date-gmt',
		) as $field => $native ) {
			$payload[ 'menu-item-' . $native ] = $item[ $field ];
		}
		$payload['menu-item-classes'] = implode( ' ', $item['classes'] );
		$result                       = wp_update_nav_menu_item( $menu_id, $item_id, wp_slash( $payload ) );
		return $result === $item_id;
	}
}
