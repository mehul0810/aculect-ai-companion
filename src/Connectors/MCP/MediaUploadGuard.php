<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Bounds assistant-triggered media downloads before WordPress sideloads them.
 */
final class MediaUploadGuard {

	private const DEFAULT_MAX_BYTES         = 10485760;
	private const DEFAULT_PREFLIGHT_TIMEOUT = 5;
	private const DEFAULT_DOWNLOAD_TIMEOUT  = 15;

	/**
	 * Return the configured maximum assistant media download size.
	 */
	public function max_bytes(): int {
		$max_bytes = (int) apply_filters( 'aculect_ai_companion_media_upload_max_bytes', self::DEFAULT_MAX_BYTES );

		return max( 1024, $max_bytes );
	}

	/**
	 * Return allowed MIME types for assistant media uploads.
	 *
	 * @return array<string, string>
	 */
	public function allowed_mime_types(): array {
		$allowed = function_exists( 'get_allowed_mime_types' ) ? get_allowed_mime_types() : array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'pdf'          => 'application/pdf',
		);

		$filtered = apply_filters( 'aculect_ai_companion_media_upload_allowed_mime_types', $allowed );

		return is_array( $filtered ) ? array_map( 'strval', $filtered ) : $allowed;
	}

	/**
	 * Inspect remote headers when the origin supports it.
	 *
	 * A failed HEAD request is not fatal because some media hosts block HEAD
	 * while still serving safe GET requests. The streamed GET path remains size
	 * capped and MIME checked after download.
	 *
	 * @param string $url      Public media URL.
	 * @param string $filename Proposed sideload filename.
	 * @return array{code: string, message: string}|null
	 */
	public function preflight( string $url, string $filename ): ?array {
		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'             => (int) apply_filters( 'aculect_ai_companion_media_upload_preflight_timeout', self::DEFAULT_PREFLIGHT_TIMEOUT ),
				'redirection'         => 3,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 1024,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 400 ) {
			return null;
		}

		return $this->validate_preflight_headers(
			$this->headers_from_response( $response ),
			$filename,
			$this->max_bytes(),
			$this->allowed_mime_types()
		);
	}

	/**
	 * Download a remote media file with a hard response-size cap.
	 *
	 * @param string $url Public media URL.
	 * @return array{tmp: string}|array{code: string, message: string}
	 */
	public function download( string $url ): array {
		$tmp = wp_tempnam( $url );
		if ( ! is_string( $tmp ) || '' === $tmp ) {
			return array(
				'code'    => 'upload_tmp_failed',
				'message' => 'A temporary file could not be created for the media download.',
			);
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => (int) apply_filters( 'aculect_ai_companion_media_upload_download_timeout', self::DEFAULT_DOWNLOAD_TIMEOUT ),
				'redirection'         => 3,
				'reject_unsafe_urls'  => true,
				'stream'              => true,
				'filename'            => $tmp,
				'limit_response_size' => $this->max_bytes() + 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return array(
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			wp_delete_file( $tmp );
			return array(
				'code'    => 'download_failed',
				'message' => 'The media URL did not return a successful response.',
			);
		}

		if ( is_file( $tmp ) && filesize( $tmp ) > $this->max_bytes() ) {
			wp_delete_file( $tmp );
			return $this->error_file_too_large( $this->max_bytes() );
		}

		return array( 'tmp' => $tmp );
	}

	/**
	 * Validate headers returned by a remote media URL.
	 *
	 * @param array<string, string> $headers            Response headers.
	 * @param string                $filename           Proposed sideload filename.
	 * @param int                   $max_bytes          Maximum allowed bytes.
	 * @param array<string, string> $allowed_mime_types Allowed MIME types.
	 * @return array{code: string, message: string}|null
	 */
	public function validate_preflight_headers( array $headers, string $filename, int $max_bytes, array $allowed_mime_types ): ?array {
		$content_length = $headers['content-length'] ?? '';
		if ( '' !== $content_length && is_numeric( $content_length ) && (int) $content_length > $max_bytes ) {
			return $this->error_file_too_large( $max_bytes );
		}

		$content_type = $this->normalize_content_type( $headers['content-type'] ?? '' );
		if ( '' !== $content_type && ! $this->is_generic_content_type( $content_type ) && ! $this->is_allowed_content_type( $content_type, $allowed_mime_types ) ) {
			return array(
				'code'    => 'unsupported_media_type',
				'message' => 'The media URL returned a content type that is not allowed for uploads.',
			);
		}

		if ( '' !== $content_type && $this->is_generic_content_type( $content_type ) && ! $this->is_allowed_filename( $filename, $allowed_mime_types ) ) {
			return array(
				'code'    => 'unsupported_media_type',
				'message' => 'The media URL does not provide an allowed file type.',
			);
		}

		return null;
	}

	/**
	 * Validate the downloaded file against size and MIME/extension limits.
	 *
	 * @param string $tmp      Downloaded temp file.
	 * @param string $filename Proposed sideload filename.
	 * @return array{code: string, message: string}|null
	 */
	public function validate_downloaded_file( string $tmp, string $filename ): ?array {
		if ( ! is_file( $tmp ) ) {
			return array(
				'code'    => 'download_failed',
				'message' => 'The media file could not be downloaded.',
			);
		}

		$max_bytes = $this->max_bytes();
		if ( filesize( $tmp ) > $max_bytes ) {
			return $this->error_file_too_large( $max_bytes );
		}

		$allowed_mime_types = $this->allowed_mime_types();
		if ( function_exists( 'wp_check_filetype_and_ext' ) ) {
			$filetype = wp_check_filetype_and_ext( $tmp, $filename, $allowed_mime_types );
			if ( empty( $filetype['type'] ) || ! $this->is_allowed_content_type( (string) $filetype['type'], $allowed_mime_types ) ) {
				return array(
					'code'    => 'unsupported_media_type',
					'message' => 'The downloaded file type is not allowed for uploads.',
				);
			}

			return null;
		}

		return $this->is_allowed_filename( $filename, $allowed_mime_types ) ? null : array(
			'code'    => 'unsupported_media_type',
			'message' => 'The downloaded file type is not allowed for uploads.',
		);
	}

	/**
	 * Normalize response headers into lower-case string values.
	 *
	 * @param array<string, mixed> $response HTTP response.
	 * @return array<string, string>
	 */
	private function headers_from_response( array $response ): array {
		$headers = wp_remote_retrieve_headers( $response );
		$items   = is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers;
		$result  = array();

		foreach ( $items as $name => $value ) {
			$result[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		return $result;
	}

	/**
	 * Check whether a content type is in the allowed MIME list.
	 *
	 * @param string                $content_type       Content type.
	 * @param array<string, string> $allowed_mime_types Allowed MIME types.
	 */
	private function is_allowed_content_type( string $content_type, array $allowed_mime_types ): bool {
		$content_type = $this->normalize_content_type( $content_type );

		return in_array( $content_type, array_map( array( $this, 'normalize_content_type' ), array_values( $allowed_mime_types ) ), true );
	}

	/**
	 * Check whether a filename has an allowed extension.
	 *
	 * @param string                $filename           Proposed filename.
	 * @param array<string, string> $allowed_mime_types Allowed MIME types keyed by extension pattern.
	 */
	private function is_allowed_filename( string $filename, array $allowed_mime_types ): bool {
		$extension = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( '' === $extension ) {
			return false;
		}

		foreach ( array_keys( $allowed_mime_types ) as $extension_pattern ) {
			$extensions = explode( '|', (string) $extension_pattern );
			if ( in_array( $extension, $extensions, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalize a Content-Type header value.
	 *
	 * @param string $content_type Raw Content-Type header.
	 */
	private function normalize_content_type( string $content_type ): string {
		$parts = explode( ';', strtolower( trim( $content_type ) ) );

		return trim( $parts[0] ?? '' );
	}

	/**
	 * Check whether a content type is too generic to enforce before download.
	 *
	 * @param string $content_type Content type.
	 */
	private function is_generic_content_type( string $content_type ): bool {
		return in_array(
			$this->normalize_content_type( $content_type ),
			array( 'application/octet-stream', 'binary/octet-stream' ),
			true
		);
	}

	/**
	 * Build a deterministic file-too-large error.
	 *
	 * @param int $max_bytes Maximum allowed bytes.
	 * @return array{code: string, message: string}
	 */
	private function error_file_too_large( int $max_bytes ): array {
		return array(
			'code'    => 'file_too_large',
			'message' => sprintf(
				'The media file is larger than the allowed %s byte limit.',
				function_exists( 'number_format_i18n' ) ? number_format_i18n( $max_bytes ) : number_format( $max_bytes )
			),
		);
	}
}
