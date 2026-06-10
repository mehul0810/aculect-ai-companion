<?php
/**
 * Builds and refreshes the local Aculect Intelligence content index.
 *
 * @package Aculect\AICompanion\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence;

use Aculect\AICompanion\Brand\BrandProfile;

/**
 * Converts WordPress content into fast MCP-ready index rows and chunks.
 */
final class ContentIndexer {

	public const STALE_SWEEP_HOOK = 'aculect_ai_companion_content_index_stale_sweep';

	private const DEFAULT_BATCH_LIMIT  = 25;
	private const MAX_BATCH_LIMIT      = 100;
	private const MAX_CHUNK_WORDS      = 750;
	private const MAX_RESOLVED_LINKS   = 50;
	private const MAX_PENDING_IDS      = 1000;
	private const PENDING_IDS_OPTION   = 'aculect_ai_companion_pending_index_ids';
	private const INDEXABLE_STATUSES   = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Per-request URL to post ID resolution cache.
	 *
	 * url_to_postid() is one of the most expensive single calls in WordPress;
	 * link-heavy posts repeat the same internal URLs constantly.
	 *
	 * @var array<string, int>
	 */
	private static array $url_post_ids = array();

	public function __construct(
		private readonly ?ContentIndexRepository $repository = null
	) {
	}

	/**
	 * Defer indexing of one post to the queued stale sweep.
	 *
	 * Used by bulk contexts (WP-CLI imports, cron, REST batch writes) where
	 * running the full indexer inline per post would multiply request time.
	 *
	 * @param int $post_id Post ID.
	 */
	public function defer_index_post( int $post_id ): void {
		$post_id = absint( $post_id );
		if ( 0 >= $post_id ) {
			return;
		}

		$pending = get_option( self::PENDING_IDS_OPTION, array() );
		$pending = is_array( $pending ) ? array_values( array_filter( array_map( 'absint', $pending ) ) ) : array();

		if ( ! in_array( $post_id, $pending, true ) ) {
			$pending[] = $post_id;
			update_option( self::PENDING_IDS_OPTION, array_slice( $pending, -self::MAX_PENDING_IDS ), false );
		}

		$this->mark_post_stale( $post_id );
		$this->schedule_stale_sweep();
	}

