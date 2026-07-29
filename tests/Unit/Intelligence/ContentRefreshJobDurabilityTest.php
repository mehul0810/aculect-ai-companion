<?php
/**
 * Queued content refresh job durability tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\ContentIndexRepository;
use Aculect\AICompanion\Intelligence\ContentIndexer;
use Aculect\AICompanion\Intelligence\Database\Installer;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused durability tests replace wpdb with a local test double.

/**
 * Verifies queued refreshes checkpoint, resume, and report scheduling failures.
 */
final class ContentRefreshJobDurabilityTest extends TestCase {

	private const SCHEDULER_FAULT_GLOBALS = array(
		'aculect_ai_companion_test_schedule_failure',
		'aculect_ai_companion_test_schedule_failure_hooks',
		'aculect_ai_companion_test_schedule_literal_false_hooks',
	);

	private object|null $original_wpdb = null;
	private RefreshJobWpdb $wpdb;

	/**
	 * @var array<string, mixed>
	 */
	private array $original_scheduler_fault_globals = array();

	protected function setUp(): void {
		parent::setUp();

		$this->original_scheduler_fault_globals = array();
		foreach ( self::SCHEDULER_FAULT_GLOBALS as $global_name ) {
			if ( array_key_exists( $global_name, $GLOBALS ) ) {
				$this->original_scheduler_fault_globals[ $global_name ] = $GLOBALS[ $global_name ];
			}
		}

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = new RefreshJobWpdb();
		$GLOBALS['wpdb']     = $this->wpdb;

		$GLOBALS['aculect_ai_companion_test_options']                      = array();
		$GLOBALS['aculect_ai_companion_test_posts']                        = array();
		$GLOBALS['aculect_ai_companion_test_scheduled_events']             = array();
		$GLOBALS['aculect_ai_companion_test_schedule_failure']             = false;
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks']       = array();
		$GLOBALS['aculect_ai_companion_test_schedule_literal_false_hooks'] = array();
	}

	protected function tearDown(): void {
		foreach ( self::SCHEDULER_FAULT_GLOBALS as $global_name ) {
			if ( array_key_exists( $global_name, $this->original_scheduler_fault_globals ) ) {
				$GLOBALS[ $global_name ] = $this->original_scheduler_fault_globals[ $global_name ];
			} else {
				unset( $GLOBALS[ $global_name ] );
			}
		}

		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}

