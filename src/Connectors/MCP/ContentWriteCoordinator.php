<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Coordinates multi-step content writes and compensates when a later step fails.
 *
 * WordPress does not provide a transaction spanning posts, terms, and featured
 * media. This boundary makes that limitation explicit: failures are rolled back
 * where possible, and an unverified rollback is terminal so callers never retry
 * an uncertain write automatically.
 */
final class ContentWriteCoordinator {

	/**
	 * Create a post and apply its dependent writes.
	 *
	 * @param array<string, mixed>     $payload             Post insert payload.
	 * @param int|null                 $featured_media      Featured media ID.
	 * @param array<string, list<int>> $taxonomy_assignments Taxonomy term IDs.
	 * @return array<string, mixed>
	 */
	public function create( array $payload, ?int $featured_media, array $taxonomy_assignments ): array {
		try {
			$post_id = wp_insert_post( $payload, true );
		} catch ( \Throwable ) {
			return $this->error( 'write_failed', 'Content could not be created.' );
		}

		if ( is_wp_error( $post_id ) ) {
			return $this->error( (string) $post_id->get_error_code(), $post_id->get_error_message() );
		}

		$post_id = (int) $post_id;

		if ( null !== $featured_media ) {
			try {
				$featured_result = set_post_thumbnail( $post_id, $featured_media );
			} catch ( \Throwable ) {
				$featured_result = false;
			}
			if ( false === $featured_result ) {
				return $this->failed_after_create( $post_id, 'featured_media', 'Featured image could not be assigned.' );
			}
		}

		foreach ( $taxonomy_assignments as $taxonomy_name => $term_ids ) {
			try {
				$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy_name, false );
			} catch ( \Throwable ) {
				$result = new \WP_Error( 'taxonomy_assignment_failed', 'Taxonomy terms could not be assigned.' );
			}
			if ( is_wp_error( $result ) ) {
				return $this->failed_after_create(
					$post_id,
					'taxonomy_assignment',
					(string) $result->get_error_message(),
					(string) $result->get_error_code()
				);
			}
		}

