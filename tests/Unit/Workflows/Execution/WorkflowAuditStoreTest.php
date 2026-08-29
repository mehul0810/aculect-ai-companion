<?php
/**
 * Tests for durable summary-only workflow audit storage.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Execution;

require_once dirname( __DIR__, 3 ) . '/Support/WorkflowRunSqliteWpdb.php';

use Aculect\AICompanion\Tests\Support\WorkflowRunSqliteWpdb;
use Aculect\AICompanion\Workflows\Database\AuditInstaller;
use Aculect\AICompanion\Workflows\Execution\WorkflowAuditRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowAuditStore;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStoreException;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Isolated SQLite storage fixture.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- The focused test replaces wpdb with an isolated adapter.

/** Verifies bounded audit persistence and fail-closed row mapping. */
final class WorkflowAuditStoreTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! extension_loaded( 'pdo_sqlite' ) ) {
			self::markTestSkipped( 'pdo_sqlite is required for workflow audit persistence tests.' );
		}

		$this->original_wpdb                          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']                              = new WorkflowRunSqliteWpdb();
		$GLOBALS['aculect_ai_companion_test_options'] = array(
			'aculect_ai_companion_workflow_audit_db_version' => '2026.08.29.1',
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

	public function test_append_and_queries_keep_only_bounded_summary_fields(): void {
		$store = new WorkflowAuditStore();
		$event = new WorkflowAuditRecord(
			'run-audit-1',
			'proposal_only_fixture',
			1,
			str_repeat( 'a', 64 ),
			'run_started',
			'',
			7,
			'started',
			hash( 'sha256', 'approval-reference' ),
			array( 'content.title', 'step.create_draft' ),
			'',
			'2026-08-29 00:00:00'
		);

		$store->append( $event );
		$for_run = $store->for_run( 'run-audit-1' );
		$recent  = $store->recent( 1 );

		self::assertCount( 1, $for_run );
		self::assertCount( 1, $recent );
		self::assertSame( $event->approval_reference_hash(), $for_run[0]->approval_reference_hash() );
		self::assertSame( array( 'content.title', 'step.create_draft' ), $for_run[0]->changed_fields() );
		self::assertTrue( $for_run[0]->to_array()['approval_recorded'] );
		self::assertArrayNotHasKey( 'approval_reference_hash', $for_run[0]->to_array() );

		$raw = $this->wpdb()->get_row(
			$this->wpdb()->prepare( 'SELECT * FROM %i WHERE run_id = %s', AuditInstaller::table_name(), 'run-audit-1' ),
			'ARRAY_A'
		);
		self::assertIsArray( $raw );
		self::assertSame( hash( 'sha256', 'approval-reference' ), $raw['approval_reference_hash'] );
		self::assertStringNotContainsString( 'approval-reference', (string) $raw['approval_reference_hash'] );
		self::assertSame( '["content.title","step.create_draft"]', $raw['changed_fields'] );
	}

	public function test_malformed_rows_fail_closed(): void {
		$store = new WorkflowAuditStore();
		$wpdb  = $this->wpdb();
		$wpdb->insert(
			AuditInstaller::table_name(),
			array(
				'run_id'                  => 'run-audit-2',
				'workflow_id'             => 'proposal_only_fixture',
				'workflow_version'        => 1,
				'definition_checksum'     => str_repeat( 'b', 64 ),
				'event_type'              => 'run_started',
				'step_id'                 => '',
				'actor_id'                => 7,
				'outcome_code'            => '',
				'approval_reference_hash' => '',
				'changed_fields'          => '{}',
				'rollback_note'           => '',
				'created_at'              => '2026-08-29 00:00:00',
			),
			array()
		);

		try {
			$store->for_run( 'run-audit-2' );
			self::fail( 'Malformed audit rows must not be returned.' );
		} catch ( WorkflowRunStoreException $exception ) {
			self::assertSame( 'audit_row_invalid', $exception->error_code() );
		}
	}

	public function test_schema_is_summary_only_and_site_scoped(): void {
		$sql = AuditInstaller::schema_sql(
			'wp_42_aculect_ai_workflow_audit',
			'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);

		self::assertStringContainsString( 'CREATE TABLE wp_42_aculect_ai_workflow_audit', $sql );
		self::assertStringContainsString( 'approval_reference_hash char(64) NOT NULL', $sql );
		self::assertStringContainsString( 'changed_fields text NOT NULL', $sql );
		self::assertStringContainsString( 'rollback_note varchar(255) NOT NULL', $sql );
		self::assertStringNotContainsStringIgnoringCase( 'input_ciphertext', $sql );
		self::assertStringNotContainsStringIgnoringCase( 'output_ciphertext', $sql );
	}

	private function wpdb(): WorkflowRunSqliteWpdb {
		$wpdb = $GLOBALS['wpdb'] ?? null;
		if ( ! $wpdb instanceof WorkflowRunSqliteWpdb ) {
			throw new \RuntimeException( 'SQLite wpdb fixture is unavailable.' );
		}

		return $wpdb;
	}
}
