<?php
/**
 * Versioned Aculect Memory persistence.
 *
 * @package Aculect\AICompanion\Intelligence\Memory
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Memory;

use Aculect\AICompanion\Intelligence\Database\Installer;

/**
 * Owns bounded SQL operations for the canonical memory table.
 */
final class MemoryRepository {

	private const COLUMNS   = 'id, memory_key, memory_uuid, namespace, owner_user_id, domain, value, evidence, confidence, status, visibility, sensitivity, version, content_hash, source, valid_from, expires_at, deleted_at, created_at, updated_at';
	private const MAX_LIMIT = 50;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned canonical memory table.

	/**
	 * Find one record by stable key and namespace.
	 *
	 * @param string $key             Stable memory key.
	 * @param string $namespace       Site-owned namespace.
	 * @param bool   $include_deleted Whether tombstones may be returned.
	 * @return array<string, mixed>
	 */
	public function find( string $key, string $namespace = 'site', bool $include_deleted = false ): array {
		$key       = $this->key( $key );
		$namespace = $this->namespace( $namespace );
		if ( '' === $key ) {
			return array();
		}

		$row = $this->find_row( $key, $include_deleted );

		if ( array() === $row || $namespace !== $this->namespace( $row['namespace'] ?? 'site' ) ) {
			return array();
		}

		return $this->public_record( $row );
	}

	/**
	 * Save a record with optional optimistic version enforcement.
	 *
	 * @param array<string, mixed> $input Memory input.
	 * @return array<string, mixed>
	 */
	public function save( array $input ): array {
		global $wpdb;

		$key       = $this->key( $input['key'] ?? $input['memory_key'] ?? '' );
		$namespace = $this->namespace( $input['namespace'] ?? 'site' );
		if ( '' === $key || '' === trim( is_scalar( $input['value'] ?? null ) ? (string) $input['value'] : '' ) ) {
			return $this->error( 'invalid_memory', 'Provide a stable key and non-empty value.' );
		}

		$existing = $this->find_row( $key, true );
		if ( array() !== $existing && $namespace !== $this->namespace( $existing['namespace'] ?? 'site' ) ) {
			return $this->error( 'memory_namespace_conflict', 'Memory keys are site-global and already belong to another namespace.' );
		}
		$current  = array() === $existing ? 0 : max( 1, absint( $existing['version'] ?? 1 ) );
		$expected = isset( $input['expected_version'] ) ? absint( $input['expected_version'] ) : null;
		if ( null !== $expected && $expected !== $current ) {
			return $this->conflict( $current );
		}

		$input['memory_key'] = $key;
		$input['namespace']  = $namespace;
		$data                = ( new MemoryRecord() )->normalize( $input, array() === $existing ? null : $existing );
		$formats             = $this->formats();
		if ( array() === $existing ) {
			$data['created_at'] = gmdate( 'Y-m-d H:i:s' );
			$formats[]          = '%s';
			$written            = $wpdb->insert( Installer::memory_items_table(), $data, $formats );
		} else {
			$written = $wpdb->update(
				Installer::memory_items_table(),
				$data,
				array(
					'id'      => (int) $existing['id'],
					'version' => $current,
				),
				$formats,
				array( '%d', '%d' )
			);
		}

		if ( false === $written ) {
			return $this->error( 'memory_save_failed', 'Memory item could not be saved.' );
		}
		if ( 0 === $written && array() !== $existing ) {
			return $this->conflict( $current );
		}

		return array(
			'status'  => 'success',
			'memory'  => $this->find( $key, $namespace, true ),
			'message' => 'Memory item saved for future Aculect Intelligence responses.',
		);
	}

	/**
	 * Create a tombstone using the caller's observed version.
	 *
	 * @param string   $key              Stable memory key.
	 * @param string   $namespace        Site-owned namespace.
	 * @param int|null $expected_version Version observed by the caller.
	 * @return array<string, mixed>
	 */
	public function forget( string $key, string $namespace, ?int $expected_version ): array {
		$current = $this->find( $key, $namespace );
		if ( array() === $current ) {
			return $this->error( 'memory_not_found', 'Memory item was not found.' );
		}

		return $this->save(
			array_merge(
				$current,
				array(
					'key'              => $key,
					'namespace'        => $namespace,
					'status'           => 'dismissed',
					'deleted_at'       => gmdate( 'Y-m-d H:i:s' ),
					'expected_version' => $expected_version ?? (int) $current['version'],
				)
			)
		);
	}

