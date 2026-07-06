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

		$item                   = $this->map_post( $post );
		$item['block_locators'] = $this->block_locators( (string) $post->post_content );

		return $item;
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

		$schedule_error = $this->future_status_error( $status, $date_payload, null );
		if ( array() !== $schedule_error ) {
			return $schedule_error;
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
			$additional_changes = array_merge(
				$this->taxonomy_assignment_changes( $taxonomy_assignments ),
				null !== $featured_media ? array( $this->change( 'featured_media', null, $featured_media ) ) : array()
			);
			$diff               = $this->post_payload_diff( array(), $payload );
			$additional_diff    = $this->diff_from_changes( $additional_changes );

			return $this->preview_response(
				'content.create_item',
				$data,
				array(
					'type' => $post_type,
					'id'   => null,
				),
				array_merge(
					$this->post_payload_changes( array(), $payload ),
					$additional_changes
				),
				array(),
				$this->diff_payload( array_merge( $diff['fields'], $additional_diff['fields'] ) )
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
		$requested_status = null;
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
					$can_read_before = current_user_can( 'read_post', $post_id );
					return $this->preview_response(
						'content.update_item',
						$data,
						array(
							'type' => $post->post_type,
							'id'   => $post_id,
						),
						array(
							$this->change( 'status', $can_read_before ? $post->post_status : null, 'trash' ),
						),
						array( 'This item will be moved to the WordPress trash and can be restored from the admin.' ),
						$this->diff_payload(
							array(
								$this->field_diff( 'status', $post->post_status, 'trash', $can_read_before, 'not_readable' ),
							)
						)
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
			$requested_status      = $status;
			$update['post_status'] = $status;
		}

		$date_payload = $this->post_date_payload_from_data( $data );
		if ( isset( $date_payload['error'] ) ) {
			$error = $date_payload['error'];
			return is_array( $error ) ? $error : $this->error( 'invalid_date', 'Date could not be resolved.' );
		}

		if ( null !== $requested_status ) {
			$schedule_error = $this->future_status_error( $requested_status, $date_payload, $post );
			if ( array() !== $schedule_error ) {
				return $schedule_error;
			}
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
			$from               = array(
				'post_title'    => $post->post_title,
				'post_content'  => $post->post_content,
				'post_excerpt'  => $post->post_excerpt,
				'post_name'     => $post->post_name,
				'post_status'   => $post->post_status,
				'post_author'   => (int) $post->post_author,
				'post_date'     => $post->post_date,
				'post_date_gmt' => $post->post_date_gmt,
			);
			$can_read_before    = current_user_can( 'read_post', $post_id );
			$changes_from       = $can_read_before ? $from : array();
			$additional_changes = array_merge(
				$this->taxonomy_assignment_changes( $taxonomy_assignments, $can_read_before ? $post_id : 0 ),
				! empty( $featured_media_change )
					? array( $this->change( 'featured_media', $can_read_before ? get_post_thumbnail_id( $post_id ) : null, $featured_media_change['value'] ) )
					: array()
			);
			$diff               = $this->post_payload_diff( $from, $update, $can_read_before );
			$additional_diff    = $this->diff_from_changes( $additional_changes );

			return $this->preview_response(
				'content.update_item',
				$data,
				array(
					'type' => $post->post_type,
					'id'   => $post_id,
				),
				array_merge(
					$this->post_payload_changes( $changes_from, $update ),
					$additional_changes
				),
				array(),
				$this->diff_payload( array_merge( $diff['fields'], $additional_diff['fields'] ) )
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
	 * Update one supported parsed block by deterministic path.
	 *
	 * @param array<string, mixed> $data Content block update fields.
	 * @return array<string, mixed>
	 */
	public function update_block( array $data ): array {
		$post_id = absint( $data['id'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return $this->error( 'not_found', 'Content item not found.' );
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object instanceof \WP_Post_Type || ! $this->is_supported_post_type( $post_type_object ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update this content item.' );
		}

		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $this->error( 'block_update_unavailable', 'WordPress block parsing and serialization APIs are required for targeted block updates.' );
		}

		$path = $this->block_locator_path( $data['locator'] ?? null );
		if ( array() === $path ) {
			return $this->error( 'invalid_block_locator', 'Provide a deterministic block locator path from a prior content read.' );
		}

		$blocks = $this->normalize_parsed_blocks( parse_blocks( (string) $post->post_content ) );
		$target = $this->block_at_path( $blocks, $path );
		if ( null === $target ) {
			return $this->error( 'invalid_block_locator', 'No block exists at the requested locator path.' );
		}

		$block_name = (string) ( $target['blockName'] ?? '' );
		if ( ! $this->is_registered_block( $block_name ) ) {
			return $this->error( 'unsupported_block_type', 'Target block type is not registered on this site.' );
		}

		if ( array_key_exists( 'attrs', $data ) && array() !== (array) $data['attrs'] ) {
			return $this->error( 'unsupported_block_attribute_update', 'Registered block attribute writes are deferred for this beta slice; use text replacement for core paragraph and heading blocks.' );
		}

		if ( ! in_array( $block_name, array( 'core/paragraph', 'core/heading' ), true ) ) {
			return $this->error( 'unsupported_block_type', 'Only core paragraph and heading text replacement is supported in this beta slice.' );
		}

		$link = $this->internal_link_update_payload( $data['internal_link'] ?? null );
		$text = '';
		if ( isset( $link['error'] ) ) {
			return $link;
		}
		if ( array() === $link ) {
			if ( ! array_key_exists( 'text', $data ) ) {
				return $this->error( 'invalid_block_update', 'Provide text or one allowlisted internal_link payload for the targeted block update.' );
			}

			$text = sanitize_text_field( (string) $data['text'] );
		}

		$before_text = $this->block_text( $target );
		$updated     = $this->replace_block_at_path(
			$blocks,
			$path,
			function ( array $block ) use ( $block_name, $link, $text ): array {
				if ( array() !== $link ) {
					return $this->block_with_internal_link( $block, $block_name, $link );
				}

				return $this->block_with_text( $block, $block_name, $text );
			}
		);

		if ( null === $updated ) {
			return $this->error( 'invalid_block_locator', 'No block exists at the requested locator path.' );
		}

		$content = $this->serialize_parsed_blocks( $updated );
		if ( array() !== $link && ! str_contains( $content, 'href="' . esc_url( (string) $link['url'] ) . '"' ) ) {
			return $this->error( 'internal_link_anchor_not_found', 'The targeted block does not contain an unlinked occurrence of the requested anchor text.' );
		}

		$validation = $this->validated_block_content_argument( array( 'content' => $content ) );
		if ( isset( $validation['error'] ) ) {
			return $validation;
		}

		$locator    = array(
			'path'       => $path,
			'path_label' => implode( '/', $path ),
			'block_name' => $block_name,
		);
		$after_text = array() !== $link ? $before_text : $text;
		$warnings   = array() !== $link
			? array( 'Internal links are inserted only into one targeted paragraph or heading block after duplicate-link validation by the caller.' )
			: array( 'Attribute writes are deferred in this beta slice; only paragraph and heading text replacement is enabled.' );
		$diff       = $this->diff_payload(
			array(
				array() !== $link
					? $this->field_diff( 'block.internal_link', 'not_applied', (string) $link['url'] )
					: $this->field_diff( 'block.text', $before_text, $after_text ),
			)
		);
		$changes    = array(
			array() !== $link
				? $this->change( 'block.internal_link', 'not_applied', (string) $link['url'] )
				: $this->change( 'block.text', $before_text, $after_text ),
		);

		if ( $this->is_dry_run( $data ) ) {
			$response                    = $this->preview_response(
				'content.update_block',
				$data,
				array(
					'type'    => $post->post_type,
					'id'      => $post_id,
					'locator' => $locator,
				),
				$changes,
				$warnings,
				$diff
			);
			$response['post_id']         = $post_id;
			$response['block_locator']   = $locator;
			$response['changed_fields']  = array_values( array_column( array_filter( $changes ), 'field' ) );
			$response['block_before']    = array( 'text' => $before_text );
			$response['block_after']     = array( 'text' => $after_text );
			$response['edit_url']        = get_edit_post_link( $post_id, 'raw' );
			$response['validated_write'] = false;

			return $response;
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $this->error( (string) $result->get_error_code(), $result->get_error_message() );
		}

		return array(
			'status'         => 'updated',
			'action'         => 'content.update_block',
			'post_id'        => $post_id,
			'block_locator'  => $locator,
			'changed_fields' => array_values( array_column( array_filter( $changes ), 'field' ) ),
			'diff'           => $diff,
			'warnings'       => $warnings,
			'edit_url'       => get_edit_post_link( $post_id, 'raw' ),
		);
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
				$this->error(
					'invalid_block_content',
					(string) ( $validation['message'] ?? 'Block content must use registered WordPress blocks and must not include core/html.' )
				),
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
	 * Build deterministic block locator metadata for read responses.
	 *
	 * @param string $content Serialized block content.
	 * @return list<array<string, mixed>>
	 */
	private function block_locators( string $content ): array {
		if ( '' === trim( $content ) || ! function_exists( 'parse_blocks' ) ) {
			return array();
		}

		return $this->flatten_block_locators( $this->normalize_parsed_blocks( parse_blocks( $content ) ) );
	}

	/**
	 * Flatten parsed blocks into path locators.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param array<int>                       $prefix Path prefix.
	 * @phpstan-param list<int> $prefix
	 * @return list<array<string, mixed>>
	 */
	private function flatten_block_locators( array $blocks, array $prefix = array() ): array {
		$items = array();
		foreach ( array_values( $blocks ) as $index => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			$path    = array_merge( $prefix, array( $index ) );
			$items[] = array(
				'path'       => $path,
				'path_label' => implode( '/', $path ),
				'block_name' => (string) $block['blockName'],
				'text'       => $this->block_text( $block ),
			);

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$items = array_merge( $items, $this->flatten_block_locators( $block['innerBlocks'], $path ) );
			}
		}

		return $items;
	}

	/**
	 * Normalize a client locator path.
	 *
	 * @param mixed $locator Locator payload.
	 * @return list<int>
	 */
	private function block_locator_path( mixed $locator ): array {
		if ( is_string( $locator ) ) {
			$locator = array( 'path' => array_map( 'intval', array_filter( explode( '/', $locator ), static fn( string $part ): bool => '' !== $part ) ) );
		}

		if ( ! is_array( $locator ) || ! isset( $locator['path'] ) || ! is_array( $locator['path'] ) ) {
			return array();
		}

		$path = array();
		foreach ( $locator['path'] as $part ) {
			if ( is_int( $part ) && 0 > $part ) {
				return array();
			}

			$index = absint( $part );
			if ( (string) $index !== (string) $part && ! is_int( $part ) ) {
				return array();
			}
			$path[] = $index;
		}

		return array() === $path || count( $path ) > 12 ? array() : $path;
	}

	/**
	 * Return a parsed block by path.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param array<int>                       $path   Locator path.
	 * @phpstan-param list<int> $path
	 * @return array<string, mixed>|null
	 */
	private function block_at_path( array $blocks, array $path ): ?array {
		$current = $blocks;
		$block   = null;
		foreach ( $path as $index ) {
			if ( ! isset( $current[ $index ] ) || ! is_array( $current[ $index ] ) ) {
				return null;
			}

			$block   = $current[ $index ];
			$current = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		}

		return $block;
	}

	/**
	 * Replace a parsed block at path.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Parsed blocks.
	 * @param array<int>                       $path     Locator path.
	 * @param callable                         $callback Replacement callback.
	 * @phpstan-param list<int> $path
	 * @return array<int, array<string, mixed>>|null
	 */
	private function replace_block_at_path( array $blocks, array $path, callable $callback ): ?array {
		$index = array_shift( $path );
		if ( null === $index || ! isset( $blocks[ $index ] ) ) {
			return null;
		}

		if ( array() === $path ) {
			$blocks[ $index ] = $callback( $blocks[ $index ] );
			return $blocks;
		}

		$inner = isset( $blocks[ $index ]['innerBlocks'] ) && is_array( $blocks[ $index ]['innerBlocks'] ) ? $blocks[ $index ]['innerBlocks'] : array();
		$next  = $this->replace_block_at_path( $inner, $path, $callback );
		if ( null === $next ) {
			return null;
		}

		$blocks[ $index ]['innerBlocks'] = $next;
		return $blocks;
	}

	/**
	 * Normalize WordPress parsed blocks into a recursively indexed list.
	 *
	 * @param array<mixed> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_parsed_blocks( array $blocks ): array {
		$normalized = array();
		foreach ( array_values( $blocks ) as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block['innerBlocks'] = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] )
				? $this->normalize_parsed_blocks( $block['innerBlocks'] )
				: array();
			$normalized[]         = $block;
		}

		return $normalized;
	}

	/**
	 * Serialize normalized parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private function serialize_parsed_blocks( array $blocks ): string {
		$serialized = '';
		foreach ( $blocks as $block ) {
			/**
			 * WordPress core defines a precise parsed-block array shape; this tool
			 * only mutates fields that preserve that shape after parse_blocks().
			 *
			 * @var array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: array<int, array<string, mixed>>, innerHTML: string, innerContent: array<int, mixed>} $serializable_block
			 */
			$serializable_block = $block;
			$serialized        .= serialize_block( $serializable_block );
		}

		return $serialized;
	}

	/**
	 * Return plain text from a parsed block.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function block_text( array $block ): string {
		return trim( wp_strip_all_tags( (string) ( $block['innerHTML'] ?? '' ) ) );
	}

	/**
	 * Return a parsed paragraph or heading block with escaped replacement text.
	 *
	 * @param array<string, mixed> $block      Parsed block.
	 * @param string               $block_name Block name.
	 * @param string               $text       Replacement text.
	 * @return array<string, mixed>
	 */
	private function block_with_text( array $block, string $block_name, string $text ): array {
		$escaped = esc_html( $text );
		if ( 'core/heading' === $block_name ) {
			$level = absint( $block['attrs']['level'] ?? 2 );
			$level = max( 1, min( 6, $level ) );
			$html  = sprintf( '<h%d>%s</h%d>', $level, $escaped, $level );
		} else {
			$html = sprintf( '<p>%s</p>', $escaped );
		}

		$block['innerHTML']    = $html;
		$block['innerContent'] = array( $html );

		return $block;
	}

	/**
	 * Return a parsed paragraph or heading block with one allowlisted internal link.
	 *
	 * @param array<string, mixed> $block      Parsed block.
	 * @param string               $block_name Block name.
	 * @param array<string, mixed> $link       Internal-link payload.
	 * @return array<string, mixed>
	 */
	private function block_with_internal_link( array $block, string $block_name, array $link ): array {
		$html   = (string) ( $block['innerHTML'] ?? '' );
		$anchor = (string) $link['anchor_text'];
		$url    = (string) $link['url'];
		$linked = $this->replace_first_unlinked_anchor( $html, $anchor, $url );

		if ( null === $linked ) {
			return $block;
		}

		if ( 'core/heading' === $block_name && ! preg_match( '/<h[1-6][^>]*>.*<\/h[1-6]>/is', $linked ) ) {
			$level  = absint( $block['attrs']['level'] ?? 2 );
			$level  = max( 1, min( 6, $level ) );
			$linked = sprintf( '<h%d>%s</h%d>', $level, $linked, $level );
		} elseif ( 'core/paragraph' === $block_name && ! preg_match( '/<p\b[^>]*>.*<\/p>/is', $linked ) ) {
			$linked = sprintf( '<p>%s</p>', $linked );
		}

		$block['innerHTML']    = wp_kses_post( $linked );
		$block['innerContent'] = array( $block['innerHTML'] );

		return $block;
	}

	/**
	 * Validate a narrow internal-link update payload.
	 *
	 * @param mixed $payload Raw payload.
	 * @return array<string, mixed>
	 */
	private function internal_link_update_payload( mixed $payload ): array {
		if ( null === $payload ) {
			return array();
		}
		if ( ! is_array( $payload ) ) {
			return $this->error( 'invalid_internal_link', 'Internal link updates must provide an anchor_text and URL.' );
		}

		$anchor    = sanitize_text_field( (string) ( $payload['anchor_text'] ?? '' ) );
		$url       = esc_url_raw( (string) ( $payload['url'] ?? '' ) );
		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$link_host = wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $anchor || '' === $url || ! is_string( $site_host ) || ! is_string( $link_host ) || strtolower( $site_host ) !== strtolower( $link_host ) ) {
			return $this->error( 'invalid_internal_link', 'Internal link updates require a non-empty anchor_text and same-site URL.' );
		}

		return array(
			'anchor_text' => $anchor,
			'url'         => $url,
		);
	}

	/**
	 * Replace the first plain-text anchor occurrence that is not already linked.
	 *
	 * @param string $html   Block inner HTML.
	 * @param string $anchor Anchor text.
	 * @param string $url    Target URL.
	 */
	private function replace_first_unlinked_anchor( string $html, string $anchor, string $url ): ?string {
		$pattern = '/' . preg_quote( $anchor, '/' ) . '/iu';
		if ( 1 !== preg_match( $pattern, wp_strip_all_tags( $html ) ) ) {
			return null;
		}

		$parts = preg_split( '/(<a\b[^>]*>.*?<\/a>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		foreach ( $parts as $index => $part ) {
			if ( 1 === $index % 2 || 1 !== preg_match( $pattern, wp_strip_all_tags( $part ) ) ) {
				continue;
			}

			$replacement     = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $anchor ) );
			$parts[ $index ] = preg_replace( $pattern, $replacement, $part, 1 );
			return implode( '', $parts );
		}

		return null;
	}

	/**
	 * Check whether a block is registered.
	 *
	 * @param string $block_name Block name.
	 */
	private function is_registered_block( string $block_name ): bool {
		if ( '' === $block_name || ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return false;
		}

		return null !== \WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
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
	 * Reject schedule requests WordPress would normalize into a publish.
	 *
	 * @param string               $status       Requested post status.
	 * @param array<string, mixed> $date_payload Resolved post date payload.
	 * @param \WP_Post|null        $existing     Existing post for update calls.
	 * @return array<string, mixed>
	 */
	private function future_status_error( string $status, array $date_payload, ?\WP_Post $existing ): array {
		if ( 'future' !== $status ) {
			return array();
		}

		$date_gmt = (string) ( $date_payload['post_date_gmt'] ?? '' );
		if ( '' === $date_gmt && $existing instanceof \WP_Post ) {
			$date_gmt = (string) $existing->post_date_gmt;
		}

		if ( '' === $date_gmt || str_starts_with( $date_gmt, '0000-00-00' ) ) {
			return $this->schedule_date_error( 'Scheduling requires a future date. Pass date with status future.' );
		}

		$scheduled = $this->create_date_from_format( '!Y-m-d H:i:s', $date_gmt, new DateTimeZone( 'UTC' ) );
		if ( ! $scheduled instanceof DateTimeImmutable ) {
			return $this->schedule_date_error( 'Scheduling requires a valid future date. Pass date as site-local time or ISO 8601 with an offset.', $date_gmt );
		}

		$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		if ( $scheduled <= $now ) {
			return $this->schedule_date_error( 'Scheduled posts require date to be in the future relative to the WordPress site timezone.', $date_gmt );
		}

		return array();
	}

	/**
	 * Build a structured scheduling error with enough context for MCP clients.
	 *
	 * @param string $message  Human-readable message.
	 * @param string $date_gmt Resolved GMT date.
	 * @return array<string, mixed>
	 */
	private function schedule_date_error( string $message, string $date_gmt = '' ): array {
		$error = $this->error( 'invalid_schedule_date', $message );

		$site_timezone = $this->site_timezone();
		$site_now      = ( new DateTimeImmutable( 'now', $site_timezone ) )->format( 'Y-m-d H:i:s' );

		$error['site_timezone']     = $site_timezone->getName();
		$error['site_current_time'] = $site_now;
		if ( '' !== $date_gmt ) {
			$error['resolved_date_gmt'] = $date_gmt;
		}

		return $error;
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
