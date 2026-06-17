<?php
/**
 * Site Editor intelligence abilities for MCP clients.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Reads WordPress Site Editor surfaces without touching theme files.
 */
final class SiteEditorAbilities extends AbstractAbilityService {

	public const OPTION_SNAPSHOT = 'aculect_ai_companion_site_editor_snapshot';

	private const MAX_TEMPLATES          = 100;
	private const MAX_TEMPLATE_CONTENT   = 100000;
	private const MAX_NAVIGATION_ITEMS   = 50;
	private const MAX_STYLE_VARIATIONS   = 30;
	private const TEMPLATE_TYPE_TEMPLATE = 'wp_template';
	private const TEMPLATE_TYPE_PART     = 'wp_template_part';

	/**
	 * Return Site Editor context for the active theme.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_context( array $args = array() ): array {
		if ( ! $this->can_read_site_editor() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect Site Editor settings.' );
		}

		return $this->context_payload( $args );
	}

	/**
	 * Persist a plugin-owned Site Editor intelligence snapshot.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function refresh_context( array $args = array() ): array {
		if ( ! $this->can_read_site_editor() ) {
			return $this->error( 'forbidden', 'You do not have permission to refresh Site Editor intelligence.' );
		}

		$context = $this->context_payload( array_merge( $args, array( 'context' => 'compact' ) ) );
		$stored  = array(
			'refreshed_at' => gmdate( 'c' ),
			'fingerprint'  => (string) ( $context['fingerprint'] ?? '' ),
			'context'      => $context,
		);

		if ( $this->is_dry_run( $args ) ) {
			return $this->preview_response(
				'site_editor.refresh_context',
				$args,
				array(
					'type' => 'site_editor_snapshot',
					'id'   => self::OPTION_SNAPSHOT,
				),
				array(
					$this->change( 'fingerprint', $this->stored_fingerprint(), (string) $stored['fingerprint'] ),
				),
				array( 'This stores only a plugin-owned snapshot for faster MCP planning. It does not edit theme files or Site Editor records.' )
			);
		}

		update_option( self::OPTION_SNAPSHOT, $stored, false );

		return array(
			'status'            => 'success',
			'message'           => 'Site Editor intelligence snapshot refreshed.',
			'snapshot'          => $this->snapshot_summary( $stored ),
			'memory_candidates' => $context['memory_candidates'] ?? array(),
			'next_actions'      => array(
				'Use site_editor_get_context before planning template, template part, style, navigation, or pattern changes.',
				'Use memory_save with relevant memory_candidates when durable guidance should be reviewed for future MCP work.',
			),
		);
	}

	/**
	 * List block templates exposed through the Site Editor.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_templates( array $args = array() ): array {
		return $this->list_template_objects( self::TEMPLATE_TYPE_TEMPLATE, $args );
	}

	/**
	 * List block template parts exposed through the Site Editor.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function list_template_parts( array $args = array() ): array {
		return $this->list_template_objects( self::TEMPLATE_TYPE_PART, $args );
	}

	/**
	 * Read one block template.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_template( array $args ): array {
		return $this->get_template_object( self::TEMPLATE_TYPE_TEMPLATE, $args );
	}

	/**
	 * Read one block template part.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get_template_part( array $args ): array {
		return $this->get_template_object( self::TEMPLATE_TYPE_PART, $args );
	}

	/**
	 * Delete stored Site Editor intelligence.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_SNAPSHOT );
	}

	/**
	 * Build the context payload.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function context_payload( array $args ): array {
		$context        = $this->collection_context( $args );
		$theme          = $this->theme_context();
		$settings       = $this->global_settings_summary();
		$styles         = $this->global_styles_summary();
		$templates      = $this->template_collection_summary( self::TEMPLATE_TYPE_TEMPLATE );
		$template_parts = $this->template_collection_summary( self::TEMPLATE_TYPE_PART );
		$navigation     = $this->navigation_summary();
		$blocks         = ( new BlockKnowledgeAbilities() )->list_blocks(
			array(
				'context'  => 'compact',
				'purpose'  => 'layout',
				'per_page' => 12,
			)
		);
		$patterns       = ( new BlockKnowledgeAbilities() )->list_patterns(
			array(
				'context'  => 'compact',
				'search'   => 'header footer hero grid columns landing',
				'per_page' => 12,
			)
		);

		$payload = array(
			'status'            => 'ready',
			'type'              => 'site_editor',
			'label'             => 'Site Editor Intelligence',
			'description'       => 'Admin-level Site Editor context for the active theme. This reads WordPress editor data and never writes theme files.',
			'theme'             => $theme,
			'site_editor'       => array(
				'available'           => (bool) $theme['is_block_theme'],
				'admin_url'           => function_exists( 'admin_url' ) ? admin_url( 'site-editor.php' ) : '',
				'required_capability' => 'edit_theme_options',
				'change_model'        => 'Admin-level WordPress changes only; no filesystem or theme-file writes.',
			),
			'global_settings'   => $settings,
			'global_styles'     => $styles,
			'templates'         => $templates,
			'template_parts'    => $template_parts,
			'navigation'        => $navigation,
			'style_variations'  => $this->style_variations_summary(),
			'block_guidance'    => array(
				'layout_blocks' => array_values( (array) ( $blocks['items'] ?? array() ) ),
				'patterns'      => array_values( (array) ( $patterns['items'] ?? array() ) ),
				'never_use'     => array( 'core/html' ),
				'instruction'   => 'Use registered blocks, template parts, patterns, and global style settings. Never ask for file edits or raw Custom HTML blocks.',
			),
			'fingerprint'       => $this->fingerprint( compact( 'theme', 'settings', 'styles', 'templates', 'template_parts', 'navigation' ) ),
			'stored_snapshot'   => $this->snapshot_summary( (array) get_option( self::OPTION_SNAPSHOT, array() ) ),
			'memory_candidates' => $this->memory_candidates( $theme, $settings, $templates, $template_parts ),
			'next_actions'      => array(
				'For theme/editor work, call site_editor_list_templates or site_editor_list_template_parts before proposing changes.',
				'For visual layout work, inspect registered blocks and patterns before composing serialized block markup.',
				'Use site_editor_refresh_context to store a fresh plugin-owned intelligence snapshot after theme or Site Editor changes.',
			),
			'safety'            => array(
				'filesystem_writes_allowed'               => false,
				'raw_wp_options_included'                 => false,
				'secrets_included'                        => false,
				'requires_confirmation_for_future_writes' => true,
			),
		);

		if ( 'full' === $context ) {
			$payload['full_context_available'] = array(
				'templates_tool'      => ( new AbilitiesRegistry() )->tool_name( 'site_editor.list_templates' ),
				'template_parts_tool' => ( new AbilitiesRegistry() )->tool_name( 'site_editor.list_template_parts' ),
				'global_settings_api' => function_exists( 'wp_get_global_settings' ),
				'global_styles_api'   => function_exists( 'wp_get_global_styles' ),
			);
		}

		return $payload;
	}

	/**
	 * Return the active theme context.
	 *
	 * @return array<string, mixed>
	 */
	private function theme_context(): array {
		$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;

		return array(
			'name'           => is_object( $theme ) && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Name' ) : '',
			'stylesheet'     => is_object( $theme ) && method_exists( $theme, 'get_stylesheet' ) ? (string) $theme->get_stylesheet() : '',
			'template'       => is_object( $theme ) && method_exists( $theme, 'get_template' ) ? (string) $theme->get_template() : '',
			'version'        => is_object( $theme ) && method_exists( $theme, 'get' ) ? (string) $theme->get( 'Version' ) : '',
			'is_block_theme' => $this->is_block_theme(),
		);
	}

