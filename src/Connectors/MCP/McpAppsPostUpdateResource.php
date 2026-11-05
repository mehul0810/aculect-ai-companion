<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Serves the self-contained, versioned post-update result MCP App.
 */
final class McpAppsPostUpdateResource {

	/**
	 * Return the MCP resource descriptor.
	 *
	 * @return array<string, string>
	 */
	public function descriptor(): array {
		return array(
			'uri'         => McpAppsNegotiation::POST_UPDATE_URI,
			'name'        => 'Aculect Post-Update Result',
			'description' => 'A compact visual summary of a completed WordPress content update.',
			'mimeType'    => McpAppsNegotiation::MIME_TYPE,
		);
	}

	/**
	 * Read the packaged HTML resource.
	 *
	 * @return array<string, mixed>
	 */
	public function read(): array {
		$path = dirname( __DIR__, 3 ) . '/assets/mcp-apps/post-update/v1/post-update.html';
		$html = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a fixed plugin-packaged asset, not a remote URL.
		if ( ! is_string( $html ) ) {
			return array(
				'error'   => 'resource_unavailable',
				'message' => 'The post-update result view is unavailable in this plugin package.',
			);
		}

		return array(
			'contents' => array(
				array(
					'uri'      => McpAppsNegotiation::POST_UPDATE_URI,
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
