<?php
/**
 * Tests for encrypted durable workflow run and step storage.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Execution
 */

declare(strict_types=1);

// phpcs:disable Squiz.PHP.CommentedOutCode.Found -- Inline type assertions document the test double boundary.

namespace Aculect\AICompanion\Tests\Unit\Workflows\Execution;

require_once dirname( __DIR__, 3 ) . '/Support/WorkflowRunSqliteWpdb.php';

use Aculect\AICompanion\Tests\Support\WorkflowRunSqliteWpdb;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStore;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStoreException;
use Aculect\AICompanion\Workflows\Execution\WorkflowStepState;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Isolated SQLite storage fixture.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused test replaces wpdb with an isolated adapter.

/**
 * Verifies encryption, CAS lifecycle transitions, step fencing, and schema.
 */
final class WorkflowRunStoreTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			self::markTestSkipped( 'pdo_sqlite is required for workflow run persistence tests.' );
		}

		$this->original_wpdb                          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']                              = new WorkflowRunSqliteWpdb();
		$GLOBALS['aculect_ai_companion_test_options'] = array(
			'aculect_ai_companion_workflow_runs_db_version' => '2026.08.29.2',
			'aculect_ai_companion_secret_storage_key' => str_repeat( 'k', 64 ),
		);
	}

	protected function tearDown(): void {
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}

		parent::tearDown();
	}

	public function test_create_claim_complete_and_transition_keep_payloads_encrypted_and_fenced(): void {
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore( null, static fn (): int => 1724889600 );
		$run   = $store->create( 'run-store-1', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );

		self::assertSame( WorkflowRunState::PREPARED, $run->state() );
		self::assertSame( 1, $run->state_version() );
		$raw = $this->wpdb()->get_row( $this->wpdb()->prepare( 'SELECT * FROM %i WHERE run_id = %s', 'wp_aculect_ai_workflow_runs', 'run-store-1' ), 'ARRAY_A' );
		self::assertIsArray( $raw );
		self::assertStringStartsWith( 'v1:', (string) $raw['input_ciphertext'] );
		self::assertStringNotContainsString( 'post_id', (string) $raw['input_ciphertext'] );

		$running = $store->transition( 'run-store-1', WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 );
		self::assertNotNull( $running );
		self::assertSame( 2, $running?->state_version() );
		self::assertNull( $store->transition( 'run-store-1', WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 ), 'A stale lifecycle writer must lose the CAS race.' );

		$claimed = $store->claim_step( 'run-store-1', 'read_content', 7 );
		self::assertNotNull( $claimed );
		self::assertSame( 2, $claimed?->fence() );
		self::assertSame( 1, $claimed?->attempt() );

		$result    = WorkflowAdapterResult::success(
			array(
				'secret' => 'never plaintext',
				'ok'     => true,
			)
		);
		$completed = $store->complete_step( 'run-store-1', 'read_content', $claimed?->fence() ?? 0, $result, 7 );
		self::assertNotNull( $completed );
		self::assertSame( 'success', $completed?->result_code() );
		self::assertSame( WorkflowRunState::RUNNING, $store->get( 'run-store-1' )?->state() );
		self::assertStringContainsString( 'never plaintext', (string) $completed?->output_json() );

		$step_raw = $this->wpdb()->get_row( $this->wpdb()->prepare( 'SELECT * FROM %i WHERE step_id = %s', 'wp_aculect_ai_workflow_run_steps', 'read_content' ), 'ARRAY_A' );
		self::assertIsArray( $step_raw );
		self::assertStringStartsWith( 'v1:', (string) $step_raw['output_ciphertext'] );
		self::assertStringNotContainsString( 'never plaintext', (string) $step_raw['output_ciphertext'] );
		self::assertSame( WorkflowRunState::RUNNING, $store->get( 'run-store-1' )?->state() );
	}

	public function test_approval_reference_hash_is_persisted_across_lifecycle_cas(): void {
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore( null, static fn (): int => 1724889600 );
		$store->create( 'run-approval-hash', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		$hash    = hash( 'sha256', 'approval-reference' );
		$running = $store->transition( 'run-approval-hash', WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7, null, null, $hash );

		self::assertNotNull( $running );
		self::assertSame( $hash, $running?->approval_reference_hash() );
		self::assertTrue( $running?->to_array()['approval_recorded'] ?? false );
		self::assertArrayNotHasKey( 'approval_reference_hash', $running?->to_array() ?? array() );
		$raw = $this->wpdb()->get_row( $this->wpdb()->prepare( 'SELECT * FROM %i WHERE run_id = %s', 'wp_aculect_ai_workflow_runs', 'run-approval-hash' ), 'ARRAY_A' );
		self::assertSame( $hash, $raw['approval_reference_hash'] ?? null );
	}

	public function test_store_rejects_input_hash_mismatch_and_invalid_stored_output(): void {
		$plan  = $this->plan( '{"post_id":9}' );
		$store = new WorkflowRunStore();

		try {
			$store->create( 'run-store-2', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, WorkflowInputContract::from_json( '{"post_id":10}' ), WorkflowRunState::PREPARED, 7 );
			self::fail( 'Input and plan hashes must be bound before persistence.' );
		} catch ( WorkflowRunStoreException $exception ) {
			self::assertSame( 'input_plan_mismatch', $exception->error_code() );
		}

		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store->create( 'run-store-3', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		$wpdb = $this->wpdb();
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET output_ciphertext = %s, output_hash = %s WHERE step_id = %s',
				'wp_aculect_ai_workflow_run_steps',
				'plaintext-output',
				str_repeat( 'a', 64 ),
				'read_content'
			)
		);

		try {
			$store->steps( 'run-store-3' );
			self::fail( 'Plaintext output must not cross the mapper boundary.' );
		} catch ( WorkflowRunStoreException $exception ) {
			self::assertSame( 'stored_output_invalid', $exception->error_code() );
		}
	}

	public function test_late_step_completion_is_rejected_after_run_cancellation(): void {
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore( null, static fn (): int => 1724889600 );
		$store->create( 'run-store-cancel', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		$running = $store->transition( 'run-store-cancel', WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 );
		self::assertNotNull( $running );
		$claimed = $store->claim_step( 'run-store-cancel', 'read_content', 7 );
		self::assertNotNull( $claimed );
		self::assertNull(
			$store->transition( 'run-store-cancel', WorkflowRunState::RUNNING, 2, WorkflowRunState::CANCELLED, 7, 'safe_stop' ),
			'Cancellation must not cross an active durable step lease.'
		);
		self::assertNotNull( $store->fail_step( 'run-store-cancel', 'read_content', $claimed?->fence() ?? 0, 'execution_not_available', 7 ) );
		$cancelled = $store->transition( 'run-store-cancel', WorkflowRunState::RUNNING, 2, WorkflowRunState::CANCELLED, 7, 'safe_stop' );
		self::assertNotNull( $cancelled );
		self::assertNull( $cancelled?->waiting_expires_at(), 'Cancellation must persist SQL NULL for waiting_expires_at.' );

		self::assertNull(
			$store->complete_step(
				'run-store-cancel',
				'read_content',
				$claimed?->fence() ?? 0,
				WorkflowAdapterResult::success( array( 'late' => true ) ),
				7
			),
			'An adapter completion arriving after cancellation must be fenced out.'
		);
	}

	public function test_cancellation_is_rejected_while_an_uncertain_step_is_durable(): void {
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore( null, static fn (): int => 1724889600 );
		$store->create( 'run-store-uncertain-cancel', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		self::assertNotNull( $store->transition( 'run-store-uncertain-cancel', WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 ) );
		$claimed = $store->claim_step( 'run-store-uncertain-cancel', 'read_content', 7 );
		self::assertNotNull( $claimed );
		self::assertNotNull( $store->fail_step( 'run-store-uncertain-cancel', 'read_content', $claimed?->fence() ?? 0, 'execution_uncertain', 7 ) );

		self::assertNull(
			$store->transition( 'run-store-uncertain-cancel', WorkflowRunState::RUNNING, 2, WorkflowRunState::CANCELLED, 7, 'safe_stop' ),
			'An uncertain durable step must be reconciled before cancellation.'
		);
	}

	public function test_late_step_completion_is_rejected_after_its_lease_expires(): void {
		$now   = 1724889600;
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore(
			null,
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$store->create( 'run-store-lease', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		$running = $store->transition( 'run-store-lease', WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 );
		self::assertNotNull( $running );
		$claimed = $store->claim_step( 'run-store-lease', 'read_content', 7 );
		self::assertNotNull( $claimed );
		self::assertNotNull( $claimed?->lease_expires_at() );

		$now += 31;
		self::assertNull(
			$store->complete_step(
				'run-store-lease',
				'read_content',
				$claimed?->fence() ?? 0,
				WorkflowAdapterResult::success( array( 'late' => true ) ),
				7
			),
			'An adapter completion arriving after lease expiry must be fenced out.'
		);
		$reclaimed = $store->claim_step( 'run-store-lease', 'read_content', 7 );
		self::assertNotNull( $reclaimed );
		self::assertGreaterThan( $claimed?->fence() ?? 0, $reclaimed?->fence() ?? 0 );

		self::assertNull(
			$store->complete_step(
				'run-store-lease',
				'read_content',
				$claimed?->fence() ?? 0,
				WorkflowAdapterResult::success( array( 'late' => true ) ),
				7
			),
			'An adapter completion arriving after lease expiry must be fenced out.'
		);
	}

	public function test_only_execution_uncertain_can_close_an_expired_step_claim(): void {
		$now   = 1724889600;
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore(
			null,
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$store->create( 'run-store-expired-failure', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		self::assertNotNull( $store->transition( 'run-store-expired-failure', WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 ) );
		$claimed = $store->claim_step( 'run-store-expired-failure', 'read_content', 7 );
		self::assertNotNull( $claimed );
		$now += 31;

		self::assertNull(
			$store->fail_step( 'run-store-expired-failure', 'read_content', $claimed?->fence() ?? 0, 'execution_not_available', 7 ),
			'An expired claim must not accept a late adapter failure code.'
		);
		$uncertain = $store->fail_step( 'run-store-expired-failure', 'read_content', $claimed?->fence() ?? 0, 'execution_uncertain', 7 );
		self::assertNotNull( $uncertain );
		self::assertSame( WorkflowStepState::FAILED, $uncertain?->state() );
		self::assertSame( 'execution_uncertain', $uncertain?->error_code() );
	}

	public function test_retention_prunes_expired_waiting_and_stale_prepared_rows(): void {
		$now   = 1724889600;
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore(
			null,
			static function () use ( &$now ): int {
				return $now;
			}
		);

		$store->create( 'run-retention-waiting', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::WAITING_FOR_INPUT, 7, null, gmdate( 'Y-m-d H:i:s', $now - 1 ) );
		$now += 8 * 86400;
		$store->create( 'run-retention-waiting-new', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		self::assertNull( $store->get( 'run-retention-waiting' ), 'An expired waiting window must not be extended by the retention cutoff.' );

		$store->create( 'run-retention-prepared', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		$dry_run = $store->create( 'run-retention-dry-run', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		self::assertNotNull( $store->transition( $dry_run->run_id(), WorkflowRunState::PREPARED, 1, WorkflowRunState::DRY_RUN_READY, 7 ) );
		$now += 2592001;
		$store->create( 'run-retention-terminal-new', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );

		self::assertNull( $store->get( 'run-retention-prepared' ) );
		self::assertNull( $store->get( 'run-retention-dry-run' ) );
		self::assertNotNull( $store->get( 'run-retention-terminal-new' ) );
	}

	public function test_retention_fences_stale_running_rows_before_they_can_be_deleted(): void {
		$now   = 1724889600;
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore(
			null,
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$store->create( 'run-retention-abandoned', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		self::assertNotNull( $store->transition( 'run-retention-abandoned', WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 ) );
		$now += 2592001;
		$store->create( 'run-retention-abandoned-new', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );

		$abandoned = $store->get( 'run-retention-abandoned' );
		self::assertNotNull( $abandoned );
		self::assertSame( WorkflowRunState::FAILED, $abandoned?->state() );
		self::assertSame( 'execution_uncertain', $abandoned?->outcome_code() );
	}

	public function test_retention_rolls_back_parent_when_child_delete_returns_false(): void {
		$now   = 1724889600;
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore(
			null,
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$store->create( 'run-retention-failure', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		$now                                += 2592001;
		$this->wpdb()->fail_query_containing = 'workflow_run_steps';
		$store->create( 'run-retention-failure-new', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );

		self::assertNotNull( $store->get( 'run-retention-failure' ), 'A failed child delete must roll back the parent delete.' );
		self::assertCount( 1, $store->steps( 'run-retention-failure' ) );
		self::assertNotNull( $store->get( 'run-retention-failure-new' ) );
	}

	public function test_retention_rolls_back_parent_when_parent_delete_returns_false(): void {
		$now   = 1724889600;
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore(
			null,
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$store->create( 'run-retention-parent-failure', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		$now                                += 2592001;
		$this->wpdb()->fail_query_containing = 'DELETE FROM "wp_aculect_ai_workflow_runs"';
		$store->create( 'run-retention-parent-failure-new', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );

		self::assertNotNull( $store->get( 'run-retention-parent-failure' ) );
		self::assertCount( 1, $store->steps( 'run-retention-parent-failure' ) );
	}

	public function test_retention_rolls_back_parent_when_commit_returns_false(): void {
		$now   = 1724889600;
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore(
			null,
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$store->create( 'run-retention-commit-failure', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		$now                                += 2592001;
		$this->wpdb()->fail_query_containing = 'COMMIT';
		$this->wpdb()->fail_query_once       = true;
		$store->create( 'run-retention-commit-failure-new', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );

		self::assertNotNull( $store->get( 'run-retention-commit-failure' ) );
		self::assertCount( 1, $store->steps( 'run-retention-commit-failure' ) );
	}

	public function test_create_fails_closed_when_start_transaction_returns_false(): void {
		$plan                                = $this->plan( '{"post_id":9}' );
		$input                               = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store                               = new WorkflowRunStore();
		$this->wpdb()->fail_query_containing = 'START TRANSACTION';
		$this->wpdb()->fail_query_once       = true;

		try {
			$store->create( 'run-start-failure', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
			self::fail( 'A failed transaction start must not persist a run.' );
		} catch ( WorkflowRunStoreException $exception ) {
			self::assertSame( 'transaction_failed', $exception->error_code() );
		}
		self::assertNull( $store->get( 'run-start-failure' ) );
	}

	public function test_run_tables_are_repaired_to_innodb_before_use(): void {
		$wpdb = $this->wpdb();
		$wpdb->table_engines['wp_aculect_ai_workflow_runs']      = 'MyISAM';
		$wpdb->table_engines['wp_aculect_ai_workflow_run_steps'] = 'MyISAM';

		self::assertTrue( \Aculect\AICompanion\Workflows\Database\RunInstaller::install() );
		self::assertSame( 'InnoDB', $wpdb->table_engines['wp_aculect_ai_workflow_runs'] );
		self::assertSame( 'InnoDB', $wpdb->table_engines['wp_aculect_ai_workflow_run_steps'] );
	}

	public function test_run_tables_fail_closed_when_engine_repair_fails(): void {
		$wpdb = $this->wpdb();
		$wpdb->table_engines['wp_aculect_ai_workflow_runs'] = 'MyISAM';
		$wpdb->fail_query_containing                        = 'ALTER TABLE';

		self::assertFalse( \Aculect\AICompanion\Workflows\Database\RunInstaller::install() );
	}

	public function test_engine_repair_failure_is_retry_throttled_until_backoff_expires(): void {
		$wpdb = $this->wpdb();
		$wpdb->table_engines['wp_aculect_ai_workflow_runs']      = 'MyISAM';
		$wpdb->table_engines['wp_aculect_ai_workflow_run_steps'] = 'MyISAM';
		$wpdb->fail_query_containing                             = 'ALTER TABLE';
		$wpdb->fail_query_once                                   = true;

		self::assertFalse( \Aculect\AICompanion\Workflows\Database\RunInstaller::install() );
		$retry_after = (int) get_option( 'aculect_ai_companion_workflow_runs_engine_repair_retry_after', 0 );
		self::assertGreaterThan( time(), $retry_after );
		self::assertSame( 0, $wpdb->alter_count );

		self::assertFalse( \Aculect\AICompanion\Workflows\Database\RunInstaller::install() );
		self::assertSame( 0, $wpdb->alter_count, 'A failed repair must not synchronously retry on every boot.' );

		update_option( 'aculect_ai_companion_workflow_runs_engine_repair_retry_after', time() - 1, false );
		self::assertTrue( \Aculect\AICompanion\Workflows\Database\RunInstaller::install() );
		self::assertSame( 2, $wpdb->alter_count );
	}

	public function test_malformed_and_far_future_repair_options_are_replaced_before_repair(): void {
		$cases = array(
			array(
				'lock'  => 'not-a-canonical-lease',
				'retry' => 'not-a-timestamp',
			),
			array(
				'lock'  => str_repeat( 'a', 32 ) . ':' . ( time() + 3600 ),
				'retry' => (string) ( time() + 3600 ),
			),
		);

		foreach ( $cases as $case ) {
			$wpdb            = new WorkflowRunSqliteWpdb();
			$GLOBALS['wpdb'] = $wpdb;
			update_option( 'aculect_ai_companion_workflow_runs_engine_repair_lock', $case['lock'], false );
			update_option( 'aculect_ai_companion_workflow_runs_engine_repair_retry_after', $case['retry'], false );
			$wpdb->table_engines['wp_aculect_ai_workflow_runs']      = 'MyISAM';
			$wpdb->table_engines['wp_aculect_ai_workflow_run_steps'] = 'MyISAM';

			self::assertTrue( \Aculect\AICompanion\Workflows\Database\RunInstaller::install() );
			self::assertSame( 2, $wpdb->alter_count );
			self::assertSame( 'InnoDB', $wpdb->table_engines['wp_aculect_ai_workflow_runs'] );
			self::assertSame( 'InnoDB', $wpdb->table_engines['wp_aculect_ai_workflow_run_steps'] );
			self::assertSame( 'missing', get_option( 'aculect_ai_companion_workflow_runs_engine_repair_lock', 'missing' ) );
			self::assertSame( 'missing', get_option( 'aculect_ai_companion_workflow_runs_engine_repair_retry_after', 'missing' ) );
		}
	}

	public function test_false_is_mysql_does_not_claim_sqlite_transactionality(): void {
		$wpdb           = $this->wpdb();
		$wpdb->is_mysql = false;
		$wpdb->table_engines['wp_aculect_ai_workflow_runs']      = 'MyISAM';
		$wpdb->table_engines['wp_aculect_ai_workflow_run_steps'] = 'MyISAM';

		self::assertFalse( \Aculect\AICompanion\Workflows\Database\RunInstaller::install() );
		self::assertSame( 'MyISAM', $wpdb->table_engines['wp_aculect_ai_workflow_runs'] );
		self::assertSame( 'MyISAM', $wpdb->table_engines['wp_aculect_ai_workflow_run_steps'] );
	}

	public function test_waiting_transition_cas_rechecks_expiry_in_sql(): void {
		$now   = 1724889600;
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore(
			null,
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$store->create( 'run-expiry-transition', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::WAITING_FOR_INPUT, 7, null, gmdate( 'Y-m-d H:i:s', $now + 10 ) );
		$now += 11;

		self::assertNull( $store->transition( 'run-expiry-transition', WorkflowRunState::WAITING_FOR_INPUT, 1, WorkflowRunState::PREPARED, 7 ) );
	}

	public function test_waiting_plan_replacement_cas_rechecks_expiry_in_sql(): void {
		$now   = 1724889600;
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore(
			null,
			static function () use ( &$now ): int {
				return $now;
			}
		);
		$store->create( 'run-expiry-replacement', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::WAITING_FOR_INPUT, 7, null, gmdate( 'Y-m-d H:i:s', $now + 10 ) );
		$now += 11;

		self::assertNull( $store->replace_plan( 'run-expiry-replacement', 1, $plan, $input, 7 ) );
	}

	public function test_run_schema_has_separate_run_and_step_fencing_fields(): void {
		$method = new ReflectionMethod( \Aculect\AICompanion\Workflows\Database\RunInstaller::class, 'schema_sql' );
		$sql    = (string) $method->invoke(
			null,
			array(
				'runs'  => 'wp_aculect_ai_workflow_runs',
				'steps' => 'wp_aculect_ai_workflow_run_steps',
			),
			'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);

		self::assertStringContainsString( 'input_ciphertext longtext NOT NULL', $sql );
		self::assertStringContainsString( 'state_version bigint(20) unsigned NOT NULL DEFAULT 1', $sql );
		self::assertStringContainsString( 'output_ciphertext longtext NOT NULL', $sql );
		self::assertStringContainsString( 'output_hash char(64) NOT NULL DEFAULT', $sql );
		self::assertStringContainsString( 'lease_expires_at datetime NULL', $sql );
		self::assertStringContainsString( 'approval_reference_hash char(64) NOT NULL DEFAULT', $sql );
		self::assertStringContainsString( 'UNIQUE KEY run_step (run_pk, step_id)', $sql );
		self::assertStringContainsString( 'ENGINE=InnoDB', $sql );
		self::assertStringNotContainsStringIgnoringCase( 'foreign key', $sql );
	}

	private function plan( string $input_json ): WorkflowPlan {
		$path = dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/proposal-only-v1.json';
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned fixture.
		self::assertIsString( $json );

		return ( new WorkflowPlanBuilder() )->build( WorkflowDefinition::from_json( $json ), WorkflowInputContract::from_json( $input_json ) );
	}

	private function wpdb(): WorkflowRunSqliteWpdb {
		/* @var WorkflowRunSqliteWpdb $wpdb */
		$wpdb = $GLOBALS['wpdb'];

		return $wpdb;
	}
}
