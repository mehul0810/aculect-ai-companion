<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Intelligence\ContentIndexer;

/**
 * Higher-level content workflows that guide assistant clients through safe WordPress authoring.
 */
final class ContentWorkflowAbilities extends AbstractAbilityService {

	private const MIN_LONG_FORM_WORDS          = 3000;
	private const MAX_LONG_FORM_WORDS          = 5000;
	private const DEFAULT_LONG_FORM_WORDS      = 3500;
	private const MAX_SERIALIZED_CONTENT_BYTES = 300000;
	private const BLOCK_COMMENT_PATTERN        = '/<!--\s+(\/?)wp:([A-Za-z0-9_\/.-]+)(?:\s+({.*?}))?\s*(\/?)-->/s';

	private const SEMANTIC_BLOCKS = array(
		'core/heading',
		'core/paragraph',
		'core/list',
		'core/quote',
		'core/image',
		'core/buttons',
		'core/table',
		'core/separator',
	);

	private const LAYOUT_BLOCKS = array(
		'core/group',
		'core/columns',
		'core/column',
		'core/cover',
		'core/media-text',
		'core/gallery',
		'core/image',
		'core/heading',
		'core/paragraph',
		'core/list',
		'core/buttons',
		'core/button',
		'core/separator',
	);

	private const CONTENT_MODES = array(
		'article',
		'page',
		'landing_page',
		'visual_layout',
		'service_page',
		'product_page',
		'case_study',
	);

	private const LAYOUT_MODES = array(
		'page',
		'landing_page',
		'visual_layout',
		'service_page',
		'product_page',
		'case_study',
	);

	/**
	 * Build a deterministic long-form content plan for an assistant client.
	 *
	 * @param array<string, mixed> $args Workflow arguments.
	 * @return array<string, mixed>
	 */
	public function prepare_post( array $args ): array {
		$brief              = $this->clean_text( (string) ( $args['brief'] ?? '' ) );
		$post_type          = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		$audience           = $this->clean_text( (string) ( $args['audience'] ?? '' ) );
		$seo_intent         = $this->clean_text( (string) ( $args['seo_intent'] ?? '' ) );
		$layout_intent      = $this->clean_text( (string) ( $args['layout_intent'] ?? '' ) );
		$visual_reference   = $this->clean_text( (string) ( $args['visual_reference_summary'] ?? '' ) );
		$section_requests   = $this->clean_string_list( $args['section_requirements'] ?? array(), 12 );
		$preferred_families = $this->block_families( $args['preferred_block_families'] ?? array() );
		$preferred_blocks   = $this->block_names( $args['preferred_blocks'] ?? array() );
		$preferred_patterns = $this->identifier_list( $args['preferred_patterns'] ?? array(), 12 );
		$desired_word_count = $this->desired_word_count( $args );
		$existing_post_id   = absint( $args['existing_post_id'] ?? 0 );

		if ( '' === $brief ) {
			return $this->with_workflow_session(
				$this->workflow_error( 'invalid_brief', 'Provide a brief for the content workflow.' ),
				$args,
				'prepared',
				'content_workflow_prepare_post'
			);
		}

		$post_type    = '' === $post_type ? 'post' : $post_type;
		$content_mode = $this->content_mode( $args, $brief, $post_type, $layout_intent, $visual_reference, $section_requests );
		$operations   = ( new McpToolAvailability() )->operations_manifest_for_current_user();
		$outline      = $this->content_outline( $content_mode, $desired_word_count, $section_requests );
		$context      = $this->intelligence_context( $brief, $post_type, $seo_intent, $operations );
		$block_plan   = $this->block_plan(
			$content_mode,
			$outline,
			$layout_intent,
			$visual_reference,
			$preferred_families,
			$preferred_blocks,
			$preferred_patterns
		);

		return $this->with_workflow_session(
			array(
				'status'               => 'ready',
				'workflow'             => 'content_workflow_prepare_post',
				'content_mode'         => $content_mode,
				'post_type'            => $post_type,
				'brief'                => $brief,
				'audience'             => $audience,
				'seo_intent'           => $seo_intent,
				'layout_intent'        => $layout_intent,
				'visual_reference'     => $visual_reference,
				'section_requirements' => $section_requests,
				'desired_word_count'   => $desired_word_count,
				'existing_post_id'     => $existing_post_id,
				'outline'              => $outline,
				'block_plan'           => $block_plan,
				'recommendations'      => array(
					'taxonomies' => 'Use available taxonomy tools to select existing terms before writing.',
					'media'      => $this->is_layout_mode( $content_mode )
						? 'For visual/page layouts, pair existing image attachment IDs with core/image, core/cover, core/media-text, or gallery blocks as the layout requires; upload only when media upload is available.'
						: 'Use an existing image attachment ID for featured_media; upload only when the media upload operation is available.',
					'seo'        => 'Prepare Rank Math meta_title, meta_description, and focus_keywords when SEO metadata is requested.',
				),
				'required_operations'  => array(
					'create_draft'        => $operations['workflows']['create_draft'] ?? array(),
					'update_post'         => $operations['workflows']['update_post'] ?? array(),
					'update_rankmath_seo' => $operations['workflows']['update_rankmath_seo'] ?? array(),
					'search_chunks'       => $operations['intelligence_index']['search_chunks'] ?? array(),
					'internal_links'      => $operations['intelligence_index']['internal_links'] ?? array(),
					'validate_blocks'     => array(
						'tool'      => ( new AbilitiesRegistry() )->tool_name( 'intelligence.content.validate_blocks' ),
						'available' => true,
						'read_only' => true,
					),
					'block_discovery'     => array(
						'tool'      => ( new AbilitiesRegistry() )->tool_name( 'intelligence.blocks.list_available' ),
						'available' => true,
						'read_only' => true,
					),
					'pattern_discovery'   => array(
						'tool'      => ( new AbilitiesRegistry() )->tool_name( 'intelligence.patterns.list_available' ),
						'available' => true,
						'read_only' => true,
					),
				),
				'intelligence_context' => $context,
				'operations'           => $operations,
				'next_actions'         => array(
					'Use intelligence_context.memories, related_items, relevant_chunks, and internal_links while drafting.',
					$this->is_layout_mode( $content_mode )
						? 'Generate sectioned, layout-aware serialized WordPress block markup using the outline section IDs and layout_plan; prefer registered patterns, core/group, core/columns, core/cover, core/media-text, and editable media/text blocks when they match the visual direction.'
						: 'Generate sectioned serialized WordPress block markup using the outline section IDs.',
					'Validate the full block document before any write.',
					'Call content_workflow_create_draft for new long-form content or content_workflow_update_post for an existing item.',
				),
			),
			$args,
			'prepared',
			'content_workflow_prepare_post'
		);
	}

