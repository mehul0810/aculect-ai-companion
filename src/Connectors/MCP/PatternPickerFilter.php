<?php
/**
 * Bounded source and content-type filters for pattern discovery.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Match picker-specific filters against sanitized pattern inventory metadata.
 */
final class PatternPickerFilter {
	/**
	 * Determine whether a pattern matches optional source and content-type filters.
	 *
	 * @param array<string, mixed> $pattern Mapped pattern inventory record.
	 * @param array<string, mixed> $args    Tool arguments.
	 */
	public static function matches( array $pattern, array $args ): bool {
		$category = sanitize_key( (string) ( $args['category'] ?? '' ) );
		if ( '' !== $category && ! in_array( $category, (array) ( $pattern['categories'] ?? array() ), true ) ) {
			return false;
		}

		$block_type = preg_replace( '/[^A-Za-z0-9_\/.\-]/', '', sanitize_text_field( (string) ( $args['block_type'] ?? '' ) ) ) ?? '';
		if ( '' !== $block_type && ! in_array( $block_type, (array) ( $pattern['block_types'] ?? array() ), true ) ) {
			return false;
		}

		$source         = sanitize_key( (string) ( $args['source'] ?? '' ) );
		$pattern_source = (string) ( $pattern['source'] ?? '' );
		if ( '' !== $source && $source !== $pattern_source ) {
			return false;
		}

		$content_type = sanitize_key( (string) ( $args['content_type'] ?? '' ) );
		$post_types   = $pattern['post_types'] ?? array();
		if ( '' !== $content_type && is_array( $post_types ) && array() !== $post_types && ! in_array( $content_type, $post_types, true ) ) {
			return false;
		}
		$inserter         = (bool) ( $args['inserter'] ?? false );
		$pattern_inserter = (bool) ( $pattern['inserter'] ?? false );
		if ( array_key_exists( 'inserter', $args ) && $inserter !== $pattern_inserter ) {
			return false;
		}

		$search = strtolower( sanitize_text_field( (string) ( $args['search'] ?? '' ) ) );
		if ( '' === $search ) {
			return true;
		}
		$haystack = strtolower(
			implode(
				' ',
				array_merge(
					array( $pattern['name'] ?? '', $pattern['title'] ?? '', $pattern['description'] ?? '', $pattern['guidance'] ?? '' ),
					(array) ( $pattern['categories'] ?? array() ),
					(array) ( $pattern['keywords'] ?? array() ),
					(array) ( $pattern['use_cases'] ?? array() )
				)
			)
		);

		return str_contains( $haystack, $search );
	}
}
