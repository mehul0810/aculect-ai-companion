<?php
/**
 * Bounded snapshot and delta feed for site-owned memory replicas.
 *
 * @package Aculect\AICompanion\Intelligence\Memory\Sync
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Memory\Sync;

use Aculect\AICompanion\Intelligence\Database\Installer;
use Aculect\AICompanion\Intelligence\Memory\MemoryRecord;
use RuntimeException;

/** Sends current eligible records, or content-free invalidations, never historical private payloads. */
final class MemorySyncFeed {
	/**
	 * Read a bounded page. A snapshot fences the event log before scanning records.
	 *
	 * @param string $namespace Site-owned namespace.
	 * @param string $cursor Opaque continuation.
	 * @param int    $limit Maximum rows.
	 * @return array{items:list<array<string,mixed>>,cursor:string,has_more:bool}
	 * @throws RuntimeException For invalid cursors or database failures.
	 */
	public function page( string $namespace, string $cursor, int $limit ): array {
		global $wpdb;
		$position = $this->position( $namespace, $cursor );
		$limit    = max( 1, min( 20, $limit ) );
		$items    = Installer::memory_items_table();
		if ( 'snapshot' === $position['mode'] ) {
			$sql = $wpdb->prepare( 'SELECT id AS sequence, memory_uuid, memory_key, namespace, value, domain, status, visibility, sensitivity, version, content_hash, valid_from, expires_at, deleted_at FROM %i WHERE namespace = %s AND id > %d AND id <= %d ORDER BY id ASC LIMIT %d', $items, $namespace, $position['after'], $position['upper'], $limit + 1 );
		} else {
			$sql = $wpdb->prepare( 'SELECT e.id AS sequence, e.memory_uuid, m.memory_key, e.namespace, m.value, m.domain, m.status, m.visibility, m.sensitivity, m.version, m.content_hash, m.valid_from, m.expires_at, m.deleted_at FROM %i e LEFT JOIN %i m ON m.memory_uuid = e.memory_uuid AND m.namespace = e.namespace WHERE e.namespace = %s AND e.id > %d ORDER BY e.id ASC LIMIT %d', Installer::memory_events_table(), $items, $namespace, $position['after'], $limit + 1 );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above with fixed identifiers and values.
		if ( ! is_array( $rows ) || $this->database_failed() ) {
			throw new RuntimeException( 'memory_sync_read_failed' );
		}
		$more   = count( $rows ) > $limit;
		$rows   = array_slice( $rows, 0, $limit );
		$output = array();
		foreach ( $rows as $row ) {
			$position['after'] = (int) $row['sequence'];
			$eligible          = ( new MemoryRecord() )->can_sync( $row );
			$change            = array(
				'id'        => (string) $row['memory_uuid'],
				'operation' => $eligible ? 'upsert' : 'remove',
			);
			if ( $eligible ) {
				$change['memory'] = array_intersect_key( $row, array_flip( array( 'memory_uuid', 'memory_key', 'namespace', 'value', 'domain', 'version', 'content_hash', 'valid_from', 'expires_at' ) ) );
			}
			$output[] = $change;
		}
		if ( ! $more && 'snapshot' === $position['mode'] ) {
			$position['mode']  = 'events';
			$position['after'] = $position['events'];
			$more              = true;
		}
		return array(
			'items'    => $output,
			'cursor'   => $this->encode( $position ),
			'has_more' => $more,
		);
	}

	/**
	 * Validate a namespace-bound short-lived cursor or begin a snapshot.
	 *
	 * @param string $namespace Namespace.
	 * @param string $cursor Cursor.
	 * @return array{mode:string,after:int,upper:int,events:int,namespace:string,started:int}
	 * @throws RuntimeException When the cursor is malformed or expired.
	 */
	private function position( string $namespace, string $cursor ): array {
		global $wpdb;
		if ( '' !== $cursor ) {
			if ( strlen( $cursor ) > 1024 ) {
				throw new RuntimeException( 'invalid_sync_cursor' );
			}
			$decoded = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Non-secret pagination data.
			$value   = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
			if ( ! is_array( $value ) || ( $value['namespace'] ?? null ) !== $namespace || ! in_array( $value['mode'] ?? null, array( 'snapshot', 'events' ), true ) ) {
				throw new RuntimeException( 'invalid_sync_cursor' );
			}
			foreach ( array( 'after', 'upper', 'events', 'started' ) as $key ) {
				if ( ! isset( $value[ $key ] ) || ! is_int( $value[ $key ] ) || $value[ $key ] < 0 ) {
					throw new RuntimeException( 'invalid_sync_cursor' );
				}
			}
			if ( $value['started'] > time() || time() - $value['started'] >= 300 ) {
				throw new RuntimeException( 'sync_snapshot_refresh_required' );
			}
			return array(
				'mode'      => (string) $value['mode'],
				'after'     => (int) $value['after'],
				'upper'     => (int) $value['upper'],
				'events'    => (int) $value['events'],
				'namespace' => $namespace,
				'started'   => (int) $value['started'],
			);
		}
		$events = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i WHERE namespace = %s', Installer::memory_events_table(), $namespace ) );
		if ( ! is_numeric( $events ) || $this->database_failed() ) {
			throw new RuntimeException( 'memory_sync_read_failed' );
		}
		$upper = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i WHERE namespace = %s', Installer::memory_items_table(), $namespace ) );
		if ( ! is_numeric( $upper ) || $this->database_failed() ) {
			throw new RuntimeException( 'memory_sync_read_failed' );
		}
		return array(
			'mode'      => 'snapshot',
			'after'     => 0,
			'upper'     => (int) $upper,
			'events'    => (int) $events,
			'namespace' => $namespace,
			'started'   => time(),
		);
	}

	/**
	 * Check the last query rather than retaining a pre-query error state.
	 *
	 * @phpstan-impure
	 */
	private function database_failed(): bool {
		global $wpdb;
		return property_exists( $wpdb, 'last_error' ) && '' !== (string) $wpdb->last_error;
	}

	/**
	 * Encode a validated position, not an authorization token.
	 *
	 * @param array<string,mixed> $position Position.
	 */
	private function encode( array $position ): string {
		return base64_encode( (string) wp_json_encode( $position ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Non-secret pagination data.
	}
}
