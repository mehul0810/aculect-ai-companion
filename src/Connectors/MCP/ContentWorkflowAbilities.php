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
		$desired_word_count = $this->desired_word_count( $args );
		$existing_post_id   = absint( $args['existing_post_id'] ?? 0 );

		if ( '' === $brief ) {
			return $this->workflow_error( 'invalid_brief', 'Provide a brief for the content workflow.' );
		}

		$operations = ( new McpToolAvailability() )->operations_manifest_for_current_user();
		$outline    = $this->long_form_outline( $desired_word_count );
		$context    = $this->intelligence_context( $brief, '' === $post_type ? 'post' : $post_type, $seo_intent, $operations );

		return array(
			'status'               => 'ready',
			'workflow'             => 'content_workflow_prepare_post',
			'post_type'            => '' === $post_type ? 'post' : $post_type,
			'brief'                => $brief,
			'audience'             => $audience,
			'seo_intent'           => $seo_intent,
			'desired_word_count'   => $desired_word_count,
			'existing_post_id'     => $existing_post_id,
			'outline'              => $outline,
			'block_plan'           => array(
				'format'          => 'serialized_wordpress_blocks',
				'allowed_blocks'  => self::SEMANTIC_BLOCKS,
				'never_use'       => array( 'core/html' ),
				'validation_tool' => ( new AbilitiesRegistry() )->tool_name( 'intelligence.content.validate_blocks' ),
				'section_ids'     => array_values( array_column( $outline, 'id' ) ),
			),
			'recommendations'      => array(
				'taxonomies' => 'Use available taxonomy tools to select existing terms before writing.',
				'media'      => 'Use an existing image attachment ID for featured_media; upload only when the media upload operation is available.',
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
			),
			'intelligence_context' => $context,
			'operations'           => $operations,
			'next_actions'         => array(
				'Use intelligence_context.memories, related_items, relevant_chunks, and internal_links while drafting.',
				'Generate sectioned serialized WordPress block markup using the outline section IDs.',
				'Validate the full block document before any write.',
				'Call content_workflow_create_draft for new long-form content or content_workflow_update_post for an existing item.',
			),
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
			return $validated;
		}

		$payload              = $this->content_payload( $args );
		$payload['content']   = $validated['content'];
		$payload['post_type'] = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		$payload['status']    = 'draft';

		$result = ( new ContentAbilities() )->create_item( $payload );
		return $this->content_result_response( 'content_workflow_create_draft', $result, $validated, $args );
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
			return $this->workflow_error( 'invalid_post_id', 'Provide an existing post ID.' );
		}

		$payload            = $this->content_payload( $args );
		$has_document       = array_key_exists( 'content', $args ) || array_key_exists( 'section_map', $args );
		$validated          = array();
		$preview_args       = $payload;
		$preview_args['id'] = $post_id;

		if ( $has_document ) {
			$validated = $this->validated_block_document( $args, $post_id );
			if ( isset( $validated['error'] ) ) {
				return $validated;
			}

			$payload['content']      = $validated['content'];
			$preview_args['content'] = $validated['content'];
		}

		if ( array() === $payload ) {
			return $this->workflow_error( 'invalid_update_fields', 'Provide title, content, excerpt, slug, taxonomy, featured media, date, author, or SEO fields to update.' );
		}

		if ( $this->is_dry_run( $args ) ) {
			return $this->workflow_preview(
				'content_workflow.update_post',
				$preview_args,
				array(
					'type' => 'content',
					'id'   => $post_id,
				),
				$this->workflow_changes( $payload ),
				$validated
			);
		}

		$payload['id'] = $post_id;
		$result        = ( new ContentAbilities() )->update_item( $payload );

		return $this->content_result_response( 'content_workflow_update_post', $result, $validated, $args );
	}

	/**
	 * Update Rank Math metadata through a workflow-specific tool.
	 *
	 * @param array<string, mixed> $args SEO arguments.
	 * @return array<string, mixed>
	 */
	public function update_rankmath_seo( array $args ): array {
		if ( ! $this->operation_available( 'content.update_seo' ) ) {
			return $this->workflow_error( 'workflow_operation_unavailable', 'Rank Math SEO updates require the content_update_seo operation to be available.' );
		}

		$args['plugin'] = 'rank_math';
		$result         = ( new SeoAbilities() )->update_seo( $args );
		if ( isset( $result['error'] ) ) {
			return $this->workflow_error( (string) $result['error'], (string) ( $result['message'] ?? 'Rank Math SEO metadata could not be updated.' ), array( 'seo' => $result ) );
		}

		if ( true === ( $result['dry_run'] ?? false ) ) {
			$result['workflow'] = 'seo_workflow_update_rankmath';
			return $result;
		}

		return array(
			'status'       => 'success',
			'workflow'     => 'seo_workflow_update_rankmath',
			'post_id'      => (int) ( $result['post_id'] ?? 0 ),
			'plugin'       => 'rank_math',
			'fields'       => (array) ( $result['fields'] ?? array() ),
			'changes'      => array(),
			'warnings'     => array(),
			'next_actions' => array( 'Review the Rank Math fields in the WordPress editor.' ),
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

		$validation = ( new BlockKnowledgeAbilities() )->validate_block_content( array( 'content' => $content ) );
		if ( isset( $validation['error'] ) ) {
			return $this->workflow_error( (string) $validation['error'], (string) ( $validation['message'] ?? 'Block validation failed.' ), array( 'block_validation' => $validation ) );
		}

		if ( true !== ( $validation['valid'] ?? false ) ) {
			return $this->workflow_error(
				'invalid_block_content',
				'Block content must use registered WordPress blocks and must not include core/html.',
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

		$matched = preg_match_all( '/<!--\s+wp:heading(?:\s+\{.*?\})?\s+-->.*?<!--\s+\/wp:heading\s+-->/is', $content, $matches, PREG_OFFSET_CAPTURE );
		if ( false === $matched || 0 === $matched ) {
			return array();
		}

		$sections = array();
		$count    = count( $matches[0] );
		for ( $index = 0; $index < $count; ++$index ) {
			$heading = (string) $matches[0][ $index ][0];
			$start   = (int) $matches[0][ $index ][1];
			$end     = $index + 1 < $count ? (int) $matches[0][ $index + 1 ][1] : strlen( $content );
			$id      = $this->section_id_from_heading_block( $heading );

			if ( '' === $id ) {
				continue;
			}

			$sections[] = array(
				'id'      => $id,
				'start'   => $start,
				'content' => substr( $content, $start, $end - $start ),
			);
		}

		return $sections;
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
		foreach ( array( 'title', 'excerpt', 'slug', 'date', 'featured_media', 'clear_featured_media', 'author', 'taxonomies' ) as $field ) {
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
			return $this->workflow_error(
				(string) $result['error'],
				(string) ( $result['message'] ?? 'Content workflow failed.' ),
				array(
					'block_validation' => $validated['block_validation'] ?? array(),
					'warnings'         => $warnings,
				)
			);
		}

		if ( true === ( $result['dry_run'] ?? false ) ) {
			$result['workflow']         = $workflow;
			$result['block_validation'] = $validated['block_validation'] ?? array();
			$result['warnings']         = array_values( array_unique( array_merge( (array) ( $result['warnings'] ?? array() ), $warnings ) ) );
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
	 * Apply optional Rank Math fields without bypassing content_update_seo availability.
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

		if ( ! $this->operation_available( 'content.update_seo' ) ) {
			$warnings[] = 'SEO fields were provided but content_update_seo is not available for this connection.';
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
	 * Return whether an underlying operation is callable for the current user.
	 *
	 * @param string $ability_id Ability ID.
	 */
	private function operation_available( string $ability_id ): bool {
		$registry = new AbilitiesRegistry();
		if ( ! $registry->is_enabled( $ability_id ) ) {
			return false;
		}

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return ( new RoleAbilitiesPolicy() )->is_allowed_for_user( $ability_id, $user_id, $registry );
	}

	/**
	 * Build a dry-run workflow preview without requiring WordPress writes.
	 *
	 * @param string                    $action    Internal action ID.
	 * @param array<string, mixed>      $args      Preview args.
	 * @param array<string, mixed>      $target    Target summary.
	 * @param list<array<string,mixed>> $changes Changes.
	 * @param array<string, mixed>      $validated Validation result.
	 * @return array<string, mixed>
	 */
	private function workflow_preview( string $action, array $args, array $target, array $changes, array $validated ): array {
		$preview                     = $this->preview_response( $action, $args, $target, $changes, (array) ( $validated['block_validation']['warnings'] ?? array() ) );
		$preview['workflow']         = ( new AbilitiesRegistry() )->tool_name( $action );
		$preview['block_validation'] = $validated['block_validation'] ?? array();
		$preview['next_actions']     = array( 'Repeat the same workflow call without dry_run after reviewing the preview and confirmation requirements.' );
		if ( isset( $validated['section_updates'] ) ) {
			$preview['section_updates']    = (array) $validated['section_updates'];
			$preview['available_sections'] = (array) ( $validated['available_sections'] ?? array() );
		}

		return $preview;
	}

	/**
	 * Build preview changes from workflow fields.
	 *
	 * @param array<string, mixed> $payload Proposed payload.
	 * @return list<array<string, mixed>>
	 */
	private function workflow_changes( array $payload ): array {
		$changes = array();
		foreach ( array_keys( $payload ) as $field ) {
			if ( 'dry_run' === $field ) {
				continue;
			}
			$changes[] = $this->change( (string) $field, null, $payload[ $field ] );
		}

		return array_values( array_filter( $changes ) );
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
