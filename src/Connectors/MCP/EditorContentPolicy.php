<?php
/**
 * Bounded serialized-block checks for editor-record content.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Combines native sanitization with the existing registered-block validator. */
final class EditorContentPolicy {

	/**
	 * Reject freeform/active HTML, invalid blocks and pathological nesting.
	 *
	 * @param string $content Proposed serialized block markup.
	 * @param bool   $navigation Whether only native navigation children are allowed.
	 */
	public function valid( string $content, bool $navigation = false ): bool {
		if ( '' === trim( $content ) || strlen( $content ) > EditorRecordState::MAX_BYTES || ! function_exists( 'filter_block_content' ) || ! function_exists( 'wp_kses_post' ) ) {
			return false;
		}
		if ( wp_kses_post( $content ) !== $content || filter_block_content( $content, 'post', wp_allowed_protocols() ) !== $content ) {
			return false;
		}
		$queue = array( array( parse_blocks( $content ), 0 ) );
		$count = 0;
		while ( array() !== $queue ) {
			list( $blocks, $depth ) = array_pop( $queue );
			if ( $depth > 20 ) {
				return false;
			}
			foreach ( $blocks as $block ) {
				if ( ++$count > 500 ) {
					return false;
				}
				$name = $block['blockName'] ?? null;
				if ( null === $name && '' === trim( $block['innerHTML'] ?? '' ) ) {
					continue;
				}
				if ( ! is_string( $name ) || 'core/html' === $name || ( $navigation && ! $this->navigation_block( $name ) ) ) {
					return false;
				}
				$queue[] = array( $block['innerBlocks'] ?? array(), $depth + 1 );
			}
		}
		$result = ( new BlockKnowledgeAbilities() )->validate_block_content( array( 'content' => $content ) );
		return true === ( $result['valid'] ?? false );
	}

	/**
	 * Permit the native navigation child set, including social-link descendants.
	 *
	 * @param string $name Registered block name.
	 */
	private function navigation_block( string $name ): bool {
		return in_array( $name, array( 'core/navigation-link', 'core/navigation-submenu', 'core/home-link', 'core/site-title', 'core/site-logo', 'core/search', 'core/social-links', 'core/social-link', 'core/page-list', 'core/spacer', 'core/icon', 'core/loginout', 'core/buttons', 'core/button' ), true );
	}
}
