<?php
/**
 * Bounded administration queries for durable memory.
 *
 * @package Aculect\AICompanion\Intelligence\Memory
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Memory;

use Aculect\AICompanion\Intelligence\Database\Installer;

/**
 * Keeps admin paging and aggregate counts out of the general index repository.
 */
final class MemoryAdminQuery {
	private const PER_PAGE = 20;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned memory table.

	/**
	 * Return one admin page with database-wide status totals.
	 *
	 * @param int $page Requested page number.
	 * @return array<string, mixed>
	 */
	public function page( int $page = 1 ): array {
		global $wpdb;

		$page    = max( 1, $page );
		$offset  = ( $page - 1 ) * self::PER_PAGE;
		$table   = Installer::memory_items_table();
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d',
				$table,
				self::PER_PAGE,
				$offset
			),
			ARRAY_A
		);
		$counts  = $wpdb->get_results(
			$wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i GROUP BY status', $table ),
			ARRAY_A
		);
		$summary = array(
			'total'     => 0,
			'approved'  => 0,
			'pending'   => 0,
			'dismissed' => 0,
		);
		foreach ( is_array( $counts ) ? $counts : array() as $count ) {
			$status = sanitize_key( (string) ( $count['status'] ?? '' ) );
			$total  = max( 0, (int) ( $count['total'] ?? 0 ) );
			if ( array_key_exists( $status, $summary ) ) {
				$summary[ $status ] = $total;
			}
			$summary['total'] += $total;
		}

		return array(
			'items'       => array_map( array( $this, 'record' ), array_values( array_filter( is_array( $rows ) ? $rows : array(), 'is_array' ) ) ),
			'total'       => $summary['total'],
			'page'        => $page,
			'per_page'    => self::PER_PAGE,
			'total_pages' => max( 1, (int) ceil( $summary['total'] / self::PER_PAGE ) ),
			'context'     => 'compact',
			'summary'     => $summary,
		);
	}

	/**
	 * Return the fields used by the memory review cards.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private function record( array $row ): array {
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
}
