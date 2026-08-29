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