	/**
	 * Schedule one debounced stale-sweep cron event.
	 *
	 * @param int $delay Seconds before the sweep runs.
	 */
	public function schedule_stale_sweep( int $delay = 60 ): void {
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( self::STALE_SWEEP_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 5, $delay ), self::STALE_SWEEP_HOOK );
	}

	/**
	 * Re-index deferred posts and stale rows in bounded batches.
	 *
	 * @return array<string, mixed>
	 */
	public function run_stale_sweep(): array {
		$pending = get_option( self::PENDING_IDS_OPTION, array() );
		$pending = is_array( $pending ) ? array_values( array_unique( array_filter( array_map( 'absint', $pending ) ) ) ) : array();

		$ids = array_slice( $pending, 0, self::MAX_BATCH_LIMIT );
		if ( count( $ids ) < self::MAX_BATCH_LIMIT ) {
			$stale = $this->repo()->stale_object_ids( self::MAX_BATCH_LIMIT - count( $ids ) );
			$ids   = array_values( array_unique( array_merge( $ids, $stale ) ) );
		}

		$remaining = array_values( array_diff( $pending, $ids ) );
		if ( array() === $remaining ) {
			delete_option( self::PENDING_IDS_OPTION );
		} else {
			update_option( self::PENDING_IDS_OPTION, $remaining, false );
		}

		$processed = 0;
		$errors    = 0;
		foreach ( $ids as $post_id ) {
			$result = $this->index_post( $post_id );
			if ( 'error' === ( $result['status'] ?? '' ) ) {
				++$errors;
			}
			++$processed;
		}

		if ( array() !== $remaining || count( $ids ) >= self::MAX_BATCH_LIMIT ) {
			$this->schedule_stale_sweep( 30 );
		}

		return array(
			'status'          => 'complete',
			'processed_items' => $processed,
			'error_count'     => $errors,
			'remaining_items' => count( $remaining ),
		);
	}

	/**
	 * Index one WordPress post-like object.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public function index_post( int $post_id ): array {
		$post_id = absint( $post_id );
		if ( 0 >= $post_id || ! function_exists( 'get_post' ) ) {
			return $this->result( 'skipped', $post_id, 'invalid_post_id' );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			$this->repo()->delete_content_item( $post_id );
			return $this->result( 'deleted', $post_id, 'post_not_found' );
		}

		if ( ! $this->is_indexable_post( $post ) ) {
			$this->repo()->delete_content_item( $post_id );
			return $this->result( 'deleted', $post_id, 'post_not_indexable' );
		}

		$plain_text = $this->plain_text( $post->post_content );
		$terms      = $this->terms_for_post( $post );
		$record     = array(
			'object_id'    => (int) $post->ID,
			'object_type'  => 'post',
			'post_type'    => (string) $post->post_type,
			'post_status'  => (string) $post->post_status,
			'title'        => $this->post_title( $post ),
			'slug'         => (string) $post->post_name,
			'permalink'    => $this->permalink( $post ),
			'excerpt'      => $this->excerpt( $post ),
			'summary'      => $this->summary( $plain_text ),
			'word_count'   => $this->word_count( $plain_text ),
			'content_hash' => hash( 'sha256', $post->post_title . "\n" . $post->post_excerpt . "\n" . $post->post_content ),
			'modified_gmt' => (string) $post->post_modified_gmt,
			'stale'        => false,
			'search_text'  => $this->search_text( $post, $plain_text, $terms ),
			'metadata'     => array(
				'author'      => (int) $post->post_author,
				'terms'       => $terms,
				'section_ids' => array(),
			),
		);

		$chunks = $this->chunks_from_content( (int) $post->ID, (string) $post->post_content );
		$links  = $this->links_from_content( (int) $post->ID, (string) $post->post_content );

		$record['metadata']['section_ids'] = array_values( array_column( $chunks, 'chunk_id' ) );

		$stored = $this->repo()->upsert_content_item( $record );
		if ( ! $stored ) {
			return $this->result( 'error', $post_id, 'index_write_failed' );
		}

		$chunk_count = $this->repo()->replace_chunks( $post_id, $chunks );
		$link_count  = $this->repo()->replace_links( $post_id, $links );

		return array(
			'status'      => 'indexed',
			'post_id'     => $post_id,
			'post_type'   => (string) $post->post_type,
			'post_status' => (string) $post->post_status,
			'chunks'      => $chunk_count,
			'links'       => $link_count,
		);
	}

	/**
	 * Delete all index rows for one post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_post( int $post_id ): void {
		$this->repo()->delete_content_item( $post_id );
	}

	/**
	 * Mark one post stale so MCP clients know cached context needs refresh.
	 *
	 * @param int $post_id Post ID.
	 */
	public function mark_post_stale( int $post_id ): bool {
		return $this->repo()->mark_stale( $post_id );
	}

	/**
	 * Refresh a bounded batch synchronously.
	 *
	 * @param array<string, mixed> $args Batch args.
	 * @return array<string, mixed>
	 */
	public function refresh_batch( array $args ): array {
		$post_ids = $this->post_ids_for_batch( $args );
		$job      = $this->repo()->create_job( 'content_index_refresh', $this->batch_public_args( $args ), count( $post_ids ) );

		return $this->run_refresh_job( (string) ( $job['job_key'] ?? '' ), $post_ids );
	}

	/**
	 * Queue a bounded refresh batch for WordPress cron.
	 *
	 * @param array<string, mixed> $args Batch args.
	 * @return array<string, mixed>
	 */
	public function queue_refresh_batch( array $args ): array {
		$post_ids           = $this->post_ids_for_batch( $args );
		$public_args        = $this->batch_public_args( $args );
		$public_args['ids'] = $post_ids;
		$job                = $this->repo()->create_job( 'content_index_refresh', $public_args, count( $post_ids ), 'queued' );
		$job_key            = (string) ( $job['job_key'] ?? '' );

		if (
			'' !== $job_key
			&& function_exists( 'wp_schedule_single_event' )
			&& ( ! function_exists( 'wp_next_scheduled' ) || false === wp_next_scheduled( 'aculect_ai_companion_content_index_refresh_job', array( $job_key ) ) )
		) {
			wp_schedule_single_event( time() + 5, 'aculect_ai_companion_content_index_refresh_job', array( $job_key ) );
		}

		return array(
			'status'          => 'queued',
			'job'             => $job,
			'total_items'     => count( $post_ids ),
			'processed_items' => 0,
			'errors'          => array(),
			'index'           => $this->job_index_summary( count( $post_ids ), 0, 0 ),
		);
	}

	/**
	 * Execute a queued refresh job.
	 *
	 * @param string $job_key Job key.
	 * @return array<string, mixed>
	 */
	public function run_queued_refresh_job( string $job_key ): array {
		$job = $this->repo()->job_by_key( $job_key );
		if ( array() === $job ) {
			return array(
				'status'  => 'error',
				'error'   => 'job_not_found',
				'message' => 'No queued content index refresh job exists for that key.',
			);
		}

		$claimed = $this->repo()->claim_job( $job_key );
		if ( array() === $claimed ) {
			return array(
				'status'  => 'skipped',
				'job'     => $job,
				'message' => 'The queued content index refresh job is already running or has already finished.',
				'index'   => $this->job_index_summary( (int) ( $job['total_items'] ?? 0 ), (int) ( $job['processed_items'] ?? 0 ), (int) ( $job['error_count'] ?? 0 ) ),
			);
		}

		$args     = is_array( $job['args'] ?? null ) ? (array) $job['args'] : array();
		$post_ids = isset( $args['ids'] ) && is_array( $args['ids'] )
			? array_values( array_filter( array_map( 'absint', $args['ids'] ) ) )
			: $this->post_ids_for_batch( $args );

		return $this->run_refresh_job( $job_key, $post_ids );
	}

	/**
	 * Process a resolved set of post IDs for a refresh job.
	 *
	 * @param string $job_key  Job key.
	 * @param array  $post_ids Resolved post IDs.
	 * @return array<string, mixed>
	 */
	private function run_refresh_job( string $job_key, array $post_ids ): array {
		if ( '' !== $job_key ) {
			$this->repo()->update_job(
				$job_key,
				array(
					'status'          => 'running',
					'processed_items' => 0,
					'error_count'     => 0,
					'result'          => array(),
				)
			);
		}

		$processed = 0;
		$errors    = array();
		foreach ( $post_ids as $post_id ) {
			$result = $this->index_post( $post_id );
			if ( 'error' === ( $result['status'] ?? '' ) ) {
				$errors[] = $result;
			}
			++$processed;
		}

		$this->refresh_detected_memories();

		$status = array() === $errors ? 'complete' : 'partial';
		$job    = '' === $job_key
			? array()
			: $this->repo()->update_job(
				$job_key,
				array(
					'status'          => $status,
					'processed_items' => $processed,
					'error_count'     => count( $errors ),
					'result'          => array(
						'indexed_ids' => $post_ids,
						'errors'      => $errors,
						'summary'     => $this->job_index_summary( count( $post_ids ), $processed, count( $errors ) ),
					),
				)
			);

		return array(
			'status'          => $status,
			'job'             => $job,
			'total_items'     => count( $post_ids ),
			'processed_items' => $processed,
			'errors'          => $errors,
			'index'           => $this->job_index_summary( count( $post_ids ), $processed, count( $errors ) ),
		);
	}

	/**
	 * Return a dry-run preview for a refresh batch.
	 *
	 * @param array<string, mixed> $args Batch args.
	 * @return array<string, mixed>
	 */
	public function preview_refresh_batch( array $args ): array {
		$post_ids = $this->post_ids_for_batch( $args );

		return array(
			'status'       => 'preview',
			'dry_run'      => true,
			'total_items'  => count( $post_ids ),
			'post_ids'     => $post_ids,
			'index'        => $this->job_index_summary( count( $post_ids ), 0, 0 ),
			'next_actions' => array( 'Repeat content_index_refresh_batch without dry_run to refresh these index rows.' ),
		);
	}

	/**
	 * Store detected brand/site memory from saved profile and site options.
	 */
	public function refresh_detected_memories(): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		$profile = ( new BrandProfile() )->public_profile();
		$items   = array(
			'brand.site.name'          => $profile['site']['name']['value'] ?? '',
			'brand.site.tagline'       => $profile['site']['tagline']['value'] ?? '',
			'brand.editorial.tone'     => $profile['editorial']['tone']['value'] ?? '',
			'brand.editorial.audience' => $profile['editorial']['audience']['value'] ?? '',
			'brand.editorial.avoid'    => $profile['editorial']['avoid']['value'] ?? '',
			'brand.colors.primary'     => $profile['colors']['primary']['value'] ?? '',
			'brand.colors.accent'      => $profile['colors']['accent']['value'] ?? '',
		);

		foreach ( $items as $key => $value ) {
			if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				continue;
			}

			$this->repo()->upsert_memory(
				array(
					'key'        => $key,
					'domain'     => str_starts_with( $key, 'brand.' ) ? 'brand' : 'site',
					'value'      => (string) $value,
					'evidence'   => 'Detected from saved Aculect brand profile or WordPress site defaults.',
					'confidence' => 'high',
					'status'     => 'approved',
					'source'     => 'detected',
				)
			);
		}
	}

	/**
	 * Convert serialized block content into section-level chunks.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Post content.
	 * @return list<array<string, mixed>>
	 */
	public function chunks_from_content( int $post_id, string $content ): array {
		$blocks = $this->serialized_blocks( $content );
		if ( array() === $blocks ) {
			$text = $this->plain_text( $content );
			return '' === $text ? array() : array(
				$this->chunk_row( $post_id, 1, 'content', 'content', 0, 1, $text, $content ),
			);
		}

		$chunks   = array();
		$current  = null;
		$position = 0;

		foreach ( $blocks as $block ) {
			$is_heading = 'core/heading' === $block['name'];
			$text       = $this->plain_text( $block['markup'] );
			if ( '' === $text && ! $is_heading ) {
				++$position;
				continue;
			}

			if ( $is_heading && null !== $current && array() !== $current['blocks'] ) {
				$chunks[] = $this->chunk_from_accumulator( $post_id, count( $chunks ) + 1, $current );
				$current  = null;
			}

			if ( null === $current ) {
				$heading = $is_heading ? $text : sprintf( 'Section %d', count( $chunks ) + 1 );
				$current = array(
					'heading'     => $heading,
					'anchor'      => $this->slug( $heading ),
					'block_start' => $position,
					'blocks'      => array(),
					'texts'       => array(),
				);
			}

			$current['blocks'][] = $block['markup'];
			if ( '' !== $text ) {
				$current['texts'][] = $text;
			}

			if ( $this->word_count( implode( ' ', $current['texts'] ) ) >= self::MAX_CHUNK_WORDS ) {
				$chunks[] = $this->chunk_from_accumulator( $post_id, count( $chunks ) + 1, $current );
				$current  = null;
			}

			++$position;
		}

		if ( null !== $current && array() !== $current['blocks'] ) {
			$chunks[] = $this->chunk_from_accumulator( $post_id, count( $chunks ) + 1, $current );
		}

		return $chunks;
	}

	/**
	 * Extract outbound links from content.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content Post content.
	 * @return list<array<string, mixed>>
	 */
	public function links_from_content( int $post_id, string $content ): array {
		unset( $post_id );

		if ( ! preg_match_all( '/<a\s+[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$links    = array();
		$resolved = 0;
		foreach ( array_slice( $matches, 0, 300 ) as $match ) {
			$url = esc_url_raw( html_entity_decode( (string) $match[2], ENT_QUOTES ) );
			if ( '' === $url ) {
				continue;
			}

			$target_id = 0;
			if ( $resolved < self::MAX_RESOLVED_LINKS && $this->is_internal_url( $url ) ) {
				$target_id = $this->target_post_id( $url );
				++$resolved;
			}

			$links[] = array(
				'target_id'   => $target_id,
				'target_url'  => $url,
				'anchor_text' => $this->plain_text( (string) $match[3] ),
				'rel'         => '',
				'context'     => $this->summary( $this->plain_text( (string) $match[0] ), 20 ),
			);
		}

		return $links;
	}

	/**
	 * Check whether a URL points at this site before resolving it to a post.
	 *
	 * Cheap host comparison so external links never trigger url_to_postid().
	 *
	 * @param string $url Link URL.
	 */
	private function is_internal_url( string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return str_starts_with( $url, '/' );
		}

		if ( ! function_exists( 'home_url' ) ) {
			return false;
		}

		return $host === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Return queryable post IDs for a refresh batch.
	 *
	 * @param array<string, mixed> $args Batch args.
	 * @return list<int>
	 */
	private function post_ids_for_batch( array $args ): array {
		if ( isset( $args['ids'] ) && is_array( $args['ids'] ) ) {
			$ids = array_values( array_filter( array_map( 'absint', $args['ids'] ) ) );
			$ids = array_values( array_unique( $ids ) );
			sort( $ids );

			return array_slice( $ids, 0, self::MAX_BATCH_LIMIT );
		}

		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$post_type = sanitize_key( (string) ( $args['post_type'] ?? 'post' ) );
		$limit     = min( self::MAX_BATCH_LIMIT, max( 1, absint( $args['limit'] ?? self::DEFAULT_BATCH_LIMIT ) ) );
		$status    = $this->status_arg( $args['status'] ?? self::INDEXABLE_STATUSES );

		$ids = get_posts(
			array(
				'post_type'              => '' === $post_type ? 'post' : $post_type,
				'post_status'            => $status,
				'posts_per_page'         => $limit,
				'fields'                 => 'ids',
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'perm'                   => 'readable',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_values( array_filter( array_map( 'absint', $ids ) ) );
	}

	/**
	 * Return public batch args for job storage.
	 *
	 * @param array<string, mixed> $args Batch args.
	 * @return array<string, mixed>
	 */
	private function batch_public_args( array $args ): array {
		return array(
			'post_type' => sanitize_key( (string) ( $args['post_type'] ?? 'post' ) ),
			'status'    => $this->status_arg( $args['status'] ?? self::INDEXABLE_STATUSES ),
			'limit'     => min( self::MAX_BATCH_LIMIT, max( 1, absint( $args['limit'] ?? self::DEFAULT_BATCH_LIMIT ) ) ),
			'ids'       => isset( $args['ids'] ) && is_array( $args['ids'] ) ? array_slice( array_values( array_filter( array_map( 'absint', $args['ids'] ) ) ), 0, self::MAX_BATCH_LIMIT ) : array(),
		);
	}

	/**
	 * Check whether a post should be indexed.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function is_indexable_post( \WP_Post $post ): bool {
		if ( ! in_array( (string) $post->post_status, self::INDEXABLE_STATUSES, true ) ) {
			return false;
		}

		if ( in_array( (string) $post->post_type, array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request' ), true ) ) {
			return false;
		}

		if ( ! function_exists( 'get_post_type_object' ) ) {
			return true;
		}

		$type = get_post_type_object( (string) $post->post_type );
		if ( ! $type instanceof \WP_Post_Type ) {
			return false;
		}

		return (bool) $type->public || (bool) $type->show_ui || (bool) $type->show_in_rest;
	}

	/**
	 * Extract a deterministic block list from serialized block markup.
	 *
	 * @param string $content Serialized content.
	 * @return list<array{name: string, markup: string}>
	 */
	private function serialized_blocks( string $content ): array {
		if ( ! preg_match_all( '/<!--\s+wp:([A-Za-z0-9_\/.-]+)(?:\s+[^>]*)?(\/)?-->(?:(.*?)<!--\s+\/wp:\1\s+-->)?/is', $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$blocks = array();
		foreach ( $matches as $match ) {
			$name = (string) $match[1];
			$name = str_contains( $name, '/' ) ? $name : 'core/' . $name;

			$blocks[] = array(
				'name'   => $name,
				'markup' => (string) $match[0],
			);
		}

		return $blocks;
	}

	/**
	 * Build one chunk from accumulated blocks.
	 *
	 * @param int                  $post_id Content ID.
	 * @param int                  $index   Section index.
	 * @param array<string, mixed> $current Accumulator.
	 * @return array<string, mixed>
	 */
	private function chunk_from_accumulator( int $post_id, int $index, array $current ): array {
		$text    = implode( "\n\n", array_map( 'strval', (array) ( $current['texts'] ?? array() ) ) );
		$markup  = implode( "\n", array_map( 'strval', (array) ( $current['blocks'] ?? array() ) ) );
		$heading = sanitize_text_field( (string) ( $current['heading'] ?? sprintf( 'Section %d', $index ) ) );
		$anchor  = sanitize_key( (string) ( $current['anchor'] ?? $this->slug( $heading ) ) );

		return $this->chunk_row(
			$post_id,
			$index,
			'' === $anchor ? 'section-' . $index : $anchor,
			$heading,
			absint( $current['block_start'] ?? 0 ),
			count( (array) ( $current['blocks'] ?? array() ) ),
			$text,
			$markup
		);
	}

	/**
	 * Build one chunk row.
	 *
	 * @param int    $post_id     Content ID.
	 * @param int    $index       Section index.
	 * @param string $chunk_id    Stable chunk ID.
	 * @param string $heading     Section heading.
	 * @param int    $block_start First block index.
	 * @param int    $block_count Block count.
	 * @param string $text        Plain text.
	 * @param string $markup      Block markup.
	 * @return array<string, mixed>
	 */
	private function chunk_row( int $post_id, int $index, string $chunk_id, string $heading, int $block_start, int $block_count, string $text, string $markup ): array {
		return array(
			'object_id'     => $post_id,
			'chunk_id'      => sprintf( 'section-%03d-%s', $index, substr( sanitize_key( $chunk_id ), 0, 80 ) ),
			'heading'       => $heading,
			'anchor'        => sanitize_key( $chunk_id ),
			'section_index' => $index,
			'word_count'    => $this->word_count( $text ),
			'content_hash'  => hash( 'sha256', $markup ),
			'block_start'   => $block_start,
			'block_count'   => $block_count,
			'text'          => $text,
			'block_markup'  => $markup,
			'metadata'      => array(
				'post_id' => $post_id,
			),
		);
	}

	/**
	 * Return plain text from block or HTML content.
	 *
	 * @param string $content Raw content.
	 */
	private function plain_text( string $content ): string {
		$content = preg_replace( '/<!--\s+\/?wp:[\s\S]*?-->/i', ' ', $content ) ?? $content;
		$content = html_entity_decode( $content, ENT_QUOTES );
		$text    = wp_strip_all_tags( $content );

		return preg_replace( '/\s+/', ' ', trim( $text ) ) ?? '';
	}

	/**
	 * Build a summary from the first words.
	 *
	 * @param string $text  Source text.
	 * @param int    $words Max words.
	 */
	private function summary( string $text, int $words = 45 ): string {
		$parts = preg_split( '/\s+/', trim( $text ) );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$summary = implode( ' ', array_slice( array_filter( $parts ), 0, max( 1, $words ) ) );

		return strlen( $summary ) < strlen( $text ) ? $summary . '...' : $summary;
	}

	/**
	 * Count words in plain text.
	 *
	 * @param string $text Plain text.
	 */
	private function word_count( string $text ): int {
		$words = preg_split( '/\s+/', trim( $text ) );

		return is_array( $words ) ? count( array_filter( $words ) ) : 0;
	}

	/**
	 * Build a slug.
	 *
	 * @param string $value Raw value.
	 */
	private function slug( string $value ): string {
		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $value );
		}

		return trim( strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $value ) ?? '' ), '-' );
	}

	/**
	 * Build searchable text.
	 *
	 * @param \WP_Post                   $post       Post.
	 * @param string                     $plain_text Plain content text.
	 * @param list<array<string, mixed>> $terms      Term metadata.
	 */
	private function search_text( \WP_Post $post, string $plain_text, array $terms ): string {
		$term_names = array_values(
			array_filter(
				array_map(
					static fn ( array $term ): string => (string) ( $term['name'] ?? '' ),
					$terms
				)
			)
		);

		return trim( implode( "\n", array( $post->post_title, $post->post_excerpt, implode( ' ', $term_names ), $plain_text ) ) );
	}

	/**
	 * Return post title.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function post_title( \WP_Post $post ): string {
		return function_exists( 'get_the_title' ) ? (string) get_the_title( $post ) : (string) $post->post_title;
	}

	/**
	 * Return post excerpt.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function excerpt( \WP_Post $post ): string {
		return $this->plain_text( (string) $post->post_excerpt );
	}

	/**
	 * Return permalink when available.
	 *
	 * @param \WP_Post $post Post object.
	 */
	private function permalink( \WP_Post $post ): string {
		if ( function_exists( 'get_permalink' ) ) {
			return get_permalink( $post );
		}

		return '';
	}

	/**
	 * Return safe term metadata for a post.
	 *
	 * @param \WP_Post $post Post object.
	 * @return list<array<string, mixed>>
	 */
	private function terms_for_post( \WP_Post $post ): array {
		if ( ! function_exists( 'get_object_taxonomies' ) || ! function_exists( 'wp_get_post_terms' ) ) {
			return array();
		}

		$taxonomies = get_object_taxonomies( (string) $post->post_type, 'names' );
		if ( ! is_array( $taxonomies ) ) {
			return array();
		}

		$items = array();
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms(
				(int) $post->ID,
				(string) $taxonomy,
				array(
					'fields' => 'all',
				)
			);

			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					$items[] = array(
						'id'       => (int) $term->term_id,
						'taxonomy' => (string) $term->taxonomy,
						'name'     => (string) $term->name,
						'slug'     => (string) $term->slug,
					);
				}
			}
		}

		return $items;
	}

	/**
	 * Resolve an internal URL to a target post ID when WordPress can do so.
	 *
	 * @param string $url Target URL.
	 */
	private function target_post_id( string $url ): int {
		if ( ! function_exists( 'url_to_postid' ) ) {
			return 0;
		}

		if ( ! isset( self::$url_post_ids[ $url ] ) ) {
			self::$url_post_ids[ $url ] = absint( url_to_postid( $url ) );
		}

		return self::$url_post_ids[ $url ];
	}

	/**
	 * Normalize status input.
	 *
	 * @param mixed $status Raw status value.
	 * @return list<string>
	 */
	private function status_arg( mixed $status ): array {
		if ( is_string( $status ) ) {
			$status = array_map( 'trim', explode( ',', $status ) );
		}

		$statuses = is_array( $status ) ? $status : array( $status );
		$statuses = array_values(
			array_intersect(
				array_map( 'sanitize_key', array_map( 'strval', $statuses ) ),
				self::INDEXABLE_STATUSES
			)
		);

		return array() === $statuses ? self::INDEXABLE_STATUSES : $statuses;
	}

	/**
	 * Build a small status payload.
	 *
	 * @param string $status  Status.
	 * @param int    $post_id Post ID.
	 * @param string $reason  Reason.
	 * @return array<string, mixed>
	 */
	private function result( string $status, int $post_id, string $reason ): array {
		return array(
			'status'  => $status,
			'post_id' => $post_id,
			'reason'  => $reason,
		);
	}

	/**
	 * Return an index summary that does not leak global content counts to normal users.
	 *
	 * @param int $total_items     Job total item count.
	 * @param int $processed_items Job processed item count.
	 * @param int $error_count     Job error count.
	 * @return array<string, mixed>
	 */
	private function job_index_summary( int $total_items, int $processed_items, int $error_count ): array {
		if ( ! function_exists( 'current_user_can' ) || current_user_can( 'manage_options' ) ) {
			return $this->repo()->summary();
		}

		return array(
			'filtered_by_access'  => true,
			'job_total_items'     => max( 0, $total_items ),
			'job_processed_items' => max( 0, $processed_items ),
			'job_error_count'     => max( 0, $error_count ),
		);
	}

	/**
	 * Return repository instance.
	 */
	private function repo(): ContentIndexRepository {
		return $this->repository ?? new ContentIndexRepository();
	}
}
