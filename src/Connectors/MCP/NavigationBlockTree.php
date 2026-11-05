<?php
/**
 * Bounded transformations of existing native navigation blocks.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Preserves parsed block data while editing one explicitly selected item. */
final class NavigationBlockTree {
	/** Maximum serialized navigation size in bytes. */
	public const MAX_BYTES = 100000;

	/**
	 * Load a supported item through native submenu ancestors only.
	 *
	 * @param string $content Existing serialized navigation.
	 * @param string $block_path Dotted zero-based block indices.
	 * @return array<string, mixed>
	 */
	public function load( string $content, string $block_path ): array {
		$path = $this->path( $block_path );
		if ( null === $path || array() === $path || strlen( $content ) > self::MAX_BYTES || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $this->error( 'invalid_navigation_block' );
		}
		$tree = array_values( parse_blocks( $content ) );
		if ( ! $this->bounded( $tree ) ) {
			return $this->error( 'unsupported_navigation_structure' );
		}
		$serialized = serialize_blocks( $tree );
		$tree       = $this->normalize( $tree );
		if ( $this->normalize( array_values( parse_blocks( $serialized ) ) ) !== $tree ) {
			return $this->error( 'unsupported_navigation_structure' );
		}
		$item = $this->find( $tree, $path );
		if ( null === $item || ! in_array( $item['blockName'], array( 'core/navigation-link', 'core/navigation-submenu' ), true ) || ( 'core/navigation-link' === $item['blockName'] && array() !== $item['innerBlocks'] ) ) {
			return $this->error( 'unsupported_navigation_block' );
		}
		return array(
			'tree'       => $tree,
			'item'       => $item,
			'block_path' => $block_path,
		);
	}

	/**
	 * Change supported fields and optionally move an existing item.
	 *
	 * @param string                  $content Existing serialized navigation.
	 * @param string                  $block_path Dotted zero-based block indices.
	 * @param array<array-key, mixed> $changes Allowed item fields and placement.
	 * @return array<string, mixed>
	 */
	public function apply( string $content, string $block_path, array $changes ): array {
		$loaded = $this->load( $content, $block_path );
		if ( isset( $loaded['error'] ) ) {
			return $loaded;
		}
		if ( array() === $changes || array_is_list( $changes ) || array_diff( array_keys( $changes ), array( 'label', 'url', 'target', 'rel', 'parent_path', 'order' ) ) ) {
			return $this->error( 'invalid_navigation_changes' );
		}
		$before = $loaded['item'];
		$after  = $this->fields( $before, $changes );
		if ( isset( $after['error'] ) ) {
			return $after;
		}
		$path = $this->path( $block_path );
		if ( null === $path ) {
			return $this->error( 'invalid_navigation_block' );
		}
		$tree = $loaded['tree'];
		$this->replace( $tree, $path, $after );
		$placed = $this->place( $tree, $path, $changes );
		if ( isset( $placed['error'] ) ) {
			return $placed;
		}
		$tree    = $placed['tree'];
		$changed = $loaded['tree'] !== $tree;
		$output  = $changed ? serialize_blocks( $tree ) : $content;
		if ( strlen( $output ) > self::MAX_BYTES || ! $this->bounded( $tree ) || $this->normalize( array_values( parse_blocks( $output ) ) ) !== $tree ) {
			return $this->error( 'unsupported_navigation_structure' );
		}
		return array(
			'content'        => $output,
			'before'         => $before,
			'after'          => $after,
			'new_block_path' => implode( '.', $placed['path'] ),
			'changed'        => $changed,
		);
	}

	/**
	 * Validate a bounded canonical index path; empty denotes the root.
	 *
	 * @param string $value Dotted path.
	 * @return list<int>|null
	 */
	private function path( string $value ): ?array {
		if ( '' === $value ) {
			return array();
		}
		if ( strlen( $value ) > 79 || ! preg_match( '/^(?:0|[1-9][0-9]{0,2})(?:\.(?:0|[1-9][0-9]{0,2})){0,19}$/D', $value ) ) {
			return null;
		}
		$path = array_map( 'intval', explode( '.', $value ) );
		return max( $path ) < 500 ? $path : null;
	}

	/**
	 * Check parser output, traversal bounds and representable child slots.
	 *
	 * @param array<int, array<string, mixed>> $tree Parsed blocks.
	 */
	private function bounded( array $tree ): bool {
		$queue = array( array( $tree, 1 ) );
		$count = 0;
		while ( $queue ) {
			list( $blocks, $depth ) = array_pop( $queue );
			foreach ( $blocks as $block ) {
				if ( ++$count > 500 || $depth > 20 || ! is_array( $block['attrs'] ?? null ) || ! is_array( $block['innerBlocks'] ?? null ) || ! is_array( $block['innerContent'] ?? null ) || ! is_string( $block['innerHTML'] ?? null ) ) {
					return false;
				}
				$slots = 0;
				$html  = '';
				foreach ( $block['innerContent'] as $fragment ) {
					if ( null === $fragment ) {
						++$slots;
					} elseif ( is_string( $fragment ) ) {
						$html .= $fragment;
					} else {
						return false;
					}
				}
				if ( count( $block['innerBlocks'] ) !== $slots || $html !== $block['innerHTML'] ) {
					return false;
				}
				$queue[] = array( $block['innerBlocks'], $depth + 1 );
			}
		}
		return true;
	}

