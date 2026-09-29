<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

/** Test the upload cap without a live WordPress runtime. */
function wp_max_upload_size(): int {
	return (int) ( $GLOBALS['aculect_image_upload_limit'] ?? 8 * 1024 * 1024 );
}

/**
 * Recognize the one temporary upload fixture.
 *
 * @param string $path Candidate upload path.
 */
function is_uploaded_file( string $path ): bool {
	return ( $GLOBALS['aculect_image_upload_tmp'] ?? null ) === $path;
}

/**
 * Simulate the site's upload MIME policy.
 *
 * @param string $path PHP upload path.
 * @param string $filename Original upload name.
 * @return array{ext: string|false, type: string|false, proper_filename: string|false}
 */
function wp_check_filetype_and_ext( string $path, string $filename ): array {
	unset( $path );
	return array(
		'ext'             => pathinfo( $filename, PATHINFO_EXTENSION ),
		'type'            => $GLOBALS['aculect_image_upload_policy_mime'] ?? 'image/png',
		'proper_filename' => false,
	);
}

/**
 * Simulate successful or failed image decoding.
 *
 * @param string $path PHP upload path.
 */
function wp_get_image_editor( string $path ): object {
	unset( $path );
	return $GLOBALS['aculect_image_upload_editor'] ?? new \stdClass();
}

/**
 * Record the WordPress media API handoff.
 *
 * @param string $field File input name.
 * @param int    $post_id Parent post ID.
 */
function media_handle_upload( string $field, int $post_id ): int|\WP_Error {
	$GLOBALS['aculect_image_upload_media_calls'][] = array( $field, $post_id );
	return $GLOBALS['aculect_image_upload_media_result'] ?? 314;
}
