<?php
/**
 * Isolated WordPress lifecycle stubs for trashed-content recovery tests.
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
		$GLOBALS['trashed_content_recovery_test_lock_calls'] = ( $GLOBALS['trashed_content_recovery_test_lock_calls'] ?? 0 ) + 1;
		$callback = $GLOBALS['trashed_content_recovery_test_lock_callback'] ?? null;
		return is_callable( $callback ) ? $callback( $post_id ) : false;
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
		return 'trashed-content-recovery-test-secret';
	}
}

if ( ! function_exists( 'metadata_exists' ) ) {
	/**
	 * Check whether a test post-meta key has a stored row.
	 *
	 * @param string $meta_type Object type.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Meta key.
	 */
	function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {
		unset( $meta_type );
		$meta = $GLOBALS['aculect_ai_companion_test_post_meta'][ $object_id ] ?? array();
		return is_array( $meta ) && array_key_exists( $meta_key, $meta );
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	/**
	 * Remove one native test post-meta row.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	function delete_post_meta( int $post_id, string $meta_key ): bool {
		if ( ! metadata_exists( 'post', $post_id, $meta_key ) ) {
			return false;
		}
		unset( $GLOBALS['aculect_ai_companion_test_post_meta'][ $post_id ][ $meta_key ] );
		return true;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Remove only the exact test callback and priority.
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  $callback  Callback identity.
	 * @param int    $priority  Hook priority.
	 * @return bool
	 */
	function remove_filter( string $hook_name, mixed $callback, int $priority = 10 ): bool {
		$filters = $GLOBALS['aculect_ai_companion_test_hooks']['filters'] ?? array();
		foreach ( $filters as $index => $filter ) {
			if ( ( $filter['hook_name'] ?? null ) === $hook_name && ( $filter['priority'] ?? null ) === $priority && ( $filter['callback'] ?? null ) === $callback ) {
				unset( $filters[ $index ] );
				$GLOBALS['aculect_ai_companion_test_hooks']['filters'] = array_values( $filters );
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'wp_untrash_post' ) ) {
	/**
	 * Model core untrash status filtering, native metadata cleanup and hooks.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Post|false|null
	 */
	function wp_untrash_post( int $post_id = 0 ): WP_Post|false|null {
		$GLOBALS['trashed_content_recovery_test_untrash_calls'] = ( $GLOBALS['trashed_content_recovery_test_untrash_calls'] ?? 0 ) + 1;
		$override = $GLOBALS['trashed_content_recovery_test_untrash_override'] ?? null;
		if ( is_callable( $override ) ) {
			return $override( $post_id );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'trash' !== $post->post_status ) {
			return false;
		}

		$status  = get_post_meta( $post_id, '_wp_trash_meta_status', true );
		$status  = is_string( $status ) && '' !== $status ? $status : 'draft';
		$filters = $GLOBALS['aculect_ai_companion_test_hooks']['filters'] ?? array();
		usort(
			$filters,
			static fn ( array $left, array $right ): int => (int) ( $left['priority'] ?? 10 ) <=> (int) ( $right['priority'] ?? 10 )
		);
		foreach ( $filters as $filter ) {
			if ( 'wp_untrash_post_status' !== ( $filter['hook_name'] ?? null ) || ! is_callable( $filter['callback'] ?? null ) ) {
				continue;
			}
			$args   = array_slice( array( $status, $post_id ), 0, max( 0, (int) ( $filter['accepted_args'] ?? 1 ) ) );
			$status = call_user_func_array( $filter['callback'], $args );
		}
		if ( ! is_string( $status ) || '' === $status ) {
			return false;
		}

		$updated = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status,
			)
		);
		if ( ! is_int( $updated ) || $updated !== $post_id ) {
			return false;
		}

		delete_post_meta( $post_id, '_wp_trash_meta_status' );
		delete_post_meta( $post_id, '_wp_trash_meta_time' );
		$GLOBALS['trashed_content_recovery_test_comments_restored'][] = $post_id;

		$after_callback = $GLOBALS['trashed_content_recovery_test_untrash_after_callback'] ?? null;
		if ( is_callable( $after_callback ) ) {
			$after_callback( $post_id );
		}

		return get_post( $post_id );
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
