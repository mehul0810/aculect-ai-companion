<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Media abilities implementation.
 */
final class MediaAbilities extends AbstractAbilityService {
	private const MEDIA_AUDIT_MAX_PER_PAGE           = 100;
	private const MEDIA_AUDIT_MAX_CONTENT_SCAN_LIMIT = 250;
	private const MAX_ENCODED_WHITESPACE_BYTES       = 1048576;

	/**
	 * List media attachments.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
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
		/**
		 * Readable media posts.
		 *
		 * @var list<\WP_Post> $posts
		 */
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
	 * Return bounded read-only media usage intelligence.
	 *
	 * @param array<string, mixed> $args Audit arguments.
	 * @return array<string, mixed>
	 */
	public function audit_usage( array $args ): array {
		if ( ! current_user_can( 'upload_files' ) && ! current_user_can( 'edit_posts' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to audit media.' );
		}

		$page               = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page           = max( 1, min( self::MEDIA_AUDIT_MAX_PER_PAGE, absint( $args['per_page'] ?? 20 ) ) );
		$content_scan_limit = max( 0, min( self::MEDIA_AUDIT_MAX_CONTENT_SCAN_LIMIT, absint( $args['content_scan_limit'] ?? 100 ) ) );
		$status_filter      = sanitize_key( (string) ( $args['status_filter'] ?? 'all' ) );
		$allowed_filters    = array( 'all', 'unused', 'missing_alt', 'attached', 'unattached', 'used' );
		if ( ! in_array( $status_filter, $allowed_filters, true ) ) {
			$status_filter = 'all';
		}

		$attachments = $this->readable_editable_attachments( $args );
		$total       = count( $attachments );
		$usage       = $content_scan_limit > 0 ? $this->attachment_usage_map( $attachments, $content_scan_limit ) : array();
		$items       = array();
		$summary     = array(
			'total_scanned'    => $total,
			'attached'         => 0,
			'unattached'       => 0,
			'used'             => 0,
			'likely_unused'    => 0,
			'missing_alt'      => 0,
			'with_caption'     => 0,
			'with_description' => 0,
		);

		foreach ( $attachments as $attachment ) {
			$item = $this->media_audit_item( $attachment, $usage[ $attachment->ID ] ?? array() );
			$this->increment_media_summary( $summary, $item );
			if ( ! $this->media_item_matches_filter( $item, $status_filter ) ) {
				continue;
			}

			$items[] = $item;
		}

		return array(
			'items'        => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			'total'        => count( $items ),
			'page'         => $page,
			'per_page'     => $per_page,
			'summary'      => $summary,
			'bounds'       => array(
				'attachments_considered' => $total,
				'content_scan_limit'     => $content_scan_limit,
				'content_scan_truncated' => ( $usage['__scanned_posts'] ?? 0 ) >= $content_scan_limit && $content_scan_limit > 0,
			),
			'safety'       => array(
				'read_only'                 => true,
				'deletion_actions_exposed'  => false,
				'private_file_paths_hidden' => true,
				'raw_sql_hidden'            => true,
			),
			'next_actions' => array(
				'Use media_get_item for full metadata before updates.',
				'Review likely_unused items manually before trashing media with existing media tools.',
				'Use media_update_item to add missing alt text when appropriate.',
			),
		);
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
				return $this->error( (string) $result->get_error_code(), $result->get_error_message() );
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

		$download = $guard->download( $url );
		if ( isset( $download['code'] ) ) {
			return $this->error( $download['code'], $download['message'] );
		}

		/**
		 * Successful download payload.
		 *
		 * @var array{tmp: string} $download
		 */
		$tmp            = $download['tmp'];
		$download_error = $guard->validate_downloaded_file( $tmp, $filename );
		if ( null !== $download_error ) {
			wp_delete_file( $tmp );
			return $this->error( $download_error['code'], $download_error['message'] );
		}

		return $this->sideload_validated_file( $tmp, $filename, $data );
	}

	/**
	 * Upload media from base64 or data URL image data.
	 *
	 * @param array<string, mixed> $data Upload fields.
	 * @return array<string, mixed>
	 */
	public function upload_image_data( array $data ): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $this->error( 'forbidden', 'You do not have permission to upload media.' );
		}

