<?php
/**
 * Safe site-structure discovery abilities for reusable blocks and block areas.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Reads reusable-block and widget/block-area metadata without returning full bodies.
 */
final class SiteStructureDiscoveryAbilities extends AbstractAbilityService {

	private const DEFAULT_PER_PAGE    = 20;
	private const MAX_PER_PAGE        = 100;
	private const MAX_REUSABLE_BLOCKS = 100;
	private const MAX_CONTENT_PREVIEW = 600;
	private const REUSABLE_BLOCK_TYPE = 'wp_block';
	private const TEMPLATE_PART_TYPE  = 'wp_template_part';
	private const TEMPLATE_PART_MAX   = 100;

	/**
	 * List reusable blocks/synced patterns with bounded metadata.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_reusable_blocks( array $args = array() ): array {
		if ( ! $this->can_read_reusable_blocks() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect reusable blocks or synced patterns.' );
		}

		if ( ! function_exists( 'post_type_exists' ) || ! post_type_exists( self::REUSABLE_BLOCK_TYPE ) || ! function_exists( 'get_posts' ) ) {
			return array(
				'items'       => array(),
				'total'       => 0,
				'page'        => 1,
				'per_page'    => $this->per_page( $args ),
				'type'        => self::REUSABLE_BLOCK_TYPE,
				'available'   => false,
				'bounded'     => true,
				'read_only'   => true,
				'message'     => 'Reusable blocks are not available in this WordPress runtime.',
				'next_action' => 'Use registered block patterns or Site Editor template parts when reusable blocks are unavailable.',
			);
		}

		$page            = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page        = $this->per_page( $args );
		$include_preview = ! empty( $args['include_preview'] );
		$statuses        = $this->statuses_from_args( $args, array( 'publish', 'draft', 'pending', 'private' ) );
		$posts           = get_posts(
			array(
				'post_type'      => self::REUSABLE_BLOCK_TYPE,
				'post_status'    => $statuses,
				'posts_per_page' => self::MAX_REUSABLE_BLOCKS,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$items           = array_values(
			array_filter(
				array_map(
					fn ( \WP_Post $post ): array => $this->map_reusable_block( $post, $include_preview ),
					$posts
				)
			)
		);
		$total           = count( $items );

		return array(
			'items'               => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			'total'               => $total,
			'page'                => $page,
			'per_page'            => $per_page,
			'type'                => self::REUSABLE_BLOCK_TYPE,
			'available'           => true,
			'bounded'             => true,
			'max_items_scanned'   => self::MAX_REUSABLE_BLOCKS,
			'include_preview'     => $include_preview,
			'preview_max_bytes'   => self::MAX_CONTENT_PREVIEW,
			'read_only'           => true,
			'content_body_note'   => 'Full reusable-block content bodies are never returned by this list tool.',
			'required_capability' => 'edit_posts',
		);
	}

	/**
	 * List registered sidebars/widget areas and block-theme template-part areas.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_block_areas( array $args = array() ): array {
		unset( $args );

		if ( ! $this->can_read_block_areas() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect widget or block areas.' );
		}

		$is_block_theme      = $this->is_block_theme();
		$sidebars            = $this->sidebars();
		$template_part_areas = $this->template_part_areas();

		return array(
			'theme'               => $this->theme_context( $is_block_theme ),
			'site_structure_mode' => $is_block_theme ? 'block_theme' : 'classic_theme',
			'guidance'            => $is_block_theme
				? 'This site uses a block theme. Prefer Site Editor templates and template parts; classic widget areas may be absent or secondary.'
				: 'This site uses a classic theme. Widget areas and sidebars are the primary theme-owned placement surfaces when registered.',
			'widget_areas'        => array(
				'available' => array() !== $sidebars,
				'total'     => count( $sidebars ),
				'items'     => $sidebars,
			),
			'block_areas'         => array(
				'available' => $is_block_theme && array() !== $template_part_areas,
				'total'     => count( $template_part_areas ),
				'items'     => $template_part_areas,
			),
			'bounded'             => true,
			'read_only'           => true,
			'required_capability' => 'edit_theme_options',
			'safety'              => array(
				'mutates_widgets'       => false,
				'mutates_templates'     => false,
				'raw_theme_files_read'  => false,
				'full_content_included' => false,
			),
		);
	}

	/**
	 * Convert a reusable block post to safe metadata.
	 *
	 * @param \WP_Post $post            Reusable block post.
	 * @param bool     $include_preview Whether to include a bounded text preview.
	 * @return array<string, mixed>
	 */
	private function map_reusable_block( \WP_Post $post, bool $include_preview ): array {
		if ( ! current_user_can( 'read_post', (int) $post->ID ) ) {
			return array();
		}

		$content = (string) $post->post_content;
		$item    = array_filter(
			array(
				'id'           => (int) $post->ID,
				'title'        => sanitize_text_field( (string) $post->post_title ),
				'name'         => sanitize_title( (string) $post->post_name ),
				'type'         => 'synced_pattern',
				'source'       => self::REUSABLE_BLOCK_TYPE,
				'status'       => sanitize_key( (string) $post->post_status ),
				'modified_gmt' => sanitize_text_field( (string) $post->post_modified_gmt ),
				'usage_hints'  => $this->reusable_block_usage_hints( $content ),
				'edit_link'    => $this->edit_link( (int) $post->ID ),
				'view_link'    => $this->view_link( $post ),
			),
			static fn ( mixed $value ): bool => null !== $value && '' !== $value && array() !== $value
		);

		if ( $include_preview ) {
			$preview                   = $this->bounded_preview( $content );
			$item['content_preview']   = $preview['preview'];
			$item['preview_truncated'] = $preview['truncated'];
			$item['content_hash']      = '' === $content ? '' : hash( 'sha256', $content );
		}

		return $item;
	}

