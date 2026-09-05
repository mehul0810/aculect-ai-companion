<?php
/**
 * Database schema for the local Aculect Intelligence index.
 *
 * @package Aculect\AICompanion\Intelligence\Database
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Database;

use Aculect\AICompanion\Intelligence\ContentIndexer;

/**
 * Owns the content index, chunk, link graph, memory, job, and cache tables.
 */
final class Installer {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Plugin-owned intelligence index tables require controlled schema changes.

	private const DB_VERSION        = '2026.09.05.2';
	private const OPTION_DB_VERSION = 'aculect_ai_companion_intelligence_db_version';

	/**
	 * Create or update all intelligence index tables.
	 *
	 * @param bool $verify_tables Whether to verify table existence even when the stored version is current.
	 * @return bool Whether every required intelligence table is available after installation.
	 */
	public static function install( bool $verify_tables = false ): bool {
		$installed    = (string) get_option( self::OPTION_DB_VERSION, '0' );
		$schema_stale = version_compare( $installed, self::DB_VERSION, '<' );
		if ( $schema_stale && ! InstallerRetryState::allows_attempt( $verify_tables ) ) {
			return false;
		}
		$missing = ( $verify_tables || $schema_stale ) ? self::missing_table_keys( false ) : array();

		if ( $schema_stale || array() !== $missing ) {
			try {
				self::create_tables( in_array( 'memory_items', $missing, true ) );
			} catch ( \Throwable ) {
				return self::installation_failed( $installed, array(), 'dbdelta_exception' );
			}

			$missing = self::missing_table_keys( false );
			if ( array() !== $missing ) {
				return self::installation_failed( $installed, $missing, 'tables_missing' );
			}
			if ( $schema_stale && ! update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false ) ) {
				return self::installation_failed( $installed, array(), 'version_write_failed' );
			}

			InstallerRetryState::clear();
		}

		MemorySchemaMigrator::ensure_scheduled();

