<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Comment abilities implementation.
 */
final class CommentAbilities extends AbstractAbilityService {
	public function list_comments( array $args ): array {
		if ( ! current_user_can( 'moderate_comments' ) && ! current_user_can( 'edit_posts' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to list comments.' );
		}

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 50 ) ) );
		$status   = $this->comment_status( (string) ( $args['status'] ?? 'all' ), true );

		$query = array(
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'status'  => $status,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		);

		if ( ! empty( $args['post_id'] ) ) {
			$query['post_id'] = absint( $args['post_id'] );
		}

		$search = '';
		if ( ! empty( $args['author'] ) ) {
			$search = sanitize_text_field( (string) $args['author'] );
		}

		if ( ! empty( $args['author_user_id'] ) ) {
			$query['user_id'] = absint( $args['author_user_id'] );
		}

		if ( ! empty( $args['author_email'] ) ) {
			$query['author_email'] = sanitize_email( (string) $args['author_email'] );
		}

		$date_query = $this->comment_date_query( $args );
		if ( array() !== $date_query ) {
			$query['date_query'] = $date_query;
		}

		if ( ! empty( $args['search'] ) ) {
			$search = sanitize_text_field( (string) $args['search'] );
		}

		if ( '' !== $search ) {
			$query['search'] = $search;
		}

		$comments = get_comments( $query );
		$total    = get_comments(
			array_merge(
				$query,
				array(
					'count'  => true,
					'number' => 0,
					'offset' => 0,
				)
			)
		);

		return array(
			'items'    => array_map( array( $this, 'map_comment' ), is_array( $comments ) ? $comments : array() ),
			'total'    => is_numeric( $total ) ? (int) $total : 0,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Read one comment.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_comment( array $data ): array {
		$comment = get_comment( absint( $data['id'] ?? 0 ) );
		if ( ! $comment instanceof \WP_Comment ) {
			return $this->error( 'not_found', 'Comment not found.' );
		}

		if ( ! $this->can_read_comment( $comment ) ) {
			return $this->error( 'forbidden', 'You do not have permission to read this comment.' );
		}

		return $this->map_comment( $comment );
	}

	/**
	 * Create a comment on an editable post.
	 *
	 * @param array<string, mixed> $data Comment fields.
	 * @return array<string, mixed>
	 */
	public function create_comment( array $data ): array {
		$post_id = absint( $data['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		$parent  = null;

		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to comment on this post.' );
		}

		if ( ! empty( $data['parent_id'] ) ) {
			$parent_id = absint( $data['parent_id'] );
			$parent    = get_comment( $parent_id );
			if ( ! $parent instanceof \WP_Comment || (int) $parent->comment_post_ID !== $post_id ) {
				return $this->error( 'invalid_parent', 'Parent comment was not found on the selected post.' );
			}

			if ( ! $this->can_read_comment( $parent ) ) {
				return $this->error( 'forbidden', 'You do not have permission to reply to this comment.' );
			}
		}

		$content = wp_kses_post( (string) ( $data['content'] ?? '' ) );
		if ( '' === trim( wp_strip_all_tags( $content ) ) ) {
			return $this->error( 'invalid_comment', 'Comment content is required.' );
		}

		$user        = wp_get_current_user();
		$author_name = '' !== $user->display_name ? $user->display_name : $user->user_login;
		$approved    = 'hold';
		if ( current_user_can( 'moderate_comments' ) ) {
			$approved = $this->comment_status( (string) ( $data['status'] ?? 'approve' ), false );
		}

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'comments.create_item',
				$data,
				array(
					'type' => 'comment',
					'id'   => null,
				),
				array_values(
					array_filter(
						array(
							$this->change( 'post_id', null, $post_id ),
							$this->change( 'content', null, $content ),
							$this->change( 'status', null, $approved ),
							$this->change( 'parent_id', null, $parent instanceof \WP_Comment ? (int) $parent->comment_ID : 0 ),
						)
					)
				)
			);
		}

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_content'      => $content,
				'comment_approved'     => 'approve' === $approved ? '1' : '0',
				'user_id'              => get_current_user_id(),
				'comment_author'       => sanitize_text_field( $author_name ),
				'comment_author_email' => sanitize_email( $user->user_email ),
				'comment_parent'       => $parent instanceof \WP_Comment ? (int) $parent->comment_ID : 0,
			)
		);

		if ( ! $comment_id ) {
			return $this->error( 'comment_failed', 'Comment could not be created.' );
		}

		$comment = get_comment( (int) $comment_id );
		return $comment instanceof \WP_Comment ? $this->map_comment( $comment ) : array( 'id' => (int) $comment_id );
	}

	/**
	 * Update comment content or moderation status.
	 *
	 * @param array<string, mixed> $data Comment fields.
	 * @return array<string, mixed>
	 */
	public function update_comment( array $data ): array {
		$comment_id = absint( $data['id'] ?? 0 );
		$comment    = get_comment( $comment_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return $this->error( 'not_found', 'Comment not found.' );
		}

		if ( ! current_user_can( 'edit_comment', $comment_id ) && ! current_user_can( 'moderate_comments' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update this comment.' );
		}

		$update = array( 'comment_ID' => $comment_id );
		if ( array_key_exists( 'content', $data ) ) {
			$update['comment_content'] = wp_kses_post( (string) $data['content'] );
		}

		if ( array_key_exists( 'status', $data ) ) {
			$status = $this->comment_status( (string) $data['status'], false );
			if ( $this->is_dry_run( $data ) ) {
				return $this->preview_response(
					'comments.update_item',
					$data,
					array(
						'type' => 'comment',
						'id'   => $comment_id,
					),
					array_values(
						array_filter(
							array(
								array_key_exists( 'content', $data ) ? $this->change( 'content', $comment->comment_content, $update['comment_content'] ?? $comment->comment_content ) : null,
								$this->change( 'status', wp_get_comment_status( $comment ), $status ),
							)
						)
					),
					'trash' === $status ? array( 'This comment will be moved to the WordPress trash and can be restored from comment moderation.' ) : array()
				);
			}

			if ( ! wp_set_comment_status( $comment_id, $status ) ) {
				return $this->error( 'comment_status_failed', 'Comment status could not be updated.' );
			}
		} elseif ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'comments.update_item',
				$data,
				array(
					'type' => 'comment',
					'id'   => $comment_id,
				),
				array_values(
					array_filter(
						array(
							array_key_exists( 'content', $data ) ? $this->change( 'content', $comment->comment_content, $update['comment_content'] ?? $comment->comment_content ) : null,
						)
					)
				)
			);
		}

		if ( count( $update ) > 1 && false === wp_update_comment( $update ) ) {
			return $this->error( 'comment_failed', 'Comment could not be updated.' );
		}

		$comment = get_comment( $comment_id );
		$result  = $comment instanceof \WP_Comment ? $this->map_comment( $comment ) : array( 'id' => $comment_id );
		if ( isset( $status ) && 'trash' === $status ) {
			$result['recovery'] = array(
				'type'    => 'trash',
				'message' => 'Restore this comment from the WordPress trash if the change was unintended.',
			);
		}

		return $result;
	}

	/**
	 * Bulk update comment moderation status.
	 *
	 * @param array<string, mixed> $data Comment fields.
	 * @return array<string, mixed>
	 */
	public function bulk_update_comments( array $data ): array {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to bulk moderate comments.' );
		}

		$comment_ids = $this->comment_ids( $data['ids'] ?? array() );
		if ( array() === $comment_ids ) {
			return $this->error( 'invalid_comments', 'At least one comment ID is required.' );
		}

		$status  = $this->comment_status( (string) ( $data['status'] ?? '' ), false );
		$changes = array();
		foreach ( $comment_ids as $comment_id ) {
			$comment = get_comment( $comment_id );
			if ( ! $comment instanceof \WP_Comment ) {
				return $this->error( 'not_found', 'One or more comments could not be found.' );
			}

			$changes[] = $this->change( 'comments.' . $comment_id . '.status', wp_get_comment_status( $comment ), $status );
		}

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'comments.bulk_update',
				$data,
				array(
					'type' => 'comment',
					'id'   => null,
				),
				$changes,
				array( 'Bulk moderation requires confirmation before changes are applied.' )
			);
		}

		$items = array();
		foreach ( $comment_ids as $comment_id ) {
			if ( ! wp_set_comment_status( $comment_id, $status ) ) {
				return $this->error( 'comment_status_failed', 'One or more comments could not be updated.' );
			}

			$comment = get_comment( $comment_id );
			if ( $comment instanceof \WP_Comment ) {
				$items[] = $this->map_comment( $comment );
			}
		}

		return array(
			'items' => $items,
			'total' => count( $items ),
		);
	}
}
