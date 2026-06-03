<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Read-only block and pattern knowledge for assistant clients.
 */
final class BlockKnowledgeAbilities extends AbstractAbilityService {

	private const DEFAULT_PER_PAGE       = 25;
	private const MAX_PER_PAGE           = 100;
	private const MAX_PATTERN_PREVIEW    = 600;
	private const MAX_PATTERN_CONTENT    = 20000;
	private const CUSTOM_HTML_BLOCK_NAME = 'core/html';

	/**
	 * List registered blocks with bounded metadata and usage guidance.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_blocks( array $args = array() ): array {
		if ( ! $this->has_block_registry() ) {
			return $this->error( 'blocks_api_unavailable', 'The WordPress block registry is not available on this site.' );
		}

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
		$context  = $this->collection_context( $args );
		$items    = array_values(
			array_filter(
				array_map(
					fn( object $block ): array => $this->map_block( $block, 'full' === $context ),
					$this->registered_blocks()
				),
				fn( array $block ): bool => $this->block_matches_filters( $block, $args )
			)
		);

		usort(
			$items,
			static fn( array $a, array $b ): int => strcmp( (string) $a['name'], (string) $b['name'] )
		);

		$total = count( $items );
		$items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );

		return array(
			'items'            => $items,
			'total'            => $total,
			'page'             => $page,
			'per_page'         => $per_page,
			'context'          => $context,
			'content_guidance' => $this->content_guidance(),
		);
	}

	/**
	 * Return full metadata and usage guidance for one registered block.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_block_info( array $args ): array {
		if ( ! $this->has_block_registry() ) {
			return $this->error( 'blocks_api_unavailable', 'The WordPress block registry is not available on this site.' );
		}

		$name = $this->sanitize_identifier( (string) ( $args['name'] ?? '' ) );
		if ( '' === $name ) {
			return $this->error( 'invalid_block', 'Block name is required.' );
		}

		$block = $this->registered_block( $name );
		if ( null === $block ) {
			return $this->error( 'not_found', 'Block is not registered on this site.' );
		}

		return array_merge(
			$this->map_block( $block, true ),
			array(
				'content_guidance' => $this->content_guidance(),
			)
		);
	}

	/**
	 * List registered block patterns with bounded metadata and usage guidance.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_patterns( array $args = array() ): array {
		if ( ! $this->has_pattern_registry() ) {
			return $this->error( 'patterns_api_unavailable', 'The WordPress block patterns registry is not available on this site.' );
		}

		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
		$context  = $this->collection_context( $args );
		$patterns = $this->registered_patterns();
		$items    = array_values(
			array_filter(
				array_map(
					fn( array $pattern, string $name ): array => $this->map_pattern( $pattern, $name, 'full' === $context, false ),
					$patterns,
					array_keys( $patterns )
				),
				fn( array $pattern ): bool => $this->pattern_matches_filters( $pattern, $args )
			)
		);

		usort(
			$items,
			static fn( array $a, array $b ): int => strcmp( (string) $a['name'], (string) $b['name'] )
		);

		$total = count( $items );
		$items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );

		return array(
			'items'            => $items,
			'total'            => $total,
			'page'             => $page,
			'per_page'         => $per_page,
			'context'          => $context,
			'content_guidance' => $this->content_guidance(),
		);
	}

	/**
	 * Return metadata and optional block markup for one registered pattern.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_pattern_info( array $args ): array {
		if ( ! $this->has_pattern_registry() ) {
			return $this->error( 'patterns_api_unavailable', 'The WordPress block patterns registry is not available on this site.' );
		}

		$name = $this->sanitize_identifier( (string) ( $args['name'] ?? '' ) );
		if ( '' === $name ) {
			return $this->error( 'invalid_pattern', 'Pattern name is required.' );
		}

		$patterns = $this->registered_patterns();
		if ( ! array_key_exists( $name, $patterns ) ) {
			return $this->error( 'not_found', 'Pattern is not registered on this site.' );
		}

		return array_merge(
			$this->map_pattern( $patterns[ $name ], $name, true, ! empty( $args['include_content'] ) ),
			array(
				'content_guidance' => $this->content_guidance(),
			)
		);
	}

	/**
	 * Validate block content before an assistant writes it to WordPress.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function validate_block_content( array $args ): array {
		$content = (string) ( $args['content'] ?? '' );
		if ( '' === trim( $content ) ) {
			return $this->error( 'invalid_content', 'Content is required for block validation.' );
		}

		if ( ! function_exists( 'parse_blocks' ) ) {
			return $this->error( 'blocks_api_unavailable', 'The WordPress block parser is not available on this site.' );
		}

		$parsed   = parse_blocks( $content );
		$names    = $this->flatten_block_names( $parsed );
		$counts   = array_count_values( $names );
		$warnings = array();
		$blocks   = array();

		foreach ( $counts as $name => $count ) {
			$registered = null !== $this->registered_block( (string) $name );
			$allowed    = self::CUSTOM_HTML_BLOCK_NAME !== $name;

			if ( ! $registered ) {
				$warnings[] = sprintf( 'Block %s is not registered on this site.', (string) $name );
			}

			if ( ! $allowed ) {
				$warnings[] = 'Never use the Custom HTML block (core/html). Use registered semantic blocks or patterns instead.';
			}

			$blocks[] = array(
				'name'                   => (string) $name,
				'count'                  => (int) $count,
				'registered'             => $registered,
				'allowed_for_generation' => $allowed,
			);
		}

		if ( array() === $blocks ) {
			$warnings[] = 'No block markup was detected. Prefer registered WordPress blocks or patterns for assistant-generated content.';
		}

		return array(
			'valid'            => array() === array_filter(
				$blocks,
				static fn( array $block ): bool => empty( $block['registered'] ) || empty( $block['allowed_for_generation'] )
			),
			'blocks'           => $blocks,
			'warnings'         => array_values( array_unique( $warnings ) ),
			'content_guidance' => $this->content_guidance(),
		);
	}

	/**
	 * Return registered block objects keyed by block name.
	 *
	 * @return array<string, object>
	 */
	private function registered_blocks(): array {
		$registry = \WP_Block_Type_Registry::get_instance();
		$blocks   = $registry->get_all_registered();

		return array_filter( $blocks, 'is_object' );
	}

