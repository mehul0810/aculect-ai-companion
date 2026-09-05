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
	public const HOOK     = 'aculect_ai_companion_memory_schema_backfill';

	private const BATCH_SIZE               = 100;
	private const MIGRATION_VERSION        = '2026.09.05.2';
	private const OPTION_MIGRATION_VERSION = 'aculect_ai_companion_memory_migration_version';
	private const WORKER_LOCK              = 'aculect_ai_companion_memory_migration';
	private const OPTION_BLOCKED           = 'aculect_ai_companion_memory_migration_blocked';
	private const MAX_ONLINE_ALTER_BYTES   = 67108864;
	private const INDEXES                  = array(
		'memory_items'  => array( 'memory_key', 'memory_uuid', 'namespace_status_updated', 'expires_at', 'memory_search' ),
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
	 *
	 * @param bool $include_deferred Whether background-only indexes must be ready.
	 */
	public static function has_required_indexes( bool $include_deferred = true ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort

		$tables = array(
			'memory_items'  => Installer::memory_items_table(),
			'memory_events' => Installer::memory_events_table(),
			'memory_sync'   => Installer::memory_sync_state_table(),
		);
		foreach ( $tables as $key => $table ) {
			$rows     = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
			$names    = array_values( array_unique( array_map( 'strval', array_column( is_array( $rows ) ? $rows : array(), 'Key_name' ) ) ) );
			$required = self::INDEXES[ $key ];
			if ( ! $include_deferred && 'memory_items' === $key ) {
				$required = array_diff( $required, array( 'memory_search' ) );
			}
			if ( array_diff( $required, $names ) ) {
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

	/**
	 * Schedule a bounded background backfill when legacy rows remain.
	 *
	 * @param int $delay Delay before the scheduled batch in seconds.
	 */
	public static function ensure_scheduled( int $delay = 30 ): bool {
		if ( self::is_complete() || ! function_exists( 'wp_schedule_single_event' ) ) {
			return self::is_complete();
		}

		if ( function_exists( 'wp_next_scheduled' ) && false !== wp_next_scheduled( self::HOOK ) ) {
			return true;
		}

		$scheduled = wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK, array(), true );

		return false !== $scheduled && ! is_wp_error( $scheduled );
	}

	/**
	 * Process one background batch and arrange continuation when required.
	 */
	public static function run_scheduled_batch(): void {
		if ( self::is_complete() ) {
			return;
		}
		$lease = self::acquire_lease();
		if ( '' === $lease ) {
			return;
		}

		try {
			$storage = self::ensure_transactional_table();
			if ( self::COMPLETE !== $storage ) {
				self::ensure_scheduled( self::FAILED === $storage ? 300 : 30 );
				return;
			}
			$index = self::ensure_search_index();
			if ( self::COMPLETE !== $index ) {
				self::ensure_scheduled( self::FAILED === $index ? 300 : 30 );
				return;
			}

			$result = self::backfill();
			if ( self::COMPLETE === $result ) {
				update_option( self::OPTION_MIGRATION_VERSION, self::MIGRATION_VERSION, false );
				return;
			}

			self::ensure_scheduled( self::FAILED === $result ? 300 : 30 );
		} finally {
			self::release_lease( $lease );
		}
	}

	/**
	 * Whether the current memory data migration has completed.
	 */
	public static function is_complete(): bool {
		return version_compare(
			(string) get_option( self::OPTION_MIGRATION_VERSION, '0' ),
			self::MIGRATION_VERSION,
			'>='
		);
	}

	/**
	 * Convert at most one legacy memory table per worker run.
	 */
	private static function ensure_transactional_table(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort

		if ( ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'query' ) ) {
			return self::FAILED;
		}

		$tables = array(
			Installer::memory_items_table(),
			Installer::memory_events_table(),
			Installer::memory_sync_state_table(),
		);
		foreach ( $tables as $table ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT TABLE_NAME AS Name, ENGINE AS Engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1', $table ), ARRAY_A );
			if ( ! is_array( $row ) || (string) ( $row['Name'] ?? '' ) !== $table || '' === (string) ( $row['Engine'] ?? '' ) ) {
				return self::FAILED;
			}
			if ( 'innodb' === strtolower( (string) $row['Engine'] ) ) {
				continue;
			}
			if ( ! self::can_alter_table( $table, 'engine_conversion' ) ) {
				return self::FAILED;
			}

			$sql = $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table );
			if ( ! is_string( $sql ) ) {
				return self::FAILED;
			}
			$converted = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above after validating the return type.
			return false === $converted ? self::FAILED : self::PENDING;
		}

		return self::COMPLETE;
	}

	/** Acquire a connection-owned database lock that cannot expire mid-query. */
	private static function acquire_lease(): string {
		global $wpdb;
		/** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		if ( ! method_exists( $wpdb, 'get_var' ) ) {
			return '';
		}
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::WORKER_LOCK ) );
		return '1' === (string) $locked ? self::WORKER_LOCK : '';
	}

	/**
	 * Release a lease only when this worker still owns it.
	 *
	 * @param string $lease Lease owner token.
	 */
	private static function release_lease( string $lease ): void {
		global $wpdb;
		/** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		if ( self::WORKER_LOCK === $lease && method_exists( $wpdb, 'get_var' ) ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::WORKER_LOCK ) );
		}
	}

	/** Add the heavyweight full-text index only from the serialized worker. */
	private static function ensure_search_index(): string {
		global $wpdb;
		$table = Installer::memory_items_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'memory_search' ), ARRAY_A );
		if ( is_array( $rows ) && array() !== $rows ) {
			return self::COMPLETE;
		}
		if ( ! self::can_alter_table( $table, 'fulltext_index' ) ) {
			return self::FAILED;
		}
		$sql = $wpdb->prepare( 'ALTER TABLE %i ADD FULLTEXT KEY memory_search (memory_key, value, evidence)', $table );
		if ( ! is_string( $sql ) ) {
			return self::FAILED;
		}
		$created = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above after validating the return type.
		return false === $created ? self::FAILED : self::PENDING;
	}

	/**
	 * Refuse unbounded online schema work unless a site operator opts in.
	 *
	 * @param string $table     Exact table name.
	 * @param string $operation Migration operation identifier.
	 */
	private static function can_alter_table( string $table, string $operation ): bool {
		global $wpdb;
		/** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort
		$measured = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
		// @phpstan-ignore-next-line -- Runtime wpdb and test doubles may return false despite the narrower core PHPDoc.
		$measurement_failed = false === $measured || null === $measured || ! is_numeric( $measured ) || ( property_exists( $wpdb, 'last_error' ) && '' !== (string) $wpdb->last_error );
		if ( $measurement_failed ) {
			$override = (bool) apply_filters( 'aculect_ai_companion_allow_large_memory_migration', false, $operation, $table, null );
			if ( ! $override ) {
				update_option(
					self::OPTION_BLOCKED,
					array(
						'operation' => $operation,
						'table'     => $table,
						'reason'    => 'size_unknown',
					),
					false
				);
				return false;
			}
			delete_option( self::OPTION_BLOCKED );
			return true;
		}
		$bytes   = (int) $measured;
		$allowed = $bytes <= self::MAX_ONLINE_ALTER_BYTES || (bool) apply_filters( 'aculect_ai_companion_allow_large_memory_migration', false, $operation, $table, $bytes );
		if ( ! $allowed ) {
			update_option(
				self::OPTION_BLOCKED,
				array(
					'operation' => $operation,
					'table'     => $table,
					'bytes'     => $bytes,
				),
				false
			);
			return false;
		}
		delete_option( self::OPTION_BLOCKED );
		return true;
	}

	/**
	 * Return support-safe details when a large migration needs maintenance approval.
	 *
	 * @return array<string, mixed>
	 */
	public static function blocked_status(): array {
		$status = get_option( self::OPTION_BLOCKED, array() );
		return is_array( $status ) ? $status : array();
	}
}
