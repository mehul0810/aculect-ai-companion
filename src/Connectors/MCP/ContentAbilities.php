<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Content abilities implementation.
 */
final class ContentAbilities extends AbstractAbilityService {
	public function list_post_types(): array {
		$types = get_post_types( array(), 'objects' );
		$items = array();

		foreach ( $types as $type ) {
			if ( ! $this->is_supported_post_type( $type ) ) {
				continue;
			}

			$items[] = array(
				'name'         => $type->name,
				'label'        => $type->label,
				'public'       => (bool) $type->public,
				'show_in_rest' => (bool) $type->show_in_rest,
				'can_read'     => $this->can_read_post_type( $type ),
				'can_create'   => $this->can_create_post_type( $type ),
				'can_update'   => current_user_can( $type->cap->edit_posts ),
			);
		}

		return $items;
	}

	/**
	 * List content items for a supported post type with pagination.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function list_items( array $args ): array {
		$per_page         = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page             = max( 1, (int) ( $args['page'] ?? 1 ) );
		$post_type        = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $this->is_supported_post_type( $post_type_object ) || ! $this->can_read_post_type( $post_type_object ) ) {
			return $this->empty_collection( $page, $per_page );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $this->statuses_from_args( $args, 'attachment' === $post_type ? array( 'inherit' ) : self::DEFAULT_POST_STATUSES ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'no_found_rows'  => false,
				'perm'           => 'readable',
			)
		);

		$posts = array_values(
			array_filter(
				$query->posts,
				static fn( $post ): bool => $post instanceof \WP_Post && current_user_can( 'read_post', $post->ID )
			)
		);

		return array(
			'items'    => array_map( array( $this, 'map_post' ), $posts ),
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Read one content item by post ID.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public function get_item( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $this->is_supported_post_type( $post_type_object ) || ! current_user_can( 'read_post', $post_id ) ) {
			return array();
		}

		return $this->map_post( $post );
	}

	/**
	 * Create a post, page, or custom post type item.
	 *
	 * @param array<string, mixed> $data Content fields.
	 * @return array<string, mixed>
	 */
	public function create_item( array $data ): array {
		$post_type        = sanitize_key( (string) ( $data['post_type'] ?? 'post' ) );
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $this->is_supported_post_type( $post_type_object ) || ! $this->can_create_post_type( $post_type_object ) ) {
			return $this->error( 'forbidden', 'You do not have permission to create this post type.' );
		}

		$status = $this->writable_status( (string) ( $data['status'] ?? 'draft' ) );
		if ( 'trash' === $status ) {
			return $this->error( 'invalid_status', 'Content cannot be created directly in the trash.' );
		}

		if ( 'publish' === $status && ! current_user_can( $post_type_object->cap->publish_posts ) ) {
			return $this->error( 'forbidden', 'You do not have permission to publish this post type.' );
		}

		$featured_media = null;
		if ( array_key_exists( 'featured_media', $data ) ) {
			if ( ! post_type_supports( $post_type, 'thumbnail' ) ) {
				return $this->error( 'unsupported_featured_media', 'This post type does not support featured images.' );
			}

			$featured_media = $this->validated_featured_media_id( $data['featured_media'] );
			if ( is_array( $featured_media ) ) {
				return $featured_media;
			}
		}

		$payload = array_filter(
			array(
				'post_type'    => $post_type,
				'post_title'   => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
				'post_content' => wp_kses_post( (string) ( $data['content'] ?? '' ) ),
				'post_excerpt' => isset( $data['excerpt'] ) ? wp_kses_post( (string) $data['excerpt'] ) : null,
				'post_name'    => isset( $data['slug'] ) ? sanitize_title( (string) $data['slug'] ) : null,
				'post_status'  => $status,
			),
			static fn( $value ): bool => null !== $value
		);

		if ( array_key_exists( 'author', $data ) ) {
			$author_id = absint( $data['author'] );
			$error     = $this->author_assignment_error( $author_id, $post_type_object );
			if ( array() !== $error ) {
				return $error;
			}

			$payload['post_author'] = $author_id;
		}

