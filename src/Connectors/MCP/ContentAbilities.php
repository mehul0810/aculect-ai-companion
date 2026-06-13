<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Content abilities implementation.
 */
final class ContentAbilities extends AbstractAbilityService {
	/**
	 * List supported post types visible through MCP.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function list_post_types(): array {
		$types = get_post_types( array(), 'objects' );
		$items = array();

		foreach ( $types as $type ) {
			if ( ! $type instanceof \WP_Post_Type || ! $this->is_supported_post_type( $type ) ) {
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

		if ( ! $post_type_object instanceof \WP_Post_Type || ! $this->is_supported_post_type( $post_type_object ) || ! $this->can_read_post_type( $post_type_object ) ) {
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

			/**
			 * Readable query posts.
			 *
			 * @var list<\WP_Post> $posts
			 */
			$mapper = 'full' === $this->collection_context( $args ) ? 'map_post' : 'map_post_compact';

		return array(
			'items'    => array_map( array( $this, $mapper ), $posts ),
			'total'    => (int) $query->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
			'context'  => $this->collection_context( $args ),
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
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object instanceof \WP_Post_Type || ! $this->is_supported_post_type( $post_type_object ) || ! current_user_can( 'read_post', $post_id ) ) {
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

		if ( ! $post_type_object instanceof \WP_Post_Type || ! $this->is_supported_post_type( $post_type_object ) || ! $this->can_create_post_type( $post_type_object ) ) {
			return $this->error( 'forbidden', 'You do not have permission to create this post type.' );
		}

		$status = $this->writable_status( (string) ( $data['status'] ?? 'draft' ) );
		if ( '' === $status ) {
			return $this->invalid_status_error();
		}

		if ( 'trash' === $status ) {
			return $this->error( 'invalid_status', 'Content cannot be created directly in the trash.' );
		}

		if ( in_array( $status, array( 'future', 'publish' ), true ) && ! current_user_can( $post_type_object->cap->publish_posts ) ) {
			return $this->error( 'forbidden', 'You do not have permission to publish this post type.' );
		}

		$validated_content = $this->validated_block_content_argument( $data );
		if ( isset( $validated_content['error'] ) ) {
			return $validated_content;
		}
		if ( array_key_exists( 'content', $validated_content ) ) {
			$data['content'] = $validated_content['content'];
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

		$date_payload = $this->post_date_payload_from_data( $data );
		if ( isset( $date_payload['error'] ) ) {
			$error = $date_payload['error'];
			return is_array( $error ) ? $error : $this->error( 'invalid_date', 'Date could not be resolved.' );
		}

		$payload = array_merge( $payload, $date_payload );

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
			/**
			 * Taxonomy assignment error payload.
			 *
			 * @var array<string, mixed> $error
			 */
			$error = $taxonomy_assignments['error'];
			return $error;
		}
		/**
		 * Resolved taxonomy assignments.
		 *
		 * @var array<string, list<int>> $taxonomy_assignments
		 */

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
			return $this->error( (string) $post_id->get_error_code(), $post_id->get_error_message() );
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

		if ( ! $post instanceof \WP_Post ) {
			return $this->error( 'not_found', 'Content item not found.' );
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object instanceof \WP_Post_Type || ! $this->is_supported_post_type( $post_type_object ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update this content item.' );
		}

		$validated_content = $this->validated_block_content_argument( $data );
		if ( isset( $validated_content['error'] ) ) {
			return $validated_content;
		}
		if ( array_key_exists( 'content', $validated_content ) ) {
			$data['content'] = $validated_content['content'];
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
			if ( '' === $status ) {
				return $this->invalid_status_error();
			}

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

			if ( in_array( $status, array( 'future', 'publish' ), true ) && ! current_user_can( $post_type_object->cap->publish_posts ) ) {
				return $this->error( 'forbidden', 'You do not have permission to publish this post type.' );
			}
			$update['post_status'] = $status;
		}

		$date_payload = $this->post_date_payload_from_data( $data );
		if ( isset( $date_payload['error'] ) ) {
			$error = $date_payload['error'];
			return is_array( $error ) ? $error : $this->error( 'invalid_date', 'Date could not be resolved.' );
		}

		$update = array_merge( $update, $date_payload );

		$taxonomy_assignments = $this->taxonomy_assignments( $data, $post->post_type );
		if ( isset( $taxonomy_assignments['error'] ) ) {
			/**
			 * Taxonomy assignment error payload.
			 *
			 * @var array<string, mixed> $error
			 */
			$error = $taxonomy_assignments['error'];
			return $error;
		}
		/**
		 * Resolved taxonomy assignments.
		 *
		 * @var array<string, list<int>> $taxonomy_assignments
		 */

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
							'post_title'    => $post->post_title,
							'post_content'  => $post->post_content,
							'post_excerpt'  => $post->post_excerpt,
							'post_name'     => $post->post_name,
							'post_status'   => $post->post_status,
							'post_author'   => (int) $post->post_author,
							'post_date'     => $post->post_date,
							'post_date_gmt' => $post->post_date_gmt,
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
			return $this->error( (string) $result->get_error_code(), $result->get_error_message() );
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

	/**
	 * Validate serialized block content for atomic content writes.
	 *
	 * @param array<string, mixed> $data Content fields.
	 * @return array<string, mixed>
	 */
	private function validated_block_content_argument( array $data ): array {
		if ( ! array_key_exists( 'content', $data ) ) {
			return array();
		}

		$content = trim( (string) $data['content'] );
		if ( '' === $content ) {
			return $this->error( 'invalid_block_content', 'Provide serialized WordPress block content.' );
		}

		if ( ! str_contains( $content, '<!-- wp:' ) ) {
			return $this->error( 'invalid_block_content', 'Use serialized WordPress block markup, not raw HTML or plain text.' );
		}

		$validation = ( new BlockKnowledgeAbilities() )->validate_block_content( array( 'content' => $content ) );
		if ( isset( $validation['error'] ) ) {
			return array_merge(
				$this->error( (string) $validation['error'], (string) ( $validation['message'] ?? 'Block validation failed.' ) ),
				array( 'block_validation' => $validation )
			);
		}

		if ( true !== ( $validation['valid'] ?? false ) ) {
			return array_merge(
				$this->error( 'invalid_block_content', 'Block content must use registered WordPress blocks and must not include core/html.' ),
				array(
					'block_validation' => $validation,
					'warnings'         => (array) ( $validation['warnings'] ?? array() ),
				)
			);
		}

		return array(
			'content'          => $content,
			'block_validation' => $validation,
		);
	}

	/**
	 * Return a structured invalid status error.
	 *
	 * @return array<string, mixed>
	 */
	private function invalid_status_error(): array {
		return $this->error(
			'invalid_status',
			sprintf(
				'Status must be one of: %s.',
				implode( ', ', $this->writable_post_statuses() )
			)
		);
	}

	/**
	 * Convert a date tool argument into WordPress post date fields.
	 *
	 * @param array<string, mixed> $data Tool arguments.
	 * @return array<string, mixed>
	 */
	private function post_date_payload_from_data( array $data ): array {
		if ( ! array_key_exists( 'date', $data ) ) {
			return array();
		}

		$date = trim( (string) $data['date'] );
		if ( '' === $date ) {
			return array( 'error' => $this->error( 'invalid_date', 'Date must be a non-empty ISO 8601 date/time string.' ) );
		}

		$parsed = $this->parse_post_date( $date );
		if ( ! $parsed instanceof DateTimeImmutable ) {
			return array( 'error' => $this->error( 'invalid_date', 'Date must use YYYY-MM-DDTHH:MM:SS, YYYY-MM-DD HH:MM:SS, or include a timezone offset such as 2026-06-01T09:00:00+00:00.' ) );
		}

		$site_date = $parsed->setTimezone( $this->site_timezone() );
		$gmt_date  = $parsed->setTimezone( new DateTimeZone( 'UTC' ) );

		return array(
			'post_date'     => $site_date->format( 'Y-m-d H:i:s' ),
			'post_date_gmt' => $gmt_date->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Parse an explicit post date while rejecting rollover dates.
	 *
	 * @param string $date Submitted tool date.
	 */
	private function parse_post_date( string $date ): ?DateTimeImmutable {
		$normalized = str_ends_with( $date, 'Z' ) ? substr( $date, 0, -1 ) . '+00:00' : $date;

		if ( 1 === preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}[+-]\\d{2}:\\d{2}$/', $normalized ) ) {
			return $this->create_date_from_format( '!Y-m-d\\TH:i:sP', $normalized, null );
		}

		if ( 1 === preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}$/', $date ) ) {
			return $this->create_date_from_format( '!Y-m-d\\TH:i:s', $date, $this->site_timezone() );
		}

		if ( 1 === preg_match( '/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', $date ) ) {
			return $this->create_date_from_format( '!Y-m-d H:i:s', $date, $this->site_timezone() );
		}

		return null;
	}

	/**
	 * Create a date object and reject parser warnings/errors.
	 *
	 * @param string            $format   Date format.
	 * @param string            $date     Submitted tool date.
	 * @param DateTimeZone|null $timezone Site timezone for local dates.
	 */
	private function create_date_from_format( string $format, string $date, ?DateTimeZone $timezone ): ?DateTimeImmutable {
		$parsed = null === $timezone ? DateTimeImmutable::createFromFormat( $format, $date ) : DateTimeImmutable::createFromFormat( $format, $date, $timezone );
		$errors = DateTimeImmutable::getLastErrors();

		if ( false !== $errors && ( 0 < $errors['warning_count'] || 0 < $errors['error_count'] ) ) {
			return null;
		}

		return $parsed instanceof DateTimeImmutable ? $parsed : null;
	}

	/**
	 * Return the WordPress site timezone.
	 */
	private function site_timezone(): DateTimeZone {
		if ( function_exists( 'wp_timezone' ) ) {
			return wp_timezone();
		}

		return new DateTimeZone( 'UTC' );
	}
}