	/**
	 * Remove only zero-byte parser fragments, which vary with nesting context.
	 *
	 * @param array<int, array<string, mixed>> $tree Bounded parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize( array $tree ): array {
		foreach ( $tree as &$block ) {
			$block['innerContent'] = array_values( array_filter( $block['innerContent'], static fn( $fragment ): bool => '' !== $fragment ) );
			$block['innerBlocks']  = $this->normalize( $block['innerBlocks'] );
		}
		return $tree;
	}

	/**
	 * Resolve an item without traversing dynamic or unknown ancestors.
	 *
	 * @param array<int, array<string, mixed>> $tree Parsed blocks.
	 * @param array<int, int>                  $path Item path.
	 * @return array<string, mixed>|null
	 */
	private function find( array $tree, array $path ): ?array {
		foreach ( $path as $depth => $index ) {
			if ( ! isset( $tree[ $index ] ) ) {
				return null;
			}
			$block = $tree[ $index ];
			if ( count( $path ) - 1 === $depth ) {
				return $block;
			}
			if ( 'core/navigation-submenu' !== $block['blockName'] ) {
				return null;
			}
			$tree = $block['innerBlocks'];
		}
		return null;
	}

	/**
	 * Replace one block, retaining ancestor markup and child slots.
	 *
	 * @param array<int, array<string, mixed>> $tree Parsed blocks.
	 * @param array<int, int>                  $path Item path.
	 * @param array<string, mixed>             $item Replacement block.
	 */
	private function replace( array &$tree, array $path, array $item ): void {
		$index = array_shift( $path );
		if ( array() === $path ) {
			$tree[ $index ] = $item;
			return;
		}
		$this->replace( $tree[ $index ]['innerBlocks'], $path, $item );
	}

	/**
	 * Validate explicit fields without accepting user serialized markup.
	 *
	 * @param array<string, mixed>    $item Selected block.
	 * @param array<array-key, mixed> $changes Requested changes.
	 * @return array<string, mixed>
	 */
	private function fields( array $item, array $changes ): array {
		foreach ( array( 'label', 'url', 'target', 'rel' ) as $field ) {
			if ( ! array_key_exists( $field, $changes ) ) {
				continue;
			}
			$value = $changes[ $field ];
			$limit = 'url' === $field ? 2048 : 255;
			if ( ! is_string( $value ) || strlen( $value ) > $limit ) {
				return $this->error( 'invalid_navigation_changes' );
			}
			if ( 'label' === $field ) {
				$value = sanitize_text_field( $value );
				if ( '' === $value ) {
					return $this->error( 'invalid_navigation_changes' );
				}
			} elseif ( 'url' === $field && ( ! $this->custom( $item['attrs'] ) || ! $this->safe_url( $value ) ) ) {
				return $this->error( 'invalid_navigation_url' );
			} elseif ( 'target' === $field ) {
				if ( ! in_array( $value, array( '', '_self', '_blank' ), true ) ) {
					return $this->error( 'invalid_navigation_target' );
				}
				$field = 'opensInNewTab';
				$value = '_blank' === $value;
			} elseif ( 'rel' === $field ) {
				if ( ! preg_match( '/^[a-zA-Z0-9\- ]*$/D', $value ) ) {
					return $this->error( 'invalid_navigation_rel' );
				}
				$value = implode( ' ', array_unique( array_filter( explode( ' ', strtolower( $value ) ), static fn( string $token ): bool => '' !== $token ) ) );
			}
			if ( 'opensInNewTab' === $field && false === $value && ! isset( $item['attrs'][ $field ] ) ) {
				continue;
			}
			$item['attrs'][ $field ] = $value;
		}
		return $item;
	}

	/**
	 * Only custom links may replace a URL associated with an object.
	 *
	 * @param array<string, mixed> $attrs Block attributes.
	 */
	private function custom( array $attrs ): bool {
		return 'custom' === ( $attrs['kind'] ?? 'custom' ) && 'custom' === ( $attrs['type'] ?? 'custom' ) && empty( $attrs['id'] );
	}

