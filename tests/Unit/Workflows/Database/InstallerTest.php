<?php
/**
 * Tests for the custom workflow definition storage installer.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Database
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Database;

use Aculect\AICompanion\Plugin;
use Aculect\AICompanion\Connectors\OAuth\IssuerBinding;
use Aculect\AICompanion\Workflows\Database\Installer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused installer tests replace wpdb with a local test double.

/**
 * Verifies exact, site-scoped installation and repair behavior.
 */
final class InstallerTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb                          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		unset(
			$GLOBALS['aculect_ai_companion_test_db_delta_callback'],
			$GLOBALS['aculect_ai_companion_test_failed_option_updates'],
			$GLOBALS['aculect_ai_companion_test_failed_option_deletes']
		);
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		unset(
			$GLOBALS['aculect_ai_companion_test_db_delta_callback'],
			$GLOBALS['aculect_ai_companion_test_failed_option_updates'],
			$GLOBALS['aculect_ai_companion_test_failed_option_deletes']
		);

		parent::tearDown();
	}

	public function test_schema_sql_is_the_exact_portable_phase_a_contract(): void {
		$sql = $this->schema_sql(
			array(
				'catalog'  => 'wp_aculect_ai_workflows',
				'versions' => 'wp_aculect_ai_workflow_versions',
			),
			'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
		);

		self::assertStringContainsString( 'CREATE TABLE wp_aculect_ai_workflows', $sql );
		self::assertStringContainsString( 'workflow_id varchar(64) NOT NULL', $sql );
		self::assertStringContainsString( "status varchar(20) NOT NULL DEFAULT 'draft'", $sql );
		self::assertStringContainsString( 'latest_version int(10) unsigned NOT NULL DEFAULT 0', $sql );
		self::assertStringContainsString( 'published_version int(10) unsigned NOT NULL DEFAULT 0', $sql );
		self::assertStringContainsString( 'template_id varchar(64) NOT NULL DEFAULT', $sql );
		self::assertStringContainsString( 'lock_version bigint(20) unsigned NOT NULL DEFAULT 1', $sql );
		self::assertStringContainsString( 'UNIQUE KEY workflow_id (workflow_id)', $sql );
		self::assertStringContainsString( 'KEY status_updated (status, updated_at, id)', $sql );
		self::assertStringContainsString( 'ENGINE=InnoDB', $sql );

		self::assertStringContainsString( 'CREATE TABLE wp_aculect_ai_workflow_versions', $sql );
		self::assertStringContainsString( 'workflow_pk bigint(20) unsigned NOT NULL', $sql );
		self::assertStringContainsString( 'definition_schema_version smallint(5) unsigned NOT NULL', $sql );
		self::assertStringContainsString( 'definition_checksum char(64) NOT NULL', $sql );
		self::assertStringContainsString( 'definition_json longtext NOT NULL', $sql );
		self::assertStringContainsString( 'UNIQUE KEY workflow_version (workflow_pk, workflow_version)', $sql );
		self::assertStringContainsString( 'KEY definition_schema (definition_schema_version, id)', $sql );

		self::assertStringNotContainsStringIgnoringCase( 'foreign key', $sql );
		self::assertStringNotContainsStringIgnoringCase( ' enum(', $sql );
		self::assertStringNotContainsStringIgnoringCase( ' json ', $sql );
		self::assertStringNotContainsStringIgnoringCase( ' generated ', $sql );
	}

	public function test_transactional_storage_requires_innodb_for_both_definition_tables(): void {
		$wpdb            = new WorkflowInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;

		self::assertTrue( Installer::transactional_tables_available() );

		$wpdb->table_engines['wp_aculect_ai_workflow_versions'] = 'MyISAM';
		self::assertFalse( Installer::transactional_tables_available() );

		$wpdb->table_engines['wp_aculect_ai_workflow_versions'] = '';
		self::assertFalse( Installer::transactional_tables_available() );
	}

	public function test_transactional_storage_rejects_unknown_database_adapters(): void {
		$wpdb            = new WorkflowInstallerWpdb();
		$wpdb->is_mysql  = false;
		$GLOBALS['wpdb'] = $wpdb;

		self::assertFalse( Installer::transactional_tables_available() );
	}

	public function test_stale_schema_creates_both_tables_and_records_version_only_after_verification(): void {
		$wpdb            = new WorkflowInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;
			$wpdb->existing_tables    = array_values( Installer::table_names() );

			return array( 'created workflow definition tables' );
		};

		self::assertTrue( Installer::install() );
		self::assertSame( '2026.08.19.1', get_option( 'aculect_ai_companion_workflows_db_version', 'missing' ) );
		self::assertSame( array(), Installer::missing_table_keys() );
		self::assertCount( 1, $wpdb->db_delta_queries );
	}

	public function test_current_schema_repairs_one_missing_multisite_table_on_default_lazy_path(): void {
		$wpdb                  = new WorkflowInstallerWpdb();
		$wpdb->prefix          = 'wp_21_';
		$wpdb->existing_tables = array( 'wp_21_aculect_ai_workflows' );
		$GLOBALS['wpdb']       = $wpdb;
		update_option( 'aculect_ai_companion_workflows_db_version', '2026.08.19.1', false );
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;
			$wpdb->existing_tables[]  = 'wp_21_aculect_ai_workflow_versions';

			return array( 'created versions table' );
		};

		self::assertTrue( Installer::install() );
		self::assertSame( array(), Installer::missing_table_keys() );
		self::assertCount( 1, $wpdb->db_delta_queries );
		self::assertStringContainsString( 'wp_21_aculect_ai_workflows', $wpdb->db_delta_queries[0] );
		self::assertStringContainsString( 'wp_21_aculect_ai_workflow_versions', $wpdb->db_delta_queries[0] );
	}

	public function test_failed_repair_does_not_advance_schema_version(): void {
		$wpdb            = new WorkflowInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_workflows_db_version', '0', false );

		self::assertFalse( Installer::install( true ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_workflows_db_version', 'missing' ) );
		self::assertSame( array( 'catalog', 'versions' ), Installer::missing_table_keys() );
		$calls_after_failure = $wpdb->get_var_calls;

		self::assertFalse( Installer::install() );
		self::assertSame( $calls_after_failure, $wpdb->get_var_calls, 'A failed repair must be retry-throttled.' );
	}

	public function test_failed_lazy_repair_invalidates_a_current_schema_version(): void {
		$wpdb                  = new WorkflowInstallerWpdb();
		$wpdb->existing_tables = array( 'wp_aculect_ai_workflows', 'wp_aculect_ai_companion_execution_claims' );
		$GLOBALS['wpdb']       = $wpdb;
		update_option( 'aculect_ai_companion_workflows_db_version', '2026.08.19.1', false );

		self::assertFalse( Installer::install() );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_workflows_db_version', 'missing' ) );
		self::assertSame(
			'failed',
			get_option( 'aculect_ai_companion_workflows_db_verification', array() )['status'] ?? ''
		);
	}

	public function test_failed_schema_version_write_is_reported(): void {
		$wpdb                  = new WorkflowInstallerWpdb();
		$wpdb->existing_tables = array_values(
			array(
				'wp_aculect_ai_workflows',
				'wp_aculect_ai_workflow_versions',
			)
		);
		$GLOBALS['wpdb']       = $wpdb;
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array( 'aculect_ai_companion_workflows_db_version' );

		self::assertFalse( Installer::install() );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_workflows_db_version', 'missing' ) );
	}

	public function test_legacy_valid_lifecycle_state_never_bypasses_authoritative_table_checks(): void {
		$wpdb                  = new WorkflowInstallerWpdb();
		$wpdb->existing_tables = array( 'wp_aculect_ai_workflows', 'wp_aculect_ai_workflow_versions' );
		$GLOBALS['wpdb']       = $wpdb;
		update_option( 'aculect_ai_companion_workflows_db_version', '2026.08.19.1', false );
		update_option(
			'aculect_ai_companion_workflows_db_verification',
			array(
				'status'        => 'valid',
				'db_version'    => '2026.08.19.1',
				'next_check_at' => time() + 3600,
			),
			false
		);

		self::assertTrue( Installer::install() );
		self::assertSame( 2, $wpdb->get_var_calls );
		self::assertSame( array(), $wpdb->db_delta_queries );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_workflows_db_verification', 'missing' ) );
	}

	public function test_table_loss_after_success_is_repaired_despite_a_future_valid_state(): void {
		$wpdb                  = new WorkflowInstallerWpdb();
		$wpdb->existing_tables = array( 'wp_aculect_ai_workflows', 'wp_aculect_ai_workflow_versions' );
		$GLOBALS['wpdb']       = $wpdb;
		update_option( 'aculect_ai_companion_workflows_db_version', '2026.08.19.1', false );

		self::assertTrue( Installer::install() );
		$wpdb->existing_tables = array( 'wp_aculect_ai_workflows' );
		update_option(
			'aculect_ai_companion_workflows_db_verification',
			array(
				'status'        => 'valid',
				'db_version'    => '2026.08.19.1',
				'next_check_at' => time() + 9 * 3600,
			),
			false
		);
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;
			$wpdb->existing_tables[]  = 'wp_aculect_ai_workflow_versions';

			return array( 'restored versions table' );
		};

		self::assertTrue( Installer::install() );
		self::assertSame( array(), Installer::missing_table_keys() );
		self::assertCount( 1, $wpdb->db_delta_queries );
	}

	public function test_invalid_failure_clocks_force_immediate_authoritative_verification(): void {
		$invalid_clocks = array(
			'9e18',
			9.0e18,
			'123.4',
			time() + 3600,
		);

		foreach ( $invalid_clocks as $clock ) {
			$wpdb            = new WorkflowInstallerWpdb();
			$GLOBALS['wpdb'] = $wpdb;
			$GLOBALS['aculect_ai_companion_test_options']           = array(
				'aculect_ai_companion_workflows_db_version'      => '0',
				'aculect_ai_companion_workflows_db_verification' => array(
					'status'        => 'failed',
					'db_version'    => '0',
					'next_check_at' => $clock,
				),
			);
			$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
				$wpdb->db_delta_queries[] = $sql;
				$wpdb->existing_tables    = array_values( Installer::table_names() );

				return array( 'created workflow definition tables' );
			};

			self::assertTrue( Installer::install(), 'Invalid clocks must not suppress verification.' );
			self::assertSame( 2, $wpdb->get_var_calls );
		}
	}

	public function test_failed_schema_version_delete_falls_back_to_a_mismatched_version(): void {
		$wpdb                  = new WorkflowInstallerWpdb();
		$wpdb->existing_tables = array( 'wp_aculect_ai_workflows' );
		$GLOBALS['wpdb']       = $wpdb;
		update_option( 'aculect_ai_companion_workflows_db_version', '2026.08.19.1', false );
		$GLOBALS['aculect_ai_companion_test_failed_option_deletes'] = array( 'aculect_ai_companion_workflows_db_version' );

		self::assertFalse( Installer::install() );
		self::assertSame( '0', get_option( 'aculect_ai_companion_workflows_db_version', 'missing' ) );
	}

	public function test_failed_schema_version_invalidation_forces_the_next_authoritative_recheck(): void {
		$wpdb                  = new WorkflowInstallerWpdb();
		$wpdb->existing_tables = array( 'wp_aculect_ai_workflows' );
		$GLOBALS['wpdb']       = $wpdb;
		update_option( 'aculect_ai_companion_workflows_db_version', '2026.08.19.1', false );
		$GLOBALS['aculect_ai_companion_test_failed_option_deletes'] = array( 'aculect_ai_companion_workflows_db_version' );
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array( 'aculect_ai_companion_workflows_db_version' );

		self::assertFalse( Installer::install() );
		$calls_after_failure = $wpdb->get_var_calls;

		self::assertFalse( Installer::install() );
		self::assertGreaterThan( $calls_after_failure, $wpdb->get_var_calls );
	}

	public function test_plugin_boot_repairs_a_missing_table_with_current_schema_version(): void {
		$wpdb                  = new WorkflowInstallerWpdb();
		$wpdb->existing_tables = array( 'wp_aculect_ai_workflows' );
		$GLOBALS['wpdb']       = $wpdb;
		$GLOBALS['aculect_ai_companion_test_options']           = array(
			'aculect_ai_companion_execution_claims_db_version' => '2026.08.19.1',
			'aculect_ai_companion_oauth_db_version'        => '2026.08.19.1',
			'aculect_ai_companion_oauth_legacy_migrated'   => '1',
			'aculect_ai_companion_oauth_issuer_backfill'   => IssuerBinding::hash(),
			'aculect_ai_companion_logs_db_version'         => '2026.05.17.1',
			'aculect_ai_companion_activity_db_version'     => '2026.05.20.1',
			'aculect_ai_companion_intelligence_db_version' => '2026.07.26.1',
			'aculect_ai_companion_workflows_db_version'    => '2026.08.19.1',
			'aculect_ai_companion_workflows_db_verification' => array(
				'status'        => 'valid',
				'db_version'    => '2026.08.19.1',
				'next_check_at' => time() + 9 * 3600,
			),
			'aculect_ai_companion_oauth_prune_lock_expires_at' => 'outcome:success:0123456789abcdef0123456789abcdef:' . ( time() + 3600 ),
		);
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;
			$wpdb->existing_tables[]  = 'wp_aculect_ai_workflow_versions';

			return array( 'created versions table' );
		};

		Plugin::instance()->boot();

		self::assertSame( array(), Installer::missing_table_keys() );
		self::assertCount( 1, $wpdb->db_delta_queries );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_workflows_db_verification', 'missing' ) );
	}

	public function test_table_names_follow_the_current_site_prefix(): void {
		$wpdb            = new WorkflowInstallerWpdb();
		$wpdb->prefix    = 'wp_42_';
		$GLOBALS['wpdb'] = $wpdb;

		self::assertSame(
			array(
				'catalog'  => 'wp_42_aculect_ai_workflows',
				'versions' => 'wp_42_aculect_ai_workflow_versions',
			),
			Installer::table_names()
		);
	}

	/**
	 * Return private schema SQL without widening the runtime API.
	 *
	 * @param array<string, string> $tables  Table names.
	 * @param string                $charset Charset declaration.
	 */
	private function schema_sql( array $tables, string $charset ): string {
		$method = new ReflectionMethod( Installer::class, 'schema_sql' );

		return (string) $method->invoke( null, $tables, $charset );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused wpdb double remains local to this test file.

/**
 * Minimal wpdb double for workflow installer tests.
 */
final class WorkflowInstallerWpdb {

	public string $prefix = 'wp_';
	public bool $is_mysql = true;

	/**
	 * Simulated MySQL table engines.
	 *
	 * @var array<string, string>
	 */
	public array $table_engines = array(
		'wp_aculect_ai_workflows'         => 'InnoDB',
		'wp_aculect_ai_workflow_versions' => 'InnoDB',
	);

	/**
	 * Existing table names.
	 *
	 * @var list<string>
	 */
	public array $existing_tables = array();

	/**
	 * Schema declarations passed to dbDelta().
	 *
	 * @var list<string>
	 */
	public array $db_delta_queries = array();

	public int $get_var_calls = 0;

	/**
	 * Most recent prepared-statement arguments.
	 *
	 * @var list<mixed>
	 */
	private array $last_args = array();

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_args = $args;

		return $query;
	}

	public function esc_like( string $text ): string {
		return $text;
	}

	public function get_var( string $query ): string {
		if ( false !== stripos( $query, 'FROM information_schema.TABLES' ) ) {
			$table = (string) ( $this->last_args[0] ?? '' );
			if ( '' === $table ) {
				preg_match( '/TABLE_NAME\s*=\s*["\']([^"\']+)["\']/i', $query, $matches );
				$table = (string) ( $matches[1] ?? '' );
			}

			return $this->table_engines[ $table ] ?? '';
		}

		++$this->get_var_calls;
		$table = (string) ( $this->last_args[0] ?? '' );

		return in_array( $table, $this->existing_tables, true ) ? $table : '';
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
}