	/**
	 * Return one registered block object.
	 *
	 * @param string $name Registered block name.
	 */
	private function registered_block( string $name ): ?object {
		if ( ! $this->has_block_registry() ) {
			return null;
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		if ( method_exists( $registry, 'get_registered' ) ) {
			$block = $registry->get_registered( $name );
			return is_object( $block ) ? $block : null;
		}

		return $this->registered_blocks()[ $name ] ?? null;
	}

	/**
	 * Return registered block patterns keyed by pattern name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function registered_patterns(): array {
		$registry = \WP_Block_Patterns_Registry::get_instance();
		$patterns = $registry->get_all_registered();

		return array_filter( $patterns, 'is_array' );
	}

	/**
	 * Check whether the block registry is available.
	 */
	private function has_block_registry(): bool {
		return class_exists( '\WP_Block_Type_Registry' )
			&& method_exists( '\WP_Block_Type_Registry', 'get_instance' )
			&& method_exists( \WP_Block_Type_Registry::get_instance(), 'get_all_registered' );
	}

	/**
	 * Check whether the pattern registry is available.
	 */
	private function has_pattern_registry(): bool {
		return class_exists( '\WP_Block_Patterns_Registry' )
			&& method_exists( '\WP_Block_Patterns_Registry', 'get_instance' )
			&& method_exists( \WP_Block_Patterns_Registry::get_instance(), 'get_all_registered' );
	}

	/**
	 * Convert a block object into deterministic MCP output.
	 *
	 * @param object $block        Registered block object.
	 * @param bool   $include_full Whether to include extended metadata.
	 * @return array<string, mixed>
	 */
	private function map_block( object $block, bool $include_full ): array {
		$name        = $this->sanitize_identifier( $this->object_string( $block, 'name' ) );
		$title       = $this->clean_text( $this->object_string( $block, 'title' ) );
		$description = $this->clean_text( $this->object_string( $block, 'description' ) );
		$category    = sanitize_key( $this->object_string( $block, 'category' ) );
		$guidance    = $this->block_guidance( $name, $category, $title, $description );
		$item        = array(
			'name'                   => $name,
			'title'                  => '' === $title ? $name : $title,
			'category'               => $category,
			'description'            => $description,
			'keywords'               => $this->string_list( $this->object_value( $block, 'keywords' ) ),
			'supports_inserter'      => $this->supports_inserter( $block ),
			'allowed_for_generation' => self::CUSTOM_HTML_BLOCK_NAME !== $name,
			'best_for'               => $guidance['best_for'],
			'avoid_for'              => $guidance['avoid_for'],
			'guidance'               => $guidance['guidance'],
		);

		if ( $include_full ) {
			$item['attributes']       = $this->attribute_keys( $this->object_value( $block, 'attributes' ) );
			$item['supports']         = $this->support_keys( $this->object_value( $block, 'supports' ) );
			$item['styles']           = $this->named_variants( $this->object_value( $block, 'styles' ) );
			$item['variations']       = $this->named_variants( $this->object_value( $block, 'variations' ) );
			$item['uses_context']     = $this->string_list( $this->object_value( $block, 'uses_context' ) );
			$item['provides_context'] = $this->string_map( $this->object_value( $block, 'provides_context' ) );
			$item['parent']           = $this->string_list( $this->object_value( $block, 'parent' ) );
			$item['ancestor']         = $this->string_list( $this->object_value( $block, 'ancestor' ) );
		}

		return $item;
	}

	/**
	 * Convert a pattern array into deterministic MCP output.
	 *
	 * @param array<string, mixed> $pattern         Registered pattern.
	 * @param string               $fallback_name   Registry key.
	 * @param bool                 $include_full    Whether to include extended metadata.
	 * @param bool                 $include_content Whether to include bounded pattern content.
	 * @return array<string, mixed>
	 */
	private function map_pattern( array $pattern, string $fallback_name, bool $include_full, bool $include_content ): array {
		$name              = $this->sanitize_identifier( (string) ( $pattern['name'] ?? $fallback_name ) );
		$title             = $this->clean_text( (string) ( $pattern['title'] ?? $name ) );
		$description       = $this->clean_text( (string) ( $pattern['description'] ?? '' ) );
		$content           = is_string( $pattern['content'] ?? null ) ? (string) $pattern['content'] : '';
		$contains_html     = $this->contains_custom_html_block( $content );
		$content_truncated = strlen( $content ) > self::MAX_PATTERN_CONTENT;
		$item              = array(
			'name'                   => $name,
			'title'                  => '' === $title ? $name : $title,
			'description'            => $description,
			'categories'             => $this->string_list( $pattern['categories'] ?? array() ),
			'keywords'               => $this->string_list( $pattern['keywords'] ?? array() ),
			'block_types'            => $this->string_list( $pattern['blockTypes'] ?? array() ),
			'post_types'             => $this->string_list( $pattern['postTypes'] ?? array() ),
			'source'                 => $this->clean_text( (string) ( $pattern['source'] ?? '' ) ),
			'inserter'               => $this->pattern_inserter_enabled( $pattern ),
			'allowed_for_generation' => ! $contains_html,
			'use_cases'              => $this->pattern_use_cases( $title, $description, $pattern ),
			'guidance'               => $contains_html
				? 'This pattern contains a Custom HTML block. Do not use it for assistant-generated content unless the site owner replaces that block first.'
				: 'Use this pattern when its layout and categories match the requested page section. Keep content editable as registered WordPress blocks.',
		);

		if ( $include_full ) {
			$item['template_types']  = $this->string_list( $pattern['templateTypes'] ?? array() );
			$item['viewport_width']  = isset( $pattern['viewportWidth'] ) ? (int) $pattern['viewportWidth'] : 0;
			$item['content_preview'] = $this->truncate( $this->clean_text( $content ), self::MAX_PATTERN_PREVIEW );
		}

		if ( $include_content ) {
			$item['content']           = $this->truncate( $content, self::MAX_PATTERN_CONTENT );
			$item['content_truncated'] = $content_truncated;
		}

		return $item;
	}

	/**
	 * Check whether block metadata matches list filters.
	 *
	 * @param array<string, mixed> $block Block item.
	 * @param array<string, mixed> $args  Tool arguments.
	 */
	private function block_matches_filters( array $block, array $args ): bool {
		$namespace = sanitize_key( (string) ( $args['namespace'] ?? '' ) );
		if ( '' !== $namespace && ! str_starts_with( (string) $block['name'], $namespace . '/' ) ) {
			return false;
		}

		$category = sanitize_key( (string) ( $args['category'] ?? '' ) );
		if ( '' !== $category && $category !== $block['category'] ) {
			return false;
		}

		if ( array_key_exists( 'inserter', $args ) && (bool) $args['inserter'] !== (bool) $block['supports_inserter'] ) {
			return false;
		}

		$search = strtolower( $this->clean_text( (string) ( $args['search'] ?? '' ) ) );
		if ( '' === $search ) {
			return true;
		}

		$haystack = strtolower(
			implode(
				' ',
				array_merge(
					array( $block['name'], $block['title'], $block['description'], $block['guidance'] ),
					(array) $block['keywords'],
					(array) $block['best_for']
				)
			)
		);

		return str_contains( $haystack, $search );
	}

	/**
	 * Check whether pattern metadata matches list filters.
	 *
	 * @param array<string, mixed> $pattern Pattern item.
	 * @param array<string, mixed> $args    Tool arguments.
	 */
	private function pattern_matches_filters( array $pattern, array $args ): bool {
		$category = sanitize_key( (string) ( $args['category'] ?? '' ) );
		if ( '' !== $category && ! in_array( $category, (array) $pattern['categories'], true ) ) {
			return false;
		}

		$block_type = $this->sanitize_identifier( (string) ( $args['block_type'] ?? '' ) );
		if ( '' !== $block_type && ! in_array( $block_type, (array) $pattern['block_types'], true ) ) {
			return false;
		}

		if ( array_key_exists( 'inserter', $args ) && (bool) $args['inserter'] !== (bool) $pattern['inserter'] ) {
			return false;
		}

		$search = strtolower( $this->clean_text( (string) ( $args['search'] ?? '' ) ) );
		if ( '' === $search ) {
			return true;
		}

		$haystack = strtolower(
			implode(
				' ',
				array_merge(
					array( $pattern['name'], $pattern['title'], $pattern['description'], $pattern['guidance'] ),
					(array) $pattern['categories'],
					(array) $pattern['keywords'],
					(array) $pattern['use_cases']
				)
			)
		);

		return str_contains( $haystack, $search );
	}

	/**
	 * Return shared generation guidance for block-aware assistant clients.
	 *
	 * @return array<string, mixed>
	 */
	private function content_guidance(): array {
		return array(
			'preferred_format' => 'Use registered WordPress block markup and available block patterns so content remains editable.',
			'never_use'        => array( self::CUSTOM_HTML_BLOCK_NAME ),
			'custom_html_rule' => 'Never use the Custom HTML block (core/html). Use semantic core blocks, registered site blocks, or patterns instead.',
			'fallback_rule'    => 'If a requested layout cannot be represented with registered blocks or patterns, ask for an approved block or pattern instead of adding raw HTML.',
		);
	}

	/**
	 * Return curated usage guidance for common core blocks.
	 *
	 * @param string $name        Block name.
	 * @param string $category    Block category.
	 * @param string $title       Block title.
	 * @param string $description Block description.
	 * @return array{best_for: list<string>, avoid_for: list<string>, guidance: string}
	 */
	private function block_guidance( string $name, string $category, string $title, string $description ): array {
		$curated = $this->curated_core_block_guidance();
		if ( isset( $curated[ $name ] ) ) {
			return $curated[ $name ];
		}

		$label    = '' === $title ? $name : $title;
		$best_for = array_filter(
			array(
				'' !== $description ? $description : sprintf( 'Content that specifically needs the %s block.', $label ),
				'' !== $category ? sprintf( 'Requests that match the %s block category.', $category ) : '',
			)
		);

		return array(
			'best_for'  => array_values( $best_for ),
			'avoid_for' => array( 'Raw HTML fallbacks or layouts better served by a registered pattern.' ),
			'guidance'  => 'Use this registered block only when it matches the requested semantic content. Do not use the Custom HTML block as a fallback.',
		);
	}

	/**
	 * Curated guidance for commonly used core blocks.
	 *
	 * @return array<string, array{best_for: list<string>, avoid_for: list<string>, guidance: string}>
	 */
	private function curated_core_block_guidance(): array {
		return array(
			'core/paragraph'             => array(
				'best_for'  => array( 'Body copy', 'Short explanatory text', 'Single text paragraphs between headings and media.' ),
				'avoid_for' => array( 'Headings', 'Lists', 'Buttons', 'Complex layouts.' ),
				'guidance'  => 'Use paragraphs for normal prose and keep one idea per block.',
			),
			'core/heading'               => array(
				'best_for'  => array( 'Section titles', 'Content hierarchy', 'Scan-friendly page structure.' ),
				'avoid_for' => array( 'Body copy', 'Button labels', 'Decorative text without document structure.' ),
				'guidance'  => 'Use heading levels in order and avoid skipping levels when generating page content.',
			),
			'core/list'                  => array(
				'best_for'  => array( 'Steps', 'Feature lists', 'Grouped bullets', 'Ordered instructions.' ),
				'avoid_for' => array( 'Navigation menus', 'Card grids', 'Long paragraphs.' ),
				'guidance'  => 'Use list blocks for related items that benefit from quick scanning.',
			),
			'core/image'                 => array(
				'best_for'  => array( 'Single images', 'Illustrations', 'Screenshots', 'Product or venue visuals.' ),
				'avoid_for' => array( 'Multiple-image layouts', 'Background hero treatments better served by Cover.' ),
				'guidance'  => 'Use existing media IDs when available and include useful alt text.',
			),
			'core/gallery'               => array(
				'best_for'  => array( 'Multiple related images', 'Portfolio samples', 'Event or product image sets.' ),
				'avoid_for' => array( 'A single image', 'Images with heavy text explanations.' ),
				'guidance'  => 'Use galleries when the images form one visual set.',
			),
			'core/quote'                 => array(
				'best_for'  => array( 'Quoted testimonials', 'Editorial pullouts with attribution', 'Source-backed excerpts.' ),
				'avoid_for' => array( 'Generic emphasis', 'Stats', 'Unattributed claims.' ),
				'guidance'  => 'Use quote blocks only for actual quoted material and include citation when available.',
			),
			'core/pullquote'             => array(
				'best_for'  => array( 'Short editorial callouts', 'Highlighted excerpts from the surrounding article.' ),
				'avoid_for' => array( 'Long testimonials', 'General layout spacing.' ),
				'guidance'  => 'Use pullquotes sparingly for emphasis within editorial content.',
			),
			'core/buttons'               => array(
				'best_for'  => array( 'Groups of calls to action', 'Primary and secondary action rows.' ),
				'avoid_for' => array( 'Navigation menus', 'Inline links inside paragraphs.' ),
				'guidance'  => 'Use Buttons as the parent wrapper when adding one or more Button blocks.',
			),
			'core/button'                => array(
				'best_for'  => array( 'One call-to-action link inside a Buttons block.' ),
				'avoid_for' => array( 'Standalone parentless buttons', 'Links that belong in text.' ),
				'guidance'  => 'Place Button blocks inside a Buttons block and use clear action labels.',
			),
			'core/columns'               => array(
				'best_for'  => array( 'Two to four-column layouts', 'Side-by-side content comparisons', 'Responsive content groups.' ),
				'avoid_for' => array( 'Tabular data', 'Long mobile-hostile layouts.' ),
				'guidance'  => 'Use columns for layout, not for data tables, and keep mobile stacking readable.',
			),
			'core/column'                => array(
				'best_for'  => array( 'One child area inside a Columns layout.' ),
				'avoid_for' => array( 'Standalone content outside Columns.' ),
				'guidance'  => 'Use Column only as a child of Columns.',
			),
			'core/group'                 => array(
				'best_for'  => array( 'Grouping related blocks', 'Applying shared spacing or background settings', 'Reusable content sections.' ),
				'avoid_for' => array( 'Replacing semantic blocks', 'Unnecessary wrappers around single simple blocks.' ),
				'guidance'  => 'Use groups when multiple blocks need to move or style together.',
			),
			'core/cover'                 => array(
				'best_for'  => array( 'Hero sections', 'Text over an image or color background', 'Prominent section openers.' ),
				'avoid_for' => array( 'Normal inline images', 'Content that needs detailed image inspection.' ),
				'guidance'  => 'Use cover blocks for intentional background treatments and keep overlaid text readable.',
			),
			'core/media-text'            => array(
				'best_for'  => array( 'Image plus text feature rows', 'Product explanation sections', 'Profile or service highlights.' ),
				'avoid_for' => array( 'Dense multi-column layouts', 'Image galleries.' ),
				'guidance'  => 'Use Media & Text when one visual and one text panel should stay paired.',
			),
			'core/table'                 => array(
				'best_for'  => array( 'Tabular comparisons', 'Structured rows and columns', 'Specifications.' ),
				'avoid_for' => array( 'Page layout', 'Button grids', 'Cards.' ),
				'guidance'  => 'Use tables only for real tabular data, not visual layout.',
			),
			'core/separator'             => array(
				'best_for'  => array( 'Light visual separation between sections.' ),
				'avoid_for' => array( 'Large spacing needs', 'Decorative dividers repeated too often.' ),
				'guidance'  => 'Use separators when a thematic break is meaningful.',
			),
			'core/spacer'                => array(
				'best_for'  => array( 'Intentional vertical spacing where theme spacing controls are insufficient.' ),
				'avoid_for' => array( 'Fixing layout problems', 'Repeated spacing that should be handled by block or theme settings.' ),
				'guidance'  => 'Use spacers sparingly and prefer theme spacing controls where possible.',
			),
			'core/embed'                 => array(
				'best_for'  => array( 'Embeddable third-party URLs such as videos, social posts, or documents.' ),
				'avoid_for' => array( 'Arbitrary scripts', 'Raw iframe HTML', 'Private or untrusted URLs.' ),
				'guidance'  => 'Use embed blocks for trusted supported URLs, never raw HTML embeds.',
			),
			'core/code'                  => array(
				'best_for'  => array( 'Displaying source code snippets as content.' ),
				'avoid_for' => array( 'Executing code', 'Adding scripts or styles to a page.' ),
				'guidance'  => 'Use Code only to display code text, not to run code.',
			),
			self::CUSTOM_HTML_BLOCK_NAME => array(
				'best_for'  => array(),
				'avoid_for' => array( 'All assistant-generated content', 'Layout fallbacks', 'Scripts', 'Iframes', 'Untrusted embeds.' ),
				'guidance'  => 'Never use the Custom HTML block (core/html). Choose semantic registered blocks or patterns instead.',
			),
		);
	}

	/**
	 * Infer concise pattern use cases from metadata.
	 *
	 * @param string               $title       Pattern title.
	 * @param string               $description Pattern description.
	 * @param array<string, mixed> $pattern Pattern metadata.
	 * @return list<string>
	 */
	private function pattern_use_cases( string $title, string $description, array $pattern ): array {
		$cases = array();
		if ( '' !== $description ) {
			$cases[] = $description;
		}

		foreach ( $this->string_list( $pattern['categories'] ?? array() ) as $category ) {
			$cases[] = sprintf( 'Reusable %s section or layout.', $category );
		}

		foreach ( $this->string_list( $pattern['blockTypes'] ?? array() ) as $block_type ) {
			$cases[] = sprintf( 'Content anchored around %s.', $block_type );
		}

		if ( array() === $cases ) {
			$cases[] = sprintf( 'Reusable layout matching the "%s" pattern title.', '' === $title ? 'selected' : $title );
		}

		return array_values( array_slice( array_unique( $cases ), 0, 6 ) );
	}

	/**
	 * Return flattened block names from parsed block arrays.
	 *
	 * @param array<int, mixed> $blocks Parsed blocks.
	 * @return list<string>
	 */
	private function flatten_block_names( array $blocks ): array {
		$names = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $this->sanitize_identifier( $block['blockName'] ) : '';
			if ( '' !== $name ) {
				$names[] = $name;
			}

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				array_push( $names, ...$this->flatten_block_names( $block['innerBlocks'] ) );
			}
		}

