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
			'aculect_ai_companion_workflow_runs_db_version' => '2026.08.29.1',
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

	public function test_transaction_failures_do_not_report_or_leave_partial_runs(): void {
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore( null, static fn (): int => 1724889600 );
		$wpdb  = $this->wpdb();

		$wpdb->fail_begin = true;
		try {
			$store->create( 'run-begin-failure', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
			self::fail( 'A failed transaction start must not persist a parent row.' );
		} catch ( WorkflowRunStoreException $exception ) {
			self::assertSame( 'transaction_begin_failed', $exception->error_code() );
		}
		self::assertSame( 0, (int) $wpdb->scalar( 'SELECT count(*) FROM wp_aculect_ai_workflow_runs' ) );

		$wpdb->fail_begin  = false;
		$wpdb->fail_commit = true;
		try {
			$store->create( 'run-commit-failure', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
			self::fail( 'A failed commit must not report a created run.' );
		} catch ( WorkflowRunStoreException $exception ) {
			self::assertSame( 'transaction_commit_failed', $exception->error_code() );
		}
		self::assertSame( 0, (int) $wpdb->scalar( 'SELECT count(*) FROM wp_aculect_ai_workflow_runs' ) );
		self::assertSame( 0, (int) $wpdb->scalar( 'SELECT count(*) FROM wp_aculect_ai_workflow_run_steps' ) );
	}

	public function test_parent_cancellation_fences_claims_and_late_completion(): void {
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore( null, static fn (): int => 1724889600 );
		$wpdb  = $this->wpdb();
		$store->create( 'run-cancel-race', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		$store->transition( 'run-cancel-race', WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 );
		$wpdb->before_claim = static function ( WorkflowRunSqliteWpdb $inner ): void {
			$inner->query( "UPDATE wp_aculect_ai_workflow_runs SET state = 'cancelled', state_version = 3, outcome_code = 'safe_stop' WHERE run_id = 'run-cancel-race'" );
		};

		self::assertNull( $store->claim_step( 'run-cancel-race', 'read_content', 7 ) );
		self::assertSame( WorkflowRunState::CANCELLED, $store->get( 'run-cancel-race' )?->state() );

		$store->create( 'run-cancel-active', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		$store->transition( 'run-cancel-active', WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 );
		$claimed = $store->claim_step( 'run-cancel-active', 'read_content', 7 );
		self::assertNotNull( $claimed );
		self::assertNull( $store->transition( 'run-cancel-active', WorkflowRunState::RUNNING, 2, WorkflowRunState::CANCELLED, 7, 'safe_stop' ) );
		$wpdb->query( "UPDATE wp_aculect_ai_workflow_runs SET state = 'cancelled', state_version = 3, outcome_code = 'safe_stop' WHERE run_id = 'run-cancel-active'" );
		self::assertNull( $store->complete_step( 'run-cancel-active', 'read_content', $claimed?->fence() ?? 0, WorkflowAdapterResult::success( array( 'ok' => true ) ), 7 ) );
	}

	public function test_lost_step_fences_and_sql_failures_fail_closed(): void {
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore( null, static fn (): int => 1724889600 );
		$wpdb  = $this->wpdb();
		$store->create( 'run-lost-fence', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::PREPARED, 7 );
		$store->transition( 'run-lost-fence', WorkflowRunState::PREPARED, 1, WorkflowRunState::RUNNING, 7 );
		$claimed = $store->claim_step( 'run-lost-fence', 'read_content', 7 );
		self::assertNotNull( $claimed );
		$wpdb->query( "UPDATE wp_aculect_ai_workflow_run_steps SET fence = 3 WHERE step_id = 'read_content'" );

		self::assertNull( $store->fail_step( 'run-lost-fence', 'read_content', 2, 'execution_failed', 7 ) );
		$wpdb->fail_finish = true;
		try {
			$store->complete_step( 'run-lost-fence', 'read_content', 3, WorkflowAdapterResult::success( array( 'ok' => true ) ), 7 );
			self::fail( 'A failed finish query must not acknowledge a saved result.' );
		} catch ( WorkflowRunStoreException $exception ) {
			self::assertSame( 'step_finish_failed', $exception->error_code() );
		}
	}

	public function test_waiting_deadline_is_required_and_cleared_when_input_resumes(): void {
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore( null, static fn (): int => 1724976000 );

		$store->create( 'run-expired-input', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::WAITING_FOR_INPUT, 7, null, '2000-01-01 00:00:00' );
		try {
			$store->replace_plan( 'run-expired-input', 1, $plan, $input, 7 );
			self::fail( 'Expired waiting input must not resume.' );
		} catch ( WorkflowRunStoreException $exception ) {
			self::assertSame( 'input_expired', $exception->error_code() );
		}

		$live  = $store->create( 'run-live-input', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::WAITING_FOR_INPUT, 7, null, '2030-01-01 00:00:00' );
		$ready = $store->replace_plan( 'run-live-input', $live->state_version(), $plan, $input, 7 );
		self::assertNotNull( $ready );
		self::assertNull( $ready?->waiting_expires_at() );
	}

	public function test_waiting_approval_deadline_is_enforced_and_cleared_when_execution_starts(): void {
		$plan  = $this->plan( '{"post_id":9}' );
		$input = WorkflowInputContract::from_json( '{"post_id":9}' );
		$store = new WorkflowRunStore( null, static fn (): int => 1724976000 );

		$expired = $store->create( 'run-expired-approval', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::DRY_RUN_READY, 7 );
		$waiting = $store->transition( 'run-expired-approval', WorkflowRunState::DRY_RUN_READY, $expired->state_version(), WorkflowRunState::WAITING_FOR_APPROVAL, 7, null, '2000-01-01 00:00:00' );
		self::assertNotNull( $waiting );
		self::assertNull( $store->transition( 'run-expired-approval', WorkflowRunState::WAITING_FOR_APPROVAL, $waiting?->state_version() ?? 0, WorkflowRunState::RUNNING, 7 ) );

		$live         = $store->create( 'run-live-approval', 'proposal_only_fixture', 1, $plan->definition_checksum(), $plan, $input, WorkflowRunState::DRY_RUN_READY, 7 );
		$waiting_live = $store->transition( 'run-live-approval', WorkflowRunState::DRY_RUN_READY, $live->state_version(), WorkflowRunState::WAITING_FOR_APPROVAL, 7, null, '2030-01-01 00:00:00' );
		$running      = $store->transition( 'run-live-approval', WorkflowRunState::WAITING_FOR_APPROVAL, $waiting_live?->state_version() ?? 0, WorkflowRunState::RUNNING, 7 );
		self::assertNotNull( $running );
		self::assertNull( $running?->waiting_expires_at() );
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
		self::assertStringContainsString( 'UNIQUE KEY run_step (run_pk, step_id)', $sql );
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
