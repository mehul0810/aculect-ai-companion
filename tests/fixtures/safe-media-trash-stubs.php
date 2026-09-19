<?php
/**
 * Isolated native attachment trash outcomes.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Simulate native trash without touching a filesystem.
 *
 * @param int $id Synthetic attachment ID.
 * @return \WP_Post|false
 * @throws \RuntimeException For a synthetic hook failure.
 */
function wp_trash_post( int $id ): \WP_Post|false {
	++$GLOBALS['safe_media_trash_calls'];
	if ( 'throw' === $GLOBALS['safe_media_trash_mode'] ) {
		throw new \RuntimeException( 'Synthetic hook failure.' );
	}
	if ( 'false' === $GLOBALS['safe_media_trash_mode'] ) {
		return false;
	}
	$post = get_post( $id );
	if ( 'unchanged' !== $GLOBALS['safe_media_trash_mode'] ) {
		$post->post_status = 'trash';
	}
	return $post;
}
