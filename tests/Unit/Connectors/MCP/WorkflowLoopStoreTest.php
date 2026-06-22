<?php
/**
 * Tests for transient-backed MCP workflow loops.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\WorkflowLoopStore;
use PHPUnit\Framework\TestCase;

/**
 * Verifies bounded item-aware workflow loop persistence.
 */
final class WorkflowLoopStoreTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_transients']      = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	public function test_create_get_and_run_next_loop_item(): void {
		$store  = new WorkflowLoopStore();
		$create = $store->create(
			array(
				'source'    => 'provided_items',
				'objective' => 'Improve thin service pages.',
				'guidance'  => 'Expand each page with useful FAQs and internal links.',
				'items'     => $this->items( 10, 20, 30 ),
			)
		);

		self::assertSame( 'success', $create['status'] );
		self::assertSame( 'created', $create['workflow_loop']['state'] );
		self::assertSame( 3, $create['summary']['total'] );
		self::assertSame( 3, $create['summary']['pending'] );
		self::assertSame( 'Expand each page with useful FAQs and internal links.', $create['workflow_loop']['guidance'] );

		$read = $store->get( array( 'workflow_loop_id' => $create['workflow_loop']['id'] ) );
		self::assertSame( $create['workflow_loop']['id'], $read['workflow_loop']['id'] );

		$next = $store->run_next( array( 'workflow_loop_id' => $create['workflow_loop']['id'] ) );
		self::assertSame( 'running', $next['workflow_loop']['state'] );
		self::assertSame( 10, $next['active_item']['id'] );
		self::assertSame( 'content_workflow_prepare_post', $next['active_item']['next_tool'] );
		self::assertSame( 10, $next['active_item']['next_tool_arguments']['existing_post_id'] );
		self::assertStringContainsString( 'Expand each page', $next['active_item']['next_tool_arguments']['brief'] );

		$after_completion = $store->run_next(
			array(
				'workflow_loop_id'  => $create['workflow_loop']['id'],
				'completed_item_id' => 10,
				'completed_status'  => 'succeeded',
			)
		);

		self::assertSame( 1, $after_completion['summary']['succeeded'] );
		self::assertSame( 20, $after_completion['active_item']['id'] );
		self::assertSame( 1, $after_completion['summary']['running'] );
	}

	public function test_run_batch_records_completion_without_repeating_successful_items(): void {
		$store  = new WorkflowLoopStore();
		$create = $store->create(
			array(
				'source'     => 'provided_items',
				'batch_size' => 2,
				'items'      => $this->items( 10, 20, 30, 40 ),
			)
		);

		$batch = $store->run_batch(
			array(
				'workflow_loop_id' => $create['workflow_loop']['id'],
				'limit'            => 2,
			)
		);

		self::assertSame( array( 10, 20 ), array_column( $batch['items_to_process'], 'id' ) );

		$resumed = $store->run_batch(
			array(
				'workflow_loop_id' => $create['workflow_loop']['id'],
				'limit'            => 2,
				'completed_items'  => array(
					array(
						'id'     => 10,
						'status' => 'succeeded',
					),
					array(
						'id'      => 20,
						'status'  => 'failed',
						'message' => 'Needs manual brief.',
					),
				),
			)
		);

		self::assertSame( 1, $resumed['summary']['succeeded'] );
		self::assertSame( 1, $resumed['summary']['failed'] );
		self::assertSame( array( 30, 40 ), array_column( $resumed['items_to_process'], 'id' ) );
		self::assertNotContains( 10, array_column( $resumed['items_to_process'], 'id' ) );
	}

	public function test_run_batch_returns_redacted_audit_summary_after_completion(): void {
		$store  = new WorkflowLoopStore();
		$create = $store->create(
			array(
				'source'     => 'provided_items',
				'batch_size' => 2,
				'items'      => $this->items( 10, 20 ),
			)
		);

		$store->run_batch(
			array(
				'workflow_loop_id' => $create['workflow_loop']['id'],
				'limit'            => 2,
			)
		);

		$completed = $store->run_batch(
			array(
				'workflow_loop_id' => $create['workflow_loop']['id'],
				'completed_items'  => array(
					array(
						'id'          => 10,
						'status'      => 'succeeded',
						'changed'     => true,
						'preview_url' => 'https://example.com/?p=10&preview=true',
						'message'     => 'Updated draft. token=abc123 user admin@example.com',
					),
					array(
						'id'       => 20,
						'status'   => 'succeeded',
						'changed'  => false,
						'message'  => 'Reviewed only. Bearer sk-live-secret',
						'warnings' => array( 'credential=abc123 was ignored' ),
					),
				),
			)
		);

		self::assertSame( 'complete', $completed['workflow_loop']['state'] );
		self::assertArrayHasKey( 'audit_summary', $completed );
		self::assertArrayHasKey( 'totals', $completed['audit_summary'] );
		self::assertArrayHasKey( 'changed_targets', $completed['audit_summary'] );
		self::assertArrayHasKey( 'failed_targets', $completed['audit_summary'] );
		self::assertArrayHasKey( 'warnings', $completed['audit_summary'] );
		self::assertArrayHasKey( 'recovery_hints', $completed['audit_summary'] );
		self::assertArrayHasKey( 'next_actions', $completed['audit_summary'] );
		self::assertSame( 2, $completed['audit_summary']['totals']['succeeded'] );
		self::assertCount( 1, $completed['audit_summary']['changed_targets'] );
		self::assertSame( 10, $completed['audit_summary']['changed_targets'][0]['id'] );
		self::assertSame( 'https://example.com/?p=10&preview=true', $completed['audit_summary']['changed_targets'][0]['preview_url'] );
		self::assertStringContainsString( 'token=[redacted]', $completed['audit_summary']['changed_targets'][0]['message'] );
		self::assertStringContainsString( '[redacted-email]', $completed['audit_summary']['changed_targets'][0]['message'] );
		self::assertStringContainsString( 'credential=[redacted]', $completed['audit_summary']['warnings'][0] );
		self::assertStringNotContainsString( 'abc123', wp_json_encode( $completed['audit_summary'] ) );
		self::assertStringNotContainsString( 'sk-live-secret', wp_json_encode( $completed['audit_summary'] ) );
		self::assertStringNotContainsString( 'abc123', wp_json_encode( $completed['items'] ) );
		self::assertStringNotContainsString( 'sk-live-secret', wp_json_encode( $completed['items'] ) );

		$read = $store->get( array( 'workflow_loop_id' => $create['workflow_loop']['id'] ) );
		self::assertArrayHasKey( 'audit_summary', $read );
		self::assertSame( $completed['audit_summary']['totals'], $read['audit_summary']['totals'] );
	}

	public function test_run_batch_returns_failed_targets_and_recovery_hints_for_blocked_items(): void {
		$store  = new WorkflowLoopStore();
		$create = $store->create(
			array(
				'source' => 'provided_items',
				'items'  => $this->items( 10 ),
			)
		);

		$store->run_batch(
			array(
				'workflow_loop_id' => $create['workflow_loop']['id'],
			)
		);

		$blocked = $store->run_batch(
			array(
				'workflow_loop_id' => $create['workflow_loop']['id'],
				'completed_items'  => array(
					array(
						'id'      => 10,
						'status'  => 'blocked',
						'message' => 'Needs owner approval before publish.',
					),
				),
			)
		);

		self::assertSame( 'blocked', $blocked['workflow_loop']['state'] );
		self::assertCount( 1, $blocked['audit_summary']['failed_targets'] );
		self::assertSame( 10, $blocked['audit_summary']['failed_targets'][0]['id'] );
		self::assertContains( 'Review failed or blocked items before starting another batch.', $blocked['audit_summary']['recovery_hints'] );
		self::assertContains( 'Use WordPress revisions, autosaves, previews, or draft status checks for reversible content review when those states exist.', $blocked['audit_summary']['recovery_hints'] );
	}

	public function test_audit_summary_bounds_targets_and_warnings(): void {
		$store  = new WorkflowLoopStore();
		$create = $store->create(
			array(
				'source'     => 'provided_items',
				'batch_size' => 10,
				'items'      => $this->items( 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12 ),
			)
		);

		$store->run_batch(
			array(
				'workflow_loop_id' => $create['workflow_loop']['id'],
				'limit'            => 10,
			)
		);

		$first_ten = array_map(
			static fn ( int $id ): array => array(
				'id'       => $id,
				'status'   => 'succeeded',
				'changed'  => true,
				'warnings' => array( 'warning for item ' . $id ),
			),
			range( 1, 10 )
		);
		$second    = $store->run_batch(
			array(
				'workflow_loop_id' => $create['workflow_loop']['id'],
				'completed_items'  => $first_ten,
				'limit'            => 10,
			)
		);

		$last_two = array_map(
			static fn ( int $id ): array => array(
				'id'       => $id,
				'status'   => 'succeeded',
				'changed'  => true,
				'warnings' => array( 'warning for item ' . $id ),
			),
			range( 11, 12 )
		);
		$done     = $store->run_batch(
			array(
				'workflow_loop_id' => $create['workflow_loop']['id'],
				'completed_items'  => $last_two,
			)
		);

		self::assertSame( array( 11, 12 ), array_column( $second['items_to_process'], 'id' ) );
		self::assertSame( 12, $done['audit_summary']['totals']['succeeded'] );
		self::assertCount( 10, $done['audit_summary']['changed_targets'] );
		self::assertCount( 10, $done['audit_summary']['warnings'] );
	}

	public function test_pause_resume_and_cancel_honor_loop_state(): void {
		$store  = new WorkflowLoopStore();
		$create = $store->create(
			array(
				'source' => 'provided_items',
				'items'  => $this->items( 10, 20 ),
			)
		);

		$paused = $store->pause( array( 'workflow_loop_id' => $create['workflow_loop']['id'] ) );
		self::assertSame( 'paused', $paused['workflow_loop']['state'] );

		$blocked_run = $store->run_next( array( 'workflow_loop_id' => $create['workflow_loop']['id'] ) );
		self::assertSame( 'paused', $blocked_run['workflow_loop']['state'] );
		self::assertArrayNotHasKey( 'active_item', $blocked_run );

		$resumed = $store->run_next(
			array(
				'workflow_loop_id' => $create['workflow_loop']['id'],
				'resume'           => true,
			)
		);
		self::assertSame( 'running', $resumed['workflow_loop']['state'] );
		self::assertSame( 10, $resumed['active_item']['id'] );

		$cancelled = $store->cancel( array( 'workflow_loop_id' => $create['workflow_loop']['id'] ) );
		self::assertSame( 'cancelled', $cancelled['workflow_loop']['state'] );
		self::assertSame( 2, $cancelled['summary']['cancelled'] );

		$after_cancel = $store->run_batch(
			array(
				'workflow_loop_id' => $create['workflow_loop']['id'],
				'resume'           => true,
			)
		);
		self::assertSame( 'cancelled', $after_cancel['workflow_loop']['state'] );
		self::assertSame( array(), $after_cancel['items_to_process'] );
	}

	public function test_loop_state_cannot_be_read_or_mutated_by_another_user(): void {
		$store  = new WorkflowLoopStore();
		$create = $store->create(
			array(
				'source' => 'provided_items',
				'items'  => $this->items( 10, 20 ),
			)
		);

		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 9;

		$read = $store->get( array( 'workflow_loop_id' => $create['workflow_loop']['id'] ) );
		self::assertSame( 'error', $read['status'] );
		self::assertSame( 'workflow_loop_not_found', $read['error'] );

		$run = $store->run_next( array( 'workflow_loop_id' => $create['workflow_loop']['id'] ) );
		self::assertSame( 'error', $run['status'] );
		self::assertSame( 'workflow_loop_not_found', $run['error'] );

		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
		$owner_read = $store->get( array( 'workflow_loop_id' => $create['workflow_loop']['id'] ) );

		self::assertSame( 'created', $owner_read['workflow_loop']['state'] );
		self::assertSame( 2, $owner_read['summary']['pending'] );
	}

	public function test_missing_loop_returns_error(): void {
		$result = ( new WorkflowLoopStore() )->get( array( 'workflow_loop_id' => 'missing' ) );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'workflow_loop_not_found', $result['error'] );
	}

	public function test_create_thin_page_loop_uses_index_candidates(): void {
		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new WorkflowLoopContentIndexWpdb(
			array(
				$this->content_row( 11, 'Short Page', 120 ),
				$this->content_row( 12, 'Long Page', 900 ),
				$this->content_row( 13, 'Another Short Page', 240, true ),
			)
		);

		$result = ( new WorkflowLoopStore() )->create(
			array(
				'source'         => 'thin_pages',
				'post_type'      => 'page',
				'status'         => 'publish',
				'max_word_count' => 300,
				'limit'          => 10,
			)
		);

		self::assertSame( 'success', $result['status'] );
		self::assertSame( 'thin_pages', $result['workflow_loop']['source'] );
		self::assertSame( 2, $result['summary']['total'] );
		self::assertSame( array( 11, 13 ), array_column( $result['items'], 'id' ) );
		self::assertTrue( $result['items'][1]['stale'] );
		self::assertSame( 0, $GLOBALS['wpdb']->get_var_calls );
	}

	/**
	 * Return test loop items.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function items( int ...$ids ): array {
		return array_map(
			static fn ( int $id ): array => array(
				'id'          => $id,
				'type'        => 'page',
				'post_status' => 'publish',
				'title'       => 'Page ' . $id,
				'permalink'   => 'https://example.com/page-' . $id . '/',
				'word_count'  => 120 + $id,
			),
			$ids
		);
	}

	/**
	 * Return one indexed content row.
	 *
	 * @return array<string, mixed>
	 */
	private function content_row( int $id, string $title, int $words, bool $stale = false ): array {
		return array(
			'object_id'    => $id,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'title'        => $title,
			'slug'         => sanitize_key( $title ),
			'permalink'    => 'https://example.com/?p=' . $id,
			'excerpt'      => '',
			'summary'      => $title,
			'word_count'   => $words,
			'content_hash' => hash( 'sha256', $title ),
			'indexed_at'   => '2026-06-18 00:00:00',
			'modified_gmt' => '2026-06-18 00:00:00',
			'stale'        => $stale ? 1 : 0,
			'metadata'     => '{}',
		);
	}
}