	/**
	 * Return local usage hints from reusable block markup only.
	 *
	 * @param string $content Serialized block content.
	 * @return array<string, mixed>
	 */
	private function reusable_block_usage_hints( string $content ): array {
		$block_names = $this->block_names( $content );

		return array_filter(
			array(
				'block_count'     => count( $block_names ),
				'block_names'     => array_slice( array_values( array_unique( $block_names ) ), 0, 20 ),
				'has_navigation'  => in_array( 'core/navigation', $block_names, true ),
				'has_query_loop'  => in_array( 'core/query', $block_names, true ),
				'has_template_ui' => (bool) array_intersect( $block_names, array( 'core/template-part', 'core/post-template' ) ),
			),
			static fn ( mixed $value ): bool => array() !== $value && false !== $value
		);
	}

	/**
	 * Return parsed block names from serialized markup.
	 *
	 * @param string $content Serialized block content.
	 * @return list<string>
	 */
	private function block_names( string $content ): array {
		if ( '' === trim( $content ) || ! function_exists( 'parse_blocks' ) ) {
			return array();
		}

		return $this->flatten_block_names( parse_blocks( $content ) );
	}

	/**
	 * Flatten parsed block names.
	 *
	 * @param array<array-key, array<string, mixed>> $blocks Parsed block list.
	 * @return list<string>
	 */
	private function flatten_block_names( array $blocks ): array {
		$names = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
			if ( '' !== $name ) {
				$names[] = $name;
			}

			if ( is_array( $block['innerBlocks'] ?? null ) ) {
				array_push( $names, ...$this->flatten_block_names( $block['innerBlocks'] ) );
			}
		}

