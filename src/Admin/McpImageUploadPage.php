<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;

/**
 * Same-site, WordPress-authenticated image upload handoff for MCP Apps users.
 *
 * No widget credential or upload grant is accepted. The browser submits directly
 * to this site's admin page, and WordPress owns the resulting attachment.
 */
final class McpImageUploadPage {

	public const PAGE_SLUG      = 'aculect-mcp-image-upload';
	private const NONCE_ACTION  = 'aculect_mcp_image_upload';
	private const FILE_FIELD    = 'aculect_image';
	private const MAX_BYTES     = 5 * 1024 * 1024;
	private const MAX_DIMENSION = 4096;
	private const MAX_PIXELS    = 12 * 1024 * 1024;
	private const TYPES         = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
	);

	/** Register a Media submenu available only to users with upload permission. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
	}

	/** Register the same-site handoff screen. */
	public function register_page(): void {
		if ( ! McpAppsNegotiation::enabled() ) {
			return;
		}
		add_submenu_page(
			'upload.php',
			__( 'Upload an image for Aculect', 'aculect-ai-companion' ),
			__( 'Aculect image upload', 'aculect-ai-companion' ),
			'upload_files',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/** Render the form or the result of a direct browser-to-WordPress POST. */
	public function render(): void {
		if ( ! McpAppsNegotiation::enabled() || ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You cannot upload media on this site.', 'aculect-ai-companion' ), '', array( 'response' => 403 ) );
		}

		$result = null;
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			nocache_headers();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- process_upload verifies the form nonce before inspecting the file.
			$result = $this->process_upload( wp_unslash( $_POST ), $_FILES[ self::FILE_FIELD ] ?? null );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Upload an image', 'aculect-ai-companion' ); ?></h1>
			<p><?php echo esc_html__( 'Upload directly to this site’s WordPress Media Library. No image is sent through Aculect or the assistant.', 'aculect-ai-companion' ); ?></p>
			<?php if ( is_array( $result ) && 'uploaded' === $result['status'] && isset( $result['attachment_id'] ) ) : ?>
				<div class="notice notice-success" role="status"><p>
					<?php echo esc_html__( 'Image uploaded to the Media Library.', 'aculect-ai-companion' ); ?>
					<strong><?php echo esc_html__( 'Attachment ID:', 'aculect-ai-companion' ); ?></strong>
					<code><?php echo esc_html( (string) $result['attachment_id'] ); ?></code>
				</p></div>
				<p><?php echo esc_html__( 'Return to the assistant and provide this attachment ID. A later action must check your permission for the attachment again; this upload does not perform that action.', 'aculect-ai-companion' ); ?></p>
			<?php elseif ( is_array( $result ) ) : ?>
				<div class="notice notice-error" role="alert"><p><?php echo esc_html( $this->error_message( $result['status'] ) ); ?></p></div>
			<?php endif; ?>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<p><label for="aculect-image"><?php echo esc_html__( 'Choose a JPEG, PNG, or WebP image', 'aculect-ai-companion' ); ?></label></p>
				<p><input id="aculect-image" type="file" name="<?php echo esc_attr( self::FILE_FIELD ); ?>" accept="image/jpeg,image/png,image/webp" required></p>
				<p><?php echo esc_html__( 'Maximum 5 MB, 4096 pixels on either side, and 12 megapixels. Your site may enforce a lower upload limit.', 'aculect-ai-companion' ); ?></p>
				<p><button class="button button-primary" type="submit"><?php echo esc_html__( 'Upload to WordPress', 'aculect-ai-companion' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Validate an admin POST and create an unattached Media Library attachment.
	 *
	 * @param array<string, mixed> $input Unslashed POST payload.
	 * @param mixed                $file PHP upload entry.
	 * @return array{status: string, attachment_id?: int}
	 */
	public function process_upload( array $input, mixed $file ): array {
		if ( ! McpAppsNegotiation::enabled() || ! current_user_can( 'upload_files' ) ) {
			return array( 'status' => 'forbidden' );
		}
		$nonce = $input['_wpnonce'] ?? null;
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return array( 'status' => 'invalid_nonce' );
		}
		if ( ! is_array( $file ) || ! $this->valid_upload_shape( $file ) ) {
			return array( 'status' => 'invalid_file' );
		}
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return array( 'status' => 'upload_failed' );
		}
		$limit = min( self::MAX_BYTES, max( 0, (int) wp_max_upload_size() ) );
		if ( 0 === $limit || $file['size'] < 1 || $file['size'] > $limit ) {
			return array( 'status' => 'invalid_size' );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) || filesize( $file['tmp_name'] ) !== $file['size'] ) {
			return array( 'status' => 'invalid_file' );
		}
		if ( ! $this->valid_image( $file['tmp_name'], $file['name'] ) ) {
			return array( 'status' => 'invalid_image' );
		}

		// Let core enforce the site's upload policy, move the PHP upload, and create metadata.
		if ( ! function_exists( 'media_handle_upload' ) && ! function_exists( __NAMESPACE__ . '\\media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		$attachment_id = media_handle_upload( self::FILE_FIELD, 0 );
		if ( is_wp_error( $attachment_id ) || ! is_int( $attachment_id ) || $attachment_id < 1 ) {
			return array( 'status' => 'upload_failed' );
		}

		return array(
			'status'        => 'uploaded',
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * Check the shape of a PHP upload entry.
	 *
	 * @param array<string, mixed> $file PHP upload entry.
	 */
	private function valid_upload_shape( array $file ): bool {
		return is_string( $file['name'] ?? null )
			&& '' !== $file['name']
			&& is_string( $file['tmp_name'] ?? null )
			&& '' !== $file['tmp_name']
			&& is_int( $file['size'] ?? null )
			&& is_int( $file['error'] ?? null );
	}

	/**
	 * Validate extension, actual bytes, image decoding, dimensions, and site MIME policy.
	 *
	 * @param string $path PHP upload temp path.
	 * @param string $filename Original filename.
	 */
	private function valid_image( string $path, string $filename ): bool {
		$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		$mime      = self::TYPES[ $extension ] ?? null;
		if ( null === $mime || ! $this->signature_matches( $path, $mime ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid uploads legitimately emit image-parser warnings.
		$dimensions = @getimagesize( $path );
		if ( ! is_array( $dimensions ) || $dimensions['mime'] !== $mime ) {
			return false;
		}
		$width  = $dimensions[0];
		$height = $dimensions[1];
		if ( 1 > $width || 1 > $height || self::MAX_DIMENSION < $width || self::MAX_DIMENSION < $height || self::MAX_PIXELS < $width * $height ) {
			return false;
		}
		$checked = wp_check_filetype_and_ext( $path, $filename );
		if ( ! is_array( $checked ) || $checked['type'] !== $mime || $checked['ext'] !== $extension || ! empty( $checked['proper_filename'] ) ) {
			return false;
		}

		return ! is_wp_error( wp_get_image_editor( $path ) );
	}

	/**
	 * Read only a fixed signature prefix from the PHP upload temp file.
	 *
	 * @param string $path PHP upload temp path.
	 * @param string $mime Expected image MIME type.
	 */
	private function signature_matches( string $path, string $mime ): bool {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- PHP upload temp file, bounded signature read.
		if ( false === $handle ) {
			return false;
		}
		$prefix = fread( $handle, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Bounded local signature read.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Paired with local read above.
		if ( ! is_string( $prefix ) ) {
			return false;
		}
		return match ( $mime ) {
			'image/jpeg' => str_starts_with( $prefix, "\xff\xd8\xff" ),
			'image/png'  => str_starts_with( $prefix, "\x89PNG\r\n\x1a\n" ),
			'image/webp' => str_starts_with( $prefix, 'RIFF' ) && 'WEBP' === substr( $prefix, 8, 4 ),
			default       => false,
		};
	}

	/**
	 * Map internal failures to actionable, non-sensitive messages.
	 *
	 * @param string $status Internal failure code.
	 */
	private function error_message( string $status ): string {
		return match ( $status ) {
			'forbidden'     => __( 'Your WordPress account cannot upload media on this site.', 'aculect-ai-companion' ),
			'invalid_nonce' => __( 'This upload form expired. Reload the page and try again.', 'aculect-ai-companion' ),
			'invalid_size'  => __( 'The image is empty or exceeds this site’s upload size limit. Choose a smaller image.', 'aculect-ai-companion' ),
			'invalid_image' => __( 'Choose a valid JPEG, PNG, or WebP image within the stated dimensions.', 'aculect-ai-companion' ),
			'upload_failed' => __( 'WordPress could not save the image. Check the Media Library and retry if it is not there.', 'aculect-ai-companion' ),
			default         => __( 'Choose an image and try again.', 'aculect-ai-companion' ),
		};
	}
}
