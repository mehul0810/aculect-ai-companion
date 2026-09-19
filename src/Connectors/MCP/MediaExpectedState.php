<?php
/**
 * Backward-compatible optimistic attachment state for existing media writers.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Hashes metadata privately; never exposes paths, metadata or file contents. */
final class MediaExpectedState {

	/**
	 * Add an optional precondition without breaking legacy client schemas.
	 *
	 * @param array<string,mixed> $properties Existing write fields.
	 * @param array<int,string>   $required Existing required fields.
	 * @return array<string,mixed>
	 */
	public static function schema( array $properties, array $required ): array {
		$schema                                 = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
		$schema['properties']['expected_state'] = array(
			'minLength'   => 64,
			'maxLength'   => 64,
			'type'        => 'string',
			'pattern'     => '^[a-f0-9]{64}$',
			'description' => 'Recommended: send expected_state from media.get_item to reject stale attachment metadata. Omission retains legacy behavior. This does not hash file bytes or make writes atomic.',
		);
		return $schema;
	}

	/**
	 * Derive a bounded site-bound digest of editable fields and attachment metadata.
	 *
	 * @param \WP_Post $post Authorized current attachment.
	 */
	public function token( \WP_Post $post ): ?string {
		try {
			$payload = array( get_current_blog_id(), $post->ID, $post->post_type, $post->post_status, $post->post_title, $post->post_content, $post->post_excerpt, $post->post_name, $post->post_parent, $post->post_author, $post->post_modified_gmt );
			foreach ( array( '_wp_attachment_image_alt', '_wp_attached_file', '_wp_attachment_metadata' ) as $key ) {
				$payload[] = get_post_meta( $post->ID, $key, true );
			}
			$remaining = 2048;
			$bytes     = 1048576;
			if ( ! $this->bounded( $payload, 0, $remaining, $bytes ) ) {
				return null;
			}
			$json = json_encode( $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Reject invalid UTF-8 instead of silently normalizing state before hashing.
			$salt = wp_salt( 'auth' );
			return is_string( $json ) && strlen( $json ) <= 1048576 && is_string( $salt ) && '' !== $salt ? hash_hmac( 'sha256', $json, $salt ) : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Reject malformed or stale supplied state; omitted state stays compatible.
	 *
	 * @param \WP_Post            $post Authorized current attachment.
	 * @param array<string,mixed> $args Existing write arguments.
	 * @return array<string,string>|null
	 */
	public function error( \WP_Post $post, array $args ): ?array {
		if ( ! array_key_exists( 'expected_state', $args ) ) {
			return null;
		}
		$expected = $args['expected_state'];
		$fresh    = get_post( $post->ID );
		$current  = $fresh instanceof \WP_Post && 'attachment' === $fresh->post_type ? $this->token( $fresh ) : null;
		if ( is_string( $expected ) && is_string( $current ) && hash_equals( $current, $expected ) ) {
			return null;
		}
		return array(
			'error'   => 'stale_media_state',
			'message' => 'Read this attachment again and send its fresh expected_state before changing it.',
		);
	}

	/**
	 * Reject objects and excessive metadata shapes before JSON serialization.
	 *
	 * @param mixed $value Native field or metadata value.
	 * @param int   $depth Current nesting depth.
	 * @param int   $remaining Remaining bounded nodes.
	 * @param int   $bytes Remaining byte budget before encoding.
	 */
	private function bounded( mixed $value, int $depth, int &$remaining, int &$bytes ): bool {
		$bytes -= is_string( $value ) ? strlen( $value ) : 32;
		if ( --$remaining < 0 || $depth > 10 || $bytes < 0 ) {
			return false;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$bytes -= is_string( $key ) ? strlen( $key ) : 32;
				if ( ! $this->bounded( $child, $depth + 1, $remaining, $bytes ) ) {
					return false;
				}
			}
			return true;
		}
		return null === $value || is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) || ( is_string( $value ) && strlen( $value ) <= 1048576 );
	}
}