		return $names;
	}

	/**
	 * Return a bounded plain-text content preview.
	 *
	 * @param string $content Serialized block content.
	 * @return array{preview: string, truncated: bool}
	 */
	private function bounded_preview( string $content ): array {
		$content   = (string) preg_replace( '/<!--.*?-->/s', '', $content );
		$text      = trim( wp_strip_all_tags( $content ) );
		$truncated = strlen( $text ) > self::MAX_CONTENT_PREVIEW;

		return array(
			'preview'   => $truncated ? substr( $text, 0, self::MAX_CONTENT_PREVIEW ) : $text,
			'truncated' => $truncated,
		);
	}

	/**
	 * Return registered classic widget/sidebar areas.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function sidebars(): array {
		global $wp_registered_sidebars, $wp_registered_widgets;

		$registered = is_array( $wp_registered_sidebars ?? null ) ? $wp_registered_sidebars : array();
		$widgets    = (array) get_option( 'sidebars_widgets', array() );
		$items      = array();

		foreach ( $registered as $id => $sidebar ) {
			if ( ! is_array( $sidebar ) ) {
				continue;
			}

			$sidebar_id  = sanitize_key( (string) ( $sidebar['id'] ?? $id ) );
			$widget_ids  = array_values( array_filter( array_map( 'strval', (array) ( $widgets[ $sidebar_id ] ?? array() ) ) ) );
			$widget_info = array();

			foreach ( array_slice( $widget_ids, 0, 20 ) as $widget_id ) {
				$widget_info[] = array_filter(
					array(
						'id'   => sanitize_text_field( $widget_id ),
						'name' => sanitize_text_field( (string) ( $wp_registered_widgets[ $widget_id ]['name'] ?? '' ) ),
					),
					static fn ( mixed $value ): bool => '' !== $value
				);
			}

			$items[] = array(
				'id'           => $sidebar_id,
				'name'         => sanitize_text_field( (string) ( $sidebar['name'] ?? $sidebar_id ) ),
				'description'  => sanitize_text_field( (string) ( $sidebar['description'] ?? '' ) ),
				'type'         => 'widget_area',
				'source'       => 'registered_sidebar',
				'status'       => array() === $widget_ids ? 'inactive' : 'active',
				'widget_count' => count( $widget_ids ),
				'widgets'      => $widget_info,
				'admin_url'    => function_exists( 'admin_url' ) ? admin_url( 'widgets.php' ) : '',
			);
		}

		return $items;
	}

	/**
	 * Return block-theme template-part areas exposed by WordPress.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function template_part_areas(): array {
		if ( ! function_exists( 'get_block_templates' ) ) {
			return array();
		}

		$area_labels = $this->template_part_area_labels();
		$templates   = array_slice( get_block_templates( array(), self::TEMPLATE_PART_TYPE ), 0, self::TEMPLATE_PART_MAX );
		$areas       = array();

		foreach ( $templates as $template ) {
			if ( ! is_object( $template ) ) {
				continue;
			}

			$template_data = get_object_vars( $template );
			$area          = sanitize_key( (string) ( $template_data['area'] ?? '' ) );
			if ( '' === $area ) {
				$area = 'uncategorized';
			}

			if ( ! isset( $areas[ $area ] ) ) {
				$areas[ $area ] = array(
					'id'                  => $area,
					'name'                => $area_labels[ $area ] ?? ucfirst( str_replace( '-', ' ', $area ) ),
					'type'                => 'block_area',
					'source'              => 'template_part',
					'status'              => 'active',
					'template_part_count' => 0,
					'template_parts'      => array(),
					'admin_url'           => function_exists( 'admin_url' ) ? admin_url( 'site-editor.php?path=/patterns' ) : '',
				);
			}

			++$areas[ $area ]['template_part_count'];
			$areas[ $area ]['template_parts'][] = array_filter(
				array(
					'id'        => sanitize_text_field( (string) ( $template_data['id'] ?? '' ) ),
					'slug'      => sanitize_key( (string) ( $template_data['slug'] ?? '' ) ),
					'title'     => $this->template_text( $template_data['title'] ?? '' ),
					'source'    => sanitize_key( (string) ( $template_data['source'] ?? '' ) ),
					'status'    => sanitize_key( (string) ( $template_data['status'] ?? '' ) ),
					'edit_link' => $this->template_part_edit_link( $template ),
				),
				static fn ( mixed $value ): bool => '' !== $value
			);
		}

		return array_values( $areas );
	}

	/**
	 * Return labels for known template-part areas.
	 *
	 * @return array<string, string>
	 */
	private function template_part_area_labels(): array {
		$labels = array();
		if ( function_exists( 'get_allowed_block_template_part_areas' ) ) {
			foreach ( get_allowed_block_template_part_areas() as $area ) {
				$labels[ sanitize_key( (string) $area['area'] ) ] = sanitize_text_field( (string) $area['label'] );
			}
		}

		return $labels;
	}

	/**
	 * Normalize template titles.
	 *
	 * @param mixed $value Raw title.
	 */
	private function template_text( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = $value['rendered'] ?? $value['raw'] ?? '';
		} elseif ( is_object( $value ) ) {
			$value = $value->rendered ?? $value->raw ?? '';
		}

		return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
	}

	/**
	 * Return the active theme context.
	 *
	 * @param bool $is_block_theme Whether the active theme is a block theme.
	 * @return array<string, mixed>
	 */
	private function theme_context( bool $is_block_theme ): array {
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;

		return array(
			'name'           => is_object( $theme ) && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Name' ) : '',
			'stylesheet'     => is_object( $theme ) && method_exists( $theme, 'get_stylesheet' ) ? (string) $theme->get_stylesheet() : '',
			'template'       => is_object( $theme ) && method_exists( $theme, 'get_template' ) ? (string) $theme->get_template() : '',
			'is_block_theme' => $is_block_theme,
		);
	}

	/**
	 * Check whether the active theme is a block theme.
	 */
	private function is_block_theme(): bool {
		return function_exists( 'wp_is_block_theme' ) ? (bool) wp_is_block_theme() : false;
	}

	/**
	 * Return an edit link when safe.
	 *
	 * @param int $post_id Post ID.
	 */
	private function edit_link( int $post_id ): string {
		if ( $post_id <= 0 || ! function_exists( 'get_edit_post_link' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return '';
		}

		return (string) get_edit_post_link( $post_id, 'raw' );
	}

	/**
	 * Return a view link when WordPress exposes one.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function view_link( \WP_Post $post ): string {
		return function_exists( 'get_permalink' ) ? (string) get_permalink( $post ) : '';
	}

	/**
	 * Return a Site Editor edit link for a template part.
	 *
	 * @param object $template Template object.
	 */
	private function template_part_edit_link( object $template ): string {
		$id = sanitize_text_field( (string) ( $template->id ?? '' ) );
		if ( '' === $id || ! function_exists( 'admin_url' ) ) {
			return '';
		}

		return admin_url( 'site-editor.php?postType=wp_template_part&postId=' . rawurlencode( $id ) );
	}

	/**
	 * Bound collection page size.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 */
	private function per_page( array $args ): int {
		return max( 1, min( self::MAX_PER_PAGE, (int) ( $args['per_page'] ?? self::DEFAULT_PER_PAGE ) ) );
	}

	/**
	 * Check read access for reusable blocks/synced patterns.
	 */
	private function can_read_reusable_blocks(): bool {
		return ! function_exists( 'current_user_can' ) || current_user_can( 'edit_posts' ) || current_user_can( 'edit_theme_options' );
	}

	/**
	 * Check read access for widget/block areas.
	 */
	private function can_read_block_areas(): bool {
		return ! function_exists( 'current_user_can' ) || current_user_can( 'edit_theme_options' );
	}
}
