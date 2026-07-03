<?php
/**
 * MCP abilities backed by the local Aculect Intelligence index.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Brand\BrandProfile;
use Aculect\AICompanion\Intelligence\ContentIndexer;
use Aculect\AICompanion\Intelligence\ContentIndexRepository;
use Aculect\AICompanion\Intelligence\InternalLinkPolicy;
use Aculect\AICompanion\Intelligence\InternalLinkSuggestionRepository;
use Aculect\AICompanion\Intelligence\InternalLinkTargetInspector;
use Aculect\AICompanion\Intelligence\LearningSuggestionRepository;

/**
 * Exposes indexed search, chunk retrieval, link suggestions, memories, and batch refresh to MCP clients.
 */
final class IntelligenceIndexAbilities extends AbstractAbilityService {

	private const CANONICAL_FETCH_TEXT_LIMIT = 120000;

	/**
	 * Search WordPress content using the canonical MCP retrieval contract.
	 *
	 * @param array<string, mixed> $args Search args.
	 * @return array<string, mixed>
	 */
	public function canonical_search( array $args ): array {
		$query = sanitize_text_field( (string) ( $args['query'] ?? '' ) );
		if ( '' === $query ) {
			return array( 'results' => array() );
		}

		$items = array();
		if ( $this->index_runtime_available() ) {
			$result = $this->search_items(
				array(
					'query'    => $query,
					'per_page' => 10,
					'context'  => 'compact',
				)
			);
			$items  = (array) ( $result['items'] ?? array() );
		}

		if ( array() === $items ) {
			$items = $this->degraded_live_items(
				array(
					'query'    => $query,
					'per_page' => 10,
				)
			);
		}

		return array(
			'results' => array_values(
				array_filter(
					array_map( array( $this, 'canonical_search_result' ), $items )
				)
			),
		);
	}

	/**
	 * Fetch one WordPress content item using the canonical MCP retrieval contract.
	 *
	 * @param array<string, mixed> $args Fetch args.
	 * @return array<string, mixed>
	 */
	public function canonical_fetch( array $args ): array {
		$id = sanitize_text_field( (string) ( $args['id'] ?? '' ) );
		if ( '' === $id ) {
			return $this->error_response( 'invalid_id', 'Provide an ID returned by search or a readable WordPress post ID.' );
		}

		$chunk = $this->canonical_chunk_identity( $id );
		if ( null !== $chunk ) {
			return $this->canonical_fetch_chunk( (int) $chunk['post_id'], (string) $chunk['chunk_id'] );
		}

		$post_id = $this->canonical_post_id( $id );
		if ( $post_id <= 0 ) {
			return $this->error_response( 'invalid_id', 'Provide an ID returned by search or a readable WordPress post ID.' );
		}

		if ( ! $this->can_read_post( $post_id ) ) {
			return $this->error_response( 'forbidden', 'You do not have permission to read that content item.' );
		}

		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return $this->error_response( 'not_found', 'No readable WordPress content item exists for that ID.' );
		}

		$indexed = $this->index_runtime_available() ? $this->repo()->content_item( $post_id ) : array();

