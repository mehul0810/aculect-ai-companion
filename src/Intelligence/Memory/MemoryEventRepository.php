<?php
/**
 * Append-only Aculect Memory event storage.
 *
 * @package Aculect\AICompanion\Intelligence\Memory
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Memory;

use Aculect\AICompanion\Intelligence\Database\Installer;

/**
 * Persists bounded history and cursor-based change feeds.
 */
final class MemoryEventRepository {

	private const MAX_LIMIT = 100;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned append-only event table.

	/**
	 * Append one immutable event.
	 *
	 * @param array<string, mixed> $event Event data.
	 */
	public function append( array $event ): bool {
		global $wpdb;

		$payload     = wp_json_encode( is_array( $event['payload'] ?? null ) ? $event['payload'] : array() );
		$payload     = false === $payload ? '{}' : $payload;
		$memory_uuid = $this->uuid( $event['memory_uuid'] ?? '' );
		$event_uuid  = $this->uuid( $event['event_uuid'] ?? '' );
		$event_uuid  = '' === $event_uuid ? $this->generate_uuid() : $event_uuid;
		$memory_uuid = '' === $memory_uuid ? $this->generate_uuid() : $memory_uuid;
		$connection  = substr( sanitize_key( (string) ( $event['connection_id'] ?? '' ) ), 0, 191 );
		$event_type  = substr( sanitize_key( (string) ( $event['event_type'] ?? 'updated' ) ), 0, 32 );
		$namespace   = $this->namespace( $event['namespace'] ?? 'site' );

		return false !== $wpdb->insert(
			Installer::memory_events_table(),
			array(
				'event_uuid'     => $event_uuid,
				'memory_uuid'    => $memory_uuid,
				'namespace'      => $namespace,
				'event_type'     => '' === $event_type ? 'updated' : $event_type,
				'memory_version' => max( 1, absint( $event['memory_version'] ?? 1 ) ),
				'actor_user_id'  => absint( $event['actor_user_id'] ?? get_current_user_id() ),
				'connection_id'  => $connection,
				'payload'        => $payload,
				'payload_hash'   => hash( 'sha256', $payload ),
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Return ordered changes after an opaque numeric cursor.
	 *
	 * @param array<string, mixed> $args Feed arguments.
	 * @return array{items:list<array<string, mixed>>,cursor:int,has_more:bool}
	 */
	public function changes( array $args = array() ): array {
		global $wpdb;

		$cursor    = max( 0, absint( $args['cursor'] ?? 0 ) );
		$limit     = min( self::MAX_LIMIT, max( 1, absint( $args['limit'] ?? 25 ) ) );
		$namespace = $this->namespace( $args['namespace'] ?? 'site' );
		$rows      = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, event_uuid, memory_uuid, namespace, event_type, memory_version, payload_hash, created_at FROM %i WHERE namespace = %s AND id > %d ORDER BY id ASC LIMIT %d',
				Installer::memory_events_table(),
				$namespace,
				$cursor,
				$limit + 1
			),
			ARRAY_A
		);
		$rows      = array_values( array_filter( is_array( $rows ) ? $rows : array(), 'is_array' ) );
		$has_more  = count( $rows ) > $limit;
		$rows      = array_slice( $rows, 0, $limit );
		$next      = array() === $rows ? $cursor : (int) ( end( $rows )['id'] ?? $cursor );

		return array(
			'items'    => $rows,
			'cursor'   => $next,
			'has_more' => $has_more,
		);
	}

	/**
	 * Return bounded history for one memory UUID.
	 *
	 * @param string $memory_uuid Stable memory UUID.
	 * @param string $namespace   Site-owned namespace.
	 * @param int    $limit       Maximum events.
	 * @return list<array<string, mixed>>
	 */
	public function history( string $memory_uuid, string $namespace = 'site', int $limit = 25 ): array {
		global $wpdb;

		$memory_uuid = $this->uuid( $memory_uuid );
		$namespace   = $this->namespace( $namespace );
		if ( '' === $memory_uuid ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, event_uuid, memory_uuid, namespace, event_type, memory_version, payload_hash, created_at FROM %i WHERE memory_uuid = %s AND namespace = %s ORDER BY id DESC LIMIT %d',
				Installer::memory_events_table(),
				$memory_uuid,
				$namespace,
				min( self::MAX_LIMIT, max( 1, $limit ) )
			),
			ARRAY_A
		);

		return array_values( array_filter( is_array( $rows ) ? $rows : array(), 'is_array' ) );
	}

	private function namespace( mixed $value ): string {
		$value = preg_replace( '/[^a-zA-Z0-9:_\-.]/', '_', is_scalar( $value ) ? (string) $value : '' ) ?? '';
		return substr( '' === trim( $value, '_-.' ) ? 'site' : trim( $value, '_-.' ), 0, 191 );
	}

	private function uuid( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value ) ? $value : '';
	}

	private function generate_uuid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}
}