	/**
	 * Determine whether the active theme is a block theme.
	 */
	private function is_block_theme(): bool {
		if ( function_exists( 'wp_is_block_theme' ) ) {
			return (bool) wp_is_block_theme();
		}

		return function_exists( 'get_block_templates' ) || function_exists( 'wp_get_global_settings' );
	}

	/**
	 * Return merged global settings summary.
	 *
	 * @return array<string, mixed>
	 */
	private function global_settings_summary(): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array(
				'available' => false,
				'message'   => 'wp_get_global_settings is unavailable in this WordPress runtime.',
			);
		}

		$settings = wp_get_global_settings();
		$settings = is_array( $settings ) ? $settings : array();

		return array(
			'available'  => true,
			'hash'       => $this->fingerprint( $settings ),
			'color'      => $this->setting_group_summary( (array) ( $settings['color'] ?? array() ), array( 'palette', 'gradients', 'duotone' ) ),
			'typography' => $this->setting_group_summary( (array) ( $settings['typography'] ?? array() ), array( 'fontFamilies', 'fontSizes' ) ),
			'spacing'    => $this->setting_group_summary( (array) ( $settings['spacing'] ?? array() ), array( 'spacingScale', 'spacingSizes', 'units' ) ),
			'layout'     => array(
				'keys'         => array_keys( (array) ( $settings['layout'] ?? array() ) ),
				'content_size' => (string) ( $settings['layout']['contentSize'] ?? '' ),
				'wide_size'    => (string) ( $settings['layout']['wideSize'] ?? '' ),
			),
			'custom'     => array(
				'has_custom_settings' => ! empty( $settings['custom'] ),
				'keys'                => array_keys( (array) ( $settings['custom'] ?? array() ) ),
			),
		);
	}

	/**
	 * Return merged global styles summary.
	 *
	 * @return array<string, mixed>
	 */
	private function global_styles_summary(): array {
		if ( ! function_exists( 'wp_get_global_styles' ) ) {
			return array(
				'available' => false,
				'message'   => 'wp_get_global_styles is unavailable in this WordPress runtime.',
			);
		}

		$styles = wp_get_global_styles();
		$styles = is_array( $styles ) ? $styles : array();
		$blocks = (array) ( $styles['blocks'] ?? array() );

		return array(
			'available'         => true,
			'hash'              => $this->fingerprint( $styles ),
			'top_level_keys'    => array_keys( $styles ),
			'block_style_count' => count( $blocks ),
			'block_styles'      => array_slice( array_keys( $blocks ), 0, 30 ),
			'elements'          => array_keys( (array) ( $styles['elements'] ?? array() ) ),
		);
	}

	/**
	 * Return a compact setting group summary.
	 *
	 * @param array<string, mixed> $group     Settings group.
	 * @param array                $item_keys Keys that contain token lists.
	 * @phpstan-param list<string> $item_keys
	 * @return array<string, mixed>
	 */
	private function setting_group_summary( array $group, array $item_keys ): array {
		$counts = array();
		foreach ( $item_keys as $key ) {
			$counts[ $key ] = $this->count_token_items( $group[ $key ] ?? array() );
		}

		return array(
			'keys'   => array_keys( $group ),
			'counts' => $counts,
		);
	}

	/**
	 * Count token-like items in nested theme.json structures.
	 *
	 * @param mixed $value Token list.
	 */
	private function count_token_items( mixed $value ): int {
		if ( ! is_array( $value ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $value as $item ) {
			if ( is_array( $item ) && isset( $item['slug'] ) ) {
				++$count;
				continue;
			}

			if ( is_array( $item ) ) {
				$count += $this->count_token_items( $item );
			}
		}

		return $count;
	}

	/**
	 * Return a template collection response.
	 *
	 * @param string               $type Template post type.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function list_template_objects( string $type, array $args ): array {
		if ( ! $this->can_read_site_editor() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect Site Editor templates.' );
		}

		$context = $this->collection_context( $args );
		$items   = array_map(
			fn ( object $template ): array => $this->map_template( $template, 'full' === $context ),
			$this->templates( $type )
		);

		return array(
			'items'     => array_slice( array_values( $items ), 0, self::MAX_TEMPLATES ),
			'total'     => count( $items ),
			'context'   => $context,
			'type'      => $type,
			'bounded'   => true,
			'max_items' => self::MAX_TEMPLATES,
		);
	}

	/**
	 * Read one template object by ID or slug.
	 *
	 * @param string               $type Template post type.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function get_template_object( string $type, array $args ): array {
		if ( ! $this->can_read_site_editor() ) {
			return $this->error( 'forbidden', 'You do not have permission to inspect Site Editor templates.' );
		}

		$id       = sanitize_text_field( (string) ( $args['id'] ?? '' ) );
		$slug     = sanitize_key( (string) ( $args['slug'] ?? '' ) );
		$template = null;

		$template_type = $this->template_type( $type );

		if ( '' !== $id && function_exists( 'get_block_template' ) ) {
			$template = get_block_template( $id, $template_type );
		}

		if ( ! is_object( $template ) && '' !== $slug ) {
			foreach ( $this->templates( $template_type ) as $candidate ) {
				if ( (string) ( $candidate->slug ?? '' ) === $slug ) {
					$template = $candidate;
					break;
				}
			}
		}

		if ( ! is_object( $template ) ) {
			return $this->error( 'not_found', 'No Site Editor template or template part matched that ID or slug.' );
		}

		return $this->map_template( $template, true );
	}

	/**
	 * Return templates for a type.
	 *
	 * @param string $type Template post type.
	 * @return list<object>
	 */
	private function templates( string $type ): array {
		if ( ! function_exists( 'get_block_templates' ) ) {
			return array();
		}

		$templates = get_block_templates( array(), $this->template_type( $type ) );

		return array_values( array_filter( $templates, 'is_object' ) );
	}

	/**
	 * Normalize template post type to values accepted by WordPress.
	 *
	 * @param string $type Candidate template type.
	 * @return 'wp_template'|'wp_template_part'
	 */
	private function template_type( string $type ): string {
		return self::TEMPLATE_TYPE_PART === $type ? self::TEMPLATE_TYPE_PART : self::TEMPLATE_TYPE_TEMPLATE;
	}

	/**
	 * Return a template inventory summary.
	 *
	 * @param string $type Template post type.
	 * @return array<string, mixed>
	 */
	private function template_collection_summary( string $type ): array {
		$items      = array_map(
			fn ( object $template ): array => $this->map_template( $template, false ),
			$this->templates( $type )
		);
		$customized = count(
			array_filter(
				$items,
				static fn ( array $item ): bool => ! empty( $item['wp_id'] ) || 'custom' === (string) ( $item['source'] ?? '' )
			)
		);

		return array(
			'available'        => function_exists( 'get_block_templates' ),
			'total'            => count( $items ),
			'customized_count' => $customized,
			'items'            => array_slice( $items, 0, 20 ),
		);
	}

	/**
	 * Convert a WP_Block_Template-like object into safe metadata.
	 *
	 * @param object $template Template object.
	 * @param bool   $include_content Whether to include bounded block markup.
	 * @return array<string, mixed>
	 */
	private function map_template( object $template, bool $include_content ): array {
		$item = array(
			'id'             => sanitize_text_field( (string) ( $template->id ?? '' ) ),
			'slug'           => sanitize_key( (string) ( $template->slug ?? '' ) ),
			'theme'          => sanitize_key( (string) ( $template->theme ?? '' ) ),
			'type'           => sanitize_key( (string) ( $template->type ?? '' ) ),
			'source'         => sanitize_key( (string) ( $template->source ?? '' ) ),
			'origin'         => sanitize_key( (string) ( $template->origin ?? '' ) ),
			'status'         => sanitize_key( (string) ( $template->status ?? '' ) ),
			'title'          => $this->template_text( $template->title ?? '' ),
			'description'    => $this->template_text( $template->description ?? '' ),
			'area'           => sanitize_key( (string) ( $template->area ?? '' ) ),
			'wp_id'          => absint( $template->wp_id ?? 0 ),
			'has_theme_file' => ! empty( $template->has_theme_file ),
			'is_custom'      => ! empty( $template->is_custom ),
		);

		if ( $include_content ) {
			$content                   = is_scalar( $template->content ?? null ) ? (string) $template->content : '';
			$item['content']           = strlen( $content ) > self::MAX_TEMPLATE_CONTENT
				? substr( $content, 0, self::MAX_TEMPLATE_CONTENT )
				: $content;
			$item['content_truncated'] = strlen( $content ) > self::MAX_TEMPLATE_CONTENT;
			$item['content_hash']      = '' === $content ? '' : hash( 'sha256', $content );
			$item['content_guidance']  = array(
				'format'    => 'Serialized WordPress block markup.',
				'never_use' => array( 'core/html' ),
			);
		}

		return array_filter(
			$item,
			static fn ( mixed $value ): bool => null !== $value && '' !== $value
		);
	}

	/**
	 * Normalize title/description values that may be objects.
	 *
	 * @param mixed $value Raw value.
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
	 * Return navigation post summary.
	 *
	 * @return array<string, mixed>
	 */
	private function navigation_summary(): array {
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'post_type_exists' ) || ! post_type_exists( 'wp_navigation' ) ) {
			return array(
				'available' => false,
				'items'     => array(),
				'total'     => 0,
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => 'wp_navigation',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => self::MAX_NAVIGATION_ITEMS,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! current_user_can( 'read_post', (int) $post->ID ) ) {
				continue;
			}

			$items[] = array(
				'id'       => (int) $post->ID,
				'title'    => sanitize_text_field( (string) $post->post_title ),
				'status'   => sanitize_key( (string) $post->post_status ),
				'modified' => sanitize_text_field( (string) $post->post_modified_gmt ),
			);
		}

		return array(
			'available' => true,
			'items'     => $items,
			'total'     => count( $items ),
		);
	}

	/**
	 * Return style variation summary when core exposes it.
	 *
	 * @return array<string, mixed>
	 */
	private function style_variations_summary(): array {
		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) || ! method_exists( '\WP_Theme_JSON_Resolver', 'get_style_variations' ) ) {
			return array(
				'available' => false,
				'items'     => array(),
				'total'     => 0,
			);
		}

		$variations = \WP_Theme_JSON_Resolver::get_style_variations();
		$items      = array();
		foreach ( $variations as $variation ) {
			if ( ! is_array( $variation ) ) {
				continue;
			}

			$items[] = array_filter(
				array(
					'title' => sanitize_text_field( (string) ( $variation['title'] ?? '' ) ),
					'slug'  => sanitize_key( (string) ( $variation['slug'] ?? '' ) ),
				)
			);
		}

		return array(
			'available' => true,
			'items'     => array_slice( $items, 0, self::MAX_STYLE_VARIATIONS ),
			'total'     => count( $items ),
		);
	}

	/**
	 * Return memory candidates for durable review.
	 *
	 * @param array<string, mixed> $theme          Theme context.
	 * @param array<string, mixed> $settings       Global settings summary.
	 * @param array<string, mixed> $templates      Templates summary.
	 * @param array<string, mixed> $template_parts Template parts summary.
	 * @return list<array<string, string>>
	 */
	private function memory_candidates( array $theme, array $settings, array $templates, array $template_parts ): array {
		$theme_name = (string) ( $theme['name'] ?? '' );
		$stylesheet = (string) ( $theme['stylesheet'] ?? '' );
		$is_block   = ! empty( $theme['is_block_theme'] ) ? 'block theme' : 'classic theme';
		$palette    = (int) ( $settings['color']['counts']['palette'] ?? 0 );
		$fonts      = (int) ( $settings['typography']['counts']['fontFamilies'] ?? 0 );

		return array_values(
			array_filter(
				array(
					'' === $theme_name ? array() : array(
						'key'        => 'site_editor.theme.active',
						'domain'     => 'site',
						'value'      => sprintf( 'Active theme is %s (%s), detected as a %s.', $theme_name, $stylesheet, $is_block ),
						'evidence'   => 'Site Editor intelligence read the active WordPress theme metadata.',
						'confidence' => 'high',
						'source'     => 'site_editor',
					),
					array(
						'key'        => 'site_editor.design.tokens',
						'domain'     => 'workflow',
						'value'      => sprintf( 'Site Editor exposes %d color palette entries and %d font families through merged global settings.', $palette, $fonts ),
						'evidence'   => 'Site Editor intelligence summarized merged global settings without exposing raw option values.',
						'confidence' => 0 < $palette || 0 < $fonts ? 'medium' : 'low',
						'source'     => 'site_editor',
					),
					array(
						'key'        => 'site_editor.template.inventory',
						'domain'     => 'workflow',
						'value'      => sprintf( 'Site Editor inventory currently has %d templates and %d template parts.', (int) ( $templates['total'] ?? 0 ), (int) ( $template_parts['total'] ?? 0 ) ),
						'evidence'   => 'Site Editor intelligence summarized block templates and template parts.',
						'confidence' => 'medium',
						'source'     => 'site_editor',
					),
				)
			)
		);
	}

	/**
	 * Return the current stored fingerprint.
	 */
	private function stored_fingerprint(): string {
		$stored = get_option( self::OPTION_SNAPSHOT, array() );

		return is_array( $stored ) ? (string) ( $stored['fingerprint'] ?? '' ) : '';
	}

	/**
	 * Return a bounded snapshot summary.
	 *
	 * @param array<string, mixed> $stored Stored snapshot.
	 * @return array<string, mixed>
	 */
	private function snapshot_summary( array $stored ): array {
		return array(
			'available'    => array() !== $stored,
			'refreshed_at' => (string) ( $stored['refreshed_at'] ?? '' ),
			'fingerprint'  => (string) ( $stored['fingerprint'] ?? '' ),
		);
	}

	/**
	 * Compute a stable fingerprint for bounded payloads.
	 *
	 * @param mixed $value Payload.
	 */
	private function fingerprint( mixed $value ): string {
		$json = wp_json_encode( $value );

		return false === $json ? '' : hash( 'sha256', $json );
	}

	/**
	 * Check read permission for admin-level Site Editor surfaces.
	 */
	private function can_read_site_editor(): bool {
		return ! function_exists( 'current_user_can' ) || current_user_can( 'edit_theme_options' );
	}
}
