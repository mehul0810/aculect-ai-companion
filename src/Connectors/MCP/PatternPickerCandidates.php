<?php
/**
 * Server-verified candidate projection for the MCP pattern picker.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Decorates the existing pattern inventory without trusting its descriptive metadata.
 */
final class PatternPickerCandidates {
	private const SCHEMA             = 'aculect.pattern-picker.v1';
	private const CUSTOM_HTML_BLOCK  = 'core/html';
	private const MAX_INSPECT_BYTES  = 20000;
	private const MAX_INSPECT_BLOCKS = 80;
	private const MAX_INSPECT_DEPTH  = 12;

	/**
	 * Return bounded, verified pattern candidates for the picker widget.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list( array $args = array() ): array {
		$content_type = sanitize_key( (string) ( $args['content_type'] ?? '' ) );
		if ( '' !== $content_type && ( ! function_exists( 'get_post_type_object' ) || null === get_post_type_object( $content_type ) ) ) {
			return $this->unavailable( 'invalid_content_type', 'That content type is no longer registered. Refresh the editor and choose an available content type.' );
		}

		$filters = array_merge( $args, array( 'context' => 'compact' ) );
		$result  = ( new BlockKnowledgeAbilities() )->list_patterns( $filters );
		if ( isset( $result['error'] ) ) {
			return $this->unavailable( (string) $result['error'], $this->unavailable_message( (string) $result['error'] ) );
		}

		$patterns = $this->registered_patterns();
		$items    = array();
		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$registry_key = (string) ( $item['name'] ?? '' );
			$pattern      = $patterns[ $registry_key ] ?? null;
			$assessment   = $this->assess( $registry_key, $pattern, $content_type );
			$blocks       = $assessment['blocks'];
			$items[]      = array_merge(
				$item,
				array(
					'category'              => $this->first_category( $item ),
					'compatibility'         => $assessment['compatibility'],
					'supported_blocks'      => $blocks,
					'content_type'          => $content_type,
					'compatibility_message' => $assessment['message'],
				)
			);
		}

		return array_merge(
			$result,
			array(
				'schema'   => self::SCHEMA,
				'status'   => 'ready',
				'items'    => $items,
				'guidance' => 'Candidates are suggestions only. Recheck that a selected pattern is still registered and compatible immediately before insertion; never apply untrusted markup from the client.',
			)
		);
	}

	/**
	 * Verify registry identity, parsed content blocks, and target content type.
	 *
	 * @param string                    $registry_key Registered registry key.
	 * @param array<string, mixed>|null $pattern Registered registry metadata.
	 * @param string                    $content_type Requested content type.
	 * @return array{compatibility: string, blocks: list<string>, message: string}
	 */
	private function assess( string $registry_key, ?array $pattern, string $content_type ): array {
		if ( '' === $registry_key || null === $pattern || ! isset( $pattern['name'] ) || ! is_string( $pattern['name'] ) || $registry_key !== $pattern['name'] ) {
			return $this->assessment( 'unknown', array(), 'This pattern is no longer available under the same registered identity. Refresh the list before selecting it.' );
		}

		$content = $pattern['content'] ?? null;
		if ( ! is_string( $content ) || '' === trim( $content ) || ! function_exists( 'parse_blocks' ) ) {
			return $this->assessment( 'unknown', array(), 'The registered pattern has no inspectable block content. Compatibility cannot be confirmed.' );
		}

		if ( strlen( $content ) > self::MAX_INSPECT_BYTES ) {
			return $this->assessment( 'unknown', array(), 'The pattern exceeds the safe inspection limit. Compatibility cannot be confirmed.' );
		}

		/**
		 * Parsed block result from WordPress core.
		 *
		 * @var array<int, mixed> $parsed_blocks
		 */
		$parsed_blocks = parse_blocks( $content );
		$inspection    = $this->inspect_blocks( $parsed_blocks );
		if ( $inspection['limited'] ) {
			return $this->assessment( 'unknown', $inspection['names'], 'The pattern exceeds the safe block inspection limit. Compatibility cannot be confirmed.' );
		}
		if ( $inspection['unparsed'] ) {
			return $this->assessment( 'incompatible', $inspection['names'], 'This pattern contains freeform markup outside registered blocks.' );
		}

		$blocks = array_values( array_unique( $inspection['names'] ) );
		if ( array() === $blocks ) {
			return $this->assessment( 'unknown', array(), 'No registered block content could be verified for this pattern.' );
		}

		$block_registry = class_exists( '\\WP_Block_Type_Registry' ) ? \WP_Block_Type_Registry::get_instance() : null;
		if ( null === $block_registry || ! method_exists( $block_registry, 'get_registered' ) ) {
			return $this->assessment( 'unknown', $blocks, 'The block registry is unavailable, so compatibility cannot be confirmed.' );
		}

		foreach ( $blocks as $block ) {
			if ( self::CUSTOM_HTML_BLOCK === $block ) {
				return $this->assessment( 'incompatible', $blocks, 'This pattern contains the disallowed Custom HTML block.' );
			}
			if ( null === $block_registry->get_registered( $block ) ) {
				return $this->assessment( 'incompatible', $blocks, 'This pattern uses a block that is not registered on this site.' );
			}
		}

		$declared_types = $pattern['postTypes'] ?? array();
		if ( ! is_array( $declared_types ) ) {
			return $this->assessment( 'unknown', $blocks, 'The registered content-type metadata is incomplete. Compatibility cannot be confirmed.' );
		}
		if ( '' === $content_type ) {
			return $this->assessment( 'unknown', $blocks, 'Choose a content type to verify that this pattern applies to the current editor.' );
		}
		if ( array() !== $declared_types && ! in_array( $content_type, $declared_types, true ) ) {
			return $this->assessment( 'incompatible', $blocks, 'This pattern is not registered for the selected content type.' );
		}

		return $this->assessment( 'compatible', $blocks, 'Registered identity, block content, and selected content type were verified.' );
	}

