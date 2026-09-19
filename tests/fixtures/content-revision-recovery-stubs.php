<?php
/**
 * Isolated WordPress runtime stubs for bounded revision recovery tests.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- WordPress runtime stubs.
if ( ! function_exists( 'wp_check_post_lock' ) ) {
	/**
	 * Return a test lock owner, if configured.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false
	 */
	function wp_check_post_lock( int $post_id ): int|false {
		$callback = $GLOBALS['content_revision_recovery_test_lock_callback'] ?? null;
		return is_callable( $callback ) ? $callback( $post_id ) : false;
	}
}

if ( ! function_exists( 'wp_revisions_enabled' ) ) {
	/**
	 * Report whether test revisions are enabled.
	 *
	 * @param WP_Post $post Post being checked.
	 */
	function wp_revisions_enabled( WP_Post $post ): bool {
		unset( $post );
		return (bool) ( $GLOBALS['content_revision_recovery_test_revisions_enabled'] ?? true );
	}
}

if ( ! function_exists( 'wp_save_post_revision' ) ) {
	/**
	 * Save the exact pre-restore content as a native-looking revision.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false
	 */
	function wp_save_post_revision( int $post_id ): int|false {
		$GLOBALS['content_revision_recovery_test_backup_calls'] = ( $GLOBALS['content_revision_recovery_test_backup_calls'] ?? 0 ) + 1;
		$callback = $GLOBALS['content_revision_recovery_test_backup_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			return $callback( $post_id );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$revision_id = (int) ( $GLOBALS['content_revision_recovery_test_next_revision_id'] ?? 301 );
		$revision    = new WP_Post(
			array(
				'ID'                => $revision_id,
				'post_type'         => 'revision',
				'post_status'       => 'inherit',
				'post_parent'       => $post_id,
				'post_name'         => 'revision-' . $post_id . '-' . $revision_id,
				'post_title'        => $post->post_title,
				'post_content'      => $post->post_content,
				'post_excerpt'      => $post->post_excerpt,
				'post_modified_gmt' => $post->post_modified_gmt,
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][ $revision_id ]                      = $revision;
		$GLOBALS['aculect_ai_companion_test_post_revisions'][ $post_id ][ $revision_id ] = $revision;

		return $revision_id;
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	/**
	 * Return a stable test secret for expected-state HMACs.
	 *
	 * @param string $scheme Salt scheme.
	 */
	function wp_salt( string $scheme = 'auth' ): string {
		unset( $scheme );
		return 'content-revision-recovery-test-secret';
	}
}

if ( ! function_exists( 'wp_slash' ) ) {
	/**
	 * Apply WordPress-style slashes to test update payloads.
	 *
	 * @param array|string $value Value to slash.
	 * @return array|string
	 */
	function wp_slash( array|string $value ): array|string {
		$GLOBALS['content_revision_recovery_test_slash_calls'] = ( $GLOBALS['content_revision_recovery_test_slash_calls'] ?? 0 ) + 1;
		$slash = static function ( array|string $item ) use ( &$slash ): array|string {
			if ( ! is_array( $item ) ) {
				return addslashes( $item );
			}

			foreach ( $item as $key => $child ) {
				$item[ $key ] = is_array( $child ) || is_string( $child ) ? $slash( $child ) : $child;
			}

			return $item;
		};

		return $slash( $value );
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
