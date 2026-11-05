<?php
/**
 * Adds bounded, same-site preview metadata to authorized media picker items.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Builds safe image previews without changing the media ability contract. */
final class MediaPickerMetadata {
	/**
	 * Add thumbnail dimensions and URL to a bounded list of readable items.
	 *
	 * @param list<array<string, mixed>> $items Compact media list results.
	 * @return list<array<string, mixed>>
	 */
	public function add_thumbnails( array $items ): array {
		$origin = McpAppsPickerOrigin::site();
		if ( '' === $origin ) {
			return $items;
		}

		foreach ( $items as &$item ) {
			$id = absint( $item['id'] ?? 0 );
			if ( $id < 1 || 'image/' !== substr( (string) ( $item['mime_type'] ?? '' ), 0, 6 ) ) {
				continue;
			}

			$thumbnail = wp_get_attachment_image_src( $id, 'medium' );
			if ( ! is_array( $thumbnail ) || empty( $thumbnail[0] ) || empty( $thumbnail[1] ) || empty( $thumbnail[2] ) ) {
				continue;
			}
			if ( McpAppsPickerOrigin::from_url( (string) $thumbnail[0] ) !== $origin ) {
				continue;
			}

			$item['thumbnail'] = array(
				'url'    => esc_url_raw( (string) $thumbnail[0] ),
				'width'  => (int) $thumbnail[1],
				'height' => (int) $thumbnail[2],
			);
		}
		unset( $item );

		return $items;
	}
}