	/**
	 * Recursively extract block names from parsed WordPress block content.
	 *
	 * @param array<int, mixed> $blocks Parsed blocks.
	 * @return list<string>
	 */
	/**
	 * Inspect parsed blocks and report whether their content was fully understood.
	 *
	 * @param array<int, mixed> $blocks Parsed block tree.
	 * @return array{names: list<string>, unparsed: bool, limited: bool, visited: int}
	 */
	private function inspect_blocks( array $blocks ): array {
		$state = array(
			'names'    => array(),
			'unparsed' => false,
			'limited'  => false,
			'visited'  => 0,
		);
		$this->walk_blocks( $blocks, 0, $state );

		$state['names'] = array_values( $state['names'] );

		return $state;
	}

	/**
	 * Walk parsed blocks with explicit recursion and node bounds.
	 *
	 * @param array<int, mixed>                                                             $blocks Parsed blocks.
	 * @param int                                                                           $depth Current nesting depth.
	 * @param array{names: array<int, string>, unparsed: bool, limited: bool, visited: int} $state Mutable inspection state.
	 */
	private function walk_blocks( array $blocks, int $depth, array &$state ): void {
		if ( $depth > self::MAX_INSPECT_DEPTH ) {
			$state['limited'] = true;
			return;
		}

		foreach ( $blocks as $block ) {
			++$state['visited'];
			if ( $state['visited'] > self::MAX_INSPECT_BLOCKS ) {
				$state['limited'] = true;
				return;
			}
			if ( ! is_array( $block ) ) {
				$state['unparsed'] = true;
				continue;
			}
			if ( is_string( $block['blockName'] ?? null ) ) {
				$state['names'][] = $block['blockName'];
			} elseif ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				$state['unparsed'] = true;
			}

			$inner = $block['innerBlocks'] ?? array();
			if ( is_array( $inner ) ) {
				$this->walk_blocks( $inner, $depth + 1, $state );
			}
			if ( $state['limited'] ) {
				return;
			}
		}
	}

	/**
	 * Return registry metadata when the WordPress pattern registry is available.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function registered_patterns(): array {
		if ( ! class_exists( '\\WP_Block_Patterns_Registry' ) ) {
			return array();
		}
		$registry = \WP_Block_Patterns_Registry::get_instance();
		if ( ! method_exists( $registry, 'get_all_registered' ) ) {
			return array();
		}

		$by_name = array();
		foreach ( $registry->get_all_registered() as $pattern ) {
			if ( ! is_array( $pattern ) || ! is_string( $pattern['name'] ?? null ) || '' === $pattern['name'] ) {
				continue;
			}
			$by_name[ $pattern['name'] ] = $pattern;
		}

		return $by_name;
	}

	/**
	 * Return the first registered category label, falling back to its slug.
	 *
	 * @param array<string, mixed> $item Pattern inventory item.
	 */
	private function first_category( array $item ): string {
		$categories = (array) ( $item['categories'] ?? array() );
		$slug       = (string) ( $categories[0] ?? '' );
		$labels     = (array) ( $item['category_labels'] ?? array() );

		$label = (string) ( $labels[ $slug ] ?? $slug );
		return '' === $label ? 'Uncategorized' : $label;
	}

	/**
	 * Shape an assessment consistently.
	 *
	 * @param string   $compatibility Compatibility state.
	 * @param string[] $blocks        Verified parsed blocks.
	 * @param string   $message       Human-readable explanation.
	 * @return array{compatibility: string, blocks: list<string>, message: string}
	 */
	private function assessment( string $compatibility, array $blocks, string $message ): array {
		return array(
			'compatibility' => $compatibility,
			'blocks'        => $blocks,
			'message'       => $message,
		);
	}

	/**
	 * Return a safe unavailable response.
	 *
	 * @param string $reason Machine-readable unavailable reason.
	 * @param string $message Human-readable recovery guidance.
	 *
	 * @return array<string, mixed>
	 */
	private function unavailable( string $reason, string $message ): array {
		return array(
			'schema'  => self::SCHEMA,
			'status'  => 'unavailable',
			'reason'  => sanitize_key( $reason ),
			'items'   => array(),
			'message' => $message,
		);
	}

	/**
	 * Explain temporary pattern discovery failures without exposing implementation data.
	 *
	 * @param string $reason Machine-readable failure reason.
	 */
	private function unavailable_message( string $reason ): string {
		if ( 'forbidden' === $reason ) {
			return 'Your WordPress access no longer allows pattern browsing. Refresh authorization or ask a site administrator.';
		}
		if ( 'patterns_api_unavailable' === $reason ) {
			return 'Pattern discovery is unavailable on this site. Refresh after the block editor and theme are fully loaded.';
		}

		return 'The registered pattern list is unavailable. Refresh and try again.';
	}
}