	/**
	 * Create a draft from validated serialized WordPress block content.
	 *
	 * @param array<string, mixed> $args Workflow arguments.
	 * @return array<string, mixed>
	 */
	public function create_draft( array $args ): array {
		$validated = $this->validated_block_document( $args );
		if ( isset( $validated['error'] ) ) {
			return $this->with_workflow_session( $validated, $args, 'validated', 'content_workflow_create_draft' );
		}

		$payload              = $this->content_payload( $args );
		$payload['content']   = $validated['content'];
		$payload['post_type'] = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		$payload['status']    = 'draft';

		$result   = ( new ContentAbilities() )->create_item( $payload );
		$response = $this->content_result_response( 'content_workflow_create_draft', $result, $validated, $args );
		$state    = true === ( $response['dry_run'] ?? false ) ? 'validated' : 'draft_created';

		return $this->with_workflow_session(
			$response,
			$args,
			$state,
			'content_workflow_create_draft'
		);
	}

	/**
	 * Update an existing post from validated serialized WordPress block content.
	 *
	 * @param array<string, mixed> $args Workflow arguments.
	 * @return array<string, mixed>
	 */
	public function update_post( array $args ): array {
		$post_id = absint( $args['id'] ?? 0 );
		if ( 0 >= $post_id ) {
			return $this->with_workflow_session(
				$this->workflow_error( 'invalid_post_id', 'Provide an existing post ID.' ),
				$args,
				'updated',
				'content_workflow_update_post'
			);
		}

		$payload            = $this->content_payload( $args );
		$has_document       = array_key_exists( 'content', $args ) || array_key_exists( 'section_map', $args );
		$validated          = array();
		$preview_args       = $payload;
		$preview_args['id'] = $post_id;

		if ( $has_document ) {
			$validated = $this->validated_block_document( $args, $post_id );
			if ( isset( $validated['error'] ) ) {
				return $this->with_workflow_session( $validated, $args, 'validated', 'content_workflow_update_post' );
			}

			$payload['content']      = $validated['content'];
			$preview_args['content'] = $validated['content'];
		}

		if ( array() === $payload ) {
			return $this->with_workflow_session(
				$this->workflow_error( 'invalid_update_fields', 'Provide title, content, excerpt, slug, taxonomy, featured media, date, author, or SEO fields to update.' ),
				$args,
				'updated',
				'content_workflow_update_post'
			);
		}

		if ( $this->is_dry_run( $args ) ) {
			$preview_args['dry_run'] = true;
			$result                  = ( new ContentAbilities() )->update_item( $preview_args );
			$response                = $this->content_result_response( 'content_workflow_update_post', $result, $validated, $args );

			return $this->with_workflow_session(
				$response,
				$args,
				'validated',
				'content_workflow_update_post'
			);
		}

		$payload['id'] = $post_id;
		$result        = ( new ContentAbilities() )->update_item( $payload );

		$response = $this->content_result_response( 'content_workflow_update_post', $result, $validated, $args );

		return $this->with_workflow_session( $response, $args, 'updated', 'content_workflow_update_post' );
	}

	/**
	 * Update Rank Math metadata through a workflow-specific tool.
	 *
	 * @param array<string, mixed> $args SEO arguments.
	 * @return array<string, mixed>
	 */
	public function update_rankmath_seo( array $args ): array {
		$args['plugin'] = 'rank_math';
		$result         = ( new SeoAbilities() )->update_seo( $args );
		if ( isset( $result['error'] ) ) {
			return $this->with_workflow_session(
				$this->workflow_error( (string) $result['error'], (string) ( $result['message'] ?? 'Rank Math SEO metadata could not be updated.' ), array( 'seo' => $result ) ),
				$args,
				'seo_applied',
				'seo_workflow_update_rankmath'
			);
		}

		if ( true === ( $result['dry_run'] ?? false ) ) {
			$result['workflow'] = 'seo_workflow_update_rankmath';
			return $this->with_workflow_session( $result, $args, 'validated', 'seo_workflow_update_rankmath' );
		}

		return $this->with_workflow_session(
			array(
				'status'       => 'success',
				'workflow'     => 'seo_workflow_update_rankmath',
				'post_id'      => (int) ( $result['post_id'] ?? 0 ),
				'plugin'       => 'rank_math',
				'fields'       => (array) ( $result['fields'] ?? array() ),
				'changes'      => array(),
				'warnings'     => array(),
				'next_actions' => array( 'Review the Rank Math fields in the WordPress editor.' ),
			),
			$args,
			'seo_applied',
			'seo_workflow_update_rankmath'
		);
	}

	/**
	 * Validate long-form serialized block content.
	 *
	 * @param array<string, mixed> $args Workflow arguments.
	 * @param int                  $post_id Optional post ID for section-map merges.
	 * @return array<string, mixed>
	 */
	private function validated_block_document( array $args, int $post_id = 0 ): array {
		$section_update = array();
		if ( array_key_exists( 'section_map', $args ) && ! array_key_exists( 'content', $args ) && $post_id > 0 && 'replace' !== sanitize_key( (string) ( $args['update_mode'] ?? 'sections' ) ) ) {
			$section_update = $this->merged_section_document( $post_id, $args['section_map'] );
			if ( isset( $section_update['error'] ) ) {
				return $section_update;
			}
			$content = (string) ( $section_update['content'] ?? '' );
		} else {
			$content = array_key_exists( 'section_map', $args ) && ! array_key_exists( 'content', $args )
				? $this->content_from_section_map( $args['section_map'] )
				: (string) ( $args['content'] ?? '' );
		}

		$content = trim( $content );
		if ( '' === $content ) {
			return $this->workflow_error( 'invalid_block_content', 'Provide serialized WordPress block content.' );
		}

		if ( self::MAX_SERIALIZED_CONTENT_BYTES < strlen( $content ) ) {
			return $this->workflow_error(
				'content_too_large',
				sprintf( 'Serialized block content must be %d bytes or less.', self::MAX_SERIALIZED_CONTENT_BYTES )
			);
		}

		if ( ! str_contains( $content, '<!-- wp:' ) ) {
			return $this->workflow_error( 'invalid_block_content', 'Use serialized WordPress block markup, not raw HTML or plain text.' );
		}

		$validation = ( new BlockKnowledgeAbilities() )->validate_block_content(
			array_merge(
				array( 'content' => $content ),
				$this->block_validation_context( $args )
			)
		);
		if ( isset( $validation['error'] ) ) {
			return $this->workflow_error( (string) $validation['error'], (string) ( $validation['message'] ?? 'Block validation failed.' ), array( 'block_validation' => $validation ) );
		}

		if ( true !== ( $validation['valid'] ?? false ) ) {
			return $this->workflow_error(
				'invalid_block_content',
				(string) ( $validation['message'] ?? 'Block content must use registered WordPress blocks and must not include core/html.' ),
				array(
					'block_validation' => $validation,
					'warnings'         => (array) ( $validation['warnings'] ?? array() ),
				)
			);
		}

		$result = array(
			'content'          => $content,
			'block_validation' => $validation,
		);

		if ( array() !== $section_update ) {
			$result['section_updates']    = (array) ( $section_update['section_updates'] ?? array() );
			$result['available_sections'] = (array) ( $section_update['available_sections'] ?? array() );
		}

		return $result;
	}

