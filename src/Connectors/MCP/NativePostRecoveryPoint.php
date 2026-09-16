<?php
/**
 * Native recovery points for explicitly authorized editor-record writes.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Callers own object authorization and optimistic state checks. */
final class NativePostRecoveryPoint {

	/**
	 * Require native revisions with enough retention to keep a before/after pair.
	 *
	 * @param \WP_Post $post Authorized target.
	 */
	public function available( \WP_Post $post ): bool {
		if ( ! function_exists( 'wp_revisions_enabled' ) || ! function_exists( 'wp_revisions_to_keep' ) || ! function_exists( 'wp_save_post_revision' ) ) {
			return false;
		}
		$keep = wp_revisions_to_keep( $post );
		return wp_revisions_enabled( $post ) && ( -1 === $keep || $keep >= 2 );
	}

	/**
	 * Treat an unavailable edit-lock check as locked, not permission to write.
	 *
	 * @param int $post_id Authorized target ID.
	 */
	public function locked( int $post_id ): bool {
		if ( ! function_exists( 'wp_check_post_lock' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/post.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		return ! function_exists( 'wp_check_post_lock' ) || false !== wp_check_post_lock( $post_id );
	}

	/**
	 * Save or identify the exact current content revision, without restoring meta.
	 *
	 * @param \WP_Post $post Authorized pre-write target.
	 * @return int|\WP_Error Verified revision ID or fixed error.
	 */
	public function capture( \WP_Post $post ): int|\WP_Error {
		if ( ! $this->available( $post ) || $this->locked( $post->ID ) ) {
			return new \WP_Error( 'recovery_unavailable', 'Native revisions and an unlocked editor are required.' );
		}
		$id       = wp_save_post_revision( $post->ID );
		$revision = is_int( $id ) && $id > 0 ? get_post( $id ) : null;
		if ( ! $revision instanceof \WP_Post ) {
			$latest   = wp_get_post_revisions(
				$post->ID,
				array(
					'posts_per_page' => 1,
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'check_enabled'  => false,
				)
			);
			$revision = reset( $latest );
		}
		if ( ! $revision instanceof \WP_Post || ! $this->matches( $revision->ID, $post ) ) {
			return new \WP_Error( 'recovery_unavailable', 'The pre-write content revision could not be verified.' );
		}
		return $revision->ID;
	}

	/**
	 * Verify a retained recovery revision using fresh native reads.
	 *
	 * @param int      $revision_id Recovery revision ID.
	 * @param \WP_Post $before Original authorized post.
	 */
	public function matches( int $revision_id, \WP_Post $before ): bool {
		$revision = get_post( $revision_id );
		return $revision instanceof \WP_Post
			&& 'revision' === $revision->post_type
			&& $before->ID === (int) $revision->post_parent
			&& false === wp_is_post_autosave( $revision )
			&& $this->fields( $before ) === $this->fields( $revision );
	}

	/**
	 * Preserve precisely the fields stored by normal content revisions.
	 *
	 * @param \WP_Post $post Native record.
	 * @return array<string,string>
	 */
	public function fields( \WP_Post $post ): array {
		return array(
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
		);
	}
}