	/**
	 * Accept unambiguous HTTP(S), root-relative and fragment URLs only.
	 *
	 * @param string $url Proposed link.
	 */
	private function safe_url( string $url ): bool {
		if ( '' === $url || esc_url_raw( $url, array( 'http', 'https' ) ) !== $url || strpbrk( $url, '<>' ) || preg_match( '/[\x00-\x20\x7f\\\\]/', $url ) || preg_match( '/%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url ) || str_starts_with( $url, '//' ) ) {
			return false;
		}
		if ( str_starts_with( $url, '#' ) || str_starts_with( $url, '/' ) ) {
			return ! str_starts_with( strtolower( $url ), '/%2f' );
		}
		$parts = wp_parse_url( $url );
		return is_array( $parts ) && in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
	}

	/**
	 * Move within full sibling lists using final one-based positions.
	 *
	 * @param array<int, array<string, mixed>> $tree Parsed blocks.
	 * @param array<int, int>                  $path Selected path.
	 * @param array<string, mixed>             $changes Requested placement.
	 * @return array<string, mixed>
	 */
	private function place( array $tree, array $path, array $changes ): array {
		$source = array_slice( $path, 0, -1 );
		$parent = $source;
		if ( array_key_exists( 'parent_path', $changes ) ) {
			$parent = is_string( $changes['parent_path'] ) ? $this->path( $changes['parent_path'] ) : null;
		}
		if ( null === $parent || ( array_slice( $parent, 0, count( $path ) ) === $path ) ) {
			return $this->error( 'invalid_navigation_parent' );
		}
		$destination = array() === $parent ? null : $this->find( $tree, $parent );
		if ( array() !== $parent && ( null === $destination || 'core/navigation-submenu' !== $destination['blockName'] ) ) {
			return $this->error( 'invalid_navigation_parent' );
		}
		$siblings = null === $destination ? $tree : $destination['innerBlocks'];
		$index    = $path[ count( $path ) - 1 ];
		$maximum  = count( $siblings ) + ( $source === $parent ? 0 : 1 );
		$order    = $changes['order'] ?? ( $source === $parent ? $index + 1 : $maximum );
		if ( ! is_int( $order ) || $order < 1 || $order > $maximum || $order > 500 || ( array_key_exists( 'order', $changes ) && null === $changes['order'] ) ) {
			return $this->error( 'invalid_navigation_order' );
		}
		if ( $source === $parent && $order === $index + 1 ) {
			return array(
				'tree' => $tree,
				'path' => $path,
			);
		}
		foreach ( array( $source, $parent ) as $container_path ) {
			$container = array() === $container_path ? null : $this->find( $tree, $container_path );
			if ( null !== $container && ! preg_match( '/^[ \t\r\n]*$/D', $container['innerHTML'] ) ) {
				return $this->error( 'unsupported_navigation_structure' );
			}
		}
		$item = $this->find( $tree, $path );
		if ( null === $item ) {
			return $this->error( 'invalid_navigation_block' );
		}
		$this->splice( $tree, $source, $index, 1, array() );
		// Paths supplied by callers always describe the original tree.
		if ( count( $parent ) > count( $source ) && array_slice( $parent, 0, count( $source ) ) === $source && $parent[ count( $source ) ] > $index ) {
			--$parent[ count( $source ) ];
		}
		$this->splice( $tree, $parent, $order - 1, 0, array( $item ) );
		return array(
			'tree' => $tree,
			'path' => array_merge( $parent, array( $order - 1 ) ),
		);
	}

	/**
	 * Adjust children only inside containers whose markup is whitespace.
	 *
	 * @param array<int, array<string, mixed>> $tree Parsed blocks.
	 * @param array<int, int>                  $parent Container path.
	 * @param int                              $offset Sibling offset.
	 * @param int                              $length Removal count.
	 * @param array<int, array<string, mixed>> $insert Inserted blocks.
	 */
	private function splice( array &$tree, array $parent, int $offset, int $length, array $insert ): void {
		if ( array() === $parent ) {
			array_splice( $tree, $offset, $length, $insert );
			return;
		}
		$index = array_shift( $parent );
		$this->splice( $tree[ $index ]['innerBlocks'], $parent, $offset, $length, $insert );
		if ( array() === $parent ) {
			$tree[ $index ]['innerContent'] = array_fill( 0, count( $tree[ $index ]['innerBlocks'] ), null );
			if ( '' !== $tree[ $index ]['innerHTML'] ) {
				$tree[ $index ]['innerContent'][] = $tree[ $index ]['innerHTML'];
			}
		}
	}
	/**
	 * Return a stable, user-readable refusal without exposing stored markup.
	 *
	 * @param string $code Refusal code.
	 * @return array<string, string>
	 */
	private function error( string $code ): array {
		$messages = array(
			'invalid_navigation_block'         => 'Select an existing navigation item using a bounded dotted block path.',
			'unsupported_navigation_structure' => 'This navigation structure cannot be safely preserved by the requested edit.',
			'unsupported_navigation_block'     => 'Only native navigation links and submenus through native submenu ancestors can be edited.',
			'invalid_navigation_changes'       => 'Provide supported navigation fields with bounded string values.',
			'invalid_navigation_url'           => 'Only custom links accept a safe HTTP(S), root-relative, or fragment URL.',
			'invalid_navigation_target'        => 'Target must be empty, _self, or _blank.',
			'invalid_navigation_rel'           => 'Relationship values must be space-separated alphanumeric or hyphenated tokens.',
			'invalid_navigation_parent'        => 'The parent must be the root or an existing native submenu outside the selected subtree.',
			'invalid_navigation_order'         => 'Order must be an integer within the final destination sibling positions.',
		);
		return array(
			'error'   => $code,
			'message' => $messages[ $code ],
		);
	}
}