		$taxonomy_assignments = $this->taxonomy_assignments( $data, $post_type );
		if ( isset( $taxonomy_assignments['error'] ) ) {
			return $taxonomy_assignments['error'];
		}

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'content.create_item',
				$data,
				array(
					'type' => $post_type,
					'id'   => null,
				),
				array_merge(
					$this->post_payload_changes( array(), $payload ),
					$this->taxonomy_assignment_changes( $taxonomy_assignments ),
					null !== $featured_media ? array( $this->change( 'featured_media', null, $featured_media ) ) : array()
				)
			);
		}

		$post_id = wp_insert_post( $payload, true );

		if ( is_wp_error( $post_id ) ) {
			return $this->error( $post_id->get_error_code(), $post_id->get_error_message() );
		}

		if ( null !== $featured_media && false === set_post_thumbnail( (int) $post_id, $featured_media ) ) {
			return $this->error( 'featured_media_failed', 'Featured image could not be assigned.' );
		}

		$assignment_result = $this->apply_taxonomy_assignments( (int) $post_id, $taxonomy_assignments );
		if ( isset( $assignment_result['error'] ) ) {
			return $assignment_result['error'];
		}

		return $this->get_item( (int) $post_id );
	}

	/**
	 * Create a draft content item.
	 *
	 * @param array<string, mixed> $data Content fields.
	 * @return array<string, mixed>
	 */
	public function create_draft( array $data ): array {
		$data['status'] = 'draft';
		return $this->create_item( $data );
	}

	/**
	 * Update an existing content item.
	 *
	 * @param array<string, mixed> $data Content fields.
	 * @return array<string, mixed>
	 */
	public function update_item( array $data ): array {
		$post_id = absint( $data['id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->error( 'not_found', 'Content item not found.' );
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $this->is_supported_post_type( $post_type_object ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update this content item.' );
		}

		$update = array( 'ID' => $post_id );
		if ( array_key_exists( 'title', $data ) ) {
			$update['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( array_key_exists( 'content', $data ) ) {
			$update['post_content'] = wp_kses_post( (string) $data['content'] );
		}
		if ( array_key_exists( 'excerpt', $data ) ) {
			$update['post_excerpt'] = wp_kses_post( (string) $data['excerpt'] );
		}
		if ( array_key_exists( 'slug', $data ) ) {
			$update['post_name'] = sanitize_title( (string) $data['slug'] );
		}
		if ( array_key_exists( 'author', $data ) ) {
			$author_id = absint( $data['author'] );
			$error     = $this->author_assignment_error( $author_id, $post_type_object, (int) $post->post_author );
			if ( array() !== $error ) {
				return $error;
			}

			$update['post_author'] = $author_id;
		}
		if ( array_key_exists( 'status', $data ) ) {
			$status = $this->writable_status( (string) $data['status'] );
			if ( 'trash' === $status ) {
				if ( ! current_user_can( 'delete_post', $post_id ) ) {
					return $this->error( 'forbidden', 'You do not have permission to trash this content item.' );
				}

				if ( $this->is_dry_run( $data ) ) {
					return $this->preview_response(
						'content.update_item',
						$data,
						array(
							'type' => $post->post_type,
							'id'   => $post_id,
						),
						array(
							$this->change( 'status', $post->post_status, 'trash' ),
						),
						array( 'This item will be moved to the WordPress trash and can be restored from the admin.' )
					);
				}

				$trashed = wp_trash_post( $post_id );
				if ( ! $trashed instanceof \WP_Post ) {
					return $this->error( 'trash_failed', 'Content item could not be moved to the trash.' );
				}

				$item             = $this->map_post( $trashed );
				$item['recovery'] = array(
					'type'    => 'trash',
					'message' => 'Restore this item from the WordPress trash if the change was unintended.',
				);

				return $item;
			}

			if ( 'publish' === $status && ! current_user_can( $post_type_object->cap->publish_posts ) ) {
				return $this->error( 'forbidden', 'You do not have permission to publish this post type.' );
			}
			$update['post_status'] = $status;
		}

		$taxonomy_assignments = $this->taxonomy_assignments( $data, $post->post_type );
		if ( isset( $taxonomy_assignments['error'] ) ) {
			return $taxonomy_assignments['error'];
		}

		$featured_media_change = $this->featured_media_change( $data, $post->post_type );
		if ( isset( $featured_media_change['error'] ) ) {
			return $featured_media_change;
		}

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'content.update_item',
				$data,
				array(
					'type' => $post->post_type,
					'id'   => $post_id,
				),
				array_merge(
					$this->post_payload_changes(
						array(
							'post_title'   => $post->post_title,
							'post_content' => $post->post_content,
							'post_excerpt' => $post->post_excerpt,
							'post_name'    => $post->post_name,
							'post_status'  => $post->post_status,
							'post_author'  => (int) $post->post_author,
						),
						$update
					),
					$this->taxonomy_assignment_changes( $taxonomy_assignments, $post_id ),
					! empty( $featured_media_change )
						? array( $this->change( 'featured_media', get_post_thumbnail_id( $post_id ), $featured_media_change['value'] ) )
						: array()
				)
			);
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $this->error( $result->get_error_code(), $result->get_error_message() );
		}

		$assignment_result = $this->apply_taxonomy_assignments( $post_id, $taxonomy_assignments );
		if ( isset( $assignment_result['error'] ) ) {
			return $assignment_result['error'];
		}

		if ( ! empty( $featured_media_change ) ) {
			if ( 0 === $featured_media_change['value'] ) {
				delete_post_thumbnail( $post_id );
			} elseif ( false === set_post_thumbnail( $post_id, (int) $featured_media_change['value'] ) ) {
				return $this->error( 'featured_media_failed', 'Featured image could not be assigned.' );
			}
		}

		return $this->get_item( $post_id );
	}
}
