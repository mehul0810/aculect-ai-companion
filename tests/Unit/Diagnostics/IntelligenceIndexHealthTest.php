<?php
/**
 * Tests for intelligence index health diagnostics.
 *
 * @package Aculect\AICompanion\Tests\Unit\Diagnostics
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Diagnostics\IntelligenceIndexHealth;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused diagnostics tests replace wpdb with a local test double.

/**
 * Verifies content intelligence diagnostics stay operational and support-safe.
 */
final class IntelligenceIndexHealthTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']    = new FakeIntelligenceIndexWpdb();
		update_option( 'aculect_ai_companion_pending_index_ids', array( 12, 34, 34 ), false );
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		delete_option( 'aculect_ai_companion_pending_index_ids' );

		parent::tearDown();
	}

	public function test_status_reports_backlog_and_recent_jobs_without_payloads(): void {
		$status = ( new IntelligenceIndexHealth() )->status();

		self::assertSame( 'backlogged', $status['status'] );
		self::assertSame( 8, $status['total_items'] );
		self::assertSame( 3, $status['stale_items'] );
		self::assertSame( '2026-06-01 12:00:00', $status['latest_indexed_at'] );
		self::assertFalse( $status['is_empty'] );
		self::assertSame( 2, $status['pending_object_count'] );
		self::assertSame( 1, $status['job_status_counts']['queued'] );
		self::assertSame( 1, $status['job_status_counts']['complete'] );
		self::assertCount( 1, $status['recent_refresh_jobs'] );
		self::assertSame( 'job-1', $status['recent_refresh_jobs'][0]['job_key'] );
		self::assertArrayNotHasKey( 'args', $status['recent_refresh_jobs'][0] );
		self::assertArrayNotHasKey( 'result', $status['recent_refresh_jobs'][0] );
	}
}

/**
 * Minimal wpdb test double for intelligence index diagnostics.
 */
final class FakeIntelligenceIndexWpdb {

	public string $prefix = 'wp_';

	/**
	 * Prepared SQL calls.
	 *
	 * @var list<array{query: string, args: array<int, mixed>}>
	 */
	public array $prepared = array();

	/**
	 * Record prepared SQL.
	 *
	 * @param string $query SQL query.
	 * @param mixed  ...$args Placeholder arguments.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared[] = array(
			'query' => $query,
			'args'  => $args,
		);

		return $query;
	}

	/**
	 * Return the index summary row.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return array<string, mixed>
	 */
	public function get_row( string $query, string $output ): array {
		unset( $query, $output );

		return array(
			'total'             => '8',
			'stale'             => '3',
			'latest_indexed_at' => '2026-06-01 12:00:00',
		);
	}

	/**
	 * Return job count or recent job rows.
	 *
	 * @param string $query  SQL query.
	 * @param string $output Output format.
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );

		if ( str_contains( $query, 'GROUP BY status' ) ) {
			return array(
				array(
					'status' => 'queued',
					'total'  => '1',
				),
				array(
					'status' => 'complete',
					'total'  => '1',
				),
			);
		}

		return array(
			array(
				'id'              => '7',
				'job_key'         => 'job-1',
				'job_type'        => 'content_index_refresh',
				'status'          => 'complete',
				'total_items'     => '5',
				'processed_items' => '5',
				'error_count'     => '0',
				'created_at'      => '2026-06-01 11:55:00',
				'updated_at'      => '2026-06-01 12:00:00',
			),
		);
	}
}
