<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Media abilities implementation.
 */
final class MediaAbilities extends AbstractAbilityService {
	public function list_media( array $args ): array {
		return ( new ContentAbilities() )->list_items( array_merge( $args, array( 'post_type' => 'attachment' ) ) );
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
}
