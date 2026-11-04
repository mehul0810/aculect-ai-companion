<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Serves the static, self-contained Site Audit MCP App resource.
 */
final class McpAppsSiteAuditResource {

	/**
	 * Return the UI resource descriptor.
	 *
	 * @return array<string, string>
	 */
	public function descriptor(): array {
		return array(
			'uri'         => McpAppsNegotiation::SITE_AUDIT_URI,
			'name'        => 'Aculect Site Audit',
			'description' => 'Read-only audit evidence, severity, and recommended next steps.',
			'mimeType'    => McpAppsNegotiation::MIME_TYPE,
		);
	}

	/**
	 * Return the HTML document through the standard MCP resource shape.
	 *
	 * @return array<string, mixed>
	 */
	public function read(): array {
		$path = dirname( __DIR__, 3 ) . '/assets/mcp-apps/site-audit/v1/site-audit.html';
		$html = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a fixed plugin-packaged asset, not a remote URL.
		if ( ! is_string( $html ) ) {
			return array(
				'error'   => 'resource_unavailable',
				'message' => 'The Site Audit view is unavailable in this plugin package.',
			);
		}

		return array(
			'contents' => array(
				array(
					'uri'      => McpAppsNegotiation::SITE_AUDIT_URI,
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
