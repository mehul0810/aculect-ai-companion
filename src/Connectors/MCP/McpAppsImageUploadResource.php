<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Provides a credential-free human handoff to the native WordPress uploader.
 */
final class McpAppsImageUploadResource {

	public const URI = McpAppsNegotiation::IMAGE_UPLOAD_URI;

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
		$handoff = ( new MediaUploadHandoff() )->result();
		if ( isset( $handoff['error'] ) ) {
			return $handoff;
		}
		$url = (string) $handoff['url'];

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
}