		return true;
	}

	/**
	 * Activation entry point.
	 */
	public static function activate(): void {
		self::install( true );
	}

	/**
	 * Return the content index table name.
	 */
	public static function content_index_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_content_index';
	}

	/**
	 * Return the content chunk table name.
	 */
	public static function content_chunks_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_content_chunks';
	}

	/**
	 * Return the internal link graph table name.
	 */
	public static function link_graph_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_link_graph';
	}

	/**
	 * Return the durable memory item table name.
	 */
	public static function memory_items_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_memory_items';
	}

	/**
	 * Return the append-only memory event table name.
	 */
	public static function memory_events_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_memory_events';
	}

	/**
	 * Return the memory connector synchronization state table name.
	 */
	public static function memory_sync_state_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_memory_sync_state';
	}

	/**
	 * Return the intelligence job table name.
	 */
	public static function jobs_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_jobs';
	}

	/**
	 * Return the disposable intelligence cache table name.
	 */
	public static function cache_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'aculect_ai_cache';
	}

	/**
	 * Return every intelligence table name.
	 *
	 * @return list<string>
	 */
	public static function table_names(): array {
		return array(
			self::content_index_table(),
			self::content_chunks_table(),
			self::link_graph_table(),
			self::memory_items_table(),
			self::memory_events_table(),
			self::memory_sync_state_table(),
			self::jobs_table(),
			self::cache_table(),
		);
	}

	/**
	 * Return logical keys for intelligence tables that are not currently available.
	 *
	 * @param bool $include_deferred Whether background-only indexes must be ready.
	 * @return list<string>
	 */
	public static function missing_table_keys( bool $include_deferred = true ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort

		$tables  = array_combine(
			array( 'content_index', 'content_chunks', 'link_graph', 'memory_items', 'memory_events', 'memory_sync_state', 'jobs', 'cache' ),
			self::table_names()
		);
		$missing = array();

		foreach ( $tables as $key => $table ) {
			if ( $table !== (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				$missing[] = $key;
			}
		}

		if ( ! in_array( 'memory_items', $missing, true ) && method_exists( $wpdb, 'get_col' ) ) {
			$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', self::memory_items_table() ), 0 );
			if ( array_diff( MemorySchemaMigrator::required_columns(), array_map( 'strval', $columns ) ) ) {
				$missing[] = 'memory_schema';
			}
		}
		$memory_tables = array( 'memory_items', 'memory_events', 'memory_sync_state' );
		if ( array() === array_intersect( $memory_tables, $missing ) && method_exists( $wpdb, 'get_results' ) && ! MemorySchemaMigrator::has_required_indexes( $include_deferred ) ) {
			$missing[] = 'memory_schema';
		}

		return array_values( array_unique( $missing ) );
	}

	/**
	 * Return support-safe schema repair state.
	 *
	 * @return array{attempts:int,blocked:bool,last_failed_at:int,next_retry_at:int,missing_tables:list<string>,reason:string}
	 */
	public static function repair_status(): array {
		return InstallerRetryState::status();
	}

	/**
	 * Remove intelligence index storage and schema option.
	 */
	public static function uninstall(): void {
		global $wpdb;

		foreach ( self::table_names() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( self::OPTION_DB_VERSION );
		InstallerRetryState::clear();
		ContentIndexer::delete_options();
	}

	/**
	 * Create or reconcile intelligence tables through dbDelta().
	 *
	 * @param bool $fresh_memory Whether the memory table is new and empty.
	 */
	private static function create_tables( bool $fresh_memory = false ): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$content_index = self::content_index_table();
		$chunks        = self::content_chunks_table();
		$links         = self::link_graph_table();
		$memories      = self::memory_items_table();
		$memory_events = self::memory_events_table();
		$memory_sync   = self::memory_sync_state_table();
		$jobs          = self::jobs_table();
		$cache         = self::cache_table();
		$memory_search = $fresh_memory ? ', FULLTEXT KEY memory_search (memory_key, value, evidence)' : '';

		$sql = array(
			"CREATE TABLE {$content_index} (
            object_id bigint(20) unsigned NOT NULL,
            object_type varchar(20) NOT NULL DEFAULT 'post',
            post_type varchar(60) NOT NULL,
            post_status varchar(20) NOT NULL,
            title text DEFAULT NULL,
            slug varchar(200) DEFAULT NULL,
            permalink text DEFAULT NULL,
            excerpt text DEFAULT NULL,
            summary text DEFAULT NULL,
            word_count int(10) unsigned NOT NULL DEFAULT 0,
            content_hash char(64) NOT NULL DEFAULT '',
            indexed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            modified_gmt datetime DEFAULT NULL,
            stale tinyint(1) unsigned NOT NULL DEFAULT 0,
            search_text longtext DEFAULT NULL,
            metadata longtext DEFAULT NULL,
            PRIMARY KEY  (object_id),
            KEY object_type (object_type),
            KEY post_type_status (post_type, post_status),
            KEY content_hash (content_hash),
            KEY stale (stale),
            KEY indexed_at (indexed_at),
            FULLTEXT KEY content_search (title, summary, search_text)
        ) {$charset};",
			"CREATE TABLE {$chunks} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            object_id bigint(20) unsigned NOT NULL,
            chunk_id varchar(120) NOT NULL,
            heading text DEFAULT NULL,
            anchor varchar(200) DEFAULT NULL,
            section_index int(10) unsigned NOT NULL DEFAULT 0,
            word_count int(10) unsigned NOT NULL DEFAULT 0,
            content_hash char(64) NOT NULL DEFAULT '',
            block_start int(10) unsigned NOT NULL DEFAULT 0,
            block_count int(10) unsigned NOT NULL DEFAULT 0,
            text longtext DEFAULT NULL,
            block_markup longtext DEFAULT NULL,
            metadata longtext DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY object_chunk (object_id, chunk_id),
            KEY object_id (object_id),
            KEY anchor (anchor),
            KEY content_hash (content_hash),
            KEY word_count (word_count),
            FULLTEXT KEY chunk_search (heading, text)
        ) {$charset};",
			"CREATE TABLE {$links} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_id bigint(20) unsigned NOT NULL,
            target_id bigint(20) unsigned DEFAULT NULL,
            target_url text DEFAULT NULL,
            anchor_text varchar(255) DEFAULT NULL,
            rel varchar(80) DEFAULT NULL,
            context text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY source_id (source_id),
            KEY target_id (target_id),
            KEY created_at (created_at)
        ) {$charset};",
			"CREATE TABLE {$memories} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            memory_key varchar(120) NOT NULL,
			memory_uuid char(36) DEFAULT NULL,
			namespace varchar(191) NOT NULL DEFAULT 'site',
			owner_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            domain varchar(40) NOT NULL,
            value text NOT NULL,
            evidence text DEFAULT NULL,
            confidence varchar(20) NOT NULL DEFAULT 'medium',
			status varchar(20) NOT NULL DEFAULT 'pending',
			visibility varchar(20) NOT NULL DEFAULT 'site',
			sensitivity varchar(20) NOT NULL DEFAULT 'normal',
			version bigint(20) unsigned NOT NULL DEFAULT 1,
			content_hash char(64) NOT NULL DEFAULT '',
            source varchar(40) NOT NULL DEFAULT 'manual',
			valid_from datetime DEFAULT NULL,
			expires_at datetime DEFAULT NULL,
			deleted_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
			UNIQUE KEY memory_key (memory_key),
			UNIQUE KEY memory_uuid (memory_uuid),
			KEY namespace_status_updated (namespace, status, updated_at),
			KEY owner_status (owner_user_id, status),
            KEY domain_status (domain, status),
			KEY expires_at (expires_at),
			KEY content_hash (content_hash),
            KEY updated_at (updated_at)
			{$memory_search}
        ) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$memory_events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_uuid char(36) NOT NULL,
			memory_uuid char(36) NOT NULL,
			namespace varchar(191) NOT NULL DEFAULT 'site',
			event_type varchar(32) NOT NULL,
			memory_version bigint(20) unsigned NOT NULL DEFAULT 1,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			connection_id varchar(191) NOT NULL DEFAULT '',
			payload longtext DEFAULT NULL,
			payload_hash char(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY event_uuid (event_uuid),
			KEY memory_version (memory_uuid, memory_version),
			KEY namespace_cursor (namespace, id),
			KEY connection_cursor (connection_id, id),
			KEY created_at (created_at)
		) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$memory_sync} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			connector_id varchar(191) NOT NULL,
			namespace varchar(191) NOT NULL DEFAULT 'site',
			external_id varchar(191) NOT NULL DEFAULT '',
			memory_uuid char(36) DEFAULT NULL,
			cursor_value varchar(191) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'idle',
			last_error_code varchar(64) NOT NULL DEFAULT '',
			last_attempt_at datetime DEFAULT NULL,
			last_success_at datetime DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY connector_namespace_external (connector_id, namespace, external_id),
			KEY connector_namespace_status (connector_id, namespace, status),
			KEY memory_uuid (memory_uuid),
			KEY updated_at (updated_at)
		) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$jobs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_key varchar(120) NOT NULL,
            job_type varchar(60) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            total_items int(10) unsigned NOT NULL DEFAULT 0,
            processed_items int(10) unsigned NOT NULL DEFAULT 0,
            error_count int(10) unsigned NOT NULL DEFAULT 0,
            lease_token varchar(64) NOT NULL DEFAULT '',
            args longtext DEFAULT NULL,
            result longtext DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY job_key (job_key),
            KEY job_type (job_type),
            KEY status (status),
            KEY updated_at (updated_at)
        ) {$charset};",
			"CREATE TABLE {$cache} (
            cache_key varchar(191) NOT NULL,
            cache_group varchar(60) NOT NULL DEFAULT 'default',
            payload longtext DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (cache_key),
            KEY cache_group (cache_group),
            KEY expires_at (expires_at),
            KEY updated_at (updated_at)
        ) {$charset};",
		);

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( implode( "\n", $sql ) );
	}

	/**
	 * Remove a falsely current schema marker so normal boot retries the repair.
	 *
	 * Older schema versions are already stale and must remain available for
	 * diagnostics until a later retry succeeds.
	 *
	 * @param string $installed Stored schema version before installation.
	 */
	private static function invalidate_current_version( string $installed ): void {
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			delete_option( self::OPTION_DB_VERSION );
		}
	}

	/**
	 * Persist a bounded repair failure and keep the schema marker retryable.
	 *
	 * @param string   $installed Stored version before the attempt.
	 * @param string[] $missing   Missing logical table keys.
	 * @param string   $reason    Bounded machine-readable reason.
	 */
	private static function installation_failed( string $installed, array $missing, string $reason ): bool {
		self::invalidate_current_version( $installed );
		InstallerRetryState::record_failure( $missing, $reason );

		return false;
	}
}
