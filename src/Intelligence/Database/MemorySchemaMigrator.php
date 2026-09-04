<?php
/**
 * Bounded migrations for the durable Aculect Memory schema.
 *
 * @package Aculect\AICompanion\Intelligence\Database
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Database;

/**
 * Keeps memory-specific migration work out of the general installer hot path.
 */
final class MemorySchemaMigrator {
	public const COMPLETE = 'complete';
	public const PENDING  = 'pending';
	public const FAILED   = 'failed';

	private const BATCH_SIZE = 100;
	private const INDEXES    = array(
		'memory_items'  => array( 'memory_key', 'memory_uuid', 'namespace_status_updated', 'expires_at' ),
		'memory_events' => array( 'event_uuid', 'memory_version', 'namespace_cursor' ),
		'memory_sync'   => array( 'connector_namespace_external', 'connector_namespace_status', 'memory_uuid' ),
	);

	/**
	 * Return columns required by the versioned memory contract.
	 *
	 * @return list<string>
	 */
	public static function required_columns(): array {
		return array(
			'id',
			'memory_key',
			'memory_uuid',
			'namespace',
			'owner_user_id',
			'domain',
			'value',
			'evidence',
			'confidence',
			'status',
			'visibility',
			'sensitivity',
			'version',
			'content_hash',
			'source',
			'valid_from',
			'expires_at',
			'deleted_at',
			'created_at',
			'updated_at',
		);
	}

	/**
	 * Verify indexes that protect identity and bounded read paths.
	 */
	public static function has_required_indexes(): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort

		$tables = array(
			'memory_items'  => Installer::memory_items_table(),
			'memory_events' => Installer::memory_events_table(),
			'memory_sync'   => Installer::memory_sync_state_table(),
		);
		foreach ( $tables as $key => $table ) {
			$rows  = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
			$names = array_values( array_unique( array_map( 'strval', array_column( is_array( $rows ) ? $rows : array(), 'Key_name' ) ) ) );
			if ( array_diff( self::INDEXES[ $key ], $names ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Backfill one bounded batch of legacy memory identities.
	 *
	 * Pending keeps the schema version stale; failed activates repair backoff.
	 */
	public static function backfill(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort

		if ( ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'update' ) ) {
			return self::COMPLETE;
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, memory_key, namespace, value, version FROM %i WHERE memory_uuid IS NULL OR memory_uuid = '' OR content_hash = '' ORDER BY id ASC LIMIT %d",
				Installer::memory_items_table(),
				self::BATCH_SIZE + 1
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || ( property_exists( $wpdb, 'last_error' ) && '' !== (string) $wpdb->last_error ) ) {
			return self::FAILED;
		}
		$rows = array_values( array_filter( $rows, 'is_array' ) );

		foreach ( array_slice( $rows, 0, self::BATCH_SIZE ) as $row ) {
			$namespace = '' === (string) ( $row['namespace'] ?? '' ) ? 'site' : (string) $row['namespace'];
			$key       = (string) ( $row['memory_key'] ?? '' );
			$value     = (string) ( $row['value'] ?? '' );
			$updated   = $wpdb->update(
				Installer::memory_items_table(),
				array(
					'memory_uuid'  => wp_generate_uuid4(),
					'namespace'    => $namespace,
					'content_hash' => hash( 'sha256', $namespace . "\n" . $key . "\n" . $value ),
					'version'      => max( 1, absint( $row['version'] ?? 1 ) ),
				),
				array( 'id' => absint( $row['id'] ?? 0 ) ),
				array( '%s', '%s', '%s', '%d' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				return self::FAILED;
			}
		}

		return count( $rows ) <= self::BATCH_SIZE ? self::COMPLETE : self::PENDING;
	}
}
