<?php
/**
 * Bounded Site Editor deletion snapshots and native trash verification.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Captures only bounded database state and accepts only native trash changes.
 */
final class EditorRecordDeletionSnapshot {

	/**
	 * Post fields that native trash must preserve.
	 *
	 * Modified timestamps are intentionally excluded because WordPress may
	 * update them while changing the post status through its native lifecycle.
	 *
	 * @var list<string>
	 */
	private const PRESERVED_POST_FIELDS = array(
		'ID',
		'post_type',
		'post_author',
		'post_parent',
		'post_title',
		'post_content',
		'post_excerpt',
		'post_mime_type',
		'post_date',
		'post_date_gmt',
		'post_password',
		'comment_status',
		'ping_status',
		'to_ping',
		'pinged',
		'post_content_filtered',
		'guid',
		'menu_order',
	);

	/**
	 * Native keys that WordPress adds while moving a post to trash.
	 *
	 * @var list<string>
	 */
	private const NATIVE_TRASH_META = array(
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_trash_meta_comments_status',
	);

	private const MAX_SNAPSHOT_DEPTH = 16;
	private const MAX_SNAPSHOT_NODES = 2048;
	private const MAX_SNAPSHOT_BYTES = 100000;

	/**
	 * Capture the fields and associated database state that native trash must keep.
	 *
	 * @param array<string,mixed> $state Fresh editor state.
	 * @return array<string,mixed>|null
	 */
	public function capture( array $state ): ?array {
		$post  = $state['post'];
		$terms = $this->terms( $post );
		$meta  = $this->meta( $post->ID );
		if ( null === $terms || null === $meta ) {
			return null;
		}

		$post_fields = $this->post_fields( $post );
		if ( null === $post_fields || null === $this->expected_meta( $meta, $post->post_name ) ) {
			return null;
		}

		return array(
			'post_id'      => $post->ID,
			'post_type'    => $post->post_type,
			'prior_status' => $post->post_status,
			'post_name'    => $post->post_name,
			'post'         => $post_fields,
			'terms'        => $terms['terms'],
			'area'         => $terms['area'],
			'meta'         => $meta,
		);
	}

	/**
	 * Validate the native trash postcondition without exposing stored values.
	 *
	 * @param array<string,mixed> $snapshot Pre-mutation snapshot.
	 * @param mixed               $after Post fetched after native trash.
	 */
	public function verified( array $snapshot, mixed $after ): bool {
		if ( ! $after instanceof \WP_Post || $after->ID !== $snapshot['post_id'] || 'trash' !== $after->post_status || $after->post_type !== $snapshot['post_type'] ) {
			return false;
		}
		$post_fields = $this->post_fields( $after );
		if ( null === $post_fields || $post_fields !== $snapshot['post'] ) {
			return false;
		}
		$expected_slug = $this->expected_trashed_slug( $snapshot['post_name'] );
		if ( null === $expected_slug || $expected_slug !== $after->post_name ) {
			return false;
		}
		$terms = $this->terms( $after );
		if ( null === $terms || $terms['terms'] !== $snapshot['terms'] || $terms['area'] !== $snapshot['area'] ) {
			return false;
		}
		$meta          = $this->meta( $after->ID );
		$expected_meta = $this->expected_meta( $snapshot['meta'], $snapshot['post_name'] );

		return null !== $meta && null !== $expected_meta && $meta === $expected_meta;
	}

	/**
	 * Read stable theme and template-part terms for a record.
	 *
	 * @param \WP_Post $post Current editor record.
	 * @return array{terms:array<mixed>,area:array<mixed>}|null
	 */
	private function terms( \WP_Post $post ): ?array {
		$theme_terms = 'wp_navigation' === $post->post_type ? array() : wp_get_object_terms(
			$post->ID,
			'wp_theme',
			array(
				'fields' => 'names',
				'number' => 2,
			)
		);
		$area_terms  = 'wp_template_part' === $post->post_type ? wp_get_object_terms(
			$post->ID,
			'wp_template_part_area',
			array(
				'fields' => 'names',
				'number' => 2,
			)
		) : array();
		if ( is_wp_error( $theme_terms ) || is_wp_error( $area_terms ) || ! is_array( $theme_terms ) || ! is_array( $area_terms ) ) {
			return null;
		}

		$terms = $this->canonical_array( $theme_terms );
		$area  = $this->canonical_array( $area_terms );
		if ( null === $terms || null === $area ) {
			return null;
		}

		return array(
			'terms' => $terms,
			'area'  => $area,
		);
	}

