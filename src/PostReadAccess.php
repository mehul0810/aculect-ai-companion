<?php

declare(strict_types=1);

namespace Aculect\AICompanion;

/**
 * Native authority for projections that include raw post content or excerpts.
 */
final class PostReadAccess {

	/**
	 * Require read access and edit-context authority for password-protected bodies.
	 *
	 * MCP has no post-password input. A public post's read_post capability alone
	 * therefore cannot authorize its protected body, excerpt, chunks or locators.
	 *
	 * @param int $post_id WordPress post ID.
	 */
	public static function allows( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post instanceof \WP_Post
			&& current_user_can( 'read_post', $post_id )
			&& ( empty( $post->post_password ) || current_user_can( 'edit_post', $post_id ) );
	}
}
