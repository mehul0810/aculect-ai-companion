<?php
/**
 * Regression tests for bounded and transactional memory infrastructure.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\Memory\MemoryAdminQuery;
use Aculect\AICompanion\Intelligence\Database\MemorySchemaMigrator;
use Aculect\AICompanion\Intelligence\Memory\MemoryRepository;
use Aculect\AICompanion\Intelligence\Memory\MemoryService;
use Aculect\AICompanion\Intelligence\Memory\MemoryStorageRequirements;
use PHPUnit\Framework\TestCase;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment.MissingParamTag, WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused wpdb double.

final class MemoryHardeningTest extends TestCase {
	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb                                   = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_scheduled_events'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	public function test_transaction_requirement_rejects_non_transactional_memory_table(): void {
		$wpdb            = new MemoryHardeningWpdb();
		$wpdb->engines   = array( 'InnoDB', 'MyISAM' );
		$GLOBALS['wpdb'] = $wpdb;

		self::assertFalse( ( new MemoryStorageRequirements() )->supports_transactions() );
	}

	public function test_memory_write_fails_before_mutation_on_non_transactional_storage(): void {
		$wpdb            = new MemoryHardeningWpdb();
		$wpdb->engines   = array( 'MyISAM', 'InnoDB' );
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new MemoryService() )->save(
			array(
				'key'   => 'site.voice',
				'value' => 'Direct and concise.',
			)
		);

		self::assertSame( 'memory_transaction_failed', $result['error'] );
		self::assertSame( 0, $wpdb->transaction_queries );
	}

	public function test_background_migration_converts_only_one_legacy_table_per_batch(): void {
		$wpdb            = new MemoryHardeningWpdb();
		$wpdb->engines   = array( 'MyISAM', 'InnoDB', 'InnoDB' );
		$GLOBALS['wpdb'] = $wpdb;

		MemorySchemaMigrator::run_scheduled_batch();

		self::assertSame( 1, $wpdb->alter_queries );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( MemorySchemaMigrator::HOOK ) );
	}

	public function test_background_migration_does_not_overlap_an_active_worker(): void {
		$wpdb               = new MemoryHardeningWpdb();
		$wpdb->lock_granted = false;
		$GLOBALS['wpdb']    = $wpdb;

		MemorySchemaMigrator::run_scheduled_batch();

		self::assertSame( 0, $wpdb->alter_queries );
		self::assertSame( 0, $wpdb->result_queries );
	}

	public function test_fulltext_index_is_created_only_inside_the_locked_worker(): void {
		$wpdb            = new MemoryHardeningWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		MemorySchemaMigrator::run_scheduled_batch();

		self::assertTrue( $wpdb->lock_granted );
		self::assertStringContainsString( 'ADD FULLTEXT KEY memory_search', implode( "\n", $wpdb->query_log ) );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( MemorySchemaMigrator::HOOK ) );
	}

	public function test_large_schema_change_is_deferred_for_maintenance_approval(): void {
		$wpdb                    = new MemoryHardeningWpdb();
		$wpdb->table_size_result = 70000000;
		$GLOBALS['wpdb']         = $wpdb;

		MemorySchemaMigrator::run_scheduled_batch();

		self::assertSame( 0, $wpdb->alter_queries );
		self::assertSame( 'fulltext_index', MemorySchemaMigrator::blocked_status()['operation'] );
	}

	public function test_unknown_table_size_fails_closed_before_schema_change(): void {
		$wpdb                    = new MemoryHardeningWpdb();
		$wpdb->table_size_result = false;
		$GLOBALS['wpdb']         = $wpdb;

		MemorySchemaMigrator::run_scheduled_batch();

		self::assertSame( 0, $wpdb->alter_queries );
		self::assertSame( 'size_unknown', MemorySchemaMigrator::blocked_status()['reason'] );
	}

	public function test_transaction_requirement_rejects_a_non_exact_table_match(): void {
		$wpdb                  = new MemoryHardeningWpdb();
		$wpdb->reported_tables = array( 'wpXaculect_ai_memory_items' );
		$GLOBALS['wpdb']       = $wpdb;

		self::assertFalse( ( new MemoryStorageRequirements() )->supports_transactions() );
	}

	public function test_approved_search_uses_database_filter_and_truthful_paging(): void {
		$wpdb               = new MemoryHardeningWpdb();
		$wpdb->rows         = array_fill(
			0,
			3,
			array(
				'id'         => 7,
				'memory_key' => 'match',
				'value'      => 'value',
				'updated_at' => '2026-09-05 12:00:00',
			)
		);
		$wpdb->search_index = true;
		$GLOBALS['wpdb']    = $wpdb;

		$result = ( new MemoryRepository() )->search_page(
			array(
				'query'  => 'needle',
				'domain' => 'content',
				'limit'  => 2,
			)
		);

		self::assertStringContainsString( 'MATCH(memory_key, value, evidence)', $wpdb->last_query );
		self::assertStringContainsString( 'domain = %s', $wpdb->last_query );
		self::assertStringNotContainsString( 'OFFSET', $wpdb->last_query );
		self::assertSame( 3, $wpdb->last_args[ array_key_last( $wpdb->last_args ) ] );
		self::assertCount( 2, $result['items'] );
		self::assertTrue( $result['has_more'] );
		self::assertNotSame( '', $result['next_cursor'] );
	}

	public function test_admin_page_uses_database_wide_status_counts(): void {
		$wpdb              = new MemoryHardeningWpdb();
		$wpdb->rows        = array(
			array(
				'memory_key' => 'visible',
				'status'     => 'pending',
			),
		);
		$wpdb->status_rows = array(
			array(
				'status' => 'pending',
				'total'  => 75,
			),
			array(
				'status' => 'approved',
				'total'  => 20,
			),
			array(
				'status' => 'dismissed',
				'total'  => 5,
			),
		);
		$GLOBALS['wpdb']   = $wpdb;

		$result = ( new MemoryAdminQuery() )->page( 2 );

		self::assertSame( 100, $result['summary']['total'] );
		self::assertSame( 75, $result['summary']['pending'] );
		self::assertSame( 5, $result['total_pages'] );
		self::assertSame( 2, $result['page'] );
	}
}

final class MemoryHardeningWpdb {
	public string $prefix     = 'wp_';
	public string $last_query = '';
	/** @var list<mixed> */
	public array $last_args = array();
	/** @var list<array<string, mixed>> */
	public array $rows = array();
	/** @var list<array<string, mixed>> */
	public array $status_rows = array();
	/** @var list<string> */
	public array $engines           = array( 'InnoDB', 'InnoDB' );
	private int $engine_index       = 0;
	public int $transaction_queries = 0;
	public int $alter_queries       = 0;
	public int $result_queries      = 0;
	public bool $lock_granted       = true;
	public bool $search_index       = false;
	public mixed $table_size_result = 1;
	/** @var list<string> */
	public array $query_log = array();
	/** @var list<string> */
	public array $reported_tables = array();

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_query = $query;
		$this->last_args  = $args;
		return $query;
	}

	public function esc_like( string $value ): string {
		return $value;
	}

	public function query( string $query ): int {
		$this->query_log[] = $query;
		if ( str_contains( $query, 'TRANSACTION' ) ) {
			++$this->transaction_queries;
		}
		if ( str_contains( $query, 'ALTER TABLE' ) ) {
			++$this->alter_queries;
		}
		return 1;
	}

	public function get_var( string $query ): mixed {
		return str_contains( $query, 'GET_LOCK' ) ? (int) $this->lock_granted : $this->table_size_result;
	}

	/** @return array<string, string> */
	public function get_row( string $query, string $format ): array {
		unset( $query, $format );
		$table = $this->reported_tables[ $this->engine_index ] ?? (string) ( $this->last_args[0] ?? '' );
		return array(
			'Name'   => $table,
			'Engine' => $this->engines[ $this->engine_index++ ] ?? 'InnoDB',
		);
	}

	/** @return list<array<string, mixed>> */
	public function get_results( string $query, string $format ): array {
		unset( $format );
		++$this->result_queries;
		if ( str_contains( $query, 'SHOW INDEX' ) ) {
			return $this->search_index ? array( array( 'Key_name' => 'memory_search' ) ) : array();
		}
		return str_contains( $query, 'GROUP BY status' ) ? $this->status_rows : $this->rows;
	}
}