		return array(
			'success' => true,
			'post_id' => $post_id,
		);
	}

	/**
	 * Update a post and apply its dependent writes with a reversible snapshot.
	 *
	 * @param \WP_Post                 $before              Existing post snapshot.
	 * @param array<string, mixed>     $update              Post update payload.
	 * @param array<string, list<int>> $taxonomy_assignments Taxonomy term IDs.
	 * @param array<string, mixed>     $featured_media_change Featured media change.
	 * @return array<string, mixed>
	 */
	public function update( \WP_Post $before, array $update, array $taxonomy_assignments, array $featured_media_change ): array {
		$post_id  = (int) $before->ID;
		$snapshot = $this->snapshot( $before, $taxonomy_assignments, $featured_media_change );
		if ( isset( $snapshot['error'] ) ) {
			return $snapshot;
		}

		try {
			$result = wp_update_post( $update, true );
		} catch ( \Throwable ) {
			return $this->error( 'write_failed', 'Content could not be updated.' );
		}
		if ( is_wp_error( $result ) ) {
			return $this->error( (string) $result->get_error_code(), $result->get_error_message() );
		}

		foreach ( $taxonomy_assignments as $taxonomy_name => $term_ids ) {
			try {
				$assignment_result = wp_set_object_terms( $post_id, $term_ids, $taxonomy_name, false );
			} catch ( \Throwable ) {
				$assignment_result = new \WP_Error( 'taxonomy_assignment_failed', 'Taxonomy terms could not be assigned.' );
			}
			if ( is_wp_error( $assignment_result ) ) {
				return $this->failed_after_update(
					$before,
					$snapshot,
					'taxonomy_assignment',
					(string) $assignment_result->get_error_message(),
					(string) $assignment_result->get_error_code()
				);
			}
		}

		if ( array() !== $featured_media_change ) {
			$value = absint( $featured_media_change['value'] ?? 0 );
			try {
				$featured_result = 0 === $value ? delete_post_thumbnail( $post_id ) : set_post_thumbnail( $post_id, $value );
			} catch ( \Throwable ) {
				$featured_result = false;
			}
			if ( false === $featured_result ) {
				return $this->failed_after_update( $before, $snapshot, 'featured_media', 'Featured image could not be assigned.' );
			}
		}

		return array(
			'success' => true,
			'post_id' => $post_id,
		);
	}

	/**
	 * Capture only the state touched by this update.
	 *
	 * @param \WP_Post                 $before              Existing post.
	 * @param array<string, list<int>> $taxonomy_assignments Requested taxonomy writes.
	 * @param array<string, mixed>     $featured_media_change Requested featured media write.
	 * @return array<string, mixed>
	 */
	private function snapshot( \WP_Post $before, array $taxonomy_assignments, array $featured_media_change ): array {
		$terms = array();
		foreach ( array_keys( $taxonomy_assignments ) as $taxonomy_name ) {
			try {
				$current = wp_get_object_terms(
					(int) $before->ID,
					(string) $taxonomy_name,
					array( 'fields' => 'ids' )
				);
			} catch ( \Throwable ) {
				return $this->error( 'rollback_unavailable', 'Existing taxonomy state could not be verified before the write.' );
			}
			if ( is_wp_error( $current ) ) {
				return $this->error( 'rollback_unavailable', 'Existing taxonomy state could not be verified before the write.' );
			}

			$terms[ (string) $taxonomy_name ] = $this->term_ids( $current );
		}

		$featured_media = null;
		if ( array() !== $featured_media_change ) {
			try {
				$featured_media = get_post_thumbnail_id( (int) $before->ID );
			} catch ( \Throwable ) {
				return $this->error( 'rollback_unavailable', 'Existing featured media state could not be verified before the write.' );
			}
		}

		return array(
			'post'           => $this->post_snapshot( $before ),
			'taxonomies'     => $terms,
			'featured_media' => $featured_media,
		);
	}

	/**
	 * Compensate a failed create by moving it to the WordPress trash.
	 *
	 * @param int    $post_id     Created post ID.
	 * @param string $failed_step Failed dependent write.
	 * @param string $message     Safe failure message.
	 * @param string $code        Original machine-readable code.
	 * @return array<string, mixed>
	 */
	private function failed_after_create( int $post_id, string $failed_step, string $message, string $code = 'write_failed' ): array {
		$rollback = $this->trash_created_post( $post_id );
		if ( $rollback ) {
			return array(
				'error'           => $code,
				'message'         => $message,
				'status'          => 'failed',
				'post_id'         => $post_id,
				'failed_step'     => $failed_step,
				'rollback_status' => 'verified',
			);
		}

		return $this->partial_failure( $post_id, $failed_step, $message );
	}

	/**
	 * Compensate a failed update from the captured snapshot.
	 *
	 * @param \WP_Post             $before      Existing post.
	 * @param array<string, mixed> $snapshot    Captured state.
	 * @param string               $failed_step Failed dependent write.
	 * @param string               $message     Safe failure message.
	 * @param string               $code        Original machine-readable code.
	 * @return array<string, mixed>
	 */
	private function failed_after_update( \WP_Post $before, array $snapshot, string $failed_step, string $message, string $code = 'write_failed' ): array {
		$rollback = $this->restore( $before, $snapshot );
		if ( $rollback ) {
			return array(
				'error'           => $code,
				'message'         => $message,
				'status'          => 'failed',
				'post_id'         => (int) $before->ID,
				'failed_step'     => $failed_step,
				'rollback_status' => 'verified',
			);
		}

		return $this->partial_failure( (int) $before->ID, $failed_step, $message );
	}

	/**
	 * Move a newly created post to trash and verify the terminal state.
	 *
	 * @param int $post_id Created post ID.
	 */
	private function trash_created_post( int $post_id ): bool {
		try {
			if ( ! function_exists( 'wp_trash_post' ) ) {
				return false;
			}

			$trashed = wp_trash_post( $post_id );
			$current = get_post( $post_id );
		} catch ( \Throwable ) {
			return false;
		}

		return $trashed instanceof \WP_Post && $current instanceof \WP_Post && 'trash' === $current->post_status;
	}

	/**
	 * Restore state touched by an update and verify every component.
	 *
	 * @param \WP_Post             $before   Existing post.
	 * @param array<string, mixed> $snapshot Captured state.
	 */
	private function restore( \WP_Post $before, array $snapshot ): bool {
		try {
			$post_result = wp_update_post( $snapshot['post'], true );
			if ( is_wp_error( $post_result ) ) {
				return false;
			}

			foreach ( (array) ( $snapshot['taxonomies'] ?? array() ) as $taxonomy_name => $term_ids ) {
				$result = wp_set_object_terms( (int) $before->ID, (array) $term_ids, (string) $taxonomy_name, false );
				if ( is_wp_error( $result ) ) {
					return false;
				}
			}

			if ( null !== ( $snapshot['featured_media'] ?? null ) ) {
				$featured_media = absint( $snapshot['featured_media'] );
				$result         = 0 === $featured_media
					? delete_post_thumbnail( (int) $before->ID )
					: set_post_thumbnail( (int) $before->ID, $featured_media );
				if ( false === $result ) {
					return false;
				}
			}
		} catch ( \Throwable ) {
			return false;
		}

		try {
			$current = get_post( (int) $before->ID );
			if ( ! $current instanceof \WP_Post || ! $this->post_matches( $current, (array) $snapshot['post'] ) ) {
				return false;
			}

			foreach ( (array) ( $snapshot['taxonomies'] ?? array() ) as $taxonomy_name => $term_ids ) {
				$current_terms = wp_get_object_terms( (int) $before->ID, (string) $taxonomy_name, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $current_terms ) || $this->term_ids( $current_terms ) !== $this->term_ids( (array) $term_ids ) ) {
					return false;
				}
			}

			if ( null !== ( $snapshot['featured_media'] ?? null ) && get_post_thumbnail_id( (int) $before->ID ) !== absint( $snapshot['featured_media'] ) ) {
				return false;
			}
		} catch ( \Throwable ) {
			return false;
		}

		return true;
	}

	/**
	 * Return fields that can be restored with wp_update_post().
	 *
	 * @param \WP_Post $post Existing post.
	 * @return array<string, mixed>
	 */
	private function post_snapshot( \WP_Post $post ): array {
		return array(
			'ID'            => (int) $post->ID,
			'post_title'    => (string) $post->post_title,
			'post_content'  => (string) $post->post_content,
			'post_excerpt'  => (string) $post->post_excerpt,
			'post_name'     => (string) $post->post_name,
			'post_status'   => (string) $post->post_status,
			'post_author'   => (int) $post->post_author,
			'post_parent'   => (int) $post->post_parent,
			'post_date'     => (string) $post->post_date,
			'post_date_gmt' => (string) $post->post_date_gmt,
		);
	}

	/**
	 * Verify restored post fields.
	 *
	 * @param \WP_Post             $post     Restored post.
	 * @param array<string, mixed> $snapshot Expected post fields.
	 */
	private function post_matches( \WP_Post $post, array $snapshot ): bool {
		foreach ( $snapshot as $field => $expected ) {
			if ( 'ID' === $field ) {
				$value = $post->ID;
			} elseif ( ! property_exists( $post, (string) $field ) ) {
				continue;
			} else {
				$value = $post->{$field};
			}

			if ( (string) $value !== (string) $expected ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize WordPress term results to sorted IDs.
	 *
	 * @param mixed $terms Term IDs or objects.
	 * @return list<int>
	 */
	private function term_ids( mixed $terms ): array {
		$ids = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof \WP_Term ) {
				$ids[] = (int) $term->term_id;
			} elseif ( is_scalar( $term ) ) {
				$ids[] = absint( $term );
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		sort( $ids );

		return $ids;
	}

	/**
	 * Return a terminal partial-write result.
	 *
	 * @param int    $post_id     Affected post ID.
	 * @param string $failed_step Dependent operation that failed.
	 * @param string $message     Safe failure message.
	 * @return array<string, mixed>
	 */
	private function partial_failure( int $post_id, string $failed_step, string $message ): array {
		return array(
			'error'           => 'partial_write',
			'message'         => $message,
			'status'          => 'partial',
			'terminal'        => true,
			'post_id'         => $post_id,
			'failed_step'     => $failed_step,
			'rollback_status' => 'failed',
			'recovery'        => 'Review this item in WordPress before retrying.',
		);
	}

	/**
	 * Return a consistent coordinator error.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Safe error message.
	 *
	 * @return array<string, string>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'error'   => $code,
			'message' => $message,
		);
	}
}