		return $names;
	}

	/**
	 * Return a string object property.
	 *
	 * @param object $object   Source object.
	 * @param string $property Property name.
	 */
	private function object_string( object $object, string $property ): string {
		$value = $this->object_value( $object, $property );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Return an object property when it exists.
	 *
	 * @param object $object   Source object.
	 * @param string $property Property name.
	 */
	private function object_value( object $object, string $property ): mixed {
		return property_exists( $object, $property ) ? $object->{$property} : null;
	}

	/**
	 * Return sanitized text without markup.
	 *
	 * @param string $value Raw text.
	 */
	private function clean_text( string $value ): string {
		return sanitize_text_field( $value );
	}

	/**
	 * Return a bounded string list.
	 *
	 * @param mixed $value Candidate list.
	 * @return list<string>
	 */
	private function string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$item = $this->clean_text( (string) $item );
				if ( '' !== $item ) {
					$items[] = $item;
				}
			}
		}

		return array_values( array_slice( array_unique( $items ), 0, 30 ) );
	}

	/**
	 * Return a bounded string map.
	 *
	 * @param mixed $value Candidate map.
	 * @return array<string, string>
	 */
	private function string_map( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $key => $item ) {
			if ( is_scalar( $key ) && is_scalar( $item ) ) {
				$key = $this->sanitize_identifier( (string) $key );
				if ( '' !== $key ) {
					$items[ $key ] = $this->clean_text( (string) $item );
				}
			}
		}

		return array_slice( $items, 0, 30, true );
	}

	/**
	 * Return attribute keys from a block attributes schema.
	 *
	 * @param mixed $attributes Candidate attributes schema.
	 * @return list<string>
	 */
	private function attribute_keys( mixed $attributes ): array {
		if ( ! is_array( $attributes ) ) {
			return array();
		}

		return array_values(
			array_slice(
				array_filter(
					array_map(
						fn( mixed $key ): string => $this->sanitize_identifier( (string) $key ),
						array_keys( $attributes )
					)
				),
				0,
				50
			)
		);
	}

	/**
	 * Return enabled support keys from a block supports array.
	 *
	 * @param mixed $supports Candidate supports config.
	 * @return list<string>
	 */
	private function support_keys( mixed $supports ): array {
		if ( ! is_array( $supports ) ) {
			return array();
		}

		$keys = array();
		foreach ( $supports as $key => $value ) {
			if ( false === $value || null === $value ) {
				continue;
			}

			if ( is_scalar( $key ) ) {
				$keys[] = sanitize_key( (string) $key );
			}
		}

		return array_values( array_slice( array_filter( array_unique( $keys ) ), 0, 50 ) );
	}

	/**
	 * Return names and labels from block styles or variations.
	 *
	 * @param mixed $items Candidate variant list.
	 * @return list<array<string, string>>
	 */
	private function named_variants( mixed $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}

		$output = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$name = isset( $item['name'] ) && is_scalar( $item['name'] ) ? $this->sanitize_identifier( (string) $item['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}

			$output[] = array_filter(
				array(
					'name'        => $name,
					'title'       => isset( $item['title'] ) && is_scalar( $item['title'] ) ? $this->clean_text( (string) $item['title'] ) : '',
					'description' => isset( $item['description'] ) && is_scalar( $item['description'] ) ? $this->clean_text( (string) $item['description'] ) : '',
				),
				static fn( string $value ): bool => '' !== $value
			);
		}

		return array_values( array_slice( $output, 0, 30 ) );
	}

	/**
	 * Determine whether a block supports inserter discovery.
	 *
	 * @param object $block Registered block object.
	 */
	private function supports_inserter( object $block ): bool {
		$supports = $this->object_value( $block, 'supports' );
		if ( is_array( $supports ) && array_key_exists( 'inserter', $supports ) ) {
			return (bool) $supports['inserter'];
		}

		return true;
	}

	/**
	 * Determine whether a pattern should be listed in inserter-like contexts.
	 *
	 * @param array<string, mixed> $pattern Pattern metadata.
	 */
	private function pattern_inserter_enabled( array $pattern ): bool {
		return ! array_key_exists( 'inserter', $pattern ) || (bool) $pattern['inserter'];
	}

	/**
	 * Check whether serialized block content contains a Custom HTML block.
	 *
	 * @param string $content Serialized block content.
	 */
	private function contains_custom_html_block( string $content ): bool {
		return str_contains( $content, '<!-- wp:html' ) || str_contains( $content, '<!-- wp:core/html' );
	}

	/**
	 * Sanitize a block or pattern identifier while preserving namespace slashes.
	 *
	 * @param string $value Raw identifier.
	 */
	private function sanitize_identifier( string $value ): string {
		$value = $this->clean_text( $value );

		return preg_replace( '/[^A-Za-z0-9_\/.-]/', '', $value ) ?? '';
	}

	/**
	 * Truncate a string for bounded MCP responses.
	 *
	 * @param string $value      Raw string.
	 * @param int    $max_length Maximum length.
	 */
	private function truncate( string $value, int $max_length ): string {
		if ( strlen( $value ) <= $max_length ) {
			return $value;
		}

		return substr( $value, 0, $max_length );
	}
}