		$decoded = $this->decoded_image_data( $data );
		if ( isset( $decoded['error'] ) ) {
			return $decoded;
		}

		$filename = $this->image_data_filename( $data, (string) $decoded['mime_type'] );
		$guard    = new MediaUploadGuard();
		if ( strlen( (string) $decoded['bytes'] ) > $guard->max_bytes() ) {
			return $this->error( 'file_too_large', sprintf( 'The media file must be %d bytes or smaller.', $guard->max_bytes() ) );
		}

		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'media.upload_image_data',
				$data,
				array(
					'type' => 'attachment',
					'id'   => null,
				),
				array_merge(
					array(
						$this->change( 'filename', null, sanitize_file_name( $filename ) ),
						$this->change( 'mime_type', null, (string) $decoded['mime_type'] ),
						$this->change( 'bytes', null, strlen( (string) $decoded['bytes'] ) ),
					),
					$this->media_payload_changes( 'data:image', $filename, $data )
				),
				array( 'Dry run validated the encoded image payload size only; the file was not added to the media library.' )
			);
		}

		$tmp = wp_tempnam( $filename );
		if ( ! is_string( $tmp ) || '' === $tmp ) {
			return $this->error( 'upload_tmp_failed', 'A temporary file could not be created for the image data.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WordPress media sideload requires a local temporary file.
		if ( false === file_put_contents( $tmp, (string) $decoded['bytes'] ) ) {
			wp_delete_file( $tmp );
			return $this->error( 'upload_tmp_failed', 'Image data could not be written to a temporary file.' );
		}

		$download_error = $guard->validate_downloaded_file( $tmp, $filename );
		if ( null !== $download_error ) {
			wp_delete_file( $tmp );
			return $this->error( $download_error['code'], $download_error['message'] );
		}

		return $this->sideload_validated_file( $tmp, $filename, $data );
	}

	/**
	 * Decode image data from base64 or a data URL.
	 *
	 * @param array<string, mixed> $data Upload fields.
	 * @return array{bytes: string, mime_type: string}|array<string, mixed>
	 */
	private function decoded_image_data( array $data ): array {
		$guard              = new MediaUploadGuard();
		$max_encoded_length = 4 * (int) ceil( $guard->max_bytes() / 3 );
		$raw_data_url       = (string) ( $data['data_url'] ?? '' );
		if ( '' !== $raw_data_url ) {
			$comma_position = strpos( $raw_data_url, ',' );
			if ( false === $comma_position || $comma_position > 128 ) {
				return $this->error( 'invalid_image_data', 'Data URL must be a base64-encoded image data URL.' );
			}

			if ( $this->encoded_payload_too_large( $raw_data_url, $max_encoded_length, $comma_position + 1 ) ) {
				return $this->error( 'file_too_large', sprintf( 'The media file must be %d bytes or smaller.', $guard->max_bytes() ) );
			}

			$data_url = trim( $raw_data_url );
			if ( ! preg_match( '#^data:(image/[a-z0-9.+-]+);base64,([A-Za-z0-9+/=\s]+)$#i', $data_url, $matches ) ) {
				return $this->error( 'invalid_image_data', 'Data URL must be a base64-encoded image data URL.' );
			}

			$mime_type = strtolower( sanitize_text_field( (string) $matches[1] ) );
			$base64    = (string) preg_replace( '/\s+/', '', (string) $matches[2] );
		} else {
			$mime_type  = strtolower( sanitize_text_field( (string) ( $data['mime_type'] ?? '' ) ) );
			$raw_base64 = (string) ( $data['data_base64'] ?? $data['image_base64'] ?? '' );
			if ( $this->encoded_payload_too_large( $raw_base64, $max_encoded_length ) ) {
				return $this->error( 'file_too_large', sprintf( 'The media file must be %d bytes or smaller.', $guard->max_bytes() ) );
			}

			$base64 = (string) preg_replace( '/\s+/', '', $raw_base64 );
		}

		if ( '' === $base64 || '' === $mime_type || ! str_starts_with( $mime_type, 'image/' ) ) {
			return $this->error( 'invalid_image_data', 'Provide image data as data_url or data_base64 with an image MIME type.' );
		}

		if ( strlen( $base64 ) > $max_encoded_length ) {
			return $this->error( 'file_too_large', sprintf( 'The media file must be %d bytes or smaller.', $guard->max_bytes() ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding explicit assistant-supplied image payload.
		$bytes = base64_decode( $base64, true );
		if ( false === $bytes || '' === $bytes ) {
			return $this->error( 'invalid_image_data', 'Image data is not valid base64.' );
		}

		return array(
			'bytes'     => $bytes,
			'mime_type' => $mime_type,
		);
	}

	/**
	 * Count encoded bytes without allocating a normalized payload copy.
	 *
	 * @param string $payload            Encoded value or complete data URL.
	 * @param int    $max_encoded_length Maximum non-whitespace bytes.
	 * @param int    $offset             Byte offset where base64 begins.
	 */
	private function encoded_payload_too_large( string $payload, int $max_encoded_length, int $offset = 0 ): bool {
		$encoded_bytes    = 0;
		$whitespace_bytes = 0;
		$length           = strlen( $payload );
		for ( $index = $offset; $index < $length; ++$index ) {
			if ( str_contains( " \t\r\n\f\v", $payload[ $index ] ) ) {
				++$whitespace_bytes;
				if ( $whitespace_bytes > self::MAX_ENCODED_WHITESPACE_BYTES ) {
					return true;
				}
				continue;
			}

			++$encoded_bytes;
			if ( $encoded_bytes > $max_encoded_length ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a safe filename for direct image data uploads.
	 *
	 * @param array<string, mixed> $data Upload fields.
	 * @param string               $mime_type MIME type.
	 */
	private function image_data_filename( array $data, string $mime_type ): string {
		$filename = sanitize_file_name( (string) ( $data['filename'] ?? '' ) );
		if ( '' === $filename ) {
			$filename = 'aculect-ai-companion-generated-image';
		}

		if ( '' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
			return $filename;
		}

		$extension = match ( strtolower( $mime_type ) ) {
			'image/jpeg', 'image/jpg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			default => '',
		};

		return '' === $extension ? $filename : $filename . '.' . $extension;
	}

	/**
	 * Add a validated local temp file to the media library.
	 *
	 * @param string               $tmp      Temporary file path.
	 * @param string               $filename Proposed filename.
	 * @param array<string, mixed> $data     Upload fields.
	 * @return array<string, mixed>
	 */
	private function sideload_validated_file( string $tmp, string $filename, array $data ): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$file = array(
			'name'     => sanitize_file_name( $filename ),
			'tmp_name' => $tmp,
		);

		$post_id       = absint( $data['post_id'] ?? 0 );
		$attachment_id = media_handle_sideload( $file, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			return $this->error( (string) $attachment_id->get_error_code(), $attachment_id->get_error_message() );
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
	 * Return attachments the current user can read and edit.
	 *
	 * @param array<string, mixed> $args Audit arguments.
	 * @return list<\WP_Post>
	 */
	private function readable_editable_attachments( array $args ): array {
		$query = array(
			'post_type'              => 'attachment',
			'post_status'            => $this->statuses_from_args( $args, array( 'inherit' ) ),
			'posts_per_page'         => self::MEDIA_AUDIT_MAX_PER_PAGE * 5,
			'perm'                   => 'readable',
			'orderby'                => 'ID',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		);

		if ( ! empty( $args['type'] ) ) {
			$query['post_mime_type'] = sanitize_text_field( (string) $args['type'] ) . '/*';
		}
		if ( ! empty( $args['mime_type'] ) ) {
			$query['post_mime_type'] = sanitize_text_field( (string) $args['mime_type'] );
		}
		if ( array_key_exists( 'parent_id', $args ) ) {
			$query['post_parent'] = absint( $args['parent_id'] );
		}

		$posts = function_exists( 'get_posts' ) ? get_posts( $query ) : array();

		return array_values(
			array_filter(
				$posts,
				function ( mixed $post ) use ( $query ): bool {
					if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
						return false;
					}
					if ( ! in_array( $post->post_status, (array) $query['post_status'], true ) ) {
						return false;
					}
					if ( isset( $query['post_parent'] ) && (int) $query['post_parent'] !== (int) $post->post_parent ) {
						return false;
					}
					if ( isset( $query['post_mime_type'] ) && ! $this->mime_matches_filter( $post->post_mime_type, (string) $query['post_mime_type'] ) ) {
						return false;
					}

					return current_user_can( 'read_post', (int) $post->ID ) && current_user_can( 'edit_post', (int) $post->ID );
				}
			)
		);
	}

	/**
	 * Return compact content usage for each attachment ID.
	 *
	 * @param array $attachments Attachment posts.
	 * @param int   $limit       Maximum content posts to scan.
	 * @phpstan-param list<\WP_Post> $attachments
	 * @return array<int|string, mixed>
	 */
	private function attachment_usage_map( array $attachments, int $limit ): array {
		$scanned_posts = 0;
		$map           = array();
		if ( array() === $attachments || $limit <= 0 || ! function_exists( 'get_posts' ) ) {
			return array( '__scanned_posts' => 0 );
		}

		$attachments_by_id = array();
		foreach ( $attachments as $attachment ) {
			$attachments_by_id[ (int) $attachment->ID ] = array(
				'url' => (string) wp_get_attachment_url( (int) $attachment->ID ),
			);
		}

		$posts = get_posts(
			array(
				'post_type'              => 'any',
				'post_status'            => self::DEFAULT_POST_STATUSES,
				'posts_per_page'         => $limit,
				'perm'                   => 'readable',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type || ! current_user_can( 'read_post', (int) $post->ID ) ) {
				continue;
			}

			++$scanned_posts;
			foreach ( $attachments_by_id as $attachment_id => $attachment_data ) {
				$matches = $this->attachment_matches_content( $attachment_id, (string) $attachment_data['url'], $post );
				if ( array() === $matches ) {
					continue;
				}

				$map[ $attachment_id ] = array_merge(
					$map[ $attachment_id ] ?? array(),
					array(
						array(
							'post_id'  => (int) $post->ID,
							'title'    => get_the_title( $post ),
							'type'     => $post->post_type,
							'status'   => $post->post_status,
							'link'     => get_permalink( $post ),
							'evidence' => $matches,
						),
					)
				);
			}
		}

		$map['__scanned_posts'] = $scanned_posts;

		return $map;
	}

	/**
	 * Build one media audit item.
	 *
	 * @param \WP_Post $attachment    Attachment post.
	 * @param array    $content_usage Content references.
	 * @phpstan-param list<array<string,mixed>> $content_usage
	 * @return array<string, mixed>
	 */
	private function media_audit_item( \WP_Post $attachment, array $content_usage ): array {
		$attachment_id = (int) $attachment->ID;
		$alt_text      = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$caption       = trim( wp_strip_all_tags( (string) $attachment->post_excerpt ) );
		$description   = trim( wp_strip_all_tags( (string) $attachment->post_content ) );
		$dimensions    = $this->attachment_dimensions( $attachment_id );
		$parent        = $this->attachment_parent_summary( (int) $attachment->post_parent );
		$used          = (int) $attachment->post_parent > 0 || array() !== $content_usage;

		return array(
			'id'              => $attachment_id,
			'title'           => get_the_title( $attachment ),
			'status'          => $attachment->post_status,
			'mime_type'       => $attachment->post_mime_type,
			'source_url'      => wp_get_attachment_url( $attachment_id ),
			'parent'          => $parent,
			'dimensions'      => $dimensions,
			'has_alt_text'    => '' !== $alt_text,
			'has_caption'     => '' !== $caption,
			'has_description' => '' !== $description,
			'is_image'        => function_exists( 'wp_attachment_is_image' ) ? wp_attachment_is_image( $attachment_id ) : str_starts_with( $attachment->post_mime_type, 'image/' ),
			'usage'           => array(
				'attached'           => (int) $attachment->post_parent > 0,
				'likely_used'        => $used,
				'likely_unused'      => ! $used,
				'content_matches'    => count( $content_usage ),
				'content_references' => array_slice( $content_usage, 0, 5 ),
			),
		);
	}

	/**
	 * Return safe attachment dimensions from WordPress metadata.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, int>|null
	 */
	private function attachment_dimensions( int $attachment_id ): ?array {
		if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
			return null;
		}

		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $metadata ) ) {
			return null;
		}

		$metadata = array_merge(
			array(
				'width'  => 0,
				'height' => 0,
			),
			$metadata
		);
		$width    = absint( $metadata['width'] );
		$height   = absint( $metadata['height'] );
		if ( $width <= 0 || $height <= 0 ) {
			return null;
		}

		return array(
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Return compact parent post metadata.
	 *
	 * @param int $parent_id Attachment parent ID.
	 * @return array<string, mixed>|null
	 */
	private function attachment_parent_summary( int $parent_id ): ?array {
		if ( $parent_id <= 0 ) {
			return null;
		}

		$parent = get_post( $parent_id );
		if ( ! $parent instanceof \WP_Post || ! current_user_can( 'read_post', $parent_id ) ) {
			return array( 'id' => $parent_id );
		}

		return array(
			'id'     => $parent_id,
			'title'  => get_the_title( $parent ),
			'type'   => $parent->post_type,
			'status' => $parent->post_status,
			'link'   => get_permalink( $parent ),
		);
	}

	/**
	 * Return evidence that an attachment likely appears in post content.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param string   $source_url    Public attachment URL.
	 * @param \WP_Post $post          Content post.
	 * @return list<string>
	 */
	private function attachment_matches_content( int $attachment_id, string $source_url, \WP_Post $post ): array {
		$content = (string) $post->post_content;
		if ( '' === $content ) {
			return array();
		}

		$matches = array();
		if ( str_contains( $content, 'wp-image-' . $attachment_id ) ) {
			$matches[] = 'wp_image_class';
		}
		if ( str_contains( $content, '"id":' . $attachment_id ) || str_contains( $content, '"id": ' . $attachment_id ) ) {
			$matches[] = 'block_attribute_id';
		}
		if ( '' !== $source_url && str_contains( $content, $source_url ) ) {
			$matches[] = 'source_url';
		}
		if ( (int) get_post_thumbnail_id( $post ) === $attachment_id ) {
			$matches[] = 'featured_media';
		}

		return array_values( array_unique( $matches ) );
	}

	/**
	 * Increment audit summary counts.
	 *
	 * @param array<string, int>   $summary Summary counts.
	 * @param array<string, mixed> $item    Audit item.
	 */
	private function increment_media_summary( array &$summary, array $item ): void {
		$usage = is_array( $item['usage'] ?? null ) ? $item['usage'] : array();

		$summary['attached']         += ! empty( $usage['attached'] ) ? 1 : 0;
		$summary['unattached']       += empty( $usage['attached'] ) ? 1 : 0;
		$summary['used']             += ! empty( $usage['likely_used'] ) ? 1 : 0;
		$summary['likely_unused']    += ! empty( $usage['likely_unused'] ) ? 1 : 0;
		$summary['missing_alt']      += empty( $item['has_alt_text'] ) ? 1 : 0;
		$summary['with_caption']     += ! empty( $item['has_caption'] ) ? 1 : 0;
		$summary['with_description'] += ! empty( $item['has_description'] ) ? 1 : 0;
	}

	/**
	 * Check whether one item matches the requested audit filter.
	 *
	 * @param array<string, mixed> $item   Audit item.
	 * @param string               $filter Filter key.
	 */
	private function media_item_matches_filter( array $item, string $filter ): bool {
		$usage = is_array( $item['usage'] ?? null ) ? $item['usage'] : array();

		return match ( $filter ) {
			'unused' => ! empty( $usage['likely_unused'] ),
			'missing_alt' => empty( $item['has_alt_text'] ),
			'attached' => ! empty( $usage['attached'] ),
			'unattached' => empty( $usage['attached'] ),
			'used' => ! empty( $usage['likely_used'] ),
			default => true,
		};
	}

	/**
	 * Check whether an attachment MIME type matches a tool filter.
	 *
	 * @param string $mime_type MIME type.
	 * @param string $filter    MIME filter.
	 */
	private function mime_matches_filter( string $mime_type, string $filter ): bool {
		if ( str_ends_with( $filter, '/*' ) ) {
			return str_starts_with( $mime_type, substr( $filter, 0, -1 ) );
		}

		return $mime_type === $filter;
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
