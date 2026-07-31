<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Database;

/**
 * Executes portable, bounded deletes against plugin-owned OAuth tables.
 *
 * MySQL permits DELETE statements with ORDER BY and LIMIT, while the supported
 * WP_SQLite_DB adapter does not. Selecting a bounded set of internal row IDs
 * first keeps the write portable and lets each repository recheck eligibility
 * when deleting, so concurrent state changes are not removed accidentally.
 */
final class BoundedPruner {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- OAuth protocol maintenance requires bounded, uncached access to plugin-owned tables.

	/**
	 * Select a bounded list of internal row IDs.
	 *
	 * @param string            $query Prepared-query template.
	 * @param array<int, mixed> $args  Prepared-query arguments.
	 * @param int               $limit Maximum IDs returned.
	 * @return int[]|false IDs on success, false on database failure.
	 */
	public static function candidate_ids( string $query, array $args, int $limit ): array|false {
		global $wpdb;

		self::clear_database_error();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Repositories supply fixed internal templates; all values are passed separately.
		$prepared_query = $wpdb->prepare( $query, ...$args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query and all arguments were prepared immediately above.
		$rows = $wpdb->get_results( $prepared_query, ARRAY_A );

		if ( ! is_array( $rows ) || self::database_query_failed() ) {
			return false;
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			$id = is_array( $row ) ? ( $row['id'] ?? 0 ) : 0;
			$id = absint( $id );
			if ( $id <= 0 ) {
				continue;
			}

			$normalized[ $id ] = $id;
			if ( count( $normalized ) >= $limit ) {
				break;
			}
		}

		return array_values( $normalized );
	}

	/**
	 * Delete selected IDs while rechecking their repository-owned eligibility.
	 *
	 * @param string            $table             Plugin-owned table name.
	 * @param int[]             $ids               Bounded internal row IDs.
	 * @param string            $eligibility_query SQL appended after the ID condition.
	 * @param array<int, mixed> $eligibility_args  Prepared-query arguments for eligibility.
	 * @return int|false Deleted rows on success, false on database failure.
	 */
	public static function delete_ids( string $table, array $ids, string $eligibility_query, array $eligibility_args ): int|false {
		global $wpdb;

		if ( array() === $ids ) {
			return 0;
		}

		$id_placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$query           = sprintf(
			'DELETE FROM %%i WHERE id IN ( %s ) AND %s',
			$id_placeholders,
			$eligibility_query
		);
		$args            = array_merge( array( $table ), $ids, $eligibility_args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The internal eligibility fragment contains placeholders whose values are passed separately.
		$prepared_query = $wpdb->prepare( $query, ...$args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query and all arguments were prepared immediately above.
		$result = $wpdb->query( $prepared_query );

		return false === $result ? false : (int) $result;
	}

	/**
	 * Clear a stale wpdb error before executing a candidate query.
	 */
	private static function clear_database_error(): void {
		global $wpdb;

		$wpdb->last_error = '';
	}

	/**
	 * Return whether the most recent wpdb query failed.
	 */
	private static function database_query_failed(): bool {
		global $wpdb;

		return '' !== trim( (string) $wpdb->last_error );
	}
}
