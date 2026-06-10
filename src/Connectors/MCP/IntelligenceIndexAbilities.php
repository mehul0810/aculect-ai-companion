<?php
/**
 * MCP abilities backed by the local Aculect Intelligence index.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Intelligence\ContentIndexer;
use Aculect\AICompanion\Intelligence\ContentIndexRepository;

/**
 * Exposes indexed search, chunk retrieval, link suggestions, memories, and batch refresh to MCP clients.
 */
final class IntelligenceIndexAbilities extends AbstractAbilityService {

	/**
	 * Search indexed content items.
	 *
	 * @param array<string, mixed> $args Search args.
	 * @return array<string, mixed>
	 */
	public function search_items( array $args ): array {
		$result          = $this->repo()->search_items( $args );
		$result['items'] = $this->filled_readable_items( $args, $result );

		if ( array() === $result['items'] && '' !== trim( (string) ( $args['query'] ?? '' ) ) ) {
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

			$text    = wp_strip_all_tags( (string) $post->post_content );
			$items[] = array(
				'id'           => (int) $post->ID,
				'type'         => (string) $post->post_type,
				'status'       => (string) $post->post_status,
				'title'        => (string) get_the_title( $post ),
				'slug'         => (string) $post->post_name,
				'permalink'    => (string) get_permalink( $post ),
				'excerpt'      => wp_strip_all_tags( (string) $post->post_excerpt ),
				'summary'      => wp_trim_words( $text, 45 ),
				'word_count'   => str_word_count( $text ),
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
		$related        = $this->find_related(
			array(
				'post_id'   => $source_id,
				'query'     => $topic,
				'post_type' => $args['post_type'] ?? '',
				'status'    => $args['status'] ?? '',
				'limit'     => max( 5, min( 30, absint( $args['limit'] ?? 10 ) * 2 ) ),
			)
		);

		$items = array();
		foreach ( (array) ( $related['items'] ?? array() ) as $item ) {
			$id = (int) ( $item['id'] ?? 0 );
			if ( $id <= 0 || $id === $source_id || in_array( $id, $already_linked, true ) ) {
				continue;
			}

			$items[] = array(
				'post_id'     => $id,
				'title'       => (string) ( $item['title'] ?? '' ),
				'permalink'   => (string) ( $item['permalink'] ?? '' ),
				'anchor_text' => $this->anchor_text( $item, $topic ),
				'score'       => (int) ( $item['score'] ?? 0 ),
				'reason'      => 'Indexed content overlaps with the source topic and is not already linked from the source item.',
				'stale'       => ! empty( $item['stale'] ),
			);
		}

		$limit = max( 1, min( 20, absint( $args['limit'] ?? 10 ) ) );

		return array(
			'items'              => array_slice( $items, 0, $limit ),
			'total'              => min( count( $items ), $limit ),
			'source_post'        => $source,
			'topic'              => $topic,
			'already_linked_ids' => $already_linked,
			'context'            => 'compact',
			'index'              => $this->index_summary_for_items( $items ),
			'next_actions'       => array( 'Use the suggested anchors inside semantic paragraph/list blocks, not raw HTML.' ),
		);
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
			'write_path'      => 'Use memory_save only for explicit, durable, admin-acceptable guidance.',
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
				)
			);
		}

		$result = $this->repo()->upsert_memory( $args );
		if ( 'success' === ( $result['status'] ?? '' ) ) {
			$result['next_actions'] = array( 'Call memory_list to confirm the durable memory is available to future workflows.' );
		}

		return $result;
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
	 * current_user_can( 'read_post', $id ) loads the post; without priming,
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
	 * Check read access for a post ID.
	 *
	 * @param int $post_id Post ID.
	 */
	private function can_read_post( int $post_id ): bool {
		return $post_id > 0 && ( ! function_exists( 'current_user_can' ) || current_user_can( 'read_post', $post_id ) );
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
}
