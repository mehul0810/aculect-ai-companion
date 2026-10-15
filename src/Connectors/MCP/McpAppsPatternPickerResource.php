<?php
/**
 * Serves the self-contained, versioned pattern picker MCP App.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Exposes the packaged pattern picker only to MCP Apps clients.
 */
final class McpAppsPatternPickerResource {
	/**
	 * Return the negotiated MCP resource descriptor.
	 *
	 * @return array<string, string>
	 */
	public function descriptor(): array {
		return array(
			'uri'         => McpAppsNegotiation::PATTERN_PICKER_URI,
			'name'        => 'Aculect Pattern Picker',
			'description' => 'A read-only, compatibility-aware selector for registered WordPress block patterns.',
			'mimeType'    => McpAppsNegotiation::MIME_TYPE,
		);
	}

	/**
	 * Read the generated, single-file widget asset.
	 *
	 * @return array<string, mixed>
	 */
	public function read(): array {
		$path = dirname( __DIR__, 3 ) . '/assets/mcp-apps/pattern-picker/v1/pattern-picker.html';
		$html = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a fixed plugin-packaged asset, not a remote URL.
		if ( ! is_string( $html ) ) {
			return array(
				'error'   => 'resource_unavailable',
				'message' => 'The pattern picker view is unavailable in this plugin package.',
			);
		}

		return array(
			'contents' => array(
				array(
					'uri'      => McpAppsNegotiation::PATTERN_PICKER_URI,
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
