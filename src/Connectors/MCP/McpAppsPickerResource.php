<?php
/**
 * Serves negotiated media and content picker MCP Apps.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Exposes fixed, read-only picker assets without injecting site data. */
final class McpAppsPickerResource {
	private string $kind;

	/**
	 * Initialize a picker resource for the fixed media or content view.
	 *
	 * @param string $kind Either media or content.
	 */
	public function __construct( string $kind ) {
		$this->kind = 'media' === $kind ? 'media' : 'content';
	}

	/**
	 * Return the negotiated MCP resource descriptor.
	 *
	 * @return array{uri: string, name: string, description: string, mimeType: string}
	 */
	public function descriptor(): array {
		$uri = $this->uri();
		return array(
			'uri'         => $uri,
			'name'        => 'Aculect ' . ucfirst( $this->kind ) . ' Picker',
			'description' => 'A read-only selector for existing WordPress ' . $this->kind . '. Selection does not change site data.',
			'mimeType'    => McpAppsNegotiation::MIME_TYPE,
		);
	}

	/**
	 * Read the generated, single-file widget asset.
	 *
	 * @return array<string, mixed>
	 */
	public function read(): array {
		$path = dirname( __DIR__, 3 ) . '/assets/mcp-apps/' . $this->kind . '-picker/v1/' . $this->kind . '-picker.html';
		$html = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a fixed plugin-packaged asset, not a remote URL.
		if ( ! is_string( $html ) ) {
			return array(
				'error'   => 'resource_unavailable',
				'message' => 'The ' . $this->kind . ' picker view is unavailable in this plugin package.',
			);
		}
		$origin = McpAppsPickerOrigin::site();
		if ( 'media' === $this->kind ) {
			$json_placeholder = '__ACULECT_MEDIA_ORIGIN_JSON__';
			$csp_placeholder  = '__ACULECT_MEDIA_ORIGIN_CSP__';
			$origin_json      = wp_json_encode( $origin, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			if ( '' === $origin || 1 !== substr_count( $html, $json_placeholder ) || 1 !== substr_count( $html, $csp_placeholder ) || ! is_string( $origin_json ) ) {
				return array(
					'error'   => 'resource_unavailable',
					'message' => 'The media picker origin could not be validated for safe previews.',
				);
			}
			$html = str_replace( array( $json_placeholder, $csp_placeholder ), array( $origin_json, esc_attr( $origin ) ), $html );
			if ( str_contains( $html, $json_placeholder ) || str_contains( $html, $csp_placeholder ) ) {
				return array(
					'error'   => 'resource_unavailable',
					'message' => 'The media picker origin could not be safely applied.',
				);
			}
		}

		return array(
			'contents' => array(
				array(
					'uri'      => $this->uri(),
					'mimeType' => McpAppsNegotiation::MIME_TYPE,
					'text'     => $html,
					'_meta'    => array(
						'ui' => array(
							'csp'           => array(
								'connectDomains'  => array(),
								'resourceDomains' => 'media' === $this->kind ? array( $origin ) : array(),
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

	private function uri(): string {
		return 'media' === $this->kind ? McpAppsNegotiation::MEDIA_PICKER_URI : McpAppsNegotiation::CONTENT_PICKER_URI;
	}
}
