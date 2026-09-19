<?php
/**
 * Task recall evaluation fixtures and SQL boundary coverage.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\Memory\MemoryRecall;
use Aculect\AICompanion\Connectors\MCP\MemoryListService;
use PHPUnit\Framework\TestCase;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.FunctionComment.MissingParamTag, WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused database boundary double.

final class MemoryRecallTest extends TestCase {
	private mixed $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new MemoryRecallWpdb();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	private function record( array $changes = array() ): array {
		return array_merge(
			array(
				'memory_uuid' => 'fixture',
				'memory_key'  => 'brand.voice',
				'namespace'   => 'site',
				'value'       => 'Use direct language.',
				'evidence'    => 'Owner style guide',
				'domain'      => 'brand',
				'status'      => 'approved',
				'visibility'  => 'site',
				'sensitivity' => 'normal',
				'confidence'  => 'high',
				'source'      => 'admin',
				'version'     => 1,
				'updated_at'  => '2020-01-01 00:00:00',
			),
			$changes
		);
	}

	public function test_recall_uses_database_relevance_before_recency_and_fixed_eligibility(): void {
		$db       = $GLOBALS['wpdb'];
		$db->rows = array( $this->record() );
		$result   = ( new MemoryListService() )->list(
			array(
				'task'   => 'Please write about brand voice',
				'domain' => 'brand',
			),
			true
		);
		self::assertSame( 'brand.voice', $result['items'][0]['memory_key'] );
		self::assertStringContainsString( 'ORDER BY relevance DESC', $db->sql );
		self::assertStringContainsString( 'LIMIT 51', $db->sql );
		foreach ( array( "namespace = 'site'", "status = 'approved'", "visibility = 'site'", "sensitivity = 'normal'", 'deleted_at IS NULL', 'expires_at > %s' ) as $guard ) {
			self::assertStringContainsString( $guard, $db->sql );
		}
		self::assertSame( 'brand* voice*', $db->arguments[0] );
		self::assertSame( 'brand', $db->arguments[4] );
		self::assertArrayHasKey( 'selection_reason', $result['items'][0] );
	}

	public function test_expired_private_pending_future_and_deleted_records_never_enter_context(): void {
		$rows = array( $this->record() );
		foreach ( array(
			array( 'visibility' => 'private' ),
			array( 'visibility' => 'connection' ),
			array( 'sensitivity' => 'restricted' ),
			array( 'sensitivity' => 'sensitive' ),
			array( 'status' => 'pending' ),
			array( 'status' => 'dismissed' ),
			array( 'namespace' => 'other' ),
			array( 'expires_at' => '2000-01-01 00:00:00' ),
			array( 'valid_from' => '2999-01-01 00:00:00' ),
			array( 'deleted_at' => '2020-01-01 00:00:00' ),
		) as $override ) {
			$rows[] = $this->record( array_merge( array( 'value' => 'EXCLUDED' ), $override ) );
		}
		$result = ( new MemoryRecall() )->pack( $rows, 12000, 50 );
		self::assertCount( 1, $result['items'] );
		self::assertStringNotContainsString( 'EXCLUDED', wp_json_encode( $result ) );
	}

	public function test_budget_keeps_complete_values_and_marks_omissions_even_for_escaped_unicode(): void {
		$large  = $this->record( array( 'value' => str_repeat( '😀"\\', 500 ) ) );
		$result = ( new MemoryRecall() )->pack( array( $large, $this->record() ), 1000, 10 );
		self::assertTrue( $result['truncated'] );
		self::assertLessThanOrEqual( 1000, strlen( wp_json_encode( $result ) ) );
		self::assertSame( 'Use direct language.', $result['items'][0]['value'] );
	}

	public function test_competing_guidance_is_not_silently_resolved_or_rewritten(): void {
		$rows   = array(
			$this->record(),
			$this->record(
				array(
					'memory_key' => 'brand.alternative',
					'value'      => 'Use elaborate language.',
				)
			),
		);
		$result = ( new MemoryRecall() )->pack( $rows, 6000, 10 );
		self::assertCount( 2, $result['items'] );
		self::assertSame( array_column( $rows, 'value' ), array_column( $result['items'], 'value' ) );
		self::assertStringContainsString( 'Conflicting guidance requires review', $result['guidance'] );
	}

	public function test_candidate_and_record_limits_are_bounded(): void {
		$result = ( new MemoryRecall() )->pack( array_fill( 0, 51, $this->record() ), 12000, 2 );
		self::assertCount( 2, $result['items'] );
		self::assertTrue( $result['truncated'] );
	}

	public function test_legacy_review_listing_retains_its_original_shape_and_order(): void {
		$GLOBALS['wpdb']->rows = array( $this->record() );
		$result                = ( new MemoryListService() )->list( array(), true );
		self::assertSame( 'review', $result['context'] );
		self::assertArrayHasKey( 'next_cursor', $result );
		self::assertArrayHasKey( 'protocol', $result );
		self::assertArrayNotHasKey( 'budget_chars', $result );
		self::assertStringContainsString( 'ORDER BY updated_at DESC, id DESC', $GLOBALS['wpdb']->sql );
	}

	public function test_malformed_tasks_and_mixed_list_modes_fail_before_database_access(): void {
		foreach ( array( null, false, array(), new \stdClass(), '', 'the and for', str_repeat( 'a', 2001 ) ) as $task ) {
			self::assertSame( 'invalid_recall', ( new MemoryRecall() )->recall( array( 'task' => $task ) )['error'] );
		}
		foreach ( array( array( 'budget_chars' => '6000' ), array( 'per_page' => 51 ), array( 'status' => 'pending' ), array( 'cursor' => 'next' ), array( 'domain' => array() ), array( 'query' => 'mixed' ) ) as $args ) {
			self::assertSame( 'invalid_recall', ( new MemoryRecall() )->recall( array_merge( array( 'task' => 'brand voice' ), $args ) )['error'] );
		}
		self::assertSame( 0, $GLOBALS['wpdb']->calls );
	}

	public function test_missing_index_and_database_failure_do_not_masquerade_as_empty_recall(): void {
		$GLOBALS['wpdb']->index_ready = false;
		self::assertSame( 'memory_search_migrating', ( new MemoryRecall() )->recall( array( 'task' => 'brand voice' ) )['error'] );
		$GLOBALS['wpdb']->index_ready = true;
		$GLOBALS['wpdb']->last_error  = 'private database diagnostic';
		$result                       = ( new MemoryRecall() )->recall( array( 'task' => 'brand voice' ) );
		self::assertSame( 'memory_recall_failed', $result['error'] );
		self::assertStringNotContainsString( 'private database', wp_json_encode( $result ) );
	}

	public function test_task_character_limit_matches_schema_for_multibyte_input(): void {
		$result = ( new MemoryRecall() )->recall( array( 'task' => str_repeat( '品牌', 1000 ) ) );
		self::assertArrayNotHasKey( 'error', $result );
		self::assertSame( 'invalid_recall', ( new MemoryRecall() )->recall( array( 'task' => str_repeat( '品', 2001 ) ) )['error'] );
		self::assertSame( 'invalid_recall', ( new MemoryRecall() )->recall( array( 'task' => "\xFF" ) )['error'] );
	}
}

final class MemoryRecallWpdb {
	public string $prefix     = 'wp_';
	public string $last_error = '';
	public string $sql        = '';
	public array $arguments   = array();
	public array $rows        = array();
	public bool $index_ready  = true;
	public int $calls         = 0;

	public function prepare( string $query, mixed ...$args ): string {
		$this->sql       = $query;
		$this->arguments = $args;
		return $query;
	}

	public function get_results( string $query, mixed $format ): array {
		assert( ARRAY_A === $format );
		++$this->calls;
		return str_starts_with( $query, 'SHOW INDEX' ) ? ( $this->index_ready ? array( array( 'Key_name' => 'memory_search' ) ) : array() ) : $this->rows;
	}
}
