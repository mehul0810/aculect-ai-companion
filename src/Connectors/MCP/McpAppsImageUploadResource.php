<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Admin\McpImageUploadPage;

/**
 * Provides a credential-free human handoff to the native WordPress uploader.
 */
final class McpAppsImageUploadResource {

	public const URI = 'ui://aculect/image-upload/v1.html';

	/**
	 * Return the MCP resource descriptor.
	 *
	 * @return array<string, string>
	 */
	public function descriptor(): array {
		return array(
			'uri'         => self::URI,
			'name'        => 'Upload an image to WordPress',
			'description' => 'Opens a same-site WordPress admin upload screen; the human receives an attachment ID to provide afterward.',
			'mimeType'    => McpAppsNegotiation::MIME_TYPE,
		);
	}

	/**
	 * Read the widget, exposing only the same-site authenticated admin page URL.
	 *
	 * @return array<string, mixed>
	 */
	public function read(): array {
		if ( ! current_user_can( 'upload_files' ) ) {
			return array(
				'error'   => 'forbidden',
				'message' => 'The connected WordPress user cannot upload media.',
			);
		}

		$url = admin_url( 'admin.php?page=' . McpImageUploadPage::PAGE_SLUG );
		if ( ! is_string( $url ) || ! $this->is_secure_admin_url( $url ) ) {
			return array(
				'error'   => 'handoff_unavailable',
				'message' => 'A secure WordPress Media Library upload handoff is unavailable.',
			);
		}

		$path = dirname( __DIR__, 3 ) . '/assets/mcp-apps/image-upload/v1/image-upload.html';
		$html = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a fixed plugin-packaged asset, not a remote URL.
		if ( ! is_string( $html ) || 1 !== substr_count( $html, '__ACULECT_UPLOAD_HANDOFF_URL_JSON__' ) ) {
			return array(
				'error'   => 'resource_unavailable',
				'message' => 'The image upload handoff is unavailable in this plugin package.',
			);
		}

		$url_json = wp_json_encode(
			$url,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
		if ( ! is_string( $url_json ) ) {
			return array(
				'error'   => 'handoff_unavailable',
				'message' => 'A secure WordPress Media Library upload handoff is unavailable.',
			);
		}

		$html = str_replace( '__ACULECT_UPLOAD_HANDOFF_URL_JSON__', $url_json, $html );

		return array(
			'contents' => array(
				array(
					'uri'      => self::URI,
					'mimeType' => McpAppsNegotiation::MIME_TYPE,
					'text'     => $html,
					'_meta'    => array(
						'ui' => array(
							'csp'           => array(
								'connectDomains'  => array(),
								'resourceDomains' => array(),
								'frameDomains'    => array(),
								'baseUriDomains'  => array(),
							),
							'prefersBorder' => true,
						),
					),
				),
			),
		);
	}

	/**
	 * Accept HTTPS only; the widget bridge refuses insecure external navigation.
	 *
	 * @param string $url Candidate admin URL.
	 */
	private function is_secure_admin_url( string $url ): bool {
		$parts      = wp_parse_url( $url );
		$site_parts = wp_parse_url( site_url( '/' ) );

		return is_array( $parts )
			&& is_array( $site_parts )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& strtolower( (string) $parts['host'] ) === strtolower( (string) ( $site_parts['host'] ?? '' ) )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] );
	}
}