	/**
	 * Search approved, live records after indexed filters.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return list<array<string, mixed>>
	 */
	public function search( array $args = array() ): array {
		global $wpdb;

		$limit     = min( self::MAX_LIMIT, max( 1, absint( $args['limit'] ?? 10 ) ) );
		$namespace = $this->namespace( $args['namespace'] ?? 'site' );
		$status    = sanitize_key( (string) ( $args['status'] ?? 'approved' ) );
		$status    = in_array( $status, array( 'approved', 'pending', 'dismissed' ), true ) ? $status : 'approved';
		$query     = strtolower( sanitize_text_field( is_scalar( $args['query'] ?? null ) ? (string) $args['query'] : '' ) );
		$values    = array( Installer::memory_items_table(), $namespace, $status, gmdate( 'Y-m-d H:i:s' ) );
		$values[]  = 200;

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Values are supplied through an unpacked, fixed-order list.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Column list is fixed; values use placeholders.
				'SELECT ' . self::COLUMNS . " FROM %i WHERE namespace = %s AND status = %s AND visibility = 'site' AND sensitivity = 'normal' AND deleted_at IS NULL AND (expires_at IS NULL OR expires_at > %s) ORDER BY updated_at DESC, id DESC LIMIT %d",
				...$values
			),
			ARRAY_A
		);

		$rows = array_values( array_filter( is_array( $rows ) ? $rows : array(), 'is_array' ) );
		if ( '' !== $query ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn ( array $row ): bool => str_contains(
						strtolower( (string) ( $row['memory_key'] ?? '' ) . ' ' . (string) ( $row['value'] ?? '' ) . ' ' . (string) ( $row['evidence'] ?? '' ) ),
						$query
					)
				)
			);
		}

		return array_map( array( $this, 'public_record' ), array_slice( $rows, 0, $limit ) );
	}

	/**
	 * Return a compatibility-safe public memory projection.
	 *
	 * @param array<string, mixed> $row Persistence row.
	 * @return array<string, mixed>
	 */
	private function public_record( array $row ): array {
		return array(
			'id'           => (int) ( $row['id'] ?? 0 ),
			'key'          => (string) ( $row['memory_key'] ?? '' ),
			'memory_key'   => (string) ( $row['memory_key'] ?? '' ),
			'uuid'         => (string) ( $row['memory_uuid'] ?? '' ),
			'memory_uuid'  => (string) ( $row['memory_uuid'] ?? '' ),
			'namespace'    => (string) ( $row['namespace'] ?? 'site' ),
			'domain'       => (string) ( $row['domain'] ?? '' ),
			'value'        => (string) ( $row['value'] ?? '' ),
			'evidence'     => (string) ( $row['evidence'] ?? '' ),
			'confidence'   => (string) ( $row['confidence'] ?? '' ),
			'status'       => (string) ( $row['status'] ?? '' ),
			'visibility'   => (string) ( $row['visibility'] ?? 'private' ),
			'sensitivity'  => (string) ( $row['sensitivity'] ?? 'normal' ),
			'version'      => max( 1, (int) ( $row['version'] ?? 1 ) ),
			'content_hash' => (string) ( $row['content_hash'] ?? '' ),
			'source'       => (string) ( $row['source'] ?? '' ),
			'valid_from'   => (string) ( $row['valid_from'] ?? '' ),
			'expires_at'   => (string) ( $row['expires_at'] ?? '' ),
			'deleted_at'   => (string) ( $row['deleted_at'] ?? '' ),
			'created_at'   => (string) ( $row['created_at'] ?? '' ),
			'updated_at'   => (string) ( $row['updated_at'] ?? '' ),
		);
	}

	/**
	 * Find an internal persistence row by the site-global key.
	 *
	 * @param string $key             Normalized memory key.
	 * @param bool   $include_deleted Whether tombstones may be returned.
	 * @return array<string, mixed>
	 */
	private function find_row( string $key, bool $include_deleted ): array {
		global $wpdb;

		$deleted = $include_deleted ? '' : ' AND deleted_at IS NULL';
		$row     = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Column list and deletion clause are fixed constants; values use placeholders.
				'SELECT ' . self::COLUMNS . " FROM %i WHERE memory_key = %s{$deleted} LIMIT 1",
				Installer::memory_items_table(),
				$key
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Return the persistence formats in record order.
	 *
	 * @return list<string>
	 */
	private function formats(): array {
		return array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' );
	}

	private function key( mixed $value ): string {
		$value = preg_replace( '/[^a-z0-9:_\-.]+/', '_', strtolower( is_scalar( $value ) ? (string) $value : '' ) ) ?? '';
		return substr( trim( $value, '_-.' ), 0, 120 );
	}

	private function namespace( mixed $value ): string {
		$value = preg_replace( '/[^a-zA-Z0-9:_\-.]/', '_', is_scalar( $value ) ? (string) $value : '' ) ?? '';
		return substr( '' === trim( $value, '_-.' ) ? 'site' : trim( $value, '_-.' ), 0, 191 );
	}

	/**
	 * Build a bounded error result.
	 *
	 * @param string $code    Machine-readable code.
	 * @param string $message Human-readable message.
	 * @return array{status:string,error:string,message:string}
	 */
	private function error( string $code, string $message ): array {
		return array(
			'status'  => 'error',
			'error'   => $code,
			'message' => $message,
		);
	}

	/**
	 * Build an optimistic-concurrency conflict result.
	 *
	 * @param int $version Current stored version.
	 * @return array{status:string,error:string,message:string,current_version:int}
	 */
	private function conflict( int $version ): array {
		return array(
			'status'          => 'error',
			'error'           => 'memory_version_conflict',
			'message'         => 'Memory changed after it was read.',
			'current_version' => $version,
		);
	}
}
