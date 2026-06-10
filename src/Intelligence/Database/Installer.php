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

	private const DB_VERSION        = '2026.06.08.2';
	private const OPTION_DB_VERSION = 'aculect_ai_companion_intelligence_db_version';

	/**
	 * Create or update all intelligence index tables.
	 */
	public static function install(): void {
		$installed = (string) get_option( self::OPTION_DB_VERSION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) || ! self::all_tables_exist() ) {
			self::create_tables();
			update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
		}
	}

	/**
	 * Activation entry point.
	 */
	public static function activate(): void {
		self::install();
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
			self::jobs_table(),
			self::cache_table(),
		);
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
		ContentIndexer::delete_options();
	}

	/**
	 * Create or upgrade intelligence tables through dbDelta().
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$content_index = self::content_index_table();
		$chunks        = self::content_chunks_table();
		$links         = self::link_graph_table();
		$memories      = self::memory_items_table();
		$jobs          = self::jobs_table();
		$cache         = self::cache_table();

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
            domain varchar(40) NOT NULL,
            value text NOT NULL,
            evidence text DEFAULT NULL,
            confidence varchar(20) NOT NULL DEFAULT 'medium',
            status varchar(20) NOT NULL DEFAULT 'approved',
            source varchar(40) NOT NULL DEFAULT 'manual',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY memory_key (memory_key),
            KEY domain_status (domain, status),
            KEY updated_at (updated_at)
        ) {$charset};",
			"CREATE TABLE {$jobs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_key varchar(120) NOT NULL,
            job_type varchar(60) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            total_items int(10) unsigned NOT NULL DEFAULT 0,
            processed_items int(10) unsigned NOT NULL DEFAULT 0,
            error_count int(10) unsigned NOT NULL DEFAULT 0,
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

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( implode( "\n", $sql ) );
	}

	/**
	 * Determine whether all intelligence tables exist.
	 */
	private static function all_tables_exist(): bool {
		global $wpdb;

		foreach ( self::table_names() as $table ) {
			if ( $table !== (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				return false;
			}
		}

		return true;
	}
}