/**
 * Minimal content-index wpdb test double for loop candidate discovery.
 */
final class WorkflowLoopContentIndexWpdb {

	public string $prefix = 'wp_';

	public int $get_var_calls = 0;

	/**
	 * @var list<array<string, mixed>>
	 */
	private array $rows;

	/**
	 * @var list<mixed>
	 */
	private array $last_args = array();

	/**
	 * @param list<array<string, mixed>> $rows Content rows.
	 */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_args = $args;

		return $query;
	}

	public function get_results( string $query, string $output ): array {
		unset( $output );

		if ( ! str_contains( $query, 'SELECT * FROM' ) ) {
			return array();
		}

		return $this->filtered_rows();
	}

	public function get_var( string $query ): int {
		++$this->get_var_calls;

		if ( str_contains( $query, 'COUNT(*)' ) ) {
			return count( $this->filtered_rows() );
		}

		return 0;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_row( string $query, string $output ): array {
		unset( $query, $output );

		return array(
			'total'             => count( $this->rows ),
			'stale'             => count( array_filter( $this->rows, static fn ( array $row ): bool => ! empty( $row['stale'] ) ) ),
			'latest_indexed_at' => '2026-06-18 00:00:00',
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function filtered_rows(): array {
		$post_type = (string) ( $this->last_args[1] ?? '' );
		$status    = (string) ( $this->last_args[2] ?? '' );
		$max_words = (int) ( $this->last_args[3] ?? 0 );

		return array_values(
			array_filter(
				$this->rows,
				static fn ( array $row ): bool => ( '' === $post_type || $post_type === (string) ( $row['post_type'] ?? '' ) )
					&& ( '' === $status || $status === (string) ( $row['post_status'] ?? '' ) )
					&& ( $max_words <= 0 || (int) ( $row['word_count'] ?? 0 ) <= $max_words )
			)
		);
	}
}