		parent::tearDown();
	}

	public function test_initial_schedule_failure_is_reported_and_job_is_failed(): void {
		$GLOBALS['aculect_ai_companion_test_schedule_failure'] = true;

		$result = ( new ContentIndexer() )->queue_refresh_batch( array( 'ids' => array( 1, 2 ) ) );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'job_schedule_failed', $result['error'] );
		self::assertSame( 'failed', $result['job']['status'] ?? '' );
		self::assertSame( 0, $result['job']['processed_items'] ?? -1 );
	}

	public function test_literal_false_worker_schedule_is_reported_and_job_is_failed(): void {
		$GLOBALS['aculect_ai_companion_test_schedule_literal_false_hooks'] = array( 'aculect_ai_companion_content_index_refresh_job' );

		$result = ( new ContentIndexer() )->queue_refresh_batch( array( 'ids' => array( 1, 2 ) ) );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'job_schedule_failed', $result['error'] );
		self::assertSame( 'failed', $result['job']['status'] ?? '' );
	}

	public function test_literal_false_watchdog_schedule_is_reported_and_worker_is_removed(): void {
		$GLOBALS['aculect_ai_companion_test_schedule_literal_false_hooks'] = array( 'aculect_ai_companion_content_index_refresh_recovery' );

		$result = ( new ContentIndexer() )->queue_refresh_batch( array( 'ids' => array( 1 ) ) );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'job_recovery_schedule_failed', $result['error'] );
		self::assertSame( 'failed', $result['job']['status'] ?? '' );
		self::assertArrayNotHasKey( 'aculect_ai_companion_content_index_refresh_job', $GLOBALS['aculect_ai_companion_test_scheduled_events'] );
	}

	public function test_identical_jobs_use_distinct_random_key_suffixes(): void {
		$repository = new ContentIndexRepository();

		$first  = $repository->create_job( 'content_index_refresh', array( 'ids' => array( 1, 2 ) ), 2, 'queued' );
		$second = $repository->create_job( 'content_index_refresh', array( 'ids' => array( 1, 2 ) ), 2, 'queued' );

		self::assertMatchesRegularExpression( '/_[a-f0-9]{16}$/', (string) ( $first['job_key'] ?? '' ) );
		self::assertMatchesRegularExpression( '/_[a-f0-9]{16}$/', (string) ( $second['job_key'] ?? '' ) );
		self::assertNotSame(
			substr( (string) ( $first['job_key'] ?? '' ), -16 ),
			substr( (string) ( $second['job_key'] ?? '' ), -16 )
		);
		self::assertCount( 2, $this->wpdb->jobs );
	}

	public function test_job_insert_failure_is_reported_without_scheduling_work(): void {
		$this->wpdb->fail_job_inserts = true;

		$result = ( new ContentIndexer() )->queue_refresh_batch( array( 'ids' => array( 1 ) ) );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'job_create_failed', $result['error'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_scheduled_events'] );
	}

	public function test_initial_watchdog_failure_cancels_worker_and_fails_job(): void {
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'] = array( 'aculect_ai_companion_content_index_refresh_recovery' );

		$result = ( new ContentIndexer() )->queue_refresh_batch( array( 'ids' => array( 1 ) ) );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'job_recovery_schedule_failed', $result['error'] );
		self::assertSame( 'failed', $result['job']['status'] ?? '' );
		self::assertArrayNotHasKey( 'aculect_ai_companion_content_index_refresh_job', $GLOBALS['aculect_ai_companion_test_scheduled_events'] );
	}

	public function test_existing_watchdog_is_retained_instead_of_destructively_replaced(): void {
		$indexer = new ContentIndexer();
		$queued  = $indexer->queue_refresh_batch( array( 'ids' => array( 1, 2 ) ) );
		$job_key = (string) ( $queued['job']['job_key'] ?? '' );
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'] = array( 'aculect_ai_companion_content_index_refresh_recovery' );

		$this->startScheduledWorker();
		$result = $indexer->run_queued_refresh_job( $job_key );

		self::assertSame( 'complete', $result['status'] );
		self::assertSame( array( 1, 2 ), $this->wpdb->deleted_content_ids );
		self::assertSame( '', $this->wpdb->jobs[ $job_key ]['lease_token'] ?? 'missing' );
	}

	public function test_missing_watchdog_schedule_failure_marks_unclaimed_job_failed(): void {
		$indexer = new ContentIndexer();
		$queued  = $indexer->queue_refresh_batch( array( 'ids' => array( 1 ) ) );
		$job_key = (string) ( $queued['job']['job_key'] ?? '' );
		$GLOBALS['aculect_ai_companion_test_scheduled_events'] = array();
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'] = array( 'aculect_ai_companion_content_index_refresh_recovery' );

		$result = $indexer->run_queued_refresh_job( $job_key );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'job_recovery_schedule_failed', $result['error'] );
		self::assertSame( 'failed', $result['job']['status'] ?? '' );
		self::assertSame( array(), $this->wpdb->deleted_content_ids );
	}

	public function test_missing_watchdog_failure_does_not_overwrite_an_interleaved_claim_owner(): void {
		$indexer = new ContentIndexer();
		$queued  = $indexer->queue_refresh_batch( array( 'ids' => array( 1 ) ) );
		$job_key = (string) ( $queued['job']['job_key'] ?? '' );
		$GLOBALS['aculect_ai_companion_test_scheduled_events'] = array();
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'] = array( 'aculect_ai_companion_content_index_refresh_recovery' );
		$this->wpdb->interleave_claim_owner = true;

		$result = $indexer->run_queued_refresh_job( $job_key );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'job_recovery_schedule_failed', $result['error'] );
		self::assertSame( 'running', $this->wpdb->jobs[ $job_key ]['status'] ?? '' );
		self::assertSame( str_repeat( 'a', 32 ), $this->wpdb->jobs[ $job_key ]['lease_token'] ?? '' );
	}

	public function test_continuation_schedule_failure_preserves_checkpoint_and_fails_actionably(): void {
		$indexer = new ContentIndexer();
		$queued  = $indexer->queue_refresh_batch( array( 'ids' => range( 1, 6 ) ) );
		$job_key = (string) ( $queued['job']['job_key'] ?? '' );
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'] = array( 'aculect_ai_companion_content_index_refresh_job' );

		$this->startScheduledWorker();
		$result = $indexer->run_queued_refresh_job( $job_key );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'job_continuation_schedule_failed', $result['error'] );
		self::assertSame( 5, $result['processed_items'] );
		self::assertSame( 'failed', $result['job']['status'] ?? '' );
		self::assertSame( range( 1, 5 ), $this->wpdb->deleted_content_ids );
	}

	public function test_checkpoint_database_failure_leaves_running_job_for_watchdog_recovery(): void {
		$indexer                              = new ContentIndexer();
		$queued                               = $indexer->queue_refresh_batch( array( 'ids' => array( 1, 2 ) ) );
		$job_key                              = (string) ( $queued['job']['job_key'] ?? '' );
		$this->wpdb->fail_next_claimed_update = true;

		$this->startScheduledWorker();
		$result = $indexer->run_queued_refresh_job( $job_key );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'job_lease_lost', $result['error'] );
		self::assertSame( 'running', $this->wpdb->jobs[ $job_key ]['status'] ?? '' );
		self::assertSame( 0, $this->wpdb->jobs[ $job_key ]['processed_items'] ?? -1 );
		self::assertArrayHasKey( 'aculect_ai_companion_content_index_refresh_recovery', $GLOBALS['aculect_ai_companion_test_scheduled_events'] );
	}

	public function test_terminal_checkpoint_failure_keeps_watchdog_for_safe_reclaim(): void {
		$indexer                                = new ContentIndexer();
		$queued                                 = $indexer->queue_refresh_batch( array( 'ids' => array( 1 ) ) );
		$job_key                                = (string) ( $queued['job']['job_key'] ?? '' );
		$this->wpdb->fail_claimed_update_status = 'complete';

		$this->startScheduledWorker();
		$result = $indexer->run_queued_refresh_job( $job_key );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'job_lease_lost', $result['error'] );
		self::assertSame( 'running', $this->wpdb->jobs[ $job_key ]['status'] ?? '' );
		self::assertSame( 1, $this->wpdb->jobs[ $job_key ]['processed_items'] ?? -1 );
		self::assertArrayHasKey( 'aculect_ai_companion_content_index_refresh_recovery', $GLOBALS['aculect_ai_companion_test_scheduled_events'] );
	}

	public function test_large_job_runs_in_five_item_slices_without_replaying_checkpointed_ids(): void {
		$indexer = new ContentIndexer();
		$queued  = $indexer->queue_refresh_batch( array( 'ids' => range( 1, 12 ) ) );
		$job_key = (string) ( $queued['job']['job_key'] ?? '' );

		$this->startScheduledWorker();
		$first = $indexer->run_queued_refresh_job( $job_key );
		self::assertSame( 'queued', $first['status'] );
		self::assertSame( 5, $first['processed_items'] );

		$this->startScheduledWorker();
		$second = $indexer->run_queued_refresh_job( $job_key );
		self::assertSame( 'queued', $second['status'] );
		self::assertSame( 10, $second['processed_items'] );

		$this->startScheduledWorker();
		$third = $indexer->run_queued_refresh_job( $job_key );
		self::assertSame( 'complete', $third['status'] );
		self::assertSame( 12, $third['processed_items'] );
		self::assertSame( range( 1, 12 ), $this->wpdb->deleted_content_ids );
		self::assertArrayNotHasKey( 'aculect_ai_companion_content_index_refresh_recovery', $GLOBALS['aculect_ai_companion_test_scheduled_events'] );
	}

	public function test_stale_running_job_resumes_after_checkpoint_without_replaying_completed_ids(): void {
		$indexer                                = new ContentIndexer();
		$queued                                 = $indexer->queue_refresh_batch( array( 'ids' => range( 1, 8 ) ) );
		$job_key                                = (string) ( $queued['job']['job_key'] ?? '' );
		$this->wpdb->jobs[ $job_key ]['status'] = 'running';
		$this->wpdb->jobs[ $job_key ]['processed_items'] = 5;
		$this->wpdb->jobs[ $job_key ]['result']          = wp_json_encode(
			array(
				'indexed_ids' => range( 1, 5 ),
				'errors'      => array(),
			)
		);
		$this->wpdb->jobs[ $job_key ]['updated_at']      = gmdate( 'Y-m-d H:i:s', time() - ContentIndexRepository::DEFAULT_JOB_LEASE_TTL - 30 );

		$this->startScheduledWorker();
		$result = $indexer->run_queued_refresh_job( $job_key );

		self::assertSame( 'complete', $result['status'] );
		self::assertSame( 8, $result['processed_items'] );
		self::assertSame( array( 6, 7, 8 ), $this->wpdb->deleted_content_ids );
	}

	public function test_watchdog_recovers_a_crash_immediately_after_claim(): void {
		$indexer    = new ContentIndexer();
		$repository = new ContentIndexRepository();
		$queued     = $indexer->queue_refresh_batch( array( 'ids' => array( 1, 2 ) ) );
		$job_key    = (string) ( $queued['job']['job_key'] ?? '' );
		$this->startScheduledWorker();
		$crashed_claim = $repository->claim_job( $job_key );

		self::assertNotEmpty( $crashed_claim['_lease_token'] ?? '' );
		self::assertArrayHasKey( 'aculect_ai_companion_content_index_refresh_recovery', $GLOBALS['aculect_ai_companion_test_scheduled_events'] );

		$this->wpdb->jobs[ $job_key ]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - ContentIndexRepository::DEFAULT_JOB_LEASE_TTL - 30 );
		unset( $GLOBALS['aculect_ai_companion_test_scheduled_events']['aculect_ai_companion_content_index_refresh_recovery'] );
		$result = $indexer->run_queued_refresh_job( $job_key );

		self::assertSame( 'complete', $result['status'] );
		self::assertSame( array( 1, 2 ), $this->wpdb->deleted_content_ids );
	}

	public function test_fresh_running_job_cannot_be_claimed_by_a_second_worker(): void {
		$indexer                                    = new ContentIndexer();
		$queued                                     = $indexer->queue_refresh_batch( array( 'ids' => array( 1, 2 ) ) );
		$job_key                                    = (string) ( $queued['job']['job_key'] ?? '' );
		$this->wpdb->jobs[ $job_key ]['status']     = 'running';
		$this->wpdb->jobs[ $job_key ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		$this->startScheduledWorker();
		$result = $indexer->run_queued_refresh_job( $job_key );

		self::assertSame( 'skipped', $result['status'] );
		self::assertSame( array(), $this->wpdb->deleted_content_ids );
	}

	public function test_reclaimed_worker_lease_rejects_old_checkpoints(): void {
		$repository                                 = new ContentIndexRepository();
		$job                                        = $repository->create_job( 'content_index_refresh', array( 'ids' => array( 1 ) ), 1, 'queued' );
		$job_key                                    = (string) ( $job['job_key'] ?? '' );
		$first                                      = $repository->claim_job( $job_key );
		$this->wpdb->jobs[ $job_key ]['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - ContentIndexRepository::DEFAULT_JOB_LEASE_TTL - 30 );
		$second                                     = $repository->claim_job( $job_key );

		self::assertNotSame( $first['_lease_token'] ?? '', $second['_lease_token'] ?? '' );
		self::assertSame(
			array(),
			$repository->update_claimed_job(
				$job_key,
				(string) ( $first['_lease_token'] ?? '' ),
				array(
					'status'          => 'complete',
					'processed_items' => 1,
					'error_count'     => 0,
					'result'          => array(),
				),
				true
			)
		);
		self::assertSame(
			'complete',
			$repository->update_claimed_job(
				$job_key,
				(string) ( $second['_lease_token'] ?? '' ),
				array(
					'status'          => 'complete',
					'processed_items' => 1,
					'error_count'     => 0,
					'result'          => array(),
				),
				true
			)['status'] ?? ''
		);
	}

	/**
	 * Model WordPress removing the due event immediately before its callback.
	 */
	private function startScheduledWorker(): void {
		unset( $GLOBALS['aculect_ai_companion_test_scheduled_events']['aculect_ai_companion_content_index_refresh_job'] );
	}
}

/**
 * Minimal in-memory wpdb implementation for durable refresh jobs.
 */
final class RefreshJobWpdb {

	public string $prefix                     = 'wp_';
	public bool $fail_job_inserts             = false;
	public bool $fail_next_claimed_update     = false;
	public string $fail_claimed_update_status = '';
	public bool $interleave_claim_owner       = false;

	/**
	 * Stored job rows keyed by job key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $jobs = array();

	/**
	 * Content index row IDs deleted by the worker.
	 *
	 * @var list<int>
	 */
	public array $deleted_content_ids = array();

	/**
	 * Most recent prepared-query arguments.
	 *
	 * @var array<int, mixed>
	 */
	private array $prepared_args = array();

	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared_args = $args;

		return $query;
	}

	public function insert( string $table, array $data, array $formats ): int|false {
		unset( $formats );

		if ( Installer::jobs_table() === $table ) {
			if ( $this->fail_job_inserts ) {
				return false;
			}

			$key                 = (string) $data['job_key'];
			$data['id']          = count( $this->jobs ) + 1;
			$data['lease_token'] = '';
			$this->jobs[ $key ]  = $data;

			return 1;
		}

		return 1;
	}

	public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false {
		unset( $formats, $where_formats );

		if ( Installer::jobs_table() !== $table ) {
			return 1;
		}

		$key = (string) ( $where['job_key'] ?? '' );
		if ( ! isset( $this->jobs[ $key ] ) ) {
			return 0;
		}
		if (
			isset( $where['lease_token'] )
			&& ! hash_equals( (string) ( $this->jobs[ $key ]['lease_token'] ?? '' ), (string) $where['lease_token'] )
		) {
			return 0;
		}
		if ( isset( $where['lease_token'] ) && $this->fail_next_claimed_update ) {
			$this->fail_next_claimed_update = false;

			return false;
		}
		if (
			isset( $where['lease_token'] )
			&& '' !== $this->fail_claimed_update_status
			&& (string) ( $data['status'] ?? '' ) === $this->fail_claimed_update_status
		) {
			return false;
		}

		$this->jobs[ $key ] = array_merge( $this->jobs[ $key ], $data );

		return 1;
	}

	public function query( string $query ): int|false {
		if ( ! str_contains( $query, "SET status = 'running'" ) ) {
			return 0;
		}

		$lease_token = (string) ( $this->prepared_args[1] ?? '' );
		$now         = (string) ( $this->prepared_args[2] ?? '' );
		$key         = (string) ( $this->prepared_args[3] ?? '' );
		$stale_time  = (string) ( $this->prepared_args[4] ?? '' );
		$row         = $this->jobs[ $key ] ?? null;
		if ( ! is_array( $row ) ) {
			return 0;
		}
		if ( $this->interleave_claim_owner ) {
			$this->interleave_claim_owner        = false;
			$this->jobs[ $key ]['status']         = 'running';
			$this->jobs[ $key ]['lease_token']    = str_repeat( 'a', 32 );
			$this->jobs[ $key ]['updated_at']     = $now;

			return 0;
		}

		$claimable = 'queued' === ( $row['status'] ?? '' )
			|| ( 'running' === ( $row['status'] ?? '' ) && (string) ( $row['updated_at'] ?? '' ) < $stale_time );
		if ( ! $claimable ) {
			return 0;
		}

		$this->jobs[ $key ]['status']      = 'running';
		$this->jobs[ $key ]['lease_token'] = $lease_token;
		$this->jobs[ $key ]['updated_at']  = $now;

		return 1;
	}

	public function get_row( string $query, string $output ): ?array {
		unset( $output );

		if ( str_contains( $query, 'WHERE job_key = %s' ) ) {
			$key = (string) ( $this->prepared_args[1] ?? '' );

			return $this->jobs[ $key ] ?? null;
		}

		return null;
	}

	public function get_var( string $query ): int|null {
		unset( $query );

		return null;
	}

	public function delete( string $table, array $where, array $formats ): int|false {
		unset( $formats );

		if ( Installer::content_index_table() === $table ) {
			$this->deleted_content_ids[] = (int) ( $where['object_id'] ?? 0 );
		}

		return 1;
	}
}

// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile.MultipleFound
