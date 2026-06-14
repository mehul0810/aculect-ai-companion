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
