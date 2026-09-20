<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Preconditions and exception boundaries shared by content write handlers.
 */
final class ContentWriteSafety {

	/**
	 * Check an optional optimistic-concurrency token before a content write.
	 *
	 * @param \WP_Post             $post Existing post.
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public static function expected_modified_error( \WP_Post $post, array $data ): array {
		if ( ! array_key_exists( 'expected_modified_gmt', $data ) ) {
			return array();
		}

		$expected = sanitize_text_field( (string) $data['expected_modified_gmt'] );
		$current  = sanitize_text_field( (string) $post->post_modified_gmt );
		if ( '' === $expected || hash_equals( $current, $expected ) ) {
			return array();
		}

		return array(
			'error'             => 'conflict',
			'message'           => 'Content changed after it was read. Refresh the item and retry with its current modified_gmt value.',
			'expected_modified' => $expected,
			'current_modified'  => $current,
		);
	}

	/**
	 * Update a post while converting unexpected WordPress exceptions to a safe
	 * error object for the caller.
	 *
	 * @param array<string, mixed> $update Post update payload.
	 * @return int|\WP_Error
	 */
	public static function update_post( array $update ): int|\WP_Error {
		try {
			return wp_update_post( $update, true );
		} catch ( \Throwable ) {
			return new \WP_Error( 'write_failed', 'Content could not be updated.' );
		}
	}
}