	/**
	 * Convert a section map into a full serialized block document.
	 *
	 * @param mixed $section_map Section map argument.
	 */
	private function content_from_section_map( mixed $section_map ): string {
		return trim( implode( "\n\n", array_values( $this->section_content_map( $section_map ) ) ) );
	}

	/**
	 * Merge provided section content into an existing serialized block document.
	 *
	 * @param int   $post_id     Existing post ID.
	 * @param mixed $section_map Section map argument.
	 * @return array<string, mixed>
	 */
	private function merged_section_document( int $post_id, mixed $section_map ): array {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->workflow_error( 'forbidden', 'You do not have permission to update this content item.' );
		}

		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return $this->workflow_error( 'not_found', 'Content item not found.' );
		}

		$updates = $this->section_content_map( $section_map );
		if ( array() === $updates ) {
			return $this->workflow_error( 'invalid_section_map', 'Provide section IDs mapped to serialized block content.' );
		}

		$sections = $this->document_sections( (string) $post->post_content );
		if ( array() === $sections ) {
			return $this->workflow_error( 'section_merge_unavailable', 'Existing content must contain stable heading sections before section_map updates can be merged.' );
		}

		$available_ids = array_values( array_unique( array_column( $sections, 'id' ) ) );
		$missing_ids   = array_values( array_diff( array_keys( $updates ), $available_ids ) );
		if ( array() !== $missing_ids ) {
			return $this->workflow_error(
				'section_not_found',
				'One or more section_map IDs were not found in the existing block document.',
				array(
					'missing_section_ids'   => $missing_ids,
					'available_section_ids' => $available_ids,
				)
			);
		}

		$content = (string) $post->post_content;
		$merged  = substr( $content, 0, (int) $sections[0]['start'] );
		foreach ( $sections as $section ) {
			$id      = (string) $section['id'];
			$merged .= $updates[ $id ] ?? (string) $section['content'];
		}

		return array(
			'content'            => trim( $merged ),
			'section_updates'    => array_values( array_keys( $updates ) ),
			'available_sections' => $available_ids,
		);
	}

	/**
	 * Normalize section_map input into section ID => block markup.
	 *
	 * @param mixed $section_map Section map argument.
	 * @return array<string, string>
	 */
	private function section_content_map( mixed $section_map ): array {
		if ( ! is_array( $section_map ) ) {
			return array();
		}

		$sections = array();
		foreach ( $section_map as $key => $section ) {
			$content = '';
			$id      = is_string( $key ) ? $key : '';

			if ( is_array( $section ) ) {
				$content = trim( (string) ( $section['content'] ?? '' ) );
				foreach ( array( 'id', 'section_id', 'anchor', 'heading' ) as $id_key ) {
					if ( '' === $id && isset( $section[ $id_key ] ) && is_scalar( $section[ $id_key ] ) ) {
						$id = (string) $section[ $id_key ];
					}
				}
			} elseif ( is_scalar( $section ) ) {
				$content = trim( (string) $section );
			}

			if ( '' === $id ) {
				$id = $this->section_id_from_heading_block( $content );
			}

			$id = $this->normalize_section_id( $id );
			if ( '' !== $id && '' !== $content ) {
				$sections[ $id ] = $content;
			}
		}

		return $sections;
	}

	/**
	 * Split an existing serialized document into heading-led sections.
	 *
	 * @param string $content Existing serialized block content.
	 * @return list<array{id: string, start: int, content: string}>
	 */
	private function document_sections( string $content ): array {
		if ( '' === trim( $content ) || ! str_contains( $content, '<!-- wp:heading' ) ) {
			return array();
		}

		$blocks = $this->top_level_block_sections( $content );
		if ( array() === $blocks ) {
			return array();
		}

		$sections = array();
		$count    = count( $blocks );
		for ( $index = 0; $index < $count; ++$index ) {
			$start = (int) $blocks[ $index ]['start'];
			$end   = $index + 1 < $count ? (int) $blocks[ $index + 1 ]['start'] : strlen( $content );
			$id    = (string) $blocks[ $index ]['id'];

			$sections[] = array(
				'id'      => $id,
				'start'   => $start,
				'content' => substr( $content, $start, $end - $start ),
			);
		}

		return $sections;
	}

	/**
	 * Find section-bearing top-level block boundaries without splitting nested containers.
	 *
	 * @param string $content Existing serialized block content.
	 * @return list<array{id: string, start: int}>
	 */
	private function top_level_block_sections( string $content ): array {
		$matches = array();
		$matched = preg_match_all( self::BLOCK_COMMENT_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE );
		if ( false === $matched || 0 === $matched ) {
			return array();
		}

		$sections        = array();
		$stack           = array();
		$token_count     = count( $matches[0] );
		$top_level_start = null;

		for ( $index = 0; $index < $token_count; ++$index ) {
			$token         = (string) $matches[0][ $index ][0];
			$position      = (int) $matches[0][ $index ][1];
			$is_closer     = '/' === (string) ( $matches[1][ $index ][0] ?? '' );
			$name_fragment = (string) ( $matches[2][ $index ][0] ?? '' );
			$name          = str_contains( $name_fragment, '/' ) ? $name_fragment : 'core/' . $name_fragment;
			$self_closed   = ! $is_closer && str_ends_with( rtrim( $token ), '/-->' );

			if ( ! $is_closer ) {
				if ( array() === $stack ) {
					$top_level_start = $position;
				}

				if ( 'core/heading' === $name && null !== $top_level_start ) {
					$id = $this->section_id_from_heading_block( $token );
					if ( '' !== $id && ! isset( $sections[ $top_level_start ] ) ) {
						$sections[ $top_level_start ] = array(
							'id'    => $id,
							'start' => $top_level_start,
						);
					}
				}

				if ( ! $self_closed ) {
					$stack[] = $name;
				}
			} else {
				$last = array_pop( $stack );
				if ( $last !== $name ) {
					$stack = array();
				}
			}

			if ( array() === $stack ) {
				$top_level_start = null;
			}
		}

		return array_values( $sections );
	}

	/**
	 * Return a stable section ID from heading block markup.
	 *
	 * @param string $heading_block Serialized heading block.
	 */
	private function section_id_from_heading_block( string $heading_block ): string {
		if ( preg_match( '/<!--\s+wp:heading\s+(\{.*?\})\s+-->/is', $heading_block, $matches ) ) {
			$attrs = json_decode( (string) $matches[1], true );
			if ( is_array( $attrs ) && isset( $attrs['anchor'] ) && is_scalar( $attrs['anchor'] ) ) {
				return $this->normalize_section_id( (string) $attrs['anchor'] );
			}
		}

		if ( preg_match( '/<h[1-6][^>]*\sid=[\'"]([^\'"]+)[\'"]/i', $heading_block, $matches ) ) {
			return $this->normalize_section_id( (string) $matches[1] );
		}

		return $this->normalize_section_id( wp_strip_all_tags( $heading_block ) );
	}

	/**
	 * Normalize assistant-supplied section identifiers to match generated anchors.
	 *
	 * @param string $value Raw section ID.
	 */
	private function normalize_section_id( string $value ): string {
		$value = trim( $value );
		return '' === $value ? '' : $this->slug( $value );
	}

	/**
	 * Build content fields accepted by atomic content abilities.
	 *
	 * @param array<string, mixed> $args Workflow arguments.
	 * @return array<string, mixed>
	 */
	private function content_payload( array $args ): array {
		$payload = array();
		foreach ( array( 'title', 'excerpt', 'slug', 'status', 'date', 'featured_media', 'clear_featured_media', 'author', 'taxonomies' ) as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$payload[ $field ] = $args[ $field ];
			}
		}

		if ( $this->is_dry_run( $args ) ) {
			$payload['dry_run'] = true;
		}

		return $payload;
	}

	/**
	 * Return block-validation context that helps detect layout mismatches.
	 *
	 * @param array<string, mixed> $args Workflow arguments.
	 * @return array<string, mixed>
	 */
	private function block_validation_context( array $args ): array {
		$context = array();
		foreach ( array( 'content_mode', 'content_type', 'layout_intent', 'visual_reference_summary' ) as $field ) {
			if ( array_key_exists( $field, $args ) && is_scalar( $args[ $field ] ) ) {
				$context[ $field ] = (string) $args[ $field ];
			}
		}

		foreach ( array( 'expected_blocks', 'preferred_blocks', 'expected_block_families', 'preferred_block_families' ) as $field ) {
			if ( array_key_exists( $field, $args ) && is_array( $args[ $field ] ) ) {
				$context[ $field ] = $args[ $field ];
			}
		}

		return $context;
	}

	/**
	 * Normalize a content write result into a workflow payload.
	 *
	 * @param string               $workflow  Public workflow name.
	 * @param array<string, mixed> $result    Atomic result.
	 * @param array<string, mixed> $validated Validation result.
	 * @param array<string, mixed> $args      Original args.
	 * @return array<string, mixed>
	 */
	private function content_result_response( string $workflow, array $result, array $validated, array $args ): array {
		$warnings = (array) ( $validated['block_validation']['warnings'] ?? array() );

		if ( isset( $result['error'] ) ) {
			$details                     = array_diff_key( $result, array_flip( array( 'error', 'message' ) ) );
			$details['block_validation'] = $validated['block_validation'] ?? array();
			$details['warnings']         = $warnings;

			return $this->workflow_error(
				(string) $result['error'],
				(string) ( $result['message'] ?? 'Content workflow failed.' ),
				$details
			);
		}

		if ( true === ( $result['dry_run'] ?? false ) ) {
			$result['workflow']         = $workflow;
			$result['block_validation'] = $validated['block_validation'] ?? array();
			$result['warnings']         = array_values( array_unique( array_merge( (array) ( $result['warnings'] ?? array() ), $warnings ) ) );
			if ( isset( $validated['section_updates'] ) ) {
				$result['section_updates']    = (array) $validated['section_updates'];
				$result['available_sections'] = (array) ( $validated['available_sections'] ?? array() );
			}

			return $result;
		}

		$post_id = (int) ( $result['id'] ?? $result['post_id'] ?? 0 );
		$seo     = $this->maybe_update_rank_math_seo( $post_id, $args, $warnings );
		if ( $post_id > 0 ) {
			( new ContentIndexer() )->index_post( $post_id );
		}

		return array(
			'status'           => 'success',
			'workflow'         => $workflow,
			'post_id'          => $post_id,
			'post_type'        => (string) ( $result['type'] ?? $result['post_type'] ?? '' ),
			'title'            => (string) ( $result['title'] ?? '' ),
			'edit_url'         => $this->edit_url( $post_id ),
			'permalink'        => (string) ( $result['link'] ?? $result['permalink'] ?? '' ),
			'fields'           => $result,
			'seo'              => $seo,
			'block_validation' => $validated['block_validation'] ?? array(),
			'changes'          => array(),
			'warnings'         => array_values( array_unique( $warnings ) ),
			'next_actions'     => array( 'Open the draft in WordPress and review the block editor output before publishing.' ),
		);
	}

	/**
	 * Attach and advance server-side workflow session state when requested.
	 *
	 * @param array<string, mixed> $response Workflow response.
	 * @param array<string, mixed> $args     Original workflow arguments.
	 * @param string               $state    Desired state on success.
	 * @param string               $tool     Public tool name.
	 * @return array<string, mixed>
	 */
	private function with_workflow_session( array $response, array $args, string $state, string $tool ): array {
		$session_id = is_scalar( $args['workflow_session_id'] ?? null ) ? (string) $args['workflow_session_id'] : '';
		if ( '' === $session_id ) {
			return $response;
		}

		$session_result = ( new WorkflowSessionStore() )->advance_from_tool_result( $session_id, $state, $tool, $response );
		if ( array() !== $session_result && ! isset( $session_result['error'] ) ) {
			$response['workflow_session'] = $session_result['workflow_session'] ?? array();
		} elseif ( array() !== $session_result ) {
			$response['workflow_session'] = $session_result;
		}

		return $response;
	}

	/**
	 * Apply optional Rank Math fields through the workflow adapter.
	 *
	 * The workflow remains callable even when the atomic SEO tool is hidden by
	 * policy; SeoAbilities still enforces edit_post and dry-run behavior.
	 *
	 * @param int                  $post_id  Post ID.
	 * @param array<string, mixed> $args     Workflow args.
	 * @param array                $warnings Warning accumulator passed by reference.
	 * @phpstan-param list<string> $warnings
	 * @return array<string, mixed>
	 */
	private function maybe_update_rank_math_seo( int $post_id, array $args, array &$warnings ): array {
		$seo_args = $this->seo_args( $args );
		if ( 0 >= $post_id || array() === $seo_args ) {
			return array();
		}

		$seo_args['id']     = $post_id;
		$seo_args['plugin'] = 'rank_math';
		$result             = ( new SeoAbilities() )->update_seo( $seo_args );
		if ( isset( $result['error'] ) ) {
			$warnings[] = 'Rank Math SEO metadata could not be applied: ' . (string) ( $result['message'] ?? $result['error'] );
			return array(
				'error'   => $result['error'],
				'message' => $result['message'] ?? '',
			);
		}

		return $result;
	}

	/**
	 * Return SEO args supplied by the client.
	 *
	 * @param array<string, mixed> $args Workflow args.
	 * @return array<string, mixed>
	 */
	private function seo_args( array $args ): array {
		$seo = array();
		foreach ( array( 'meta_title', 'meta_description', 'focus_keywords' ) as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$seo[ $field ] = $args[ $field ];
			}
		}

		return $seo;
	}

	/**
	 * Gather compact MCP-ready site context for content planning.
	 *
	 * @param string               $brief      Content brief.
	 * @param string               $post_type  Target post type.
	 * @param string               $seo_intent SEO intent.
	 * @param array<string, mixed> $operations Current operations manifest.
	 * @return array<string, mixed>
	 */
	private function intelligence_context( string $brief, string $post_type, string $seo_intent, array $operations ): array {
		if ( ! $this->index_runtime_available() ) {
			return array(
				'status'  => 'unavailable',
				'reason'  => 'content_index_runtime_unavailable',
				'message' => 'The local content intelligence index is not available in this runtime.',
			);
		}

		$query        = trim( $brief . ' ' . $seo_intent );
		$intelligence = new IntelligenceIndexAbilities();
		$context      = array(
			'status'          => 'ready',
			'query'           => $query,
			'memories'        => array(),
			'related_items'   => array(),
			'relevant_chunks' => array(),
			'internal_links'  => array(),
			'warnings'        => array(),
		);

		if ( $this->operation_entry_available( $operations, 'intelligence_index', 'memory_list' ) ) {
			$context['memories'] = $intelligence->list_memories(
				array(
					'status'   => 'approved',
					'per_page' => 8,
				)
			);
		}

		if ( '' !== $query && $this->operation_entry_available( $operations, 'intelligence_index', 'search_items' ) ) {
			$context['related_items'] = $intelligence->search_items(
				array(
					'query'     => $query,
					'post_type' => $post_type,
					'status'    => 'publish',
					'per_page'  => 5,
				)
			);
		}

		if ( '' !== $query && $this->operation_entry_available( $operations, 'intelligence_index', 'search_chunks' ) ) {
			$context['relevant_chunks'] = $intelligence->search_chunks(
				array(
					'query'     => $query,
					'post_type' => $post_type,
					'status'    => 'publish',
					'per_page'  => 6,
					'context'   => 'compact',
				)
			);
		}

		if ( '' !== $query && $this->operation_entry_available( $operations, 'intelligence_index', 'internal_links' ) ) {
			$context['internal_links'] = $intelligence->find_internal_links(
				array(
					'topic'     => $query,
					'post_type' => $post_type,
					'status'    => 'publish',
					'limit'     => 8,
				)
			);
		}

		if ( array() === $context['memories'] ) {
			$context['warnings'][] = 'No approved local memories were available for this planning request.';
		}

		return $context;
	}

	/**
	 * Check whether an operation entry is currently available.
	 *
	 * @param array<string, mixed> $operations Current operations manifest.
	 * @param string               $group      Operation group.
	 * @param string               $key        Operation key.
	 */
	private function operation_entry_available( array $operations, string $group, string $key ): bool {
		return true === ( $operations[ $group ][ $key ]['available'] ?? false );
	}

	/**
	 * Return whether database-backed intelligence can run.
	 */
	private function index_runtime_available(): bool {
		global $wpdb;

		return isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' );
	}

	/**
	 * Return a content outline suited to the requested content mode.
	 *
	 * @param string $content_mode Content mode.
	 * @param int    $desired_word_count Desired total word count.
	 * @param array  $section_requests Optional user-requested section types.
	 * @phpstan-param list<string> $section_requests
	 * @return list<array<string, mixed>>
	 */
	private function content_outline( string $content_mode, int $desired_word_count, array $section_requests = array() ): array {
		if ( $this->is_layout_mode( $content_mode ) ) {
			return $this->layout_outline( $content_mode, $desired_word_count, $section_requests );
		}

		return $this->long_form_outline( $desired_word_count );
	}

	/**
	 * Build a reusable block plan for article or layout-aware content.
	 *
	 * @param string $content_mode Content mode.
	 * @param array  $outline Section outline.
	 * @param string $layout_intent Layout direction.
	 * @param string $visual_reference Visual reference summary.
	 * @param array  $preferred_families Preferred block families.
	 * @param array  $preferred_blocks Preferred block names.
	 * @param array  $preferred_patterns Preferred pattern names.
	 * @phpstan-param list<array<string, mixed>> $outline
	 * @phpstan-param list<string> $preferred_families
	 * @phpstan-param list<string> $preferred_blocks
	 * @phpstan-param list<string> $preferred_patterns
	 * @return array<string, mixed>
	 */
	private function block_plan( string $content_mode, array $outline, string $layout_intent, string $visual_reference, array $preferred_families, array $preferred_blocks, array $preferred_patterns ): array {
		$is_layout      = $this->is_layout_mode( $content_mode );
		$allowed_blocks = $this->available_blocks_for_plan( $is_layout ? self::LAYOUT_BLOCKS : self::SEMANTIC_BLOCKS );
		$section_ids    = array_values( array_column( $outline, 'id' ) );
		$plan           = array(
			'format'                   => 'serialized_wordpress_blocks',
			'content_mode'             => $content_mode,
			'allowed_blocks'           => $allowed_blocks,
			'never_use'                => array( 'core/html' ),
			'validation_tool'          => ( new AbilitiesRegistry() )->tool_name( 'intelligence.content.validate_blocks' ),
			'section_ids'              => $section_ids,
			'preferred_block_families' => $preferred_families,
			'preferred_blocks'         => $preferred_blocks,
			'preferred_patterns'       => $preferred_patterns,
			'layout_intent'            => $layout_intent,
			'visual_reference_summary' => $visual_reference,
		);

		if ( $is_layout ) {
			$plan['layout_blocks']        = $allowed_blocks;
			$plan['article_blocks']       = $this->available_blocks_for_plan( self::SEMANTIC_BLOCKS );
			$plan['layout_strategy']      = 'Use registered site patterns first when they match the requested section. Otherwise compose with core/group, core/columns, core/cover, core/media-text, core/image, core/buttons, and semantic text blocks. For grid-like card sections, use Columns or a Group with grid-style layout attributes when the site supports it; never use raw HTML.';
			$plan['layout_plan']          = $outline;
			$plan['block_search_terms']   = array_values( array_unique( array_merge( array( 'layout', 'columns', 'grid', 'cards', 'cover', 'media text' ), $preferred_families ) ) );
			$plan['pattern_search_terms'] = $this->pattern_search_terms( $content_mode, $layout_intent, $visual_reference );
		}

		return $plan;
	}

	/**
	 * Infer or normalize the requested content mode.
	 *
	 * @param array<string, mixed> $args Workflow arguments.
	 * @param string               $brief Content brief.
	 * @param string               $post_type Target post type.
	 * @param string               $layout_intent Layout direction.
	 * @param string               $visual_reference Visual reference summary.
	 * @param array                $section_requests Requested sections.
	 * @phpstan-param list<string> $section_requests
	 */
	private function content_mode( array $args, string $brief, string $post_type, string $layout_intent, string $visual_reference, array $section_requests ): string {
		$explicit = sanitize_key( (string) ( $args['content_mode'] ?? $args['content_type'] ?? '' ) );
		$aliases  = array(
			'blog'        => 'article',
			'blog_post'   => 'article',
			'post'        => 'article',
			'long_form'   => 'article',
			'landing'     => 'landing_page',
			'landingpage' => 'landing_page',
			'visual'      => 'visual_layout',
			'layout'      => 'visual_layout',
			'service'     => 'service_page',
			'product'     => 'product_page',
			'case'        => 'case_study',
			'case-study'  => 'case_study',
			'case_study'  => 'case_study',
		);
		$explicit = $aliases[ $explicit ] ?? $explicit;
		if ( in_array( $explicit, self::CONTENT_MODES, true ) ) {
			return $explicit;
		}

		$haystack = strtolower( trim( implode( ' ', array_merge( array( $brief, $post_type, $layout_intent, $visual_reference ), $section_requests ) ) ) );
		if ( str_contains( $haystack, 'case stud' ) ) {
			return 'case_study';
		}
		if ( str_contains( $haystack, 'product' ) || str_contains( $haystack, 'pricing' ) ) {
			return 'product_page';
		}
		if ( str_contains( $haystack, 'service' ) ) {
			return 'service_page';
		}
		if ( str_contains( $haystack, 'landing' ) || str_contains( $haystack, 'homepage' ) || str_contains( $haystack, 'home page' ) ) {
			return 'landing_page';
		}

		foreach ( array( 'image', 'screenshot', 'visual', 'layout', 'columns', 'column', 'grid', 'cards', 'hero', 'media text', 'section', 'cta', 'call to action' ) as $term ) {
			if ( str_contains( $haystack, $term ) ) {
				return 'visual_layout';
			}
		}

		return 'page' === $post_type ? 'page' : 'article';
	}

	/**
	 * Check whether a content mode should receive a layout-aware plan.
	 *
	 * @param string $content_mode Content mode.
	 */
	private function is_layout_mode( string $content_mode ): bool {
		return in_array( $content_mode, self::LAYOUT_MODES, true );
	}

	/**
	 * Build a section plan for visual and FSE-style page composition.
	 *
	 * @param string $content_mode Content mode.
	 * @param int    $desired_word_count Desired total word count.
	 * @param array  $section_requests Optional requested sections.
	 * @phpstan-param list<string> $section_requests
	 * @return list<array<string, mixed>>
	 */
	private function layout_outline( string $content_mode, int $desired_word_count, array $section_requests ): array {
		$templates = $this->layout_section_templates( $content_mode );
		if ( array() !== $section_requests ) {
			$templates = $this->merge_requested_sections( $templates, $section_requests );
		}

		$section_count = count( $templates );
		$target_words  = 0 < $section_count ? (int) floor( $desired_word_count / $section_count ) : $desired_word_count;
		$outline       = array();
		foreach ( $templates as $index => $template ) {
			$blocks    = $this->available_blocks_for_plan( (array) $template['blocks'] );
			$outline[] = array(
				'id'                   => (string) $template['id'],
				'heading'              => (string) $template['heading'],
				'level'                => 2,
				'section_type'         => (string) $template['section_type'],
				'target_words'         => array_key_last( $templates ) === $index ? $desired_word_count - ( $target_words * ( $section_count - 1 ) ) : $target_words,
				'blocks'               => $blocks,
				'layout_strategy'      => (string) $template['layout_strategy'],
				'pattern_search_terms' => (array) $template['pattern_search_terms'],
			);
		}

		return $outline;
	}

	/**
	 * Return baseline section templates for a content mode.
	 *
	 * @param string $content_mode Content mode.
	 * @return list<array<string, mixed>>
	 */
	private function layout_section_templates( string $content_mode ): array {
		$shared_hero = array(
			'id'                   => 'hero',
			'heading'              => 'Hero',
			'section_type'         => 'hero',
			'blocks'               => array( 'core/cover', 'core/group', 'core/heading', 'core/paragraph', 'core/buttons', 'core/button' ),
			'layout_strategy'      => 'Use a Cover or Group section with a strong heading, concise supporting copy, and one Buttons block.',
			'pattern_search_terms' => array( 'hero', 'banner', 'cover' ),
		);

		return match ( $content_mode ) {
			'service_page' => array(
				$shared_hero,
				$this->layout_template( 'service-grid', 'Service Grid', 'card_grid', array( 'core/group', 'core/columns', 'core/column', 'core/heading', 'core/paragraph', 'core/buttons' ), 'Use columns or a grid-style group for service cards with short copy and clear calls to action.', array( 'services', 'features', 'cards', 'grid' ) ),
				$this->layout_template( 'process', 'Process', 'steps', array( 'core/group', 'core/columns', 'core/column', 'core/list', 'core/heading', 'core/paragraph' ), 'Use columns for a short process overview or lists for ordered steps.', array( 'process', 'steps', 'how it works' ) ),
				$this->layout_template( 'proof', 'Proof', 'social_proof', array( 'core/group', 'core/columns', 'core/quote', 'core/paragraph' ), 'Use testimonial, quote, or proof sections instead of long prose blocks.', array( 'testimonials', 'proof', 'reviews' ) ),
				$this->layout_template( 'cta', 'Call to Action', 'call_to_action', array( 'core/group', 'core/heading', 'core/paragraph', 'core/buttons', 'core/button' ), 'End with a compact CTA section.', array( 'cta', 'call to action' ) ),
			),
			'product_page' => array(
				$shared_hero,
				$this->layout_template( 'feature-grid', 'Feature Grid', 'card_grid', array( 'core/group', 'core/columns', 'core/column', 'core/image', 'core/heading', 'core/paragraph' ), 'Use a visual card grid for product features.', array( 'features', 'cards', 'grid' ) ),
				$this->layout_template( 'media-demo', 'Media Demo', 'media_text', array( 'core/media-text', 'core/image', 'core/heading', 'core/paragraph', 'core/buttons' ), 'Pair a visual with concise explanatory copy.', array( 'media', 'demo', 'image text' ) ),
				$this->layout_template( 'comparison', 'Comparison', 'comparison', array( 'core/table', 'core/group', 'core/columns', 'core/list' ), 'Use tables only for real comparisons; use columns for visual comparisons.', array( 'comparison', 'pricing', 'table' ) ),
				$this->layout_template( 'cta', 'Call to Action', 'call_to_action', array( 'core/group', 'core/heading', 'core/paragraph', 'core/buttons', 'core/button' ), 'End with a purchase, signup, or inquiry CTA.', array( 'cta', 'call to action' ) ),
			),
			'case_study' => array(
				$shared_hero,
				$this->layout_template( 'challenge', 'Challenge', 'narrative_section', array( 'core/group', 'core/heading', 'core/paragraph', 'core/list' ), 'Use concise narrative blocks for the starting problem.', array( 'challenge', 'problem' ) ),
				$this->layout_template( 'solution', 'Solution', 'media_text', array( 'core/media-text', 'core/group', 'core/image', 'core/heading', 'core/paragraph' ), 'Pair solution explanation with screenshots or visuals when available.', array( 'solution', 'media text' ) ),
				$this->layout_template( 'results', 'Results', 'metric_grid', array( 'core/group', 'core/columns', 'core/column', 'core/heading', 'core/paragraph' ), 'Use columns or cards for measurable outcomes.', array( 'results', 'stats', 'metrics' ) ),
				$this->layout_template( 'cta', 'Next Steps', 'call_to_action', array( 'core/group', 'core/heading', 'core/paragraph', 'core/buttons', 'core/button' ), 'Close with a relevant next step.', array( 'cta', 'call to action' ) ),
			),
			default => array(
				$shared_hero,
				$this->layout_template( 'feature-grid', 'Feature Grid', 'card_grid', array( 'core/group', 'core/columns', 'core/column', 'core/image', 'core/heading', 'core/paragraph', 'core/buttons' ), 'Use columns or a grid-style group for cards, not a long paragraph stack.', array( 'features', 'cards', 'grid', 'columns' ) ),
				$this->layout_template( 'media-text', 'Media And Text', 'media_text', array( 'core/media-text', 'core/image', 'core/heading', 'core/paragraph' ), 'Use Media & Text when a visual reference shows side-by-side image and copy.', array( 'media text', 'image text', 'split' ) ),
				$this->layout_template( 'proof', 'Proof', 'social_proof', array( 'core/group', 'core/columns', 'core/quote', 'core/paragraph' ), 'Use proof blocks, quotes, or compact cards when the page needs trust-building content.', array( 'proof', 'testimonials', 'reviews' ) ),
				$this->layout_template( 'cta', 'Call to Action', 'call_to_action', array( 'core/group', 'core/heading', 'core/paragraph', 'core/buttons', 'core/button' ), 'End with a concise CTA section.', array( 'cta', 'call to action' ) ),
			),
		};
	}

	/**
	 * Build one layout template entry.
	 *
	 * @param string $id Section ID.
	 * @param string $heading Section heading.
	 * @param string $section_type Section type.
	 * @param array  $blocks Candidate blocks.
	 * @param string $layout_strategy Section layout guidance.
	 * @param array  $pattern_search_terms Pattern search terms.
	 * @phpstan-param list<string> $blocks
	 * @phpstan-param list<string> $pattern_search_terms
	 * @return array<string, mixed>
	 */
	private function layout_template( string $id, string $heading, string $section_type, array $blocks, string $layout_strategy, array $pattern_search_terms ): array {
		return compact( 'id', 'heading', 'section_type', 'blocks', 'layout_strategy', 'pattern_search_terms' );
	}

	/**
	 * Append user-requested sections not already represented by the baseline plan.
	 *
	 * @param array $templates Existing templates.
	 * @param array $section_requests Requested section labels.
	 * @phpstan-param list<array<string, mixed>> $templates
	 * @phpstan-param list<string> $section_requests
	 * @return list<array<string, mixed>>
	 */
	private function merge_requested_sections( array $templates, array $section_requests ): array {
		$existing = array_map( static fn( array $template ): string => (string) $template['id'], $templates );
		foreach ( $section_requests as $request ) {
			$id = $this->slug( $request );
			if ( '' === $id || in_array( $id, $existing, true ) ) {
				continue;
			}
			$templates[] = $this->layout_template(
				$id,
				$request,
				'requested_section',
				array( 'core/group', 'core/columns', 'core/column', 'core/heading', 'core/paragraph', 'core/image', 'core/buttons' ),
				'Use a registered pattern or a grouped editable block section matching this requested section.',
				array( $request, 'section', 'layout' )
			);
			$existing[]  = $id;
		}

		return $templates;
	}

	/**
	 * Return pattern search terms for the selected mode and visual direction.
	 *
	 * @param string $content_mode Content mode.
	 * @param string $layout_intent Layout direction.
	 * @param string $visual_reference Visual reference summary.
	 * @return list<string>
	 */
	private function pattern_search_terms( string $content_mode, string $layout_intent, string $visual_reference ): array {
		$terms = array( 'hero', 'features', 'cards', 'columns', 'grid', 'media text', 'cta' );
		foreach ( array( $content_mode, $layout_intent, $visual_reference ) as $value ) {
			$split_terms = preg_split( '/[^A-Za-z0-9]+/', strtolower( $value ) );
			foreach ( false === $split_terms ? array() : $split_terms as $term ) {
				if ( strlen( $term ) >= 4 ) {
					$terms[] = $term;
				}
			}
		}

		return array_values( array_slice( array_unique( $terms ), 0, 16 ) );
	}

	/**
	 * Return candidate blocks that are registered when a registry is available.
	 *
	 * @param array $candidates Candidate block names.
	 * @phpstan-param list<string> $candidates
	 * @return list<string>
	 */
	private function available_blocks_for_plan( array $candidates ): array {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) || ! method_exists( '\WP_Block_Type_Registry', 'get_instance' ) ) {
			return array_values( array_unique( $candidates ) );
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		if ( ! method_exists( $registry, 'is_registered' ) ) {
			return array_values( array_unique( $candidates ) );
		}

		$registered = array_values(
			array_filter(
				array_unique( $candidates ),
				static fn( string $name ): bool => $registry->is_registered( $name )
			)
		);

		return array() === $registered ? array_values( array_unique( $candidates ) ) : $registered;
	}

	/**
	 * Return a deterministic long-form outline.
	 *
	 * @param int $desired_word_count Desired total word count.
	 * @return list<array<string, mixed>>
	 */
	private function long_form_outline( int $desired_word_count ): array {
		$headings = array(
			'Introduction',
			'Current State and Reader Problem',
			'Key Concepts and Context',
			'Step-by-Step Workflow',
			'Practical Examples',
			'Implementation Notes',
			'Common Mistakes to Avoid',
			'FAQ',
			'Conclusion',
		);

		$section_count = max( 6, min( 9, (int) ceil( $desired_word_count / 550 ) ) );
		$headings      = array_slice( $headings, 0, $section_count );
		$target_words  = (int) floor( $desired_word_count / $section_count );
		$outline       = array();

		foreach ( $headings as $index => $heading ) {
			$outline[] = array(
				'id'           => $this->slug( $heading ),
				'heading'      => $heading,
				'level'        => 2,
				'target_words' => array_key_last( $headings ) === $index ? $desired_word_count - ( $target_words * ( $section_count - 1 ) ) : $target_words,
				'blocks'       => array( 'core/heading', 'core/paragraph' ),
			);
		}

		return $outline;
	}

	/**
	 * Clamp desired long-form word count.
	 *
	 * @param array<string, mixed> $args Workflow args.
	 */
	private function desired_word_count( array $args ): int {
		$count = absint( $args['desired_word_count'] ?? self::DEFAULT_LONG_FORM_WORDS );
		if ( 0 === $count ) {
			$count = self::DEFAULT_LONG_FORM_WORDS;
		}

		return max( self::MIN_LONG_FORM_WORDS, min( self::MAX_LONG_FORM_WORDS, $count ) );
	}

	/**
	 * Return an edit URL for a post ID when available.
	 *
	 * @param int $post_id Post ID.
	 */
	private function edit_url( int $post_id ): string {
		if ( 0 >= $post_id ) {
			return '';
		}

		if ( function_exists( 'get_edit_post_link' ) ) {
			$url = get_edit_post_link( $post_id, 'raw' );
			if ( is_string( $url ) ) {
				return $url;
			}
		}

		return function_exists( 'admin_url' )
			? admin_url( 'post.php?post=' . $post_id . '&action=edit' )
			: '';
	}

	/**
	 * Build a machine-safe slug.
	 *
	 * @param string $value Raw value.
	 */
	private function slug( string $value ): string {
		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $value );
		}

		$slug = strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', $value ) ?? '', '-' ) );
		return '' === $slug ? 'section' : $slug;
	}

	/**
	 * Clean short text fields.
	 *
	 * @param string $value Raw value.
	 */
	private function clean_text( string $value ): string {
		return sanitize_text_field( $value );
	}

	/**
	 * Return a bounded list of sanitized strings.
	 *
	 * @param mixed $value Raw list.
	 * @param int   $limit Maximum returned items.
	 * @return list<string>
	 */
	private function clean_string_list( mixed $value, int $limit ): array {
		if ( is_scalar( $value ) ) {
			$value = array( (string) $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = $this->clean_text( (string) $item );
			if ( '' !== $item ) {
				$items[] = $item;
			}

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return array_values( array_unique( $items ) );
	}

	/**
	 * Return bounded block family names.
	 *
	 * @param mixed $value Raw list.
	 * @return list<string>
	 */
	private function block_families( mixed $value ): array {
		$allowed = array( 'text', 'media', 'layout', 'navigation', 'data', 'embed', 'design', 'widget' );
		return array_values(
			array_filter(
				array_map( 'sanitize_key', $this->clean_string_list( $value, 8 ) ),
				static fn( string $family ): bool => in_array( $family, $allowed, true )
			)
		);
	}

	/**
	 * Return bounded registered-block-like names.
	 *
	 * @param mixed $value Raw list.
	 * @return list<string>
	 */
	private function block_names( mixed $value ): array {
		return $this->identifier_list( $value, 20 );
	}

	/**
	 * Return bounded identifiers while preserving namespace slashes.
	 *
	 * @param mixed $value Raw list.
	 * @param int   $limit Maximum returned items.
	 * @return list<string>
	 */
	private function identifier_list( mixed $value, int $limit ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( string $item ): string => preg_replace( '/[^A-Za-z0-9_\/.-]/', '', $item ) ?? '',
						$this->clean_string_list( $value, $limit )
					)
				)
			)
		);
	}

	/**
	 * Build a workflow error payload.
	 *
	 * @param string               $code    Error code.
	 * @param string               $message Error message.
	 * @param array<string, mixed> $extra   Extra fields.
	 * @return array<string, mixed>
	 */
	private function workflow_error( string $code, string $message, array $extra = array() ): array {
		return array_merge(
			array(
				'status'  => 'error',
				'error'   => $code,
				'message' => $message,
			),
			$extra
		);
	}
}