		return $this->canonical_post_document( $post, $indexed );
	}

	/**
	 * Search indexed content items.
	 *
	 * @param array<string, mixed> $args Search args.
	 * @return array<string, mixed>
	 */
	public function search_items( array $args ): array {
		$result          = $this->repo()->search_items( $args );
		$result['items'] = $this->filled_readable_items( $args, $result );

		$should_degrade = '' !== trim( (string) ( $args['query'] ?? '' ) ) || absint( $args['max_word_count'] ?? 0 ) > 0;
		if ( array() === $result['items'] && $should_degrade ) {
			$live = $this->degraded_live_items( $args );
			if ( array() !== $live ) {
				$result['items']           = $live;
				$result['degraded']        = true;
				$result['degraded_reason'] = $this->repo()->summary_is_empty() ? 'index_empty' : 'index_no_match';
			}
		}

		$result          = $this->filtered_result_metadata( $result, $result['items'] );
		$result['usage'] = array(
			'preferred_next_step' => 'Use content_search_chunks when a result needs section-level context or block markup.',
			'freshness'           => 'Rows marked stale should be refreshed with content_index_refresh_batch before large edits.',
		);
		if ( true === ( $result['degraded'] ?? false ) ) {
			$result['usage']['degraded'] = 'Results came from a live WordPress query, not the intelligence index. Call content_index_refresh_batch with mode=queued, then retry for indexed results with summaries and section data.';
			$result['next_actions']      = array( 'Call content_index_refresh_batch with mode=queued to build the index, then retry content_search_items.' );
		}

		return $result;
	}

	/**
	 * Run a bounded live WordPress search when the index cannot answer.
	 *
	 * AI clients cannot act on an empty result plus a prose freshness hint;
	 * a degraded live result keeps the tool useful on fresh installs and
	 * after large imports while the index catches up.
	 *
	 * @param array<string, mixed> $args Original search args.
	 * @return list<array<string, mixed>>
	 */
	private function degraded_live_items( array $args ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$per_page  = max( 1, min( 50, absint( $args['per_page'] ?? 10 ) ) );
		$post_type = sanitize_key( (string) ( $args['post_type'] ?? '' ) );
		$status    = sanitize_key( (string) ( $args['status'] ?? '' ) );
		$max_words = absint( $args['max_word_count'] ?? 0 );

		$posts = get_posts(
			array(
				's'                      => sanitize_text_field( (string) ( $args['query'] ?? '' ) ),
				'post_type'              => '' === $post_type ? 'any' : $post_type,
				'post_status'            => '' === $status ? array( 'publish', 'future', 'draft', 'pending', 'private' ) : $status,
				'posts_per_page'         => $per_page,
				'perm'                   => 'readable',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post || ! $this->can_read_post( (int) $post->ID ) ) {
				continue;
			}
			if ( '' !== $post_type && $post_type !== (string) $post->post_type ) {
				continue;
			}
			if ( '' !== $status && $status !== (string) $post->post_status ) {
				continue;
			}

			$text  = wp_strip_all_tags( (string) $post->post_content );
			$words = str_word_count( $text );
			if ( $max_words > 0 && $words > $max_words ) {
				continue;
			}

			$items[] = array(
				'id'           => (int) $post->ID,
				'type'         => (string) $post->post_type,
				'status'       => (string) $post->post_status,
				'title'        => (string) get_the_title( $post ),
				'slug'         => (string) $post->post_name,
				'permalink'    => (string) get_permalink( $post ),
				'excerpt'      => wp_strip_all_tags( (string) $post->post_excerpt ),
				'summary'      => wp_trim_words( $text, 45 ),
				'word_count'   => $words,
				'content_hash' => '',
				'indexed_at'   => '',
				'modified_gmt' => (string) $post->post_modified_gmt,
				'stale'        => true,
				'metadata'     => array(),
				'degraded'     => true,
			);
		}

		return $items;
	}

	/**
	 * Search indexed content chunks.
	 *
	 * @param array<string, mixed> $args Search args.
	 * @return array<string, mixed>
	 */
	public function search_chunks( array $args ): array {
		$result          = $this->repo()->search_chunks( $args );
		$result['items'] = $this->filled_readable_chunks( $args, $result );
		$result          = $this->filtered_result_metadata( $result, $result['items'] );
		$result['usage'] = array(
			'compact' => 'Compact responses include section text snippets for fast planning.',
			'full'    => 'Use context=full only when exact serialized block markup is needed for a long-form section update.',
		);

		return $result;
	}

	/**
	 * Find related indexed content for a source post or topic.
	 *
	 * @param array<string, mixed> $args Related-content args.
	 * @return array<string, mixed>
	 */
	public function find_related( array $args ): array {
		$post_id = absint( $args['post_id'] ?? 0 );
		$source  = $post_id > 0 ? $this->repo()->content_item( $post_id ) : array();
		$query   = sanitize_text_field( (string) ( $args['query'] ?? '' ) );

		if ( $post_id > 0 && ! $this->can_read_post( $post_id ) ) {
			return $this->error_response( 'forbidden', 'You do not have permission to read the source content item.' );
		}

		if ( '' === $query && array() !== $source ) {
			$query = trim( (string) ( $source['title'] ?? '' ) . ' ' . (string) ( $source['summary'] ?? '' ) );
		}

		if ( '' === $query ) {
			return $this->error_response( 'invalid_query', 'Provide post_id for an indexed source item or a related-content query.' );
		}

		$limit  = max( 1, min( 20, absint( $args['limit'] ?? 10 ) ) );
		$result = $this->repo()->search_items(
			array(
				'query'     => $query,
				'post_type' => $args['post_type'] ?? '',
				'status'    => $args['status'] ?? '',
				'per_page'  => min( 50, max( $limit * 3, 10 ) ),
			)
		);

		$items = array_values(
			array_filter(
				$this->filter_readable_items( (array) ( $result['items'] ?? array() ) ),
				static fn ( array $item ): bool => (int) ( $item['id'] ?? 0 ) !== $post_id
			)
		);

		$items = $this->rank_items( $query, $items );

		return array(
			'items'        => array_slice( $items, 0, $limit ),
			'total'        => min( count( $items ), $limit ),
			'query'        => $query,
			'source_post'  => $source,
			'context'      => 'compact',
			'index'        => $this->index_summary_for_items( $items ),
			'next_actions' => array( 'Use content_find_internal_links to turn related items into anchor suggestions.' ),
		);
	}

	/**
	 * Find internal link candidates for content planning or updates.
	 *
	 * @param array<string, mixed> $args Link args.
	 * @return array<string, mixed>
	 */
	public function find_internal_links( array $args ): array {
		$source_id = absint( $args['source_id'] ?? 0 );
		$topic     = sanitize_text_field( (string) ( $args['topic'] ?? $args['query'] ?? '' ) );
		$source    = $source_id > 0 ? $this->repo()->content_item( $source_id ) : array();
		$policy    = $this->internal_link_policy()->active();

		if ( $source_id > 0 && ! $this->can_read_post( $source_id ) ) {
			return $this->error_response( 'forbidden', 'You do not have permission to read the source content item.' );
		}

		if ( '' === $topic && array() !== $source ) {
			$topic = trim( (string) ( $source['title'] ?? '' ) . ' ' . (string) ( $source['summary'] ?? '' ) );
		}

		if ( '' === $topic ) {
			return $this->error_response( 'invalid_topic', 'Provide source_id for an indexed post or a topic/query for internal link discovery.' );
		}

		$already_linked = $source_id > 0 ? $this->repo()->linked_target_ids( $source_id ) : array();
		$limit          = max( 1, min( (int) $policy['limits']['max_suggestions_per_source'], absint( $args['limit'] ?? $policy['limits']['max_suggestions_per_source'] ) ) );
		$result         = $this->repo()->search_items(
			array(
				'query'     => $topic,
				'post_type' => $args['post_type'] ?? '',
				'status'    => $args['status'] ?? '',
				'per_page'  => min( 50, max( $limit * 3, 10 ) ),
			)
		);
		$related_items  = $this->internal_link_policy()->filter_candidates(
			0,
			$source,
			$this->rank_items( $topic, $this->filter_readable_items( (array) ( $result['items'] ?? array() ) ) )
		);
		$target_ids     = array_values(
			array_filter(
				array_map(
					static fn ( array $item ): int => (int) ( $item['id'] ?? 0 ),
					$related_items
				),
				static fn ( int $id ): bool => $id > 0
			)
		);
		if ( $source_id > 0 ) {
			$target_ids[] = $source_id;
		}

		$link_stats = $this->repo()->internal_link_stats( $target_ids );
		$anchors    = array_map(
			fn ( array $item ): string => $this->anchor_text( $item, $topic ),
			$related_items
		);
		$usage      = $this->repo()->internal_link_anchor_usage( $anchors, $source_id );
		$source_seo = $source_id > 0 ? $this->seo_terms_for_post( $source_id ) : array();
		$excluded   = array(
			'self_links'             => 0,
			'already_linked_targets' => 0,
		);

		$items = array();
		foreach ( $related_items as $item ) {
			$id = (int) ( $item['id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}

			if ( $id === $source_id ) {
				++$excluded['self_links'];
				continue;
			}

			if ( in_array( $id, $already_linked, true ) ) {
				++$excluded['already_linked_targets'];
				continue;
			}

			$anchor  = $this->anchor_text( $item, $topic );
			$quality = $this->internal_link_quality(
				$source,
				$item,
				$anchor,
				$topic,
				$link_stats[ $id ] ?? array(),
				$link_stats[ $source_id ] ?? array(),
				$usage[ $this->anchor_key( $anchor ) ] ?? array(),
				$source_seo,
				$this->seo_terms_for_post( $id )
			);

			$items[] = array(
				'post_id'         => $id,
				'title'           => (string) ( $item['title'] ?? '' ),
				'permalink'       => (string) ( $item['permalink'] ?? '' ),
				'anchor_text'     => $anchor,
				'score'           => (int) $quality['score'],
				'quality_score'   => (int) $quality['score'],
				'relevance_score' => (int) ( $item['score'] ?? 0 ),
				'confidence'      => (string) $quality['confidence'],
				'reasons'         => (array) $quality['reasons'],
				'warnings'        => (array) $quality['warnings'],
				'quality_signals' => (array) $quality['signals'],
				'reason'          => implode( ' ', (array) $quality['reasons'] ),
				'stale'           => ! empty( $item['stale'] ),
			);

			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		return array(
			'items'              => $items,
			'total'              => count( $items ),
			'source_post'        => $source,
			'topic'              => $topic,
			'already_linked_ids' => $already_linked,
			'context'            => 'compact',
			'index'              => $this->index_summary_for_items( $items ),
			'quality_summary'    => array(
				'read_only'       => true,
				'excluded'        => $excluded,
				'scoring_version' => 'anchor-quality-v1',
				'bounds'          => array(
					'candidate_scan_limit' => min( 50, max( $limit * 3, 10 ) ),
					'return_limit'         => $limit,
					'max_new_links'        => (int) $policy['limits']['max_new_links_per_source'],
					'max_repeated_targets' => (int) $policy['limits']['max_repeated_target_links'],
				),
			),
			'policy'             => $policy,
			'usage'              => $this->internal_link_policy()->guidance(),
			'next_actions'       => array( 'Use the suggested anchors inside semantic paragraph/list blocks, not raw HTML.', 'Do not add more than the active policy max_new_links_per_source without explicit user review.' ),
		);
	}

	/**
	 * Audit indexed content for internal-link health signals.
	 *
	 * @param array<string, mixed> $args Audit args.
	 * @return array<string, mixed>
	 */
	public function audit_internal_links( array $args ): array {
		$state = sanitize_key( (string) ( $args['state'] ?? 'needs_review' ) );
		if ( in_array( $state, array( 'broken', 'missing_target', 'unreadable_target', 'unpublished_target', 'stale_permalink', 'redirected' ), true ) ) {
			$result = $this->audit_internal_link_targets( $args, $state );

			return $this->with_internal_link_health( $result, $args );
		}

		$result = $this->repo()->internal_link_audit( $args );
		$policy = $this->internal_link_policy()->active();
		$items  = array_values(
			array_filter(
				$this->filter_readable_items( (array) ( $result['items'] ?? array() ) ),
				fn ( array $item ): bool => $this->internal_link_policy()->allows_item( $item, $policy )
			)
		);

		$result['items']         = $items;
		$result['visible_total'] = count( $items );
		if ( $this->can_view_global_index_summary() ) {
			$result['filtered_by_access'] = false;
			$result['total_is_estimated'] = false;
		} else {
			$result['total']              = count( $items );
			$result['filtered_by_access'] = true;
			$result['total_is_estimated'] = true;
		}

		$filters           = (array) ( $result['filters'] ?? array() );
		$result['index']   = $this->index_summary_for_items( $items );
		$result['summary'] = array(
			'returned_items' => count( $items ),
			'state'          => (string) ( $filters['state'] ?? 'needs_review' ),
			'thresholds'     => (array) ( $result['thresholds'] ?? array() ),
		);
		$result['policy']  = $policy;
		$result['usage']   = array(
			'read_only'       => 'This audit reports indexed link-health signals only; it does not store suggestions or apply content changes.',
			'freshness'       => 'Rows marked stale_index should be refreshed with content_index_refresh_batch before relying on counts.',
			'next_tool'       => 'Use content_find_internal_links for candidate anchors after selecting a source item.',
			'large_site_note' => 'Use page, per_page, post_type, status, and state filters instead of requesting the whole graph.',
			'policy'          => $this->internal_link_policy()->guidance(),
		);

		return $this->with_internal_link_health( $result, $args );
	}

	/**
	 * Add a compact internal-link health summary and action queue.
	 *
	 * @param array<string, mixed> $result Audit result.
	 * @param array<string, mixed> $args   Audit args.
	 * @return array<string, mixed>
	 */
	private function with_internal_link_health( array $result, array $args ): array {
		$thresholds = (array) ( $result['thresholds'] ?? $this->internal_link_thresholds( $args ) );
		$queue      = $this->internal_link_action_queue( $args, $thresholds );
		$counts     = $this->internal_link_health_counts( $args, $thresholds, $result, $queue );

		$result['health_summary'] = array(
			'score'       => $this->internal_link_health_score( $counts ),
			'status'      => $this->internal_link_health_status( $counts ),
			'counts'      => $counts,
			'read_only'   => true,
			'scope'       => $counts['filtered_by_access'] ? 'visible_content' : 'site_index',
			'methodology' => array(
				'version' => 'internal-link-health-v1',
				'basis'   => 'Bounded local content index, link graph, internal-link policy, anchor warnings, and indexed target-state signals.',
				'claim'   => 'Site-structure and discoverability guidance only; this is not an SEO ranking guarantee.',
			),
		);
		$result['action_queue']   = $queue;
		$result['next_actions']   = array_values(
			array_unique(
				array_merge(
					(array) ( $result['next_actions'] ?? array() ),
					array(
						'Work through action_queue in priority order; every action is review-only and requires a separate content edit.',
						'Refresh stale index rows before relying on link counts for large cleanup decisions.',
					)
				)
			)
		);

		return $result;
	}

	/**
	 * Build normalized internal-link audit thresholds.
	 *
	 * @param array<string, mixed> $args Audit args.
	 * @return array{min_inbound_links:int,thin_word_count:int,max_outbound_links:int}
	 */
	private function internal_link_thresholds( array $args ): array {
		return array(
			'min_inbound_links'  => max( 1, min( 100, absint( $args['min_inbound_links'] ?? 2 ) ) ),
			'thin_word_count'    => max( 1, min( 5000, absint( $args['thin_word_count'] ?? 300 ) ) ),
			'max_outbound_links' => max( 1, min( 500, absint( $args['max_outbound_links'] ?? 25 ) ) ),
		);
	}

	/**
	 * Build compact health counts.
	 *
	 * @param array<string, mixed> $args       Audit args.
	 * @param array<string, int>   $thresholds Audit thresholds.
	 * @param array<string, mixed> $result     Current audit result.
	 * @param array<string, mixed> $queue      Action queue result.
	 * @return array<string, mixed>
	 */
	private function internal_link_health_counts( array $args, array $thresholds, array $result, array $queue ): array {
		$index             = (array) ( $result['index'] ?? array() );
		$filtered          = ! empty( $result['filtered_by_access'] );
		$global_index      = ! $filtered && $this->can_view_global_index_summary();
		$total_items       = $global_index ? (int) ( $index['total_items'] ?? 0 ) : (int) ( $index['visible_items'] ?? $result['visible_total'] ?? 0 );
		$stale_index_items = $global_index ? (int) ( $index['stale_items'] ?? 0 ) : (int) ( $index['stale_visible_items'] ?? 0 );

		$counts = array(
			'total_indexed_items'       => max( 0, $total_items ),
			'orphan_content'            => 0,
			'underlinked_content'       => 0,
			'thin_content'              => 0,
			'stale_index_rows'          => max( 0, $stale_index_items ),
			'link_heavy_content'        => 0,
			'broken_internal_links'     => 0,
			'stale_internal_links'      => 0,
			'anchor_quality_warnings'   => 0,
			'pending_suggestions'       => (int) ( $queue['total'] ?? count( (array) ( $queue['items'] ?? array() ) ) ),
			'filtered_by_access'        => $filtered,
			'total_is_estimated'        => ! empty( $result['total_is_estimated'] ),
			'large_site_bounds_applied' => ! empty( $queue['has_more'] ),
			'candidate_scan_limit'      => (int) ( $queue['bounds']['candidate_scan_limit'] ?? 0 ),
			'action_queue_return_limit' => (int) ( $queue['bounds']['return_limit'] ?? 0 ),
		);

		if ( $global_index ) {
			foreach ( array( 'orphan', 'underlinked', 'thin', 'link_heavy' ) as $state ) {
				$state_result                        = $this->repo()->internal_link_audit(
					array_merge(
						$args,
						$thresholds,
						array(
							'state'    => $state,
							'page'     => 1,
							'per_page' => 1,
						)
					)
				);
				$key                                 = 'orphan' === $state ? 'orphan_content' : $state . '_content';
				$counts[ $key ]                      = (int) ( $state_result['total'] ?? 0 );
				$counts['large_site_bounds_applied'] = $counts['large_site_bounds_applied'] || (int) ( $state_result['total'] ?? 0 ) > 1;
			}
		} else {
			foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$flags = (array) ( $item['flags'] ?? array() );
				if ( in_array( 'orphan', $flags, true ) ) {
					++$counts['orphan_content'];
				}
				if ( in_array( 'underlinked', $flags, true ) ) {
					++$counts['underlinked_content'];
				}
				if ( in_array( 'thin', $flags, true ) ) {
					++$counts['thin_content'];
				}
				if ( in_array( 'link_heavy', $flags, true ) ) {
					++$counts['link_heavy_content'];
				}
			}
		}

		foreach ( (array) ( $queue['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$action = (string) ( $item['action'] ?? '' );
			if ( 'fix_broken_link' === $action ) {
				++$counts['broken_internal_links'];
			}
			if ( in_array( $action, array( 'refresh_stale_index', 'fix_stale_link_target' ), true ) ) {
				++$counts['stale_internal_links'];
			}
			if ( 'review_anchor_quality' === $action ) {
				++$counts['anchor_quality_warnings'];
			}
		}

		return $counts;
	}

	/**
	 * Return a prioritized, bounded action queue.
	 *
	 * @param array<string, mixed> $args       Audit args.
	 * @param array<string, int>   $thresholds Audit thresholds.
	 * @return array<string, mixed>
	 */
	private function internal_link_action_queue( array $args, array $thresholds ): array {
		$limit      = max( 1, min( 50, absint( $args['queue_limit'] ?? $args['per_page'] ?? 10 ) ) );
		$scan_limit = max( $limit, min( 50, $limit * 3 ) );
		$candidates = array_merge(
			$this->internal_link_target_actions( $args, $scan_limit ),
			$this->internal_link_content_actions( $args, $thresholds, $scan_limit )
		);
		$candidates = array_values( $candidates );
		$total      = count( $candidates );

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				$priority = (int) ( $b['priority_score'] ?? 0 ) <=> (int) ( $a['priority_score'] ?? 0 );
				if ( 0 !== $priority ) {
					return $priority;
				}

				return strcmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
			}
		);

		return array(
			'items'     => array_slice( $candidates, 0, $limit ),
			'total'     => min( $total, $scan_limit * 2 ),
			'has_more'  => $total > $limit,
			'context'   => 'compact',
			'read_only' => true,
			'bounds'    => array(
				'candidate_scan_limit' => $scan_limit,
				'return_limit'         => $limit,
			),
			'usage'     => array(
				'no_auto_apply' => 'Queue items are recommendations only; they do not mutate content.',
				'pagination'    => 'Increase queue_limit up to 50 or use state filters to inspect specific issue classes.',
			),
		);
	}

	/**
	 * Return action candidates for broken or stale indexed targets.
	 *
	 * @param array<string, mixed> $args       Audit args.
	 * @param int                  $scan_limit Candidate scan limit.
	 * @return list<array<string, mixed>>
	 */
	private function internal_link_target_actions( array $args, int $scan_limit ): array {
		$result  = $this->audit_internal_link_targets(
			array_merge(
				$args,
				array(
					'state'    => 'broken',
					'page'     => 1,
					'per_page' => $scan_limit,
				)
			),
			'broken'
		);
		$actions = array();

		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$source = (array) ( $item['source_post'] ?? array() );
			$state  = (string) ( $item['state'] ?? '' );
			$action = 'stale_permalink' === $state || 'redirected' === $state ? 'fix_stale_link_target' : 'fix_broken_link';
			$score  = 'fix_broken_link' === $action ? 96 : 88;
			if ( ! empty( $source['stale'] ) ) {
				$score -= 8;
			}

			$actions[] = array(
				'id'             => 'link:' . (int) ( $item['link_id'] ?? 0 ),
				'action'         => $action,
				'title'          => (string) ( $source['title'] ?? '' ),
				'post_id'        => (int) ( $source['post_id'] ?? 0 ),
				'post_type'      => (string) ( $source['post_type'] ?? '' ),
				'post_status'    => (string) ( $source['post_status'] ?? '' ),
				'priority_score' => max( 0, min( 100, $score ) ),
				'reasons'        => array_values(
					array_filter(
						array(
							'fix_broken_link' === $action ? 'Indexed internal link points to a missing, unreadable, or unpublished local target.' : 'Indexed internal link appears to use a stale URL or redirect destination.',
							(string) ( $item['suggested_next_action'] ?? '' ),
						)
					)
				),
				'signals'        => array(
					'target_state' => $state,
					'anchor_text'  => (string) ( $item['anchor_text'] ?? '' ),
					'target_url'   => (string) ( $item['target_url'] ?? '' ),
					'source_stale' => ! empty( $source['stale'] ),
				),
			);
		}

		return $actions;
	}

	/**
	 * Return action candidates for content-level internal-link health flags.
	 *
	 * @param array<string, mixed> $args       Audit args.
	 * @param array<string, int>   $thresholds Audit thresholds.
	 * @param int                  $scan_limit Candidate scan limit.
	 * @return list<array<string, mixed>>
	 */
	private function internal_link_content_actions( array $args, array $thresholds, int $scan_limit ): array {
		$result  = $this->repo()->internal_link_audit(
			array_merge(
				$args,
				$thresholds,
				array(
					'state'    => 'needs_review',
					'page'     => 1,
					'per_page' => $scan_limit,
				)
			)
		);
		$policy  = $this->internal_link_policy()->active();
		$actions = array();

		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || ! $this->can_read_post( (int) ( $item['post_id'] ?? $item['id'] ?? 0 ) ) || ! $this->internal_link_policy()->allows_item( $item, $policy ) ) {
				continue;
			}

			$actions[] = $this->internal_link_content_action( $item, $thresholds );
		}

		return array_values( array_filter( $actions ) );
	}

	/**
	 * Convert one content audit row into a queue action.
	 *
	 * @param array<string, mixed> $item       Content audit row.
	 * @param array<string, int>   $thresholds Audit thresholds.
	 * @return array<string, mixed>
	 */
	private function internal_link_content_action( array $item, array $thresholds ): array {
		$flags   = (array) ( $item['flags'] ?? array() );
		$action  = '';
		$reasons = array();
		$score   = 0;

		if ( in_array( 'stale_index', $flags, true ) ) {
			$action    = 'refresh_stale_index';
			$score     = 82;
			$reasons[] = 'Indexed row is stale; refresh it before trusting link counts or recommendations.';
		} elseif ( in_array( 'orphan', $flags, true ) || in_array( 'underlinked', $flags, true ) ) {
			$action    = 'add_inbound_links';
			$score     = in_array( 'orphan', $flags, true ) ? 78 : 68;
			$reasons[] = in_array( 'orphan', $flags, true ) ? 'No inbound internal links are indexed for this content.' : 'Indexed inbound links are below the configured threshold.';
		} elseif ( in_array( 'link_heavy', $flags, true ) ) {
			$action    = 'reduce_link_heavy_page';
			$score     = 56;
			$reasons[] = 'Outbound internal links exceed the configured link-heavy threshold.';
		} elseif ( in_array( 'thin', $flags, true ) ) {
			$action    = 'review_anchor_quality';
			$score     = 48;
			$reasons[] = 'Thin indexed content may need stronger context before adding more internal links.';
		}

		if ( '' === $action ) {
			return array();
		}

		$inbound  = (int) ( $item['inbound_internal_links'] ?? 0 );
		$outbound = (int) ( $item['outbound_internal_links'] ?? 0 );
		$words    = (int) ( $item['word_count'] ?? 0 );
		if ( $words >= 900 && in_array( $action, array( 'add_inbound_links', 'review_anchor_quality' ), true ) ) {
			$score += 6;
		}
		if ( $outbound > (int) $thresholds['max_outbound_links'] ) {
			$score += 4;
		}
		if ( 0 === $inbound ) {
			$score += 5;
		}

		return array(
			'id'             => 'post:' . (int) ( $item['post_id'] ?? $item['id'] ?? 0 ) . ':' . $action,
			'action'         => $action,
			'title'          => (string) ( $item['title'] ?? '' ),
			'post_id'        => (int) ( $item['post_id'] ?? $item['id'] ?? 0 ),
			'post_type'      => (string) ( $item['type'] ?? $item['post_type'] ?? '' ),
			'post_status'    => (string) ( $item['status'] ?? $item['post_status'] ?? '' ),
			'priority_score' => max( 0, min( 100, $score ) ),
			'reasons'        => $reasons,
			'signals'        => array(
				'flags'                   => $flags,
				'inbound_internal_links'  => $inbound,
				'outbound_internal_links' => $outbound,
				'word_count'              => $words,
				'stale_index'             => ! empty( $item['stale'] ),
			),
		);
	}

	/**
	 * Score internal-link health from local signals.
	 *
	 * @param array<string, mixed> $counts Health counts.
	 */
	private function internal_link_health_score( array $counts ): int {
		$total  = max( 1, (int) ( $counts['total_indexed_items'] ?? 0 ) );
		$score  = 100;
		$score -= min( 30, (int) ceil( ( (int) $counts['orphan_content'] / $total ) * 30 ) );
		$score -= min( 18, (int) ceil( ( (int) $counts['underlinked_content'] / $total ) * 18 ) );
		$score -= min( 14, (int) ceil( ( (int) $counts['stale_index_rows'] / $total ) * 14 ) );
		$score -= min( 10, (int) ceil( ( (int) $counts['link_heavy_content'] / $total ) * 10 ) );
		$score -= min( 8, (int) ceil( ( (int) $counts['thin_content'] / $total ) * 8 ) );
		$score -= min( 20, (int) ( $counts['broken_internal_links'] ?? 0 ) * 6 );
		$score -= min( 12, (int) ( $counts['stale_internal_links'] ?? 0 ) * 4 );
		$score -= min( 8, (int) ( $counts['anchor_quality_warnings'] ?? 0 ) * 2 );

		if ( 0 === (int) ( $counts['total_indexed_items'] ?? 0 ) ) {
			$score = 0;
		}

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Convert a numeric health score into a compact status.
	 *
	 * @param array<string, mixed> $counts Health counts.
	 */
	private function internal_link_health_status( array $counts ): string {
		if ( 0 === (int) ( $counts['total_indexed_items'] ?? 0 ) ) {
			return 'empty_index';
		}

		$score = $this->internal_link_health_score( $counts );
		if ( $score >= 85 ) {
			return 'healthy';
		}
		if ( $score >= 65 ) {
			return 'needs_attention';
		}

		return 'needs_work';
	}

	/**
	 * Audit indexed outbound internal links for broken or stale targets.
	 *
	 * @param array<string, mixed> $args  Audit args.
	 * @param string               $state Requested target state.
	 * @return array<string, mixed>
	 */
	private function audit_internal_link_targets( array $args, string $state ): array {
		$args['state'] = 'broken';
		$result        = $this->repo()->internal_link_target_audit( $args );
		$policy        = $this->internal_link_policy()->active();
		$inspector     = new InternalLinkTargetInspector();
		$items         = array();

		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$source = (array) ( $item['source_post'] ?? array() );
			if ( ! $this->can_read_post( (int) ( $source['post_id'] ?? 0 ) ) || ! $this->internal_link_policy()->allows_item( $source, $policy ) ) {
				continue;
			}

			$inspection = $inspector->inspect( $item );
			$item_state = (string) ( $inspection['state'] ?? '' );
			if ( ! $inspector->is_reportable_state( $item_state ) ) {
				continue;
			}
			if ( 'broken' !== $state && $state !== $item_state ) {
				continue;
			}

			$items[] = array_merge(
				$item,
				array(
					'state'                 => $item_state,
					'suggested_next_action' => (string) ( $inspection['suggested_next_action'] ?? '' ),
					'evidence'              => (array) ( $inspection['evidence'] ?? array() ),
				)
			);
		}

		$result['items']         = $items;
		$result['visible_total'] = count( $items );
		$result['filters']       = array_merge( (array) ( $result['filters'] ?? array() ), array( 'state' => $state ) );
		if ( $this->can_view_global_index_summary() ) {
			$result['filtered_by_access'] = false;
			$result['total_is_estimated'] = false;
		} else {
			$result['total']              = count( $items );
			$result['filtered_by_access'] = true;
			$result['total_is_estimated'] = true;
		}

		$result['index']        = $this->index_summary_for_link_targets( $items );
		$result['summary']      = array(
			'returned_items' => count( $items ),
			'state'          => $state,
			'audit_type'     => 'internal_link_targets',
		);
		$result['policy']       = $policy;
		$result['usage']        = array(
			'read_only'       => 'This audit reports indexed internal links whose local targets appear missing, unreadable, unpublished, stale, or redirected; it does not rewrite links or create redirects.',
			'freshness'       => 'Refresh source rows with content_index_refresh_batch before making large cleanup decisions from older index data.',
			'large_site_note' => 'Use page, per_page, post_type, status, and state filters to scan the indexed link graph in bounded batches.',
			'redirects'       => 'Redirect evidence is included only when local Rank Math redirect data is available to the current user.',
		);
		$result['next_actions'] = array(
			'Review source_post and bounded evidence before editing content.',
			'Use content_find_internal_links after cleanup when replacement targets are needed.',
		);

		return $result;
	}

	/**
	 * Return active internal-link policy defaults and limits.
	 *
	 * @return array<string, mixed>
	 */
	public function internal_link_policy_context(): array {
		$policy = $this->internal_link_policy()->active();

		return array(
			'type'         => 'internal_link_policy',
			'policy'       => $policy,
			'limits'       => $policy['limits'],
			'guidance'     => $this->internal_link_policy()->guidance(),
			'capabilities' => array(
				'reads_policy'           => true,
				'filters_suggestions'    => true,
				'filters_audit_rows'     => true,
				'reviewable_suggestions' => true,
				'dry_run_apply_plan'     => true,
				'applies_content_links'  => false,
			),
		);
	}

	/**
	 * Create reviewable internal-link suggestion records.
	 *
	 * @param array<string, mixed> $args Suggestion args.
	 * @return array<string, mixed>
	 */
	public function create_internal_link_suggestions( array $args ): array {
		$source_id = absint( $args['source_id'] ?? $args['post_id'] ?? 0 );
		if ( $source_id <= 0 || ! $this->can_edit_post( $source_id ) ) {
			return $this->error_response( 'forbidden', 'You do not have permission to create suggestions for that source content item.' );
		}

		foreach ( $this->suggestion_target_ids( $args ) as $target_id ) {
			if ( ! $this->can_read_post( $target_id ) ) {
				return $this->error_response( 'forbidden', 'You do not have permission to read one of the target content items.' );
			}
		}

		$result = ( new InternalLinkSuggestionRepository() )->create( $args );
		if ( ! isset( $result['error'] ) ) {
			$result['capabilities'] = array(
				'execute_apply' => false,
				'dry_run_apply' => true,
			);
			$result['usage']        = array(
				'review_required' => 'Suggestions are review records only. Approve or reject each suggestion before apply planning.',
				'no_auto_apply'   => 'This tool never mutates post content.',
			);
		}

		return $result;
	}

	/**
	 * List reviewable internal-link suggestion records.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public function list_internal_link_suggestions( array $args ): array {
		$result = ( new InternalLinkSuggestionRepository() )->list( $args );
		$items  = array_values(
			array_filter(
				(array) ( $result['items'] ?? array() ),
				fn ( mixed $item ): bool => is_array( $item )
					&& $this->can_read_post( (int) ( $item['source_post']['id'] ?? 0 ) )
					&& $this->can_read_post( (int) ( $item['target_post']['id'] ?? 0 ) )
			)
		);

		$result['items']              = $items;
		$result['visible_total']      = count( $items );
		$result['filtered_by_access'] = true;
		$result['usage']              = array(
			'review_first'  => 'Approve or reject suggestions before asking for an apply plan.',
			'execute_apply' => 'Executing approved suggestions is intentionally unavailable in this slice.',
		);

		return $result;
	}

	/**
	 * Approve or reject one internal-link suggestion.
	 *
	 * @param array<string, mixed> $args Review args.
	 * @return array<string, mixed>
	 */
	public function review_internal_link_suggestion( array $args ): array {
		$id         = sanitize_key( (string) ( $args['id'] ?? $args['suggestion_id'] ?? '' ) );
		$repository = new InternalLinkSuggestionRepository();
		$suggestion = $repository->find( $id );
		if ( array() === $suggestion ) {
			return $this->error_response( 'suggestion_not_found', 'No internal-link suggestion was found for that ID.' );
		}

		if ( ! $this->can_edit_post( (int) ( $suggestion['source_post']['id'] ?? 0 ) ) ) {
			return $this->error_response( 'forbidden', 'You do not have permission to review suggestions for that source content item.' );
		}

		return $repository->review(
			$id,
			(string) ( $args['action'] ?? $args['status'] ?? '' ),
			(string) ( $args['note'] ?? $args['review_note'] ?? '' )
		);
	}

	/**
	 * Return a dry-run apply plan for an approved internal-link suggestion.
	 *
	 * @param array<string, mixed> $args Apply args.
	 * @return array<string, mixed>
	 */
	public function apply_internal_link_suggestion( array $args ): array {
		$id         = sanitize_key( (string) ( $args['id'] ?? $args['suggestion_id'] ?? '' ) );
		$repository = new InternalLinkSuggestionRepository();
		$suggestion = $repository->find( $id );
		if ( array() === $suggestion ) {
			return $this->error_response( 'suggestion_not_found', 'No internal-link suggestion was found for that ID.' );
		}

		$source_id = (int) ( $suggestion['source_post']['id'] ?? 0 );
		if ( ! $this->can_edit_post( $source_id ) ) {
			return $this->error_response( 'forbidden', 'You do not have permission to plan applying this source content item.' );
		}

		return $repository->apply_plan( $id, $this->is_dry_run( $args ) );
	}

	/**
	 * List durable Aculect memory items.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public function list_memories( array $args ): array {
		$result                 = $this->repo()->list_memories( $args );
		$result['protocol']     = array(
			'source_of_truth' => 'Aculect Intelligence local memory, not ChatGPT or Claude saved memory.',
			'write_path'      => 'Use intelligence_feedback_submit for normal learning suggestions. Use memory_save only when explicit write permission and confirmation are available.',
			'review_default'  => 'New memory_save entries default to pending review unless status is explicitly approved.',
		);
		$result['next_actions'] = array( 'Use relevant memory items as constraints when preparing content workflows.' );

		return $result;
	}

	/**
	 * Save or update one durable memory item.
	 *
	 * @param array<string, mixed> $args Memory args.
	 * @return array<string, mixed>
	 */
	public function save_memory( array $args ): array {
		if ( $this->is_memory_bootstrap_request( $args ) ) {
			return $this->bootstrap_memories( $args, 'memory.save' );
		}

		$status = sanitize_key( (string) ( $args['status'] ?? 'pending' ) );
		if ( ! in_array( $status, array( 'approved', 'pending', 'dismissed' ), true ) ) {
			$status = 'pending';
		}

		if ( $this->is_dry_run( $args ) ) {
			return $this->preview_response(
				'memory.save',
				$args,
				array(
					'type' => 'memory',
					'id'   => sanitize_text_field( (string) ( $args['key'] ?? $args['memory_key'] ?? '' ) ),
				),
				array(
					$this->change( 'value', null, sanitize_text_field( (string) ( $args['value'] ?? '' ) ) ),
					$this->change( 'status', null, $status ),
				),
				'approved' === $status
					? array( 'Approved memories affect future Aculect Intelligence responses; use this only for explicit durable guidance.' )
					: array( 'Pending memories require admin review before they affect future Aculect Intelligence responses.' )
			);
		}

		$result = $this->repo()->upsert_memory( $args );
		if ( 'success' === ( $result['status'] ?? '' ) ) {
			$memory_status           = (string) ( $result['memory']['status'] ?? $status );
			$result['review_status'] = array(
				'status'                => $memory_status,
				'admin_review_required' => 'approved' !== $memory_status,
				'updates_memory'        => 'approved' === $memory_status,
			);
			$result['next_actions']  = 'approved' === $memory_status
				? array( 'Call memory_list to confirm the durable memory is available to future workflows.' )
				: array( 'Review and approve this pending memory in Aculect Intelligence before relying on it in future workflows.' );
		}

		return $result;
	}

	/**
	 * Bootstrap durable memory from local intelligence signals.
	 *
	 * @param array<string, mixed> $args Bootstrap args.
	 * @return array<string, mixed>
	 */
	public function bootstrap_memory( array $args ): array {
		return $this->bootstrap_memories( $args, 'memory.bootstrap' );
	}

	/**
	 * Return whether a memory_save request should bootstrap initial memory.
	 *
	 * @param array<string, mixed> $args Tool args.
	 */
	private function is_memory_bootstrap_request( array $args ): bool {
		$mode      = sanitize_key( (string) ( $args['mode'] ?? '' ) );
		$has_key   = '' !== sanitize_text_field( (string) ( $args['key'] ?? $args['memory_key'] ?? '' ) );
		$has_value = '' !== sanitize_text_field( (string) ( $args['value'] ?? '' ) );

		return 'bootstrap' === $mode || ( ! $has_key && ! $has_value );
	}

	/**
	 * Bootstrap missing memory rows.
	 *
	 * @param array<string, mixed> $args   Tool args.
	 * @param string               $action Tool action.
	 * @return array<string, mixed>
	 */
	private function bootstrap_memories( array $args, string $action ): array {
		$status = sanitize_key( (string) ( $args['status'] ?? 'pending' ) );
		if ( ! in_array( $status, array( 'approved', 'pending' ), true ) ) {
			$status = 'pending';
		}

		$force         = ! empty( $args['force'] );
		$candidates    = $this->initial_memory_candidates();
		$existing      = $this->repo()->list_memories(
			array(
				'status'   => '',
				'per_page' => 100,
			)
		);
		$existing_keys = array_fill_keys( array_map( 'strval', array_column( (array) ( $existing['items'] ?? array() ), 'key' ) ), true );
		$items         = array();
		$skipped       = array();

		foreach ( $candidates as $candidate ) {
			$key = (string) ( $candidate['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}

			$memory = array_merge( $candidate, array( 'status' => $status ) );
			if ( isset( $existing_keys[ $key ] ) && ! $force ) {
				$skipped[] = array_merge( $memory, array( 'reason' => 'already_exists' ) );
				continue;
			}

			$items[] = $memory;
		}

		if ( $this->is_dry_run( $args ) ) {
			return array(
				'dry_run'               => true,
				'status'                => 'preview',
				'action'                => $action,
				'risk_level'            => 'update',
				'target'                => array(
					'type' => 'memory_bootstrap',
					'id'   => 'initial_memory',
				),
				'items'                 => $items,
				'skipped'               => $skipped,
				'changes'               => array_map(
					static fn ( array $item ): array => array(
						'field' => (string) ( $item['key'] ?? '' ),
						'from'  => null,
						'to'    => (string) ( $item['value'] ?? '' ),
					),
					$items
				),
				'warnings'              => $this->bootstrap_warnings( $items, $skipped, $status ),
				'confirmation_required' => true,
				'next_actions'          => array( 'Repeat this tool call with confirmation_token to store these memory rows.' ),
			);
		}

		$saved = array();
		foreach ( $items as $item ) {
			$result = $this->repo()->upsert_memory( $item );
			if ( 'success' === ( $result['status'] ?? '' ) && is_array( $result['memory'] ?? null ) ) {
				$saved[] = $result['memory'];
			}
		}

		return array(
			'status'        => array() === $saved ? 'unchanged' : 'success',
			'message'       => array() === $saved
				? 'No new Aculect memory rows were created. Existing memory already covers the bootstrap keys.'
				: 'Aculect memory bootstrap stored local memory rows for future MCP workflows.',
			'items'         => $saved,
			'skipped'       => $skipped,
			'summary'       => array(
				'created'  => count( $saved ),
				'skipped'  => count( $skipped ),
				'status'   => $status,
				'existing' => (int) ( $existing['total'] ?? count( $existing_keys ) ),
			),
			'review_status' => array(
				'status'                => $status,
				'admin_review_required' => 'approved' !== $status,
				'updates_memory'        => 'approved' === $status,
			),
			'next_actions'  => 'approved' === $status
				? array( 'Call memory_list to use the new approved memory in planning.' )
				: array( 'Review and approve pending memory rows in the Aculect Intelligence admin screen before relying on them in future workflows.' ),
		);
	}

	/**
	 * Return bootstrap warnings.
	 *
	 * @param list<array<string, mixed>> $items   Proposed items.
	 * @param list<array<string, mixed>> $skipped Skipped items.
	 * @param string                     $status  Review status.
	 * @return list<string>
	 */
	private function bootstrap_warnings( array $items, array $skipped, string $status ): array {
		$warnings = array();
		if ( array() === $items && array() !== $skipped ) {
			$warnings[] = 'Existing memory rows already cover the bootstrap keys. Use force=true to update them.';
		}
		if ( 'approved' !== $status ) {
			$warnings[] = 'Bootstrap memories will be pending by default and must be approved before future workflows treat them as active guidance.';
		}

		return $warnings;
	}

	/**
	 * Build initial memory candidates from local signals.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function initial_memory_candidates(): array {
		$candidates = array_merge(
			$this->brand_memory_candidates(),
			$this->site_memory_candidates(),
			$this->content_memory_candidates(),
			$this->workflow_memory_candidates(),
			$this->approved_learning_memory_candidates()
		);

		return array_values(
			array_filter(
				$candidates,
				static fn ( array $item ): bool => '' !== trim( (string) ( $item['key'] ?? '' ) ) && '' !== trim( (string) ( $item['value'] ?? '' ) )
			)
		);
	}

	/**
	 * Return brand-derived memory candidates.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function brand_memory_candidates(): array {
		$profile = ( new BrandProfile() )->public_profile();
		$fields  = array(
			'brand.site.name'          => array(
				'domain' => 'brand',
				'value'  => $profile['site']['name']['value'] ?? '',
			),
			'brand.site.tagline'       => array(
				'domain' => 'brand',
				'value'  => $profile['site']['tagline']['value'] ?? '',
			),
			'brand.editorial.tone'     => array(
				'domain' => 'brand',
				'value'  => $profile['editorial']['tone']['value'] ?? '',
			),
			'brand.editorial.audience' => array(
				'domain' => 'brand',
				'value'  => $profile['editorial']['audience']['value'] ?? '',
			),
			'brand.editorial.avoid'    => array(
				'domain' => 'brand',
				'value'  => $profile['editorial']['avoid']['value'] ?? '',
			),
			'brand.colors.primary'     => array(
				'domain' => 'brand',
				'value'  => $profile['colors']['primary']['value'] ?? '',
			),
			'brand.colors.accent'      => array(
				'domain' => 'brand',
				'value'  => $profile['colors']['accent']['value'] ?? '',
			),
		);

		return $this->memory_candidates_from_fields( $fields, 'Detected from saved Aculect brand profile or WordPress site defaults.', 'high', 'bootstrap' );
	}

	/**
	 * Return site-derived memory candidates.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function site_memory_candidates(): array {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone()->getName() : '';
		$locale   = function_exists( 'get_locale' ) ? (string) get_locale() : '';

		return $this->memory_candidates_from_fields(
			array(
				'site.timezone' => array(
					'domain' => 'site',
					'value'  => $timezone,
				),
				'site.locale'   => array(
					'domain' => 'site',
					'value'  => $locale,
				),
			),
			'Detected from WordPress site settings.',
			'high',
			'bootstrap'
		);
	}

	/**
	 * Return content-derived memory candidates.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function content_memory_candidates(): array {
		$post_types = array();
		if ( function_exists( 'get_post_types' ) ) {
			$objects = get_post_types( array( 'show_in_rest' => true ), 'objects' );
			if ( is_array( $objects ) ) {
				foreach ( $objects as $name => $object ) {
					if ( is_object( $object ) && ! empty( $object->show_ui ) ) {
						$post_types[] = (string) $name;
					}
				}
			}
		}
		if ( array() === $post_types ) {
			$post_types = array( 'post', 'page' );
		}

		return $this->memory_candidates_from_fields(
			array(
				'content.primary_post_types' => array(
					'domain' => 'content',
					'value'  => implode( ', ', array_values( array_unique( $post_types ) ) ),
				),
			),
			'Detected from WordPress REST-visible post types.',
			'medium',
			'bootstrap'
		);
	}

	/**
	 * Return workflow memory candidates.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function workflow_memory_candidates(): array {
		$seo_plugin = defined( 'RANK_MATH_VERSION' ) || class_exists( '\RankMath' ) ? 'Rank Math' : '';

		return $this->memory_candidates_from_fields(
			array(
				'workflow.blocks.no_custom_html'     => array(
					'domain' => 'workflow',
					'value'  => 'Use registered WordPress blocks and patterns; never use raw Custom HTML or core/html for generated content.',
				),
				'workflow.content.prepare_first'     => array(
					'domain' => 'workflow',
					'value'  => 'Call content_workflow_prepare_post before normal content creation or editing, then use workflow write tools when available.',
				),
				'workflow.content.default_status'    => array(
					'domain' => 'workflow',
					'value'  => 'Create and update long-form content as drafts unless the user explicitly asks to publish or schedule and confirmation is available.',
				),
				'workflow.long_form.block_authoring' => array(
					'domain' => 'workflow',
					'value'  => 'Represent long-form content as sectioned serialized WordPress block markup with stable headings, not HTML blobs.',
				),
				'seo.plugin.detected'                => array(
					'domain' => 'seo',
					'value'  => $seo_plugin,
				),
			),
			'Bundled Aculect workflow guidance for safer MCP content management.',
			'high',
			'bootstrap'
		);
	}

	/**
	 * Return memory candidates from approved learning suggestions.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function approved_learning_memory_candidates(): array {
		$items      = ( new LearningSuggestionRepository() )->approved_items();
		$candidates = array();

		foreach ( $items as $item ) {
			$id     = sanitize_key( (string) ( $item['id'] ?? '' ) );
			$domain = sanitize_key( (string) ( $item['domain'] ?? 'content' ) );
			if ( '' === $id ) {
				continue;
			}

			$candidates[] = array(
				'key'        => 'learning.' . $domain . '.' . $id,
				'domain'     => $domain,
				'value'      => (string) ( $item['suggested_update'] ?? '' ),
				'evidence'   => trim( (string) ( $item['issue'] ?? '' ) . ' ' . (string) ( $item['evidence'] ?? '' ) ),
				'confidence' => (string) ( $item['confidence'] ?? 'medium' ),
				'source'     => 'learning',
			);
		}

		return $candidates;
	}

	/**
	 * Convert key/value fields into memory candidates.
	 *
	 * @param array<string, array{domain:string,value:mixed}> $fields Fields keyed by memory key.
	 * @param string                                          $evidence Evidence text.
	 * @param string                                          $confidence Confidence level.
	 * @param string                                          $source Source label.
	 * @return list<array<string, mixed>>
	 */
	private function memory_candidates_from_fields( array $fields, string $evidence, string $confidence, string $source ): array {
		$candidates = array();
		foreach ( $fields as $key => $field ) {
			$value = is_scalar( $field['value'] ) ? trim( (string) $field['value'] ) : '';
			if ( '' === $value ) {
				continue;
			}

			$candidates[] = array(
				'key'        => $key,
				'domain'     => $field['domain'],
				'value'      => $value,
				'evidence'   => $evidence,
				'confidence' => $confidence,
				'source'     => $source,
			);
		}

		return $candidates;
	}

	/**
	 * Refresh the local content index for a bounded batch.
	 *
	 * @param array<string, mixed> $args Batch args.
	 * @return array<string, mixed>
	 */
	public function refresh_batch( array $args ): array {
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'read' ) ) {
			return $this->error_response( 'forbidden', 'You do not have permission to refresh the content intelligence index.' );
		}

		if ( $this->is_dry_run( $args ) ) {
			return ( new ContentIndexer() )->preview_refresh_batch( $args );
		}

		$mode   = sanitize_key( (string) ( $args['mode'] ?? '' ) );
		$queued = true === ( $args['queued'] ?? false ) || true === ( $args['async'] ?? false ) || 'queued' === $mode || 'async' === $mode;
		$result = $queued ? ( new ContentIndexer() )->queue_refresh_batch( $args ) : ( new ContentIndexer() )->refresh_batch( $args );

		$result['workflow']     = 'content_index_refresh_batch';
		$result['next_actions'] = 'queued' === ( $result['status'] ?? '' )
			? array( 'Poll content_batch_status with the returned job_key, then use content_search_items or content_search_chunks after completion.' )
			: array( 'Use content_search_items or content_search_chunks for fast MCP retrieval.' );

		return $result;
	}

	/**
	 * Return batch job status.
	 *
	 * @param array<string, mixed> $args Job args.
	 * @return array<string, mixed>
	 */
	public function batch_status( array $args ): array {
		$key = sanitize_text_field( (string) ( $args['job_key'] ?? '' ) );
		if ( '' === $key ) {
			return $this->error_response( 'invalid_job_key', 'Provide a job_key returned by content_index_refresh_batch.' );
		}

		$job = $this->repo()->job_by_key( $key );
		if ( array() === $job ) {
			return $this->error_response( 'job_not_found', 'No intelligence batch job exists for that key.' );
		}

		return array(
			'status' => 'success',
			'job'    => $this->public_job_for_current_user( $job ),
			'index'  => $this->job_index_summary( $job ),
		);
	}

	/**
	 * Convert an indexed item row to the canonical MCP search result shape.
	 *
	 * @param array<string, mixed> $item Indexed or live item row.
	 * @return array<string, string>
	 */
	private function canonical_search_result( array $item ): array {
		$post_id = absint( $item['id'] ?? $item['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return array();
		}

		$title = sanitize_text_field( (string) ( $item['title'] ?? $item['post_title'] ?? '' ) );
		$url   = $this->canonical_item_url( $item, $post_id );
		if ( '' === $url ) {
			return array();
		}

		return array(
			'id'    => 'wp-post:' . $post_id,
			'title' => '' === $title ? 'Untitled' : $title,
			'url'   => $url,
		);
	}

	/**
	 * Resolve a canonical post ID.
	 *
	 * @param string $id Canonical or numeric ID.
	 */
	private function canonical_post_id( string $id ): int {
		if ( 1 === preg_match( '/^(?:wp-)?post:(\d+)$/i', $id, $matches ) ) {
			return absint( $matches[1] );
		}

		if ( ctype_digit( $id ) ) {
			return absint( $id );
		}

		return 0;
	}

	/**
	 * Resolve a canonical chunk identity.
	 *
	 * @param string $id Canonical chunk ID.
	 * @return array{post_id: int, chunk_id: string}|null
	 */
	private function canonical_chunk_identity( string $id ): ?array {
		if ( 1 !== preg_match( '/^(?:wp-)?chunk:(\d+):(.+)$/i', $id, $matches ) ) {
			return null;
		}

		$post_id  = absint( $matches[1] );
		$chunk_id = sanitize_key( (string) $matches[2] );
		if ( $post_id <= 0 || '' === $chunk_id ) {
			return null;
		}

		return array(
			'post_id'  => $post_id,
			'chunk_id' => $chunk_id,
		);
	}

	/**
	 * Fetch one indexed chunk as a canonical document.
	 *
	 * @param int    $post_id  Parent post ID.
	 * @param string $chunk_id Chunk ID.
	 * @return array<string, mixed>
	 */
	private function canonical_fetch_chunk( int $post_id, string $chunk_id ): array {
		if ( ! $this->can_read_post( $post_id ) ) {
			return $this->error_response( 'forbidden', 'You do not have permission to read that content section.' );
		}

		if ( ! $this->index_runtime_available() ) {
			return $this->error_response( 'index_unavailable', 'Indexed section fetches require the Aculect content intelligence index.' );
		}

		$result = $this->search_chunks(
			array(
				'post_id'  => $post_id,
				'context'  => 'full',
				'per_page' => 50,
			)
		);

		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( (string) ( $item['chunk_id'] ?? '' ) === $chunk_id || (string) ( $item['id'] ?? '' ) === $chunk_id ) {
				return $this->canonical_chunk_document( $item );
			}
		}

		return $this->error_response( 'not_found', 'No readable indexed section exists for that ID.' );
	}

	/**
	 * Build a canonical post document.
	 *
	 * @param \WP_Post             $post    Post object.
	 * @param array<string, mixed> $indexed Optional indexed item metadata.
	 * @return array<string, mixed>
	 */
	private function canonical_post_document( \WP_Post $post, array $indexed ): array {
		$post_id = absint( $post->ID );

		return array(
			'id'       => 'wp-post:' . $post_id,
			'title'    => $this->canonical_post_title( $post, $indexed ),
			'text'     => $this->canonical_text( (string) $post->post_content ),
			'url'      => $this->canonical_post_url( $post, $indexed ),
			'metadata' => $this->canonical_metadata(
				array(
					'source'       => 'wordpress',
					'post_id'      => $post_id,
					'post_type'    => (string) $post->post_type,
					'status'       => (string) $post->post_status,
					'slug'         => (string) $post->post_name,
					'indexed_at'   => (string) ( $indexed['indexed_at'] ?? '' ),
					'content_hash' => (string) ( $indexed['content_hash'] ?? '' ),
					'stale'        => (bool) ( $indexed['stale'] ?? false ),
				)
			),
		);
	}

	/**
	 * Build a canonical chunk document.
	 *
	 * @param array<string, mixed> $chunk Indexed chunk row.
	 * @return array<string, mixed>
	 */
	private function canonical_chunk_document( array $chunk ): array {
		$post_id  = absint( $chunk['post_id'] ?? 0 );
		$chunk_id = sanitize_key( (string) ( $chunk['chunk_id'] ?? $chunk['id'] ?? '' ) );
		$heading  = sanitize_text_field( (string) ( $chunk['heading'] ?? '' ) );
		$title    = '' !== $heading ? $heading : sanitize_text_field( (string) ( $chunk['post_title'] ?? '' ) );
		$text     = (string) ( $chunk['block_markup'] ?? '' );
		if ( '' === $text ) {
			$text = (string) ( $chunk['text'] ?? '' );
		}

		return array(
			'id'       => 'wp-chunk:' . $post_id . ':' . $chunk_id,
			'title'    => '' === $title ? 'Untitled section' : $title,
			'text'     => $this->canonical_text( $text ),
			'url'      => $this->append_url_fragment(
				$this->safe_url( (string) ( $chunk['permalink'] ?? '' ) ),
				(string) ( $chunk['anchor'] ?? '' )
			),
			'metadata' => $this->canonical_metadata(
				array(
					'source'        => 'wordpress_index_chunk',
					'post_id'       => $post_id,
					'post_type'     => (string) ( $chunk['post_type'] ?? '' ),
					'status'        => (string) ( $chunk['post_status'] ?? '' ),
					'chunk_id'      => $chunk_id,
					'section_index' => absint( $chunk['section_index'] ?? 0 ),
					'content_hash'  => (string) ( $chunk['content_hash'] ?? '' ),
					'stale'         => (bool) ( $chunk['stale'] ?? false ),
				)
			),
		);
	}

	/**
	 * Return a canonical title for one post.
	 *
	 * @param \WP_Post             $post    Post object.
	 * @param array<string, mixed> $indexed Optional indexed item metadata.
	 */
	private function canonical_post_title( \WP_Post $post, array $indexed ): string {
		$title = sanitize_text_field( (string) ( $indexed['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = sanitize_text_field( (string) $post->post_title );
		}

		return '' === $title ? 'Untitled' : $title;
	}

	/**
	 * Return a canonical URL for one post.
	 *
	 * @param \WP_Post             $post    Post object.
	 * @param array<string, mixed> $indexed Optional indexed item metadata.
	 */
	private function canonical_post_url( \WP_Post $post, array $indexed ): string {
		$url = $this->safe_url( (string) ( $indexed['permalink'] ?? '' ) );
		if ( '' !== $url ) {
			return $url;
		}

		if ( function_exists( 'get_permalink' ) ) {
			$permalink = get_permalink( $post );
			if ( is_string( $permalink ) ) {
				$url = $this->safe_url( $permalink );
			}
		}

		return '' !== $url ? $url : $this->fallback_post_url( absint( $post->ID ) );
	}

	/**
	 * Return a canonical URL for a search item.
	 *
	 * @param array<string, mixed> $item    Indexed or live item row.
	 * @param int                  $post_id Post ID.
	 */
	private function canonical_item_url( array $item, int $post_id ): string {
		$url = $this->safe_url( (string) ( $item['url'] ?? $item['permalink'] ?? '' ) );

		return '' !== $url ? $url : $this->fallback_post_url( $post_id );
	}

	/**
	 * Return a fallback post URL when permalink helpers are unavailable.
	 *
	 * @param int $post_id Post ID.
	 */
	private function fallback_post_url( int $post_id ): string {
		$path = '?p=' . max( 0, $post_id );

		return function_exists( 'home_url' ) ? $this->safe_url( home_url( $path ) ) : $this->safe_url( 'https://example.com/' . $path );
	}

	/**
	 * Strip markup and bound canonical fetch text.
	 *
	 * @param string $text Raw text or block markup.
	 */
	private function canonical_text( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) ?? '' );

		return strlen( $text ) > self::CANONICAL_FETCH_TEXT_LIMIT ? substr( $text, 0, self::CANONICAL_FETCH_TEXT_LIMIT ) : $text;
	}

	/**
	 * Return only useful metadata values.
	 *
	 * @param array<string, mixed> $metadata Metadata.
	 * @return array<string, mixed>
	 */
	private function canonical_metadata( array $metadata ): array {
		return array_filter(
			$metadata,
			static fn ( mixed $value ): bool => null !== $value && '' !== $value
		);
	}

	/**
	 * Sanitize a URL string.
	 *
	 * @param string $url Raw URL.
	 */
	private function safe_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		return function_exists( 'esc_url_raw' ) ? esc_url_raw( $url ) : $url;
	}

	/**
	 * Append a fragment to a URL when one is available.
	 *
	 * @param string $url      Base URL.
	 * @param string $fragment Raw fragment.
	 */
	private function append_url_fragment( string $url, string $fragment ): string {
		$fragment = sanitize_key( $fragment );
		if ( '' === $url || '' === $fragment ) {
			return $url;
		}

		return strtok( $url, '#' ) . '#' . $fragment;
	}

	/**
	 * Check whether the local index repository can run database-backed queries.
	 */
	private function index_runtime_available(): bool {
		global $wpdb;

		return is_object( $wpdb )
			&& method_exists( $wpdb, 'prepare' )
			&& method_exists( $wpdb, 'get_row' )
			&& method_exists( $wpdb, 'get_results' );
	}

	/**
	 * Rank items by token overlap.
	 *
	 * @param string                     $query Search query.
	 * @param list<array<string, mixed>> $items Items.
	 * @return list<array<string, mixed>>
	 */
	private function rank_items( string $query, array $items ): array {
		$query_tokens = $this->tokens( $query );

		foreach ( $items as &$item ) {
			$haystack      = implode( ' ', array( $item['title'] ?? '', $item['summary'] ?? '', $item['excerpt'] ?? '' ) );
			$tokens        = $this->tokens( (string) $haystack );
			$overlap       = count( array_intersect( $query_tokens, $tokens ) );
			$item['score'] = ( $overlap * 10 ) + min( 10, (int) floor( (int) ( $item['word_count'] ?? 0 ) / 250 ) );
		}
		unset( $item );

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$score_compare = (int) ( $b['score'] ?? 0 ) <=> (int) ( $a['score'] ?? 0 );
				if ( 0 !== $score_compare ) {
					return $score_compare;
				}

				return strcmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) );
			}
		);

		return $items;
	}

	/**
	 * Tokenize text for deterministic relevance scoring.
	 *
	 * @param string $text Text.
	 * @return list<string>
	 */
	private function tokens( string $text ): array {
		$text  = strtolower( preg_replace( '/[^a-zA-Z0-9 ]+/', ' ', $text ) ?? '' );
		$parts = preg_split( '/\s+/', $text );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$stopwords = array_flip( array( 'a', 'an', 'and', 'are', 'as', 'at', 'for', 'from', 'in', 'is', 'of', 'on', 'or', 'the', 'to', 'with' ) );
		$tokens    = array_values(
			array_filter(
				array_unique( $parts ),
				static fn ( string $token ): bool => strlen( $token ) > 2 && ! isset( $stopwords[ $token ] )
			)
		);

		return array_slice( $tokens, 0, 40 );
	}

	/**
	 * Pick a concise anchor suggestion.
	 *
	 * @param array<string, mixed> $item  Candidate item.
	 * @param string               $topic Source topic.
	 */
	private function anchor_text( array $item, string $topic ): string {
		$title  = sanitize_text_field( (string) ( $item['title'] ?? '' ) );
		$tokens = $this->tokens( $topic );

		foreach ( $tokens as $token ) {
			if ( false !== stripos( $title, $token ) ) {
				return $title;
			}
		}

		return '' !== $title ? $title : 'related resource';
	}

	/**
	 * Score one internal-link candidate for anchor quality and review safety.
	 *
	 * @param array<string, mixed> $source       Source indexed row.
	 * @param array<string, mixed> $target       Target indexed row.
	 * @param string               $anchor       Suggested anchor text.
	 * @param string               $topic        Source topic/query.
	 * @param array<string, mixed> $target_stats Target link stats.
	 * @param array<string, mixed> $source_stats Source link stats.
	 * @param array<string, mixed> $anchor_usage Existing anchor usage stats.
	 * @param array<string>        $source_seo   Source SEO terms.
	 * @param array<string>        $target_seo   Target SEO terms.
	 * @return array{score: int, confidence: string, reasons: list<string>, warnings: list<string>, signals: array<string, mixed>}
	 */
	private function internal_link_quality( array $source, array $target, string $anchor, string $topic, array $target_stats, array $source_stats, array $anchor_usage, array $source_seo, array $target_seo ): array {
		$source_terms = $this->metadata_terms( $source );
		$target_terms = $this->metadata_terms( $target );
		$shared_terms = array_values( array_intersect( $source_terms, $target_terms ) );
		$shared_seo   = array_values( array_intersect( $source_seo, $target_seo ) );
		$overlap      = count( array_intersect( $this->tokens( $topic ), $this->tokens( implode( ' ', array( $target['title'] ?? '', $target['summary'] ?? '', $target['excerpt'] ?? '' ) ) ) ) );
		$score        = 55 + min( 20, max( 0, (int) ( $target['score'] ?? 0 ) ) );
		$reasons      = array( 'Indexed content overlaps with the source topic and is not already linked from the source item.' );
		$warnings     = array();
		$anchor_total = (int) ( $anchor_usage['total'] ?? 0 );
		$target_in    = (int) ( $target_stats['inbound_internal_links'] ?? 0 );
		$target_out   = (int) ( $target_stats['outbound_internal_links'] ?? 0 );
		$source_out   = (int) ( $source_stats['outbound_internal_links'] ?? 0 );

		if ( $overlap > 0 ) {
			$score    += min( 10, $overlap * 2 );
			$reasons[] = 'Target title or summary shares query terms with the source context.';
		} else {
			$score     -= 10;
			$warnings[] = 'low_context_match';
		}

		if ( array() !== $shared_terms ) {
			$score    += 8;
			$reasons[] = 'Source and target share indexed taxonomy context.';
		}

		if ( array() !== $shared_seo ) {
			$score    += 6;
			$reasons[] = 'Source and target share locally available SEO keyword context.';
		}

		if ( $target_in <= 1 ) {
			$score    += 6;
			$reasons[] = 'Target has low inbound internal-link coverage in the local index.';
		}

		if ( $anchor_total > 0 ) {
			$score     -= min( 25, $anchor_total * 5 );
			$warnings[] = 'duplicate_anchor';
		}

		if ( $anchor_total >= 3 || (int) ( $anchor_usage['target_total'] ?? 0 ) >= 3 ) {
			$score     -= 12;
			$warnings[] = 'repeated_exact_match_anchor';
		}

		if ( $this->is_over_optimized_anchor( $anchor, $target, $target_seo ) ) {
			$score     -= 10;
			$warnings[] = 'over_optimized_anchor';
		}

		if ( ! empty( $target['stale'] ) ) {
			$score     -= 10;
			$warnings[] = 'stale_index';
		}

		if ( (int) ( $target['word_count'] ?? 0 ) > 0 && (int) ( $target['word_count'] ?? 0 ) < 250 ) {
			$score     -= 6;
			$warnings[] = 'thin_target_context';
		}

		if ( $target_out > 25 ) {
			$score     -= 5;
			$warnings[] = 'target_link_heavy';
		}

		if ( $source_out > 25 ) {
			$score     -= 5;
			$warnings[] = 'source_link_heavy';
		}

		$score = max( 0, min( 100, $score ) );

		return array(
			'score'      => $score,
			'confidence' => $score >= 75 && array() === $warnings ? 'high' : ( $score >= 50 ? 'medium' : 'low' ),
			'reasons'    => array_values( array_unique( $reasons ) ),
			'warnings'   => array_values( array_unique( $warnings ) ),
			'signals'    => array(
				'anchor_usage_count'    => $anchor_total,
				'target_inbound_links'  => $target_in,
				'target_outbound_links' => $target_out,
				'source_outbound_links' => $source_out,
				'shared_taxonomy_terms' => array_slice( $shared_terms, 0, 10 ),
				'shared_seo_terms'      => array_slice( $shared_seo, 0, 10 ),
				'topic_overlap_terms'   => $overlap,
				'index_backed'          => true,
			),
		);
	}

	/**
	 * Return normalized taxonomy term slugs/names from indexed metadata.
	 *
	 * @param array<string, mixed> $item Indexed item.
	 * @return list<string>
	 */
	private function metadata_terms( array $item ): array {
		$metadata = (array) ( $item['metadata'] ?? array() );
		$terms    = array();
		foreach ( (array) ( $metadata['terms'] ?? array() ) as $term ) {
			if ( ! is_array( $term ) ) {
				continue;
			}

			$value = (string) ( $term['slug'] ?? $term['name'] ?? '' );
			$value = $this->anchor_key( $value );
			if ( '' !== $value ) {
				$terms[] = $value;
			}
		}

		return array_values( array_unique( $terms ) );
	}

	/**
	 * Return local SEO focus terms when common SEO plugins expose post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return list<string>
	 */
	private function seo_terms_for_post( int $post_id ): array {
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}

		$terms = array();
		foreach ( array( '_yoast_wpseo_focuskw', 'rank_math_focus_keyword' ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( ! is_scalar( $value ) ) {
				continue;
			}

				$parts = preg_split( '/[,|]+/', (string) $value );
			foreach ( false === $parts ? array() : $parts as $term ) {
				$term = $this->anchor_key( sanitize_text_field( $term ) );
				if ( '' !== $term ) {
					$terms[] = $term;
				}
			}
		}

		return array_values( array_unique( $terms ) );
	}

	/**
	 * Detect exact-match or keyword-stuffed anchors that should be reviewed.
	 *
	 * @param string               $anchor     Anchor text.
	 * @param array<string, mixed> $target     Target item.
	 * @param array<string>        $target_seo Target SEO terms.
	 */
	private function is_over_optimized_anchor( string $anchor, array $target, array $target_seo ): bool {
		$key = $this->anchor_key( $anchor );
		if ( '' === $key ) {
			return true;
		}

		if ( $key === $this->anchor_key( (string) ( $target['title'] ?? '' ) ) ) {
			return true;
		}

		if ( in_array( $key, $target_seo, true ) ) {
			return true;
		}

		$tokens = $this->tokens( $anchor );
		return count( $tokens ) >= 5 && count( $tokens ) === count( array_intersect( $tokens, $this->tokens( (string) ( $target['title'] ?? '' ) ) ) );
	}

	/**
	 * Normalize anchor-like text for comparisons.
	 *
	 * @param string $anchor Anchor text.
	 */
	private function anchor_key( string $anchor ): string {
		return trim( strtolower( preg_replace( '/\s+/', ' ', sanitize_text_field( $anchor ) ) ?? '' ) );
	}

	/**
	 * Filter item results by current user's read capability.
	 *
	 * @param array<int, mixed> $items Raw items.
	 * @return list<array<string, mixed>>
	 */
	private function filter_readable_items( array $items ): array {
		$this->prime_post_caches( $items, 'id' );

		return array_values(
			array_filter(
				$items,
				fn ( mixed $item ): bool => is_array( $item ) && $this->can_read_post( (int) ( $item['id'] ?? 0 ) )
			)
		);
	}

	/**
	 * Filter chunk results by current user's read capability.
	 *
	 * @param array<int, mixed> $items Raw chunks.
	 * @return list<array<string, mixed>>
	 */
	private function filter_readable_chunks( array $items ): array {
		$this->prime_post_caches( $items, 'post_id' );

		return array_values(
			array_filter(
				$items,
				fn ( mixed $item ): bool => is_array( $item ) && $this->can_read_post( (int) ( $item['post_id'] ?? 0 ) )
			)
		);
	}

	/**
	 * Warm the post cache for one result page before capability filtering.
	 *
	 * Checking current_user_can( 'read_post', $id ) loads the post; without priming,
	 * each row costs one query (N+1 across overfetch pages).
	 *
	 * @param array<int, mixed> $items    Raw result rows.
	 * @param string            $id_field Post ID field name.
	 */
	private function prime_post_caches( array $items, string $id_field ): void {
		if ( ! function_exists( '_prime_post_caches' ) ) {
			return;
		}

		$ids = array();
		foreach ( $items as $item ) {
			$id = is_array( $item ) ? absint( $item[ $id_field ] ?? 0 ) : 0;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		if ( array() !== $ids ) {
			_prime_post_caches( array_values( array_unique( $ids ) ), false, false );
		}
	}

	/**
	 * Fill one search page with readable item results by bounded overfetching.
	 *
	 * @param array<string, mixed> $args   Original search args.
	 * @param array<string, mixed> $result Initial repository result.
	 * @return list<array<string, mixed>>
	 */
	private function filled_readable_items( array $args, array $result ): array {
		return $this->fill_readable_results(
			$args,
			$result,
			fn ( array $items ): array => $this->filter_readable_items( $items ),
			fn ( array $query_args ): array => $this->repo()->search_items( $query_args ),
			'id'
		);
	}

	/**
	 * Fill one search page with readable chunk results by bounded overfetching.
	 *
	 * @param array<string, mixed> $args   Original search args.
	 * @param array<string, mixed> $result Initial repository result.
	 * @return list<array<string, mixed>>
	 */
	private function filled_readable_chunks( array $args, array $result ): array {
		return $this->fill_readable_results(
			$args,
			$result,
			fn ( array $items ): array => $this->filter_readable_chunks( $items ),
			fn ( array $query_args ): array => $this->repo()->search_chunks( $query_args ),
			'chunk_id'
		);
	}

	/**
	 * Bounded overfetch helper for capability-filtered index search.
	 *
	 * @param array<string, mixed> $args     Original search args.
	 * @param array<string, mixed> $result   Initial repository result.
	 * @param callable             $filter   Capability filter.
	 * @param callable             $search   Repository search callback.
	 * @param string               $id_field Stable item ID field.
	 * @return list<array<string, mixed>>
	 */
	private function fill_readable_results( array $args, array $result, callable $filter, callable $search, string $id_field ): array {
		$per_page = max( 1, min( 50, absint( $args['per_page'] ?? 10 ) ) );
		$items    = $filter( (array) ( $result['items'] ?? array() ) );
		if ( $this->can_view_global_index_summary() || count( $items ) >= $per_page ) {
			return array_slice( $items, 0, $per_page );
		}

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$seen     = $this->result_identity_map( $items, $id_field );
		$attempts = 0;
		$count    = count( $items );
		while ( $count < $per_page && $attempts < 4 ) {
			++$attempts;
			++$page;
			$query_args             = $args;
			$query_args['page']     = $page;
			$query_args['per_page'] = $per_page;
			$next                   = $search( $query_args );
			$raw                    = (array) ( $next['items'] ?? array() );
			if ( array() === $raw ) {
				break;
			}

			foreach ( $filter( $raw ) as $item ) {
				$identity = $this->result_identity( $item, $id_field );
				if ( '' === $identity || isset( $seen[ $identity ] ) ) {
					continue;
				}

				$seen[ $identity ] = true;
				$items[]           = $item;
				++$count;
				if ( $count >= $per_page ) {
					break 2;
				}
			}

			if ( count( $raw ) < $per_page ) {
				break;
			}
		}

		return array_slice( $items, 0, $per_page );
	}

	/**
	 * Build a set of stable result identities.
	 *
	 * @param list<array<string,mixed>> $items    Items.
	 * @param string                    $id_field ID field.
	 * @return array<string, bool>
	 */
	private function result_identity_map( array $items, string $id_field ): array {
		$seen = array();
		foreach ( $items as $item ) {
			$identity = $this->result_identity( $item, $id_field );
			if ( '' !== $identity ) {
				$seen[ $identity ] = true;
			}
		}

		return $seen;
	}

	/**
	 * Return a stable identity for one result row.
	 *
	 * @param array<string, mixed> $item     Result item.
	 * @param string               $id_field Preferred ID field.
	 */
	private function result_identity( array $item, string $id_field ): string {
		if ( isset( $item[ $id_field ] ) && is_scalar( $item[ $id_field ] ) ) {
			return (string) $item[ $id_field ];
		}

		$post_id = (int) ( $item['post_id'] ?? $item['id'] ?? 0 );
		return $post_id > 0 ? (string) $post_id : '';
	}

	/**
	 * Replace repository-wide totals with capability-filtered response metadata.
	 *
	 * The local index may contain drafts/private content so searches stay fast after a
	 * user gains access later. MCP responses must only expose what this connection can read.
	 *
	 * @param array<string, mixed>      $result Search result.
	 * @param list<array<string,mixed>> $items  Visible items.
	 * @return array<string, mixed>
	 */
	private function filtered_result_metadata( array $result, array $items ): array {
		$result['total']              = count( $items );
		$result['visible_total']      = count( $items );
		$result['filtered_by_access'] = ! $this->can_view_global_index_summary();
		$result['total_is_estimated'] = ! $this->can_view_global_index_summary();
		$result['index']              = $this->index_summary_for_items( $items );

		return $result;
	}

	/**
	 * Build a permission-safe index summary for the current connection.
	 *
	 * @param list<array<string,mixed>> $items Visible result items.
	 * @return array<string, mixed>
	 */
	private function index_summary_for_items( array $items ): array {
		if ( $this->can_view_global_index_summary() ) {
			return $this->repo()->summary();
		}

		$latest = '';
		$stale  = 0;
		foreach ( $items as $item ) {
			if ( ! empty( $item['stale'] ) ) {
				++$stale;
			}

			$indexed_at = (string) ( $item['indexed_at'] ?? '' );
			if ( '' !== $indexed_at && $indexed_at > $latest ) {
				$latest = $indexed_at;
			}
		}

		return array(
			'visible_items'             => count( $items ),
			'stale_visible_items'       => $stale,
			'latest_visible_indexed_at' => $latest,
			'filtered_by_access'        => true,
		);
	}

	/**
	 * Build a permission-safe index summary for link target audit rows.
	 *
	 * @param list<array<string,mixed>> $items Visible link target rows.
	 * @return array<string, mixed>
	 */
	private function index_summary_for_link_targets( array $items ): array {
		if ( $this->can_view_global_index_summary() ) {
			return $this->repo()->summary();
		}

		$source_items = array();
		foreach ( $items as $item ) {
			$source = (array) ( $item['source_post'] ?? array() );
			if ( array() !== $source ) {
				$source_items[] = array(
					'id'         => (int) ( $source['post_id'] ?? 0 ),
					'indexed_at' => (string) ( $source['indexed_at'] ?? '' ),
					'stale'      => ! empty( $source['stale'] ),
				);
			}
		}

		return $this->index_summary_for_items( $source_items );
	}

	/**
	 * Check read access for a post ID.
	 *
	 * @param int $post_id Post ID.
	 */
	private function can_read_post( int $post_id ): bool {
		return $post_id > 0 && ( ! function_exists( 'current_user_can' ) || current_user_can( 'read_post', $post_id ) );
	}

	/**
	 * Check edit access for a post ID.
	 *
	 * @param int $post_id Post ID.
	 */
	private function can_edit_post( int $post_id ): bool {
		return $post_id > 0 && ( ! function_exists( 'current_user_can' ) || current_user_can( 'edit_post', $post_id ) );
	}

	/**
	 * Return target IDs from a suggestion create payload.
	 *
	 * @param array<string, mixed> $args Tool args.
	 * @return list<int>
	 */
	private function suggestion_target_ids( array $args ): array {
		$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array( $args );
		$ids   = array();
		foreach ( array_slice( $items, 0, 20 ) as $item ) {
			if ( is_array( $item ) ) {
				$id = absint( $item['target_id'] ?? $item['post_id'] ?? 0 );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Return whether this connection can see global index diagnostics.
	 */
	private function can_view_global_index_summary(): bool {
		return ! function_exists( 'current_user_can' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Return a batch job payload safe for the current connection.
	 *
	 * @param array<string, mixed> $job Stored job row.
	 * @return array<string, mixed>
	 */
	private function public_job_for_current_user( array $job ): array {
		if ( $this->can_view_global_index_summary() ) {
			return $job;
		}

		$job['args'] = array_diff_key( (array) ( $job['args'] ?? array() ), array( 'ids' => true ) );
		$result      = (array) ( $job['result'] ?? array() );
		unset( $result['indexed_ids'], $result['summary'] );
		$job['result'] = $result;

		return $job;
	}

	/**
	 * Return a permission-safe index summary for a batch job.
	 *
	 * @param array<string, mixed> $job Stored job row.
	 * @return array<string, mixed>
	 */
	private function job_index_summary( array $job ): array {
		if ( $this->can_view_global_index_summary() ) {
			return $this->repo()->summary();
		}

		return array(
			'filtered_by_access'  => true,
			'job_total_items'     => (int) ( $job['total_items'] ?? 0 ),
			'job_processed_items' => (int) ( $job['processed_items'] ?? 0 ),
			'job_error_count'     => (int) ( $job['error_count'] ?? 0 ),
		);
	}

	/**
	 * Return an error payload.
	 *
	 * @param string $code Error code.
	 * @param string $message Error message.
	 * @return array<string, mixed>
	 */
	private function error_response( string $code, string $message ): array {
		return array(
			'status'  => 'error',
			'error'   => $code,
			'message' => $message,
		);
	}

	/**
	 * Return repository instance.
	 */
	private function repo(): ContentIndexRepository {
		return new ContentIndexRepository();
	}

	/**
	 * Return internal-link policy service.
	 */
	private function internal_link_policy(): InternalLinkPolicy {
		return new InternalLinkPolicy();
	}
}
