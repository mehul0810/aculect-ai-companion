<?php
/**
 * Repository for the local Aculect Intelligence index.
 *
 * @package Aculect\AICompanion\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence;

use Aculect\AICompanion\Intelligence\Database\Installer;

/**
 * Persists and reads indexed content, chunks, link graph, memories, jobs, and cache payloads.
 */
final class ContentIndexRepository {

	public const DEFAULT_JOB_LEASE_TTL = 300;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned intelligence index tables are the canonical cache for MCP search and workflow acceleration.

	private const DEFAULT_LIMIT = 10;
	private const MAX_LIMIT     = 50;

	/**
	 * Upsert one indexed content item.
	 *
	 * @param array<string, mixed> $record Indexed content row.
	 */
	public function upsert_content_item( array $record ): bool {
		global $wpdb;

		$metadata = isset( $record['metadata'] ) && is_array( $record['metadata'] ) ? $record['metadata'] : array();
		$json     = wp_json_encode( $metadata );

		$data = array(
			'object_id'    => absint( $record['object_id'] ?? 0 ),
			'object_type'  => $this->key( $record['object_type'] ?? 'post', 20 ),
			'post_type'    => $this->key( $record['post_type'] ?? 'post', 60 ),
			'post_status'  => $this->key( $record['post_status'] ?? 'draft', 20 ),
			'title'        => $this->text( $record['title'] ?? '', 1000 ),
			'slug'         => $this->text( $record['slug'] ?? '', 200 ),
			'permalink'    => $this->url( $record['permalink'] ?? '' ),
			'excerpt'      => $this->text( $record['excerpt'] ?? '', 3000 ),
			'summary'      => $this->text( $record['summary'] ?? '', 3000 ),
			'word_count'   => absint( $record['word_count'] ?? 0 ),
			'content_hash' => $this->hash( $record['content_hash'] ?? '' ),
			'indexed_at'   => gmdate( 'Y-m-d H:i:s' ),
			'modified_gmt' => $this->datetime( $record['modified_gmt'] ?? '' ),
			'stale'        => empty( $record['stale'] ) ? 0 : 1,
			'search_text'  => $this->long_text( $record['search_text'] ?? '' ),
			'metadata'     => false === $json ? '{}' : $json,
		);

		if ( 0 >= $data['object_id'] ) {
			return false;
		}

		$result = $wpdb->replace(
			Installer::content_index_table(),
			$data,
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Replace chunk rows for one content item.
	 *
	 * @param int                        $object_id Content item ID.
	 * @param list<array<string, mixed>> $chunks    Chunk rows.
	 */
	public function replace_chunks( int $object_id, array $chunks ): int {
		global $wpdb;

		$object_id = absint( $object_id );
		if ( 0 >= $object_id ) {
			return 0;
		}

		$wpdb->delete( Installer::content_chunks_table(), array( 'object_id' => $object_id ), array( '%d' ) );

		$inserted = 0;
		foreach ( array_slice( $chunks, 0, 200 ) as $chunk ) {
			$metadata = isset( $chunk['metadata'] ) && is_array( $chunk['metadata'] ) ? $chunk['metadata'] : array();
			$json     = wp_json_encode( $metadata );
			$result   = $wpdb->insert(
				Installer::content_chunks_table(),
				array(
					'object_id'     => $object_id,
					'chunk_id'      => $this->key( $chunk['chunk_id'] ?? '', 120 ),
					'heading'       => $this->text( $chunk['heading'] ?? '', 500 ),
					'anchor'        => $this->key( $chunk['anchor'] ?? '', 200 ),
					'section_index' => absint( $chunk['section_index'] ?? 0 ),
					'word_count'    => absint( $chunk['word_count'] ?? 0 ),
					'content_hash'  => $this->hash( $chunk['content_hash'] ?? '' ),
					'block_start'   => absint( $chunk['block_start'] ?? 0 ),
					'block_count'   => absint( $chunk['block_count'] ?? 0 ),
					'text'          => $this->long_text( $chunk['text'] ?? '' ),
					'block_markup'  => $this->long_text( $chunk['block_markup'] ?? '' ),
					'metadata'      => false === $json ? '{}' : $json,
				),
				array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
			);

			if ( false !== $result ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Replace outbound link rows for one content item.
	 *
	 * @param int                        $source_id Source content ID.
	 * @param list<array<string, mixed>> $links     Link rows.
	 */
	public function replace_links( int $source_id, array $links ): int {
		global $wpdb;

		$source_id = absint( $source_id );
		if ( 0 >= $source_id ) {
			return 0;
		}

		$wpdb->delete( Installer::link_graph_table(), array( 'source_id' => $source_id ), array( '%d' ) );

		$inserted = 0;
		foreach ( array_slice( $links, 0, 300 ) as $link ) {
			$result = $wpdb->insert(
				Installer::link_graph_table(),
				array(
					'source_id'   => $source_id,
					'target_id'   => isset( $link['target_id'] ) ? absint( $link['target_id'] ) : null,
					'target_url'  => $this->url( $link['target_url'] ?? '' ),
					'anchor_text' => $this->text( $link['anchor_text'] ?? '', 255 ),
					'rel'         => $this->text( $link['rel'] ?? '', 80 ),
					'context'     => $this->text( $link['context'] ?? '', 1000 ),
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false !== $result ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Delete all index rows for one content item.
	 *
	 * @param int $object_id Content item ID.
	 */
	public function delete_content_item( int $object_id ): void {
		global $wpdb;

		$object_id = absint( $object_id );
		if ( 0 >= $object_id ) {
			return;
		}

		$wpdb->delete( Installer::content_index_table(), array( 'object_id' => $object_id ), array( '%d' ) );
		$wpdb->delete( Installer::content_chunks_table(), array( 'object_id' => $object_id ), array( '%d' ) );
		$wpdb->delete( Installer::link_graph_table(), array( 'source_id' => $object_id ), array( '%d' ) );
		$wpdb->delete( Installer::link_graph_table(), array( 'target_id' => $object_id ), array( '%d' ) );
	}

	/**
	 * Mark one indexed item stale after content, term, or metadata changes.
	 *
	 * @param int $object_id Content item ID.
	 */
	public function mark_stale( int $object_id ): bool {
		global $wpdb;

		$object_id = absint( $object_id );
		if ( 0 >= $object_id ) {
			return false;
		}

		$result = $wpdb->update(
			Installer::content_index_table(),
			array( 'stale' => 1 ),
			array( 'object_id' => $object_id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Check whether the content index has no rows at all.
	 */
	public function summary_is_empty(): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table; scalar count.
		return 0 === (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', Installer::content_index_table() )
		);
	}

	/**
	 * Return object IDs of stale index rows, most recently modified first.
	 *
	 * @param int $limit Maximum IDs to return.
	 * @return list<int>
	 */
	public function stale_object_ids( int $limit ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin table; bounded sweep query.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT object_id FROM %i WHERE stale = 1 ORDER BY modified_gmt DESC LIMIT %d',
				Installer::content_index_table(),
				$limit
			)
		);

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Return one indexed content item.
	 *
	 * @param int $object_id Content item ID.
	 * @return array<string, mixed>
	 */
	public function content_item( int $object_id ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE object_id = %d', Installer::content_index_table(), absint( $object_id ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->public_content_row( $row ) : array();
	}

	/**
	 * Search indexed content rows.
	 *
	 * @param array<string, mixed> $args Search arguments.
	 * @return array<string, mixed>
	 */
	public function search_items( array $args ): array {
		global $wpdb;

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = $this->limit( $args['per_page'] ?? self::DEFAULT_LIMIT );
		$offset   = ( $page - 1 ) * $per_page;
		$where    = $this->content_where_clause( $args );
		$table    = Installer::content_index_table();
		$count    = $this->bool_arg( $args['include_total'] ?? true, true );
		$limit    = $count ? $per_page : $per_page + 1;

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic filters add a variable number of placeholder values via argument unpacking.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE clause is built from fixed fragments and placeholder values in content_where_clause().
				"SELECT * FROM %i {$where['sql']} ORDER BY stale ASC, modified_gmt DESC, object_id DESC LIMIT %d OFFSET %d",
				...array_merge( array( $table ), $where['values'], array( $limit, $offset ) )
			),
			ARRAY_A
		);

		$rows     = is_array( $rows ) ? $rows : array();
		$has_more = ! $count && count( $rows ) > $per_page;
		$rows     = $has_more ? array_slice( $rows, 0, $per_page ) : $rows;

		$result = array(
			'items'    => array_map( array( $this, 'public_content_row' ), $rows ),
			'page'     => $page,
			'per_page' => $per_page,
			'context'  => $this->context( $args ),
		);

		if ( $count ) {
			$result['total'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE clause is built from fixed fragments and placeholder values in content_where_clause().
					"SELECT COUNT(*) FROM %i {$where['sql']}",
					...array_merge( array( $table ), $where['values'] )
				)
			);
		} else {
			$result['has_more'] = $has_more;
		}

		if ( $this->bool_arg( $args['include_index_summary'] ?? true, true ) ) {
			$result['index'] = $this->summary();
		}

		return $result;
	}

	/**
	 * Search indexed content chunks.
	 *
	 * @param array<string, mixed> $args Search arguments.
	 * @return array<string, mixed>
	 */
	public function search_chunks( array $args ): array {
		global $wpdb;

		$page      = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page  = $this->limit( $args['per_page'] ?? self::DEFAULT_LIMIT );
		$offset    = ( $page - 1 ) * $per_page;
		$where     = $this->chunk_where_clause( $args );
		$chunk_tbl = Installer::content_chunks_table();
		$index_tbl = Installer::content_index_table();
		$count     = $this->bool_arg( $args['include_total'] ?? true, true );
		$limit     = $count ? $per_page : $per_page + 1;

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic filters add a variable number of placeholder values via argument unpacking.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE clause is built from fixed fragments and placeholder values in chunk_where_clause().
				"SELECT chunks.*, idx.title, idx.post_type, idx.post_status, idx.permalink, idx.stale FROM %i chunks INNER JOIN %i idx ON idx.object_id = chunks.object_id {$where['sql']} ORDER BY idx.stale ASC, chunks.word_count DESC, chunks.id DESC LIMIT %d OFFSET %d",
				...array_merge( array( $chunk_tbl, $index_tbl ), $where['values'], array( $limit, $offset ) )
			),
			ARRAY_A
		);

		$rows     = is_array( $rows ) ? $rows : array();
		$has_more = ! $count && count( $rows ) > $per_page;
		$rows     = $has_more ? array_slice( $rows, 0, $per_page ) : $rows;
		$context  = $this->context( $args );

		$result = array(
			'items'    => array_map( fn ( array $row ): array => $this->public_chunk_row( $row, $context ), $rows ),
			'page'     => $page,
			'per_page' => $per_page,
			'context'  => $context,
		);

		if ( $count ) {
			$result['total'] = (int) $wpdb->get_var(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Query has two identifier placeholders plus a variable number of safe filter placeholders.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE clause is built from fixed fragments and placeholder values in chunk_where_clause().
					"SELECT COUNT(*) FROM %i chunks INNER JOIN %i idx ON idx.object_id = chunks.object_id {$where['sql']}",
					...array_merge( array( $chunk_tbl, $index_tbl ), $where['values'] )
				)
			);
		} else {
			$result['has_more'] = $has_more;
		}

		if ( $this->bool_arg( $args['include_index_summary'] ?? true, true ) ) {
			$result['index'] = $this->summary();
		}

		return $result;
	}

	/**
	 * Return outbound indexed target IDs for one source post.
	 *
	 * @param int $source_id Source post ID.
	 * @return list<int>
	 */
	public function linked_target_ids( int $source_id ): array {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT target_id FROM %i WHERE source_id = %d AND target_id IS NOT NULL',
				Installer::link_graph_table(),
				absint( $source_id )
			)
		);

		return array_values( array_filter( array_map( 'absint', is_array( $rows ) ? $rows : array() ) ) );
	}

	/**
	 * Return bounded link-count stats for indexed content IDs.
	 *
	 * @param array<int> $object_ids Indexed content IDs.
	 * @return array<int, array{inbound_internal_links: int, outbound_internal_links: int}>
	 */
	public function internal_link_stats( array $object_ids ): array {
		global $wpdb;

		$object_ids = array_values( array_unique( array_filter( array_map( 'absint', array_slice( $object_ids, 0, 50 ) ) ) ) );
		if ( array() === $object_ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $object_ids ), '%d' ) );
		$index_tbl    = Installer::content_index_table();
		$link_tbl     = Installer::link_graph_table();
		$rows         = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list is bounded and built from integer placeholders.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IN placeholders are generated above from a bounded list.
				"SELECT idx.object_id, COUNT(DISTINCT inbound.source_id) AS inbound_internal_links, COUNT(DISTINCT outbound.id) AS outbound_internal_links FROM %i idx LEFT JOIN %i inbound ON inbound.target_id = idx.object_id LEFT JOIN %i outbound ON outbound.source_id = idx.object_id AND outbound.target_id IS NOT NULL AND outbound.target_id > 0 WHERE idx.object_id IN ({$placeholders}) GROUP BY idx.object_id",
				...array_merge( array( $index_tbl, $link_tbl, $link_tbl ), $object_ids )
			),
			ARRAY_A
		);

		$stats = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id = absint( $row['object_id'] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}

			$stats[ $id ] = array(
				'inbound_internal_links'  => (int) ( $row['inbound_internal_links'] ?? 0 ),
				'outbound_internal_links' => (int) ( $row['outbound_internal_links'] ?? 0 ),
			);
		}

		return $stats;
	}

	/**
	 * Return bounded usage stats for existing internal-link anchors.
	 *
	 * @param array<string> $anchors Anchor texts.
	 * @param int           $source_id Optional source ID for source-local duplicate checks.
	 * @return array<string, array{total: int, source_total: int, target_total: int}>
	 */
	public function internal_link_anchor_usage( array $anchors, int $source_id = 0 ): array {
		global $wpdb;

		$anchors = array_values(
			array_unique(
				array_filter(
					array_map(
						fn ( string $anchor ): string => $this->text( $anchor, 255 ),
						array_slice( $anchors, 0, 50 )
					)
				)
			)
		);
		if ( array() === $anchors ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $anchors ), '%s' ) );
		$rows         = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- The IN list is bounded and built from string placeholders.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IN placeholders are generated above from a bounded list.
				"SELECT anchor_text, COUNT(*) AS total, COUNT(DISTINCT source_id) AS source_total, COUNT(DISTINCT target_id) AS target_total, SUM(CASE WHEN source_id = %d THEN 1 ELSE 0 END) AS source_matches FROM %i WHERE anchor_text IN ({$placeholders}) GROUP BY anchor_text",
				...array_merge( array( absint( $source_id ), Installer::link_graph_table() ), $anchors )
			),
			ARRAY_A
		);

		$usage = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key = $this->normalized_anchor_key( (string) ( $row['anchor_text'] ?? '' ) );
			if ( '' === $key ) {
				continue;
			}

			$usage[ $key ] = array(
				'total'        => (int) ( $row['total'] ?? 0 ),
				'source_total' => (int) ( $row['source_matches'] ?? 0 ),
				'target_total' => (int) ( $row['target_total'] ?? 0 ),
			);
		}

		return $usage;
	}

	/**
	 * Return a bounded audit over indexed outbound internal links.
	 *
	 * @param array<string, mixed> $args Audit arguments.
	 * @return array<string, mixed>
	 */
	public function internal_link_target_audit( array $args ): array {
		global $wpdb;

		$page      = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page  = $this->limit( $args['per_page'] ?? self::DEFAULT_LIMIT );
		$offset    = ( $page - 1 ) * $per_page;
		$where     = $this->link_target_where_clause( $args );
		$index_tbl = Installer::content_index_table();
		$link_tbl  = Installer::link_graph_table();

		$select = 'links.id AS link_id, links.source_id, links.target_id, links.target_url, links.anchor_text, links.rel, links.context AS link_context, links.created_at AS link_created_at, source.post_type AS source_post_type, source.post_status AS source_post_status, source.title AS source_title, source.slug AS source_slug, source.permalink AS source_permalink, source.indexed_at AS source_indexed_at, source.modified_gmt AS source_modified_gmt, source.stale AS source_stale, target.post_type AS indexed_target_post_type, target.post_status AS indexed_target_post_status, target.title AS indexed_target_title, target.slug AS indexed_target_slug, target.permalink AS indexed_target_permalink, target.stale AS indexed_target_stale';
		$joins  = 'INNER JOIN %i source ON source.object_id = links.source_id LEFT JOIN %i target ON target.object_id = links.target_id';

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic filters add a variable number of placeholder values via argument unpacking.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE clause is built from fixed fragments and placeholder values.
				"SELECT {$select} FROM %i links {$joins} {$where['sql']} ORDER BY links.created_at DESC, links.id DESC LIMIT %d OFFSET %d",
				...array_merge( array( $link_tbl, $index_tbl, $index_tbl ), $where['values'], array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic filters add a variable number of placeholder values via argument unpacking.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE clause is built from fixed fragments and placeholder values.
				"SELECT COUNT(*) FROM %i links {$joins} {$where['sql']}",
				...array_merge( array( $link_tbl, $index_tbl, $index_tbl ), $where['values'] )
			)
		);

		return array(
			'items'      => array_map(
				array( $this, 'public_link_target_row' ),
				array_filter( is_array( $rows ) ? $rows : array(), 'is_array' )
			),
			'total'      => $total,
			'page'       => $page,
			'per_page'   => $per_page,
			'context'    => 'compact',
			'filters'    => array(
				'state'     => 'broken',
				'post_type' => $this->key( $args['post_type'] ?? '', 60 ),
				'status'    => $this->key( $args['status'] ?? '', 20 ),
			),
			'index'      => $this->summary(),
			'audit_type' => 'internal_link_targets',
		);
	}

	/**
	 * Return a bounded internal-link audit over the content index and link graph.
	 *
	 * @param array<string, mixed> $args Audit arguments.
	 * @return array<string, mixed>
	 */
	public function internal_link_audit( array $args ): array {
		global $wpdb;

		$page       = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page   = $this->limit( $args['per_page'] ?? self::DEFAULT_LIMIT );
		$offset     = ( $page - 1 ) * $per_page;
		$thresholds = array(
			'min_inbound_links'  => max( 1, min( 100, absint( $args['min_inbound_links'] ?? 2 ) ) ),
			'thin_word_count'    => max( 1, min( 5000, absint( $args['thin_word_count'] ?? 300 ) ) ),
			'max_outbound_links' => max( 1, min( 500, absint( $args['max_outbound_links'] ?? 25 ) ) ),
		);
		$state      = $this->audit_state( $args['state'] ?? 'needs_review' );
		$where      = $this->audit_where_clause( $args );
		$having     = $this->audit_having_clause( $state, $thresholds );
		$index_tbl  = Installer::content_index_table();
		$link_tbl   = Installer::link_graph_table();
		$select     = 'idx.*, COUNT(DISTINCT inbound.source_id) AS inbound_internal_links, COUNT(DISTINCT outbound.id) AS outbound_internal_links';
		$joins      = 'LEFT JOIN %i inbound ON inbound.target_id = idx.object_id LEFT JOIN %i outbound ON outbound.source_id = idx.object_id AND outbound.target_id IS NOT NULL AND outbound.target_id > 0';
		$group      = 'GROUP BY idx.object_id, idx.object_type, idx.post_type, idx.post_status, idx.title, idx.slug, idx.permalink, idx.excerpt, idx.summary, idx.word_count, idx.content_hash, idx.indexed_at, idx.modified_gmt, idx.stale, idx.search_text, idx.metadata';

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic filters add a variable number of placeholder values via argument unpacking.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE and HAVING clauses are built from fixed fragments and placeholder values.
				"SELECT {$select} FROM %i idx {$joins} {$where['sql']} {$group} {$having['sql']} ORDER BY idx.stale DESC, inbound_internal_links ASC, idx.word_count ASC, outbound_internal_links DESC, idx.modified_gmt DESC, idx.object_id DESC LIMIT %d OFFSET %d",
				...array_merge( array( $index_tbl, $link_tbl, $link_tbl ), $where['values'], $having['values'], array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic filters add a variable number of placeholder values via argument unpacking.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE and HAVING clauses are built from fixed fragments and placeholder values.
				"SELECT COUNT(*) FROM (SELECT idx.object_id, COUNT(DISTINCT inbound.source_id) AS inbound_internal_links, COUNT(DISTINCT outbound.id) AS outbound_internal_links, idx.word_count, idx.stale FROM %i idx {$joins} {$where['sql']} GROUP BY idx.object_id, idx.word_count, idx.stale {$having['sql']}) audit_rows",
				...array_merge( array( $index_tbl, $link_tbl, $link_tbl ), $where['values'], $having['values'] )
			)
		);

		$items = array_map(
			fn ( array $row ): array => $this->public_link_audit_row( $row, $thresholds ),
			array_filter( is_array( $rows ) ? $rows : array(), 'is_array' )
		);

		return array(
			'items'        => $items,
			'total'        => $total,
			'page'         => $page,
			'per_page'     => $per_page,
			'context'      => 'compact',
			'filters'      => array(
				'state'     => $state,
				'post_type' => $this->key( $args['post_type'] ?? '', 60 ),
				'status'    => $this->key( $args['status'] ?? '', 20 ),
			),
			'thresholds'   => $thresholds,
			'index'        => $this->summary(),
			'next_actions' => array(
				'Refresh stale rows with content_index_refresh_batch before relying on audit results for large edits.',
				'Use content_find_internal_links for candidate anchors after choosing a source item from the audit.',
			),
		);
	}

	/**
	 * Return high-level index summary.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		global $wpdb;

		$table = Installer::content_index_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS total, SUM(CASE WHEN stale = 1 THEN 1 ELSE 0 END) AS stale, MAX(indexed_at) AS latest_indexed_at FROM %i',
				$table
			),
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();

		return array(
			'total_items'       => (int) ( $row['total'] ?? 0 ),
			'stale_items'       => (int) ( $row['stale'] ?? 0 ),
			'latest_indexed_at' => (string) ( $row['latest_indexed_at'] ?? '' ),
		);
	}

	/**
	 * Return job counts grouped by status for one job type.
	 *
	 * @param string $type Job type.
	 * @return array<string, int>
	 */
	public function job_status_counts( string $type = 'content_index_refresh' ): array {
		global $wpdb;

		$stale_time = gmdate( 'Y-m-d H:i:s', time() - self::DEFAULT_JOB_LEASE_TTL );
		$rows       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CASE WHEN status = 'running' AND updated_at < %s THEN 'stale' ELSE status END AS status, COUNT(*) AS total FROM %i WHERE job_type = %s GROUP BY CASE WHEN status = 'running' AND updated_at < %s THEN 'stale' ELSE status END",
				$stale_time,
				Installer::jobs_table(),
				$this->key( $type, 60 ),
				$stale_time
			),
			ARRAY_A
		);

		$counts = array(
			'queued'   => 0,
			'running'  => 0,
			'stale'    => 0,
			'partial'  => 0,
			'complete' => 0,
			'failed'   => 0,
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$status            = $this->job_status( $row['status'] ?? '' );
			$counts[ $status ] = (int) ( $row['total'] ?? 0 );
		}

		return $counts;
	}

	/**
	 * Return recent job summaries without args or result payloads.
	 *
	 * @param int    $limit Maximum jobs.
	 * @param string $type  Job type.
	 * @return list<array<string, mixed>>
	 */
	public function recent_job_summaries( int $limit = 5, string $type = 'content_index_refresh' ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, job_key, job_type, status, total_items, processed_items, error_count, created_at, updated_at FROM %i WHERE job_type = %s ORDER BY updated_at DESC, id DESC LIMIT %d',
				Installer::jobs_table(),
				$this->key( $type, 60 ),
				min( 20, max( 1, $limit ) )
			),
			ARRAY_A
		);

		return array_values(
			array_map(
				array( $this, 'public_job_summary_row' ),
				array_filter( is_array( $rows ) ? $rows : array(), 'is_array' )
			)
		);
	}

	/**
	 * Upsert one durable memory item.
	 *
	 * @param array<string, mixed> $memory Memory fields.
	 * @return array<string, mixed>
	 */
	public function upsert_memory( array $memory ): array {
		global $wpdb;

		$key = $this->memory_key( $memory['key'] ?? $memory['memory_key'] ?? '' );
		if ( '' === $key ) {
			return array(
				'status'  => 'error',
				'error'   => 'invalid_memory_key',
				'message' => 'Provide a stable memory key.',
			);
		}

		$data = array(
			'memory_key' => $key,
			'domain'     => $this->memory_domain( $memory['domain'] ?? 'content' ),
			'value'      => $this->text( $memory['value'] ?? '', 4000 ),
			'evidence'   => $this->text( $memory['evidence'] ?? '', 2000 ),
			'confidence' => $this->confidence( $memory['confidence'] ?? 'medium' ),
			'status'     => $this->memory_status( $memory['status'] ?? 'pending' ),
			'source'     => $this->key( $memory['source'] ?? 'manual', 40 ),
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( '' === $data['value'] ) {
			return array(
				'status'  => 'error',
				'error'   => 'invalid_memory_value',
				'message' => 'Memory value cannot be empty.',
			);
		}

		$existing = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE memory_key = %s', Installer::memory_items_table(), $key )
		);

		if ( null === $existing ) {
			$data['created_at'] = gmdate( 'Y-m-d H:i:s' );
			$result             = $wpdb->insert(
				Installer::memory_items_table(),
				$data,
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		} else {
			$result = $wpdb->update(
				Installer::memory_items_table(),
				$data,
				array( 'memory_key' => $key ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%s' )
			);
		}

		if ( false === $result ) {
			return array(
				'status'  => 'error',
				'error'   => 'memory_save_failed',
				'message' => 'Memory item could not be saved.',
			);
		}

		return array(
			'status'  => 'success',
			'memory'  => $this->memory_by_key( $key ),
			'message' => 'Memory item saved for future Aculect Intelligence responses.',
		);
	}

	/**
	 * Delete one durable memory item.
	 *
	 * @param string $key Memory key.
	 * @return array<string, mixed>
	 */
	public function delete_memory( string $key ): array {
		global $wpdb;

		$key = $this->memory_key( $key );
		if ( '' === $key ) {
			return array(
				'status'  => 'error',
				'error'   => 'invalid_memory_key',
				'message' => 'Provide a stable memory key.',
			);
		}

		$result = $wpdb->delete(
			Installer::memory_items_table(),
			array( 'memory_key' => $key ),
			array( '%s' )
		);

		if ( false === $result ) {
			return array(
				'status'  => 'error',
				'error'   => 'memory_delete_failed',
				'message' => 'Memory item could not be deleted.',
			);
		}

		return array(
			'status'  => 'success',
			'deleted' => (int) $result,
			'key'     => $key,
		);
	}

	/**
	 * Return memory rows by domain/status.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public function list_memories( array $args = array() ): array {
		global $wpdb;

		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = $this->limit( $args['per_page'] ?? self::DEFAULT_LIMIT );
		$offset   = ( $page - 1 ) * $per_page;
		$where    = $this->memory_where_clause( $args );
		$table    = Installer::memory_items_table();

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic filters add a variable number of placeholder values via argument unpacking.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE clause is built from fixed fragments and placeholder values in memory_where_clause().
				"SELECT * FROM %i {$where['sql']} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d",
				...array_merge( array( $table ), $where['values'], array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE clause is built from fixed fragments and placeholder values in memory_where_clause().
				"SELECT COUNT(*) FROM %i {$where['sql']}",
				...array_merge( array( $table ), $where['values'] )
			)
		);

		return array(
			'items'    => is_array( $rows ) ? array_map( array( $this, 'public_memory_row' ), $rows ) : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'context'  => 'compact',
		);
	}

	/**
	 * Create a batch job row.
	 *
	 * @param string               $type Job type.
	 * @param array<string, mixed> $args Job arguments.
	 * @param int                  $total Total items.
	 * @param string               $status Initial job status.
	 * @return array<string, mixed>
	 */
	public function create_job( string $type, array $args, int $total, string $status = 'running' ): array {
		global $wpdb;

		$args_json = wp_json_encode( $args );
		$key       = sanitize_key( $type ) . '_' . gmdate( 'YmdHis' ) . '_' . substr( hash( 'sha256', false === $args_json ? '' : $args_json ), 0, 8 ) . '_' . substr( bin2hex( random_bytes( 8 ) ), 0, 16 );
		$now       = gmdate( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert(
			Installer::jobs_table(),
			array(
				'job_key'         => $key,
				'job_type'        => $this->key( $type, 60 ),
				'status'          => $this->job_status( $status ),
				'total_items'     => max( 0, $total ),
				'processed_items' => 0,
				'error_count'     => 0,
				'args'            => false === $args_json ? '{}' : $args_json,
				'result'          => '{}',
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return array();
		}

		return $this->job_by_key( $key );
	}

	/**
	 * Update one batch job row.
	 *
	 * @param string               $key Job key.
	 * @param array<string, mixed> $data Job data.
	 * @return array<string, mixed>
	 */
	public function update_job( string $key, array $data ): array {
		global $wpdb;

		$key     = $this->key( $key, 120 );
		$update  = $this->job_update_values( $data );
		$updated = $wpdb->update(
			Installer::jobs_table(),
			$update['data'],
			array( 'job_key' => $key ),
			$update['formats'],
			array( '%s' )
		);
		if ( false === $updated ) {
			return array();
		}

		return $this->job_by_key( $key );
	}

	/**
	 * Update a job only while the caller still owns its current lease.
	 *
	 * @param string               $key           Job key.
	 * @param string               $lease_token   Private claim token.
	 * @param array<string, mixed> $data          Job data.
	 * @param bool                 $release_lease Whether this is a queued or terminal transition.
	 * @return array<string, mixed>
	 */
	public function update_claimed_job( string $key, string $lease_token, array $data, bool $release_lease = false ): array {
		global $wpdb;

		$key         = $this->key( $key, 120 );
		$lease_token = $this->lease_token( $lease_token );
		if ( '' === $key || '' === $lease_token ) {
			return array();
		}

		$update = $this->job_update_values( $data );
		if ( $release_lease ) {
			$update['data']['lease_token'] = '';
			$update['formats'][]           = '%s';
		}

		$updated = $wpdb->update(
			Installer::jobs_table(),
			$update['data'],
			array(
				'job_key'     => $key,
				'lease_token' => $lease_token,
			),
			$update['formats'],
			array( '%s', '%s' )
		);
		if ( 1 !== (int) $updated ) {
			return array();
		}

		return $this->job_by_key( $key );
	}

	/**
	 * Atomically claim a queued job before a worker starts processing.
	 *
	 * @param string $key       Job key.
	 * @param int    $lease_ttl Seconds before an interrupted running job is reclaimable.
	 * @return array<string, mixed>
	 */
	public function claim_job( string $key, int $lease_ttl = self::DEFAULT_JOB_LEASE_TTL ): array {
		global $wpdb;

		$key         = $this->key( $key, 120 );
		$lease_token = bin2hex( random_bytes( 16 ) );
		$now         = gmdate( 'Y-m-d H:i:s' );
		$stale_time  = gmdate( 'Y-m-d H:i:s', time() - max( 30, $lease_ttl ) );
		$result      = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'running', lease_token = %s, updated_at = %s WHERE job_key = %s AND (status = 'queued' OR (status = 'running' AND updated_at < %s))",
				Installer::jobs_table(),
				$lease_token,
				$now,
				$key,
				$stale_time
			)
		);

		if ( 1 !== (int) $result ) {
			return array();
		}

		$job = $this->job_by_key( $key );
		if ( array() !== $job ) {
			$job['_lease_token'] = $lease_token;
		}

		return $job;
	}

	/**
	 * Return one job by key.
	 *
	 * @param string $key Job key.
	 * @return array<string, mixed>
	 */
	public function job_by_key( string $key ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE job_key = %s', Installer::jobs_table(), $this->key( $key, 120 ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->public_job_row( $row ) : array();
	}

	/**
	 * Store a disposable cache payload.
	 *
	 * @param string               $key        Cache key.
	 * @param string               $group      Cache group.
	 * @param array<string, mixed> $payload    Payload.
	 * @param int                  $expiration Expiration in seconds.
	 */
	public function set_cache( string $key, string $group, array $payload, int $expiration = 3600 ): bool {
		global $wpdb;

		$json = wp_json_encode( $payload );
		$now  = gmdate( 'Y-m-d H:i:s' );

		$result = $wpdb->replace(
			Installer::cache_table(),
			array(
				'cache_key'   => $this->cache_key( $key ),
				'cache_group' => $this->key( $group, 60 ),
				'payload'     => false === $json ? '{}' : $json,
				'expires_at'  => $expiration > 0 ? gmdate( 'Y-m-d H:i:s', time() + $expiration ) : null,
				'updated_at'  => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Return a disposable cache payload when present and fresh.
	 *
	 * @param string $key Cache key.
	 * @return array<string, mixed>
	 */
	public function get_cache( string $key ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT payload, expires_at FROM %i WHERE cache_key = %s', Installer::cache_table(), $this->cache_key( $key ) ),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array();
		}

		$expires = (string) ( $row['expires_at'] ?? '' );
		if ( '' !== $expires && strtotime( $expires . ' UTC' ) < time() ) {
			$wpdb->delete( Installer::cache_table(), array( 'cache_key' => $this->cache_key( $key ) ), array( '%s' ) );
			return array();
		}

		$payload = json_decode( (string) ( $row['payload'] ?? '{}' ), true );

		return is_array( $payload ) ? $payload : array();
	}

	/**
	 * Return a public content row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function public_content_row( array $row ): array {
		$metadata = json_decode( (string) ( $row['metadata'] ?? '{}' ), true );

		return array(
			'id'           => (int) ( $row['object_id'] ?? 0 ),
			'type'         => (string) ( $row['post_type'] ?? '' ),
			'status'       => (string) ( $row['post_status'] ?? '' ),
			'title'        => (string) ( $row['title'] ?? '' ),
			'slug'         => (string) ( $row['slug'] ?? '' ),
			'permalink'    => (string) ( $row['permalink'] ?? '' ),
			'excerpt'      => (string) ( $row['excerpt'] ?? '' ),
			'summary'      => (string) ( $row['summary'] ?? '' ),
			'word_count'   => (int) ( $row['word_count'] ?? 0 ),
			'content_hash' => (string) ( $row['content_hash'] ?? '' ),
			'indexed_at'   => (string) ( $row['indexed_at'] ?? '' ),
			'modified_gmt' => (string) ( $row['modified_gmt'] ?? '' ),
			'stale'        => ! empty( $row['stale'] ),
			'metadata'     => is_array( $metadata ) ? $metadata : array(),
		);
	}

	/**
	 * Return a public internal-link audit row.
	 *
	 * @param array<string, mixed> $row        Database row.
	 * @param array<string, int>   $thresholds Audit thresholds.
	 * @return array<string, mixed>
	 */
	private function public_link_audit_row( array $row, array $thresholds ): array {
		$content  = $this->public_content_row( $row );
		$inbound  = (int) ( $row['inbound_internal_links'] ?? 0 );
		$outbound = (int) ( $row['outbound_internal_links'] ?? 0 );
		$words    = (int) ( $content['word_count'] ?? 0 );
		$stale    = ! empty( $content['stale'] );
		$flags    = array();

		if ( 0 === $inbound ) {
			$flags[] = 'orphan';
		} elseif ( $inbound < $thresholds['min_inbound_links'] ) {
			$flags[] = 'underlinked';
		}

		if ( $words <= $thresholds['thin_word_count'] ) {
			$flags[] = 'thin';
		}

		if ( $stale ) {
			$flags[] = 'stale_index';
		}

		if ( $outbound > $thresholds['max_outbound_links'] ) {
			$flags[] = 'link_heavy';
		}

		return array_merge(
			$content,
			array(
				'post_id'                 => (int) ( $content['id'] ?? 0 ),
				'inbound_internal_links'  => $inbound,
				'outbound_internal_links' => $outbound,
				'flags'                   => $flags,
				'needs_review'            => array() !== $flags,
				'suggested_next_action'   => $this->link_audit_next_action( $flags ),
			)
		);
	}

	/**
	 * Return a public outbound link target row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function public_link_target_row( array $row ): array {
		return array(
			'link_id'        => (int) ( $row['link_id'] ?? 0 ),
			'source_post'    => array(
				'post_id'      => (int) ( $row['source_id'] ?? 0 ),
				'title'        => (string) ( $row['source_title'] ?? '' ),
				'post_type'    => (string) ( $row['source_post_type'] ?? '' ),
				'post_status'  => (string) ( $row['source_post_status'] ?? '' ),
				'slug'         => (string) ( $row['source_slug'] ?? '' ),
				'permalink'    => (string) ( $row['source_permalink'] ?? '' ),
				'indexed_at'   => (string) ( $row['source_indexed_at'] ?? '' ),
				'modified_gmt' => (string) ( $row['source_modified_gmt'] ?? '' ),
				'stale'        => ! empty( $row['source_stale'] ),
			),
			'target_id'      => (int) ( $row['target_id'] ?? 0 ),
			'target_url'     => (string) ( $row['target_url'] ?? '' ),
			'anchor_text'    => (string) ( $row['anchor_text'] ?? '' ),
			'rel'            => (string) ( $row['rel'] ?? '' ),
			'context'        => $this->snippet( (string) ( $row['link_context'] ?? '' ), 140 ),
			'indexed_target' => array(
				'post_id'     => (int) ( $row['target_id'] ?? 0 ),
				'title'       => (string) ( $row['indexed_target_title'] ?? '' ),
				'post_type'   => (string) ( $row['indexed_target_post_type'] ?? '' ),
				'post_status' => (string) ( $row['indexed_target_post_status'] ?? '' ),
				'slug'        => (string) ( $row['indexed_target_slug'] ?? '' ),
				'permalink'   => (string) ( $row['indexed_target_permalink'] ?? '' ),
				'stale'       => ! empty( $row['indexed_target_stale'] ),
			),
			'created_at'     => (string) ( $row['link_created_at'] ?? '' ),
		);
	}

	/**
	 * Return a public chunk row.
	 *
	 * @param array<string, mixed> $row     Database row.
	 * @param string               $context compact or full.
	 * @return array<string, mixed>
	 */
	private function public_chunk_row( array $row, string $context ): array {
		$metadata = json_decode( (string) ( $row['metadata'] ?? '{}' ), true );
		$chunk    = array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'post_id'       => (int) ( $row['object_id'] ?? 0 ),
			'post_type'     => (string) ( $row['post_type'] ?? '' ),
			'post_status'   => (string) ( $row['post_status'] ?? '' ),
			'post_title'    => (string) ( $row['title'] ?? '' ),
			'permalink'     => (string) ( $row['permalink'] ?? '' ),
			'chunk_id'      => (string) ( $row['chunk_id'] ?? '' ),
			'heading'       => (string) ( $row['heading'] ?? '' ),
			'anchor'        => (string) ( $row['anchor'] ?? '' ),
			'section_index' => (int) ( $row['section_index'] ?? 0 ),
			'word_count'    => (int) ( $row['word_count'] ?? 0 ),
			'text'          => $this->snippet( (string) ( $row['text'] ?? '' ), 700 ),
			'content_hash'  => (string) ( $row['content_hash'] ?? '' ),
			'stale'         => ! empty( $row['stale'] ),
			'metadata'      => is_array( $metadata ) ? $metadata : array(),
		);

		if ( 'full' === $context ) {
			$chunk['block_markup'] = (string) ( $row['block_markup'] ?? '' );
		}

		return $chunk;
	}

	/**
	 * Return a public memory row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function public_memory_row( array $row ): array {
		return array(
			'id'         => (int) ( $row['id'] ?? 0 ),
			'key'        => (string) ( $row['memory_key'] ?? '' ),
			'domain'     => (string) ( $row['domain'] ?? '' ),
			'value'      => (string) ( $row['value'] ?? '' ),
			'evidence'   => (string) ( $row['evidence'] ?? '' ),
			'confidence' => (string) ( $row['confidence'] ?? '' ),
			'status'     => (string) ( $row['status'] ?? '' ),
			'source'     => (string) ( $row['source'] ?? '' ),
			'created_at' => (string) ( $row['created_at'] ?? '' ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Return a public job row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function public_job_row( array $row ): array {
		$args   = json_decode( (string) ( $row['args'] ?? '{}' ), true );
		$result = json_decode( (string) ( $row['result'] ?? '{}' ), true );

		return array(
			'id'              => (int) ( $row['id'] ?? 0 ),
			'job_key'         => (string) ( $row['job_key'] ?? '' ),
			'job_type'        => (string) ( $row['job_type'] ?? '' ),
			'status'          => $this->public_job_status( $row ),
			'total_items'     => (int) ( $row['total_items'] ?? 0 ),
			'processed_items' => (int) ( $row['processed_items'] ?? 0 ),
			'error_count'     => (int) ( $row['error_count'] ?? 0 ),
			'args'            => is_array( $args ) ? $args : array(),
			'result'          => is_array( $result ) ? $result : array(),
			'created_at'      => (string) ( $row['created_at'] ?? '' ),
			'updated_at'      => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Return a public job summary row without args or result payloads.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function public_job_summary_row( array $row ): array {
		return array(
			'id'              => (int) ( $row['id'] ?? 0 ),
			'job_key'         => (string) ( $row['job_key'] ?? '' ),
			'job_type'        => (string) ( $row['job_type'] ?? '' ),
			'status'          => $this->public_job_status( $row ),
			'total_items'     => (int) ( $row['total_items'] ?? 0 ),
			'processed_items' => (int) ( $row['processed_items'] ?? 0 ),
			'error_count'     => (int) ( $row['error_count'] ?? 0 ),
			'created_at'      => (string) ( $row['created_at'] ?? '' ),
			'updated_at'      => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Build content search WHERE clause.
	 *
	 * @param array<string, mixed> $args Search args.
	 * @return array{sql: string, values: list<mixed>}
	 */
	private function content_where_clause( array $args ): array {
		global $wpdb;

		$clauses = array();
		$values  = array();

		$query = $this->text( $args['query'] ?? '', 200 );
		if ( '' !== $query ) {
			$fulltext = $this->fulltext_query( $query );
			if ( '' !== $fulltext && $this->use_fulltext_search() ) {
				$clauses[] = 'MATCH(title, summary, search_text) AGAINST (%s IN BOOLEAN MODE)';
				$values[]  = $fulltext;
			} else {
				$like      = '%' . $wpdb->esc_like( $query ) . '%';
				$clauses[] = '(title LIKE %s OR summary LIKE %s OR search_text LIKE %s)';
				$values[]  = $like;
				$values[]  = $like;
				$values[]  = $like;
			}
		}

		$post_type = $this->key( $args['post_type'] ?? '', 60 );
		if ( '' !== $post_type ) {
			$clauses[] = 'post_type = %s';
			$values[]  = $post_type;
		}

		$status = $this->key( $args['status'] ?? '', 20 );
		if ( '' !== $status ) {
			$clauses[] = 'post_status = %s';
			$values[]  = $status;
		}

		if ( array_key_exists( 'stale', $args ) ) {
			$clauses[] = 'stale = %d';
			$values[]  = empty( $args['stale'] ) ? 0 : 1;
		}

		$max_word_count = absint( $args['max_word_count'] ?? 0 );
		if ( $max_word_count > 0 ) {
			$clauses[] = 'word_count <= %d';
			$values[]  = $max_word_count;
		}

		return array(
			'sql'    => array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Build internal-link audit WHERE filters.
	 *
	 * @param array<string, mixed> $args Audit args.
	 * @return array{sql: string, values: list<mixed>}
	 */
	private function audit_where_clause( array $args ): array {
		$clauses = array();
		$values  = array();

		$post_type = $this->key( $args['post_type'] ?? '', 60 );
		if ( '' !== $post_type ) {
			$clauses[] = 'idx.post_type = %s';
			$values[]  = $post_type;
		}

		$status = $this->key( $args['status'] ?? '', 20 );
		if ( '' !== $status ) {
			$clauses[] = 'idx.post_status = %s';
			$values[]  = $status;
		}

		return array(
			'sql'    => array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Build outbound link target audit WHERE filters.
	 *
	 * @param array<string, mixed> $args Audit args.
	 * @return array{sql: string, values: list<mixed>}
	 */
	private function link_target_where_clause( array $args ): array {
		$clauses = array( 'links.target_url <> %s' );
		$values  = array( '' );

		$post_type = $this->key( $args['post_type'] ?? '', 60 );
		if ( '' !== $post_type ) {
			$clauses[] = 'source.post_type = %s';
			$values[]  = $post_type;
		}

		$status = $this->key( $args['status'] ?? '', 20 );
		if ( '' !== $status ) {
			$clauses[] = 'source.post_status = %s';
			$values[]  = $status;
		}

		return array(
			'sql'    => 'WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Build internal-link audit HAVING filters from a state.
	 *
	 * @param string             $state      Audit state.
	 * @param array<string, int> $thresholds Audit thresholds.
	 * @return array{sql: string, values: list<mixed>}
	 */
	private function audit_having_clause( string $state, array $thresholds ): array {
		$values = array();

		switch ( $state ) {
			case 'orphan':
				$sql = 'HAVING inbound_internal_links = 0';
				break;
			case 'underlinked':
				$sql      = 'HAVING inbound_internal_links > 0 AND inbound_internal_links < %d';
				$values[] = $thresholds['min_inbound_links'];
				break;
			case 'thin':
				$sql      = 'HAVING idx.word_count <= %d';
				$values[] = $thresholds['thin_word_count'];
				break;
			case 'stale':
				$sql = 'HAVING idx.stale = 1';
				break;
			case 'link_heavy':
				$sql      = 'HAVING outbound_internal_links > %d';
				$values[] = $thresholds['max_outbound_links'];
				break;
			case 'needs_review':
				$sql      = 'HAVING inbound_internal_links = 0 OR (inbound_internal_links > 0 AND inbound_internal_links < %d) OR idx.word_count <= %d OR idx.stale = 1 OR outbound_internal_links > %d';
				$values[] = $thresholds['min_inbound_links'];
				$values[] = $thresholds['thin_word_count'];
				$values[] = $thresholds['max_outbound_links'];
				break;
			default:
				$sql = '';
				break;
		}

		return array(
			'sql'    => $sql,
			'values' => $values,
		);
	}

	/**
	 * Normalize audit state.
	 *
	 * @param mixed $state Raw state.
	 */
	private function audit_state( mixed $state ): string {
		$state = $this->key( $state, 30 );

		return in_array( $state, array( 'all', 'needs_review', 'orphan', 'underlinked', 'thin', 'stale', 'link_heavy', 'broken', 'missing_target', 'unreadable_target', 'unpublished_target', 'stale_permalink', 'redirected' ), true ) ? $state : 'needs_review';
	}

	/**
	 * Return a compact recommended next action for one audit row.
	 *
	 * @param array<int, string> $flags Audit flags.
	 */
	private function link_audit_next_action( array $flags ): string {
		if ( in_array( 'stale_index', $flags, true ) ) {
			return 'Refresh this item in the content intelligence index before making internal-link decisions.';
		}

		if ( in_array( 'orphan', $flags, true ) ) {
			return 'Find relevant source pages and add one or more contextual links to this item.';
		}

		if ( in_array( 'underlinked', $flags, true ) ) {
			return 'Add more contextual internal links from related content to this item.';
		}

		if ( in_array( 'thin', $flags, true ) ) {
			return 'Review this thin item before adding link suggestions so the target page has enough substance.';
		}

		if ( in_array( 'link_heavy', $flags, true ) ) {
			return 'Review outbound links and prune or rebalance links before adding more.';
		}

		return 'No immediate internal-link action is flagged by the current thresholds.';
	}

	/**
	 * Build chunk search WHERE clause.
	 *
	 * @param array<string, mixed> $args Search args.
	 * @return array{sql: string, values: list<mixed>}
	 */
	private function chunk_where_clause( array $args ): array {
		global $wpdb;

		$clauses = array();
		$values  = array();

		$query = $this->text( $args['query'] ?? '', 200 );
		if ( '' !== $query ) {
			$fulltext = $this->fulltext_query( $query );
			if ( '' !== $fulltext && $this->use_fulltext_search() ) {
				$clauses[] = '(MATCH(chunks.heading, chunks.text) AGAINST (%s IN BOOLEAN MODE) OR idx.title LIKE %s)';
				$values[]  = $fulltext;
				$values[]  = '%' . $wpdb->esc_like( $query ) . '%';
			} else {
				$like      = '%' . $wpdb->esc_like( $query ) . '%';
				$clauses[] = '(chunks.heading LIKE %s OR chunks.text LIKE %s OR idx.title LIKE %s)';
				$values[]  = $like;
				$values[]  = $like;
				$values[]  = $like;
			}
		}

		$post_id = absint( $args['post_id'] ?? 0 );
		if ( $post_id > 0 ) {
			$clauses[] = 'chunks.object_id = %d';
			$values[]  = $post_id;
		}

		$post_type = $this->key( $args['post_type'] ?? '', 60 );
		if ( '' !== $post_type ) {
			$clauses[] = 'idx.post_type = %s';
			$values[]  = $post_type;
		}

		$status = $this->key( $args['status'] ?? '', 20 );
		if ( '' !== $status ) {
			$clauses[] = 'idx.post_status = %s';
			$values[]  = $status;
		}

		return array(
			'sql'    => array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Build memory WHERE clause.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array{sql: string, values: list<mixed>}
	 */
	private function memory_where_clause( array $args ): array {
		global $wpdb;

		$clauses = array();
		$values  = array();

		$domain = $this->key( $args['domain'] ?? '', 40 );
		if ( '' !== $domain ) {
			$clauses[] = 'domain = %s';
			$values[]  = $domain;
		}

		$status = $this->memory_status( $args['status'] ?? 'approved' );
		if ( '' !== $status ) {
			$clauses[] = 'status = %s';
			$values[]  = $status;
		}

		$query = $this->text( $args['query'] ?? '', 200 );
		if ( '' !== $query ) {
			$like      = '%' . $wpdb->esc_like( $query ) . '%';
			$clauses[] = '(memory_key LIKE %s OR value LIKE %s OR evidence LIKE %s)';
			$values[]  = $like;
			$values[]  = $like;
			$values[]  = $like;
		}

		return array(
			'sql'    => array() === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Return one memory by key.
	 *
	 * @param string $key Memory key.
	 * @return array<string, mixed>
	 */
	private function memory_by_key( string $key ): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE memory_key = %s', Installer::memory_items_table(), $this->memory_key( $key ) ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->public_memory_row( $row ) : array();
	}

	/**
	 * Normalize a bounded limit.
	 *
	 * @param mixed $value Raw limit.
	 */
	private function limit( mixed $value ): int {
		$limit = absint( $value );
		if ( 0 === $limit ) {
			$limit = self::DEFAULT_LIMIT;
		}

		return min( self::MAX_LIMIT, max( 1, $limit ) );
	}

	/**
	 * Normalize context argument.
	 *
	 * @param array<string, mixed> $args Arguments.
	 */
	private function context( array $args ): string {
		return 'full' === (string) ( $args['context'] ?? 'compact' ) ? 'full' : 'compact';
	}

	/**
	 * Sanitize a key-like value.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Max length.
	 */
	private function key( mixed $value, int $limit ): string {
		return substr( sanitize_key( is_scalar( $value ) ? (string) $value : '' ), 0, $limit );
	}

	/**
	 * Sanitize a cache key while preserving deterministic hashes.
	 *
	 * @param string $value Raw key.
	 */
	private function cache_key( string $value ): string {
		$value = preg_replace( '/[^A-Za-z0-9:_\-.]/', '_', $value ) ?? '';
		$value = trim( $value, '_-.' );

		return substr( '' === $value ? hash( 'sha256', $value ) : $value, 0, 191 );
	}

	/**
	 * Sanitize a memory key.
	 *
	 * @param mixed $value Raw value.
	 */
	private function memory_key( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( (string) $value ) : '';
		$value = preg_replace( '/[^a-z0-9:_\-.]+/', '_', $value ) ?? '';

		return substr( trim( $value, '_-.' ), 0, 120 );
	}

	/**
	 * Sanitize short text.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Max length.
	 */
	private function text( mixed $value, int $limit ): string {
		$text = sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );

		return substr( $text, 0, $limit );
	}

	/**
	 * Normalize anchor text for usage-map keys.
	 *
	 * @param string $anchor Anchor text.
	 */
	private function normalized_anchor_key( string $anchor ): string {
		return trim( strtolower( preg_replace( '/\s+/', ' ', $anchor ) ?? '' ) );
	}

	/**
	 * Return whether indexed search should prefer MySQL full-text predicates.
	 */
	private function use_fulltext_search(): bool {
		return (bool) apply_filters( 'aculect_ai_companion_content_index_use_fulltext', true );
	}

	/**
	 * Return a normalized boolean argument.
	 *
	 * @param mixed $value   Raw argument value.
	 * @param bool  $default Default when the value is not a recognizable scalar.
	 */
	private function bool_arg( mixed $value, bool $default ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) ) {
			return 1 === $value;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return true;
			}
			if ( in_array( $value, array( '0', 'false', 'no', 'off' ), true ) ) {
				return false;
			}
		}

		return $default;
	}

	/**
	 * Build a bounded boolean-mode full-text query from assistant search text.
	 *
	 * @param string $query Raw query.
	 */
	private function fulltext_query( string $query ): string {
		$text   = strtolower( wp_strip_all_tags( $query ) );
		$tokens = preg_split( '/\s+/', $text );
		$tokens = false === $tokens ? array() : $tokens;
		$terms  = array();

		foreach ( $tokens as $token ) {
			$token = preg_replace( '/[^\pL\pN_-]+/u', '', $token ) ?? '';
			$token = trim( $token, '_-' );
			if ( strlen( $token ) < 3 ) {
				continue;
			}

			$terms[] = '+' . $token . '*';
			if ( count( $terms ) >= 8 ) {
				break;
			}
		}

		return implode( ' ', array_values( array_unique( $terms ) ) );
	}

	/**
	 * Sanitize long text for storage.
	 *
	 * @param mixed $value Raw value.
	 */
	private function long_text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Sanitize a public URL.
	 *
	 * @param mixed $value Raw value.
	 */
	private function url( mixed $value ): string {
		return is_scalar( $value ) ? esc_url_raw( (string) $value ) : '';
	}

	/**
	 * Normalize a SHA-256 hash.
	 *
	 * @param mixed $value Raw value.
	 */
	private function hash( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( (string) $value ) : '';

		return 1 === preg_match( '/^[a-f0-9]{64}$/', $value ) ? $value : hash( 'sha256', $value );
	}

	/**
	 * Normalize a MySQL datetime.
	 *
	 * @param mixed $value Raw value.
	 */
	private function datetime( mixed $value ): ?string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return '' === $value ? null : substr( sanitize_text_field( $value ), 0, 19 );
	}

	/**
	 * Normalize memory domain.
	 *
	 * @param mixed $value Raw value.
	 */
	private function memory_domain( mixed $value ): string {
		$value = $this->key( $value, 40 );

		return in_array( $value, array( 'brand', 'site', 'content', 'developer', 'seo', 'workflow' ), true ) ? $value : 'content';
	}

	/**
	 * Normalize memory status.
	 *
	 * @param mixed $value Raw value.
	 */
	private function memory_status( mixed $value ): string {
		$value = $this->key( $value, 20 );

		if ( '' === $value ) {
			return '';
		}

		return in_array( $value, array( 'approved', 'pending', 'dismissed' ), true ) ? $value : 'pending';
	}

	/**
	 * Normalize confidence level.
	 *
	 * @param mixed $value Raw value.
	 */
	private function confidence( mixed $value ): string {
		$value = $this->key( $value, 20 );

		return in_array( $value, array( 'low', 'medium', 'high' ), true ) ? $value : 'medium';
	}

	/**
	 * Build normalized fields for one job progress update.
	 *
	 * @param array<string, mixed> $data Job data.
	 * @return array{data: array<string, int|string>, formats: list<string>}
	 */
	private function job_update_values( array $data ): array {
		$result = isset( $data['result'] ) && is_array( $data['result'] ) ? $data['result'] : array();
		$json   = wp_json_encode( $result );

		return array(
			'data'    => array(
				'status'          => $this->job_status( $data['status'] ?? 'complete' ),
				'processed_items' => absint( $data['processed_items'] ?? 0 ),
				'error_count'     => absint( $data['error_count'] ?? 0 ),
				'result'          => false === $json ? '{}' : $json,
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
			),
			'formats' => array( '%s', '%d', '%d', '%s', '%s' ),
		);
	}

	/**
	 * Normalize a private lease token.
	 *
	 * @param string $value Raw token.
	 */
	private function lease_token( string $value ): string {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : '';
	}

	/**
	 * Normalize job status.
	 *
	 * @param mixed $value Raw value.
	 */
	private function job_status( mixed $value ): string {
		$value = $this->key( $value, 20 );

		return in_array( $value, array( 'queued', 'running', 'stale', 'complete', 'failed', 'partial' ), true ) ? $value : 'complete';
	}

	/**
	 * Convert an expired running row to a public stale status.
	 *
	 * @param array<string, mixed> $row Job row.
	 */
	private function public_job_status( array $row ): string {
		$status = $this->job_status( $row['status'] ?? '' );
		if ( 'running' !== $status ) {
			return $status;
		}

		$updated_at = strtotime( (string) ( $row['updated_at'] ?? '' ) . ' UTC' );

		return false !== $updated_at && $updated_at < time() - self::DEFAULT_JOB_LEASE_TTL ? 'stale' : 'running';
	}

	/**
	 * Return a bounded text snippet.
	 *
	 * @param string $text  Raw text.
	 * @param int    $limit Max chars.
	 */
	private function snippet( string $text, int $limit ): string {
		$text = preg_replace( '/\s+/', ' ', trim( $text ) ) ?? '';

		return strlen( $text ) > $limit ? substr( $text, 0, $limit - 3 ) . '...' : $text;
	}
}
