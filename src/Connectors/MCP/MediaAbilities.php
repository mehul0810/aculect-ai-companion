<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Media abilities implementation.
 */
final class MediaAbilities extends AbstractAbilityService {
	public function list_media( array $args ): array {
		if ( ! current_user_can( 'upload_files' ) && ! current_user_can( 'edit_posts' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to list media.' );
		}

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$query    = array(
			'post_type'      => 'attachment',
			'post_status'    => $this->statuses_from_args( $args, array( 'inherit' ) ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'no_found_rows'  => false,
			'perm'           => 'readable',
		);

		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( (string) $args['search'] );
		}

		if ( ! empty( $args['mime_type'] ) ) {
			$query['post_mime_type'] = sanitize_text_field( (string) $args['mime_type'] );
		} elseif ( ! empty( $args['type'] ) ) {
			$query['post_mime_type'] = sanitize_text_field( (string) $args['type'] ) . '/*';
		}

		if ( array_key_exists( 'post_id', $args ) ) {
			$query['post_parent'] = absint( $args['post_id'] );
		} elseif ( array_key_exists( 'parent_id', $args ) ) {
			$query['post_parent'] = absint( $args['parent_id'] );
		}

		if ( ! empty( $args['author'] ) ) {
			$query['author'] = absint( $args['author'] );
		}

		$date_query = $this->date_query( $args );
		if ( array() !== $date_query ) {
			$query['date_query'] = $date_query;
		}

		$result = new \WP_Query( $query );
		$posts  = array_values(
			array_filter(
				$result->posts,
				static fn( $post ): bool => $post instanceof \WP_Post && current_user_can( 'read_post', $post->ID )
			)
		);
		$mapper = 'full' === $this->collection_context( $args ) ? 'map_post' : 'map_post_compact';

		return array(
			'items'    => array_map( array( $this, $mapper ), $posts ),
			'total'    => (int) $result->found_posts,
			'page'     => $page,
			'per_page' => $per_page,
			'context'  => $this->collection_context( $args ),
		);
	}

	/**
	 * Read one media attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public function get_media( int $attachment_id ): array {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return $this->error( 'not_found', 'Media item not found.' );
		}

		if ( ! current_user_can( 'read_post', $attachment_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to read this media item.' );
		}

		return $this->map_post( $attachment );
	}

	/**
	 * Update media metadata and attachment relationship.
	 *
	 * @param array<string, mixed> $data Media fields.
	 * @return array<string, mixed>
	 */
	public function update_media( array $data ): array {
		$attachment_id = absint( $data['id'] ?? 0 );
		$attachment    = get_post( $attachment_id );
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return $this->error( 'not_found', 'Media item not found.' );
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to update this media item.' );
		}

		$update = array( 'ID' => $attachment_id );
		if ( array_key_exists( 'title', $data ) ) {
			$update['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( array_key_exists( 'caption', $data ) ) {
			$update['post_excerpt'] = wp_kses_post( (string) $data['caption'] );
		}
		if ( array_key_exists( 'description', $data ) ) {
			$update['post_content'] = wp_kses_post( (string) $data['description'] );
		}
		if ( array_key_exists( 'slug', $data ) ) {
			$update['post_name'] = sanitize_title( (string) $data['slug'] );
		}
		if ( array_key_exists( 'post_id', $data ) ) {
			$post_parent = absint( $data['post_id'] );
			if ( $post_parent > 0 ) {
				$parent = get_post( $post_parent );
				if ( ! $parent instanceof \WP_Post || ! current_user_can( 'edit_post', $post_parent ) ) {
					return $this->error( 'invalid_parent', 'Attachment parent post was not found or cannot be edited.' );
				}
			}

			$update['post_parent'] = $post_parent;
		}

		$alt_text = null;
		if ( array_key_exists( 'alt_text', $data ) ) {
			$alt_text = sanitize_text_field( (string) $data['alt_text'] );
		}

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'media.update_item',
				$data,
				array(
					'type' => 'attachment',
					'id'   => $attachment_id,
				),
				array_merge(
					$this->media_update_changes( $attachment, $update ),
					null !== $alt_text ? array( $this->change( 'alt_text', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ), $alt_text ) ) : array()
				)
			);
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $this->error( $result->get_error_code(), $result->get_error_message() );
			}
		}

		if ( null !== $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		return $this->get_media( $attachment_id );
	}

	/**
	 * Move an attachment to the trash.
	 *
	 * @param array<string, mixed> $data Media fields.
	 * @return array<string, mixed>
	 */
	public function delete_media( array $data ): array {
		$attachment_id = absint( $data['id'] ?? 0 );
		$attachment    = get_post( $attachment_id );
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return $this->error( 'not_found', 'Media item not found.' );
		}

		if ( ! current_user_can( 'delete_post', $attachment_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to delete this media item.' );
		}

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'media.delete_item',
				$data,
				array(
					'type' => 'attachment',
					'id'   => $attachment_id,
				),
				array( $this->change( 'status', $attachment->post_status, 'trash' ) ),
				array( 'Media is moved to trash when possible; permanent deletion is not exposed by this tool.' )
			);
		}

		$result = wp_trash_post( $attachment_id );
		if ( ! $result instanceof \WP_Post ) {
			return $this->error( 'media_trash_failed', 'Media item could not be moved to trash.' );
		}

		return array(
			'id'      => $attachment_id,
			'status'  => 'trash',
			'message' => 'Media item moved to trash.',
		);
	}

	/**
	 * Safely rename an uploaded attachment file on disk.
	 *
	 * @param array<string, mixed> $data Media fields.
	 * @return array<string, mixed>
	 */
	public function rename_media_file( array $data ): array {
		$attachment_id = absint( $data['id'] ?? 0 );
		$attachment    = get_post( $attachment_id );
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type ) {
			return $this->error( 'not_found', 'Media item not found.' );
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return $this->error( 'forbidden', 'You do not have permission to rename this media item.' );
		}

		$current_file = get_attached_file( $attachment_id );
		if ( ! is_string( $current_file ) || '' === $current_file || ! file_exists( $current_file ) ) {
			return $this->error( 'file_not_found', 'Attached media file could not be found on disk.' );
		}

		$uploads = wp_get_upload_dir();
		$basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		$current = wp_normalize_path( $current_file );
		if ( '' === $basedir || ! str_starts_with( $current, trailingslashit( $basedir ) ) ) {
			return $this->error( 'unsupported_file_location', 'Only files inside the WordPress uploads directory can be renamed.' );
		}

		$rename = $this->media_rename_plan( $attachment_id, $current, (string) ( $data['filename'] ?? '' ) );
		if ( isset( $rename['error'] ) ) {
			return $rename;
		}

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'media.rename_file',
				$data,
				array(
					'type' => 'attachment',
					'id'   => $attachment_id,
				),
				array( $this->change( 'filename', basename( $current ), basename( $rename['main']['to'] ) ) ),
				array( 'Physical file rename will update attachment metadata and generated image size filenames when possible.' )
			);
		}

		$renamed = array();
		foreach ( $rename['operations'] as $operation ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Renaming an existing local uploads file in an authenticated MCP request.
			if ( ! rename( $operation['from'], $operation['to'] ) ) {
				foreach ( array_reverse( $renamed ) as $done ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Roll back the local uploads file if a later rename operation fails.
					rename( $done['to'], $done['from'] );
				}

				return $this->error( 'file_rename_failed', 'Media file could not be renamed on disk.' );
			}

			$renamed[] = $operation;
		}

		update_attached_file( $attachment_id, $rename['main']['to'] );
		if ( is_array( $rename['metadata'] ) ) {
			wp_update_attachment_metadata( $attachment_id, $rename['metadata'] );
		}

		return $this->get_media( $attachment_id );
	}

	/**
	 * Sideload media from a public HTTP(S) URL.
	 *
	 * @param array<string, mixed> $data Upload fields.
	 * @return array<string, mixed>
	 */
	public function upload_media( array $data ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to upload media.' );
		}

		$url = esc_url_raw( (string) ( $data['url'] ?? '' ) );
		if ( ! $this->is_public_http_url( $url ) ) {
			return $this->error( 'invalid_url', 'A public HTTP or HTTPS media URL is required.' );
		}

		$filename = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $filename || '.' === $filename || '..' === $filename ) {
			$filename = 'aculect-ai-companion-media-upload';
		}

		$guard           = new MediaUploadGuard();
		$preflight_error = $guard->preflight( $url, $filename );
		if ( null !== $preflight_error ) {
			return $this->error( $preflight_error['code'], $preflight_error['message'] );
		}

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'media.upload_item',
				$data,
				array(
					'type' => 'attachment',
					'id'   => null,
				),
				$this->media_payload_changes( $url, $filename, $data ),
				array( 'Dry run validated the URL preflight only; the file was not downloaded or added to the media library.' )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$download = $guard->download( $url );
		if ( isset( $download['code'] ) ) {
			return $this->error( $download['code'], $download['message'] );
		}

		$tmp            = $download['tmp'];
		$download_error = $guard->validate_downloaded_file( $tmp, $filename );
		if ( null !== $download_error ) {
			wp_delete_file( $tmp );
			return $this->error( $download_error['code'], $download_error['message'] );
		}

		$file = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp,
		);

		$post_id       = absint( $data['post_id'] ?? 0 );
		$attachment_id = media_handle_sideload( $file, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $this->error( $attachment_id->get_error_code(), $attachment_id->get_error_message() );
		}

		$update = array( 'ID' => (int) $attachment_id );
		if ( isset( $data['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( isset( $data['caption'] ) ) {
			$update['post_excerpt'] = wp_kses_post( (string) $data['caption'] );
		}
		if ( isset( $data['description'] ) ) {
			$update['post_content'] = wp_kses_post( (string) $data['description'] );
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		if ( isset( $data['alt_text'] ) ) {
			update_post_meta( (int) $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) $data['alt_text'] ) );
		}

		$attachment = get_post( (int) $attachment_id );
		return $attachment instanceof \WP_Post ? $this->map_post( $attachment ) : array( 'id' => (int) $attachment_id );
	}

	/**
	 * Build a safe rename plan for an attachment and generated sizes.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $current       Current absolute file path.
	 * @param string $filename      Requested filename.
	 * @return array<string, mixed>
	 */
	private function media_rename_plan( int $attachment_id, string $current, string $filename ): array {
		$filename = sanitize_file_name( $filename );
		if ( '' === $filename ) {
			return $this->error( 'invalid_filename', 'A valid target filename is required.' );
		}

		$current_extension = strtolower( (string) pathinfo( $current, PATHINFO_EXTENSION ) );
		$target_extension  = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( '' === $target_extension && '' !== $current_extension ) {
			$filename        .= '.' . $current_extension;
			$target_extension = $current_extension;
		}

		if ( $current_extension !== $target_extension ) {
			return $this->error( 'invalid_filename_extension', 'Physical media rename must keep the original file extension.' );
		}

		$directory = dirname( $current );
		$target    = wp_normalize_path( $directory . '/' . $filename );
		if ( $target === $current ) {
			return $this->error( 'filename_unchanged', 'The requested filename matches the current filename.' );
		}

		if ( file_exists( $target ) ) {
			return $this->error( 'filename_exists', 'A file with that name already exists in the media directory.' );
		}

		$operations = array(
			array(
				'from' => $current,
				'to'   => $target,
			),
		);
		$metadata   = wp_get_attachment_metadata( $attachment_id );
		$metadata   = is_array( $metadata ) ? $metadata : array();
		$old_base   = (string) pathinfo( $current, PATHINFO_FILENAME );
		$new_base   = (string) pathinfo( $target, PATHINFO_FILENAME );
		$meta_dir   = isset( $metadata['file'] ) ? dirname( (string) $metadata['file'] ) : '';

		if ( isset( $metadata['file'] ) ) {
			$metadata['file'] = ( '.' === $meta_dir ? '' : trailingslashit( $meta_dir ) ) . basename( $target );
		}

		$sizes = $metadata['sizes'] ?? array();
		if ( is_array( $sizes ) ) {
			foreach ( $sizes as $size => $size_data ) {
				if ( ! is_array( $size_data ) || empty( $size_data['file'] ) || ! is_string( $size_data['file'] ) ) {
					continue;
				}

				$old_size_file = $size_data['file'];
				if ( ! str_starts_with( $old_size_file, $old_base . '-' ) ) {
					continue;
				}

				$new_size_file = $new_base . substr( $old_size_file, strlen( $old_base ) );
				$old_size_path = wp_normalize_path( $directory . '/' . $old_size_file );
				$new_size_path = wp_normalize_path( $directory . '/' . $new_size_file );
				if ( file_exists( $old_size_path ) && ! file_exists( $new_size_path ) ) {
					$operations[] = array(
						'from' => $old_size_path,
						'to'   => $new_size_path,
					);
				}

				$metadata['sizes'][ $size ]['file'] = $new_size_file;
			}
		}

		return array(
			'main'       => $operations[0],
			'operations' => $operations,
			'metadata'   => $metadata,
		);
	}
}