	/**
	 * Read existing post metadata while ignoring variable WordPress trash keys.
	 *
	 * @param int $post_id Record ID.
	 * @return array<mixed>|null
	 */
	private function meta( int $post_id ): ?array {
		$meta = get_post_meta( $post_id );
		if ( ! is_array( $meta ) ) {
			return null;
		}
		foreach ( self::NATIVE_TRASH_META as $key ) {
			unset( $meta[ $key ] );
		}

		return $this->canonical_array( $meta );
	}

	/**
	 * Keep post identity and content values without relying on optional stub fields.
	 *
	 * @param \WP_Post $post Native post.
	 * @return array<string,mixed>|null
	 */
	private function post_fields( \WP_Post $post ): ?array {
		$fields = array();
		foreach ( self::PRESERVED_POST_FIELDS as $field ) {
			if ( property_exists( $post, $field ) ) {
				$fields[ $field ] = $post->{$field};
			}
		}

		return $this->canonical_array( $fields );
	}

	/**
	 * Calculate WordPress's exact native trash slug transformation.
	 *
	 * Native wp_trash_post() stores the old slug in _wp_desired_post_slug and
	 * appends __trashed after truncating it to 191 bytes. Records already ending
	 * in __trashed are left unchanged. The native helper is required rather than
	 * approximated so verified results cannot claim recoverability on a guess.
	 *
	 * @param mixed $before Original post_name.
	 */
	private function expected_trashed_slug( mixed $before ): ?string {
		if ( ! is_string( $before ) || ! function_exists( '_truncate_post_slug' ) ) {
			return null;
		}
		if ( str_ends_with( $before, '__trashed' ) ) {
			return $before;
		}

		return _truncate_post_slug( $before, 191 ) . '__trashed';
	}

	/**
	 * Require exact native desired-slug metadata while preserving all other rows.
	 *
	 * @param mixed $before Original canonical metadata.
	 * @param mixed $slug   Original post_name.
	 * @return array<mixed>|null
	 */
	private function expected_meta( mixed $before, mixed $slug ): ?array {
		if ( ! is_array( $before ) || ! is_string( $slug ) ) {
			return null;
		}
		$expected = $before;
		foreach ( self::NATIVE_TRASH_META as $key ) {
			unset( $expected[ $key ] );
		}
		if ( str_ends_with( $slug, '__trashed' ) ) {
			return $this->canonical_array( $expected );
		}

		$desired = $expected['_wp_desired_post_slug'] ?? array();
		if ( ! is_array( $desired ) ) {
			$desired = array( $desired );
		}
		$desired[]                         = $slug;
		$expected['_wp_desired_post_slug'] = $desired;

		return $this->canonical_array( $expected );
	}

	/**
	 * Canonicalize one bounded array and reject unsupported metadata values.
	 *
	 * @param array<mixed> $value Value to canonicalize.
	 * @return array<mixed>|null
	 */
	private function canonical_array( array $value ): ?array {
		$nodes   = 0;
		$bytes   = 0;
		$invalid = new \stdClass();
		$result  = $this->canonical_value( $value, 0, $nodes, $bytes, $invalid );

		return $result === $invalid || ! is_array( $result ) ? null : $result;
	}

	/**
	 * Walk metadata with depth, node, byte and scalar-shape limits.
	 *
	 * @param mixed     $value Value to canonicalize.
	 * @param int       $depth Current nesting depth.
	 * @param int       $nodes Mutable node budget.
	 * @param int       $bytes Mutable string-byte budget.
	 * @param \stdClass $invalid Per-walk invalid sentinel.
	 * @return mixed
	 */
	private function canonical_value( mixed $value, int $depth, int &$nodes, int &$bytes, \stdClass $invalid ): mixed {
		++$nodes;
		if ( $depth > self::MAX_SNAPSHOT_DEPTH || $nodes > self::MAX_SNAPSHOT_NODES ) {
			return $invalid;
		}
		if ( is_string( $value ) ) {
			$bytes += strlen( $value );
			return $bytes > self::MAX_SNAPSHOT_BYTES ? $invalid : $value;
		}
		if ( is_int( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : $invalid;
		}
		if ( ! is_array( $value ) ) {
			return $invalid;
		}

		$result = array();
		foreach ( $value as $key => $child ) {
			if ( ! is_int( $key ) && ( ! is_string( $key ) || strlen( $key ) > 191 ) ) {
				return $invalid;
			}
			$normalized = $this->canonical_value( $child, $depth + 1, $nodes, $bytes, $invalid );
			if ( $normalized === $invalid ) {
				return $invalid;
			}
			$result[ $key ] = $normalized;
		}
		if ( ! array_is_list( $result ) ) {
			ksort( $result );
		}

		return $result;
	}
}
