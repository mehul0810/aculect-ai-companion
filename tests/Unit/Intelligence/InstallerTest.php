<?php
/**
 * Tests for intelligence index schema installation hot paths.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\Database\Installer;
use Aculect\AICompanion\Intelligence\Database\InstallerRetryState;
use Aculect\AICompanion\Intelligence\Database\MemorySchemaMigrator;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused installer tests replace wpdb with a local test double.

/**
 * Verifies intelligence schema checks stay off normal boot when current.
 */
final class InstallerTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb                          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		unset( $GLOBALS['aculect_ai_companion_test_db_delta_callback'] );
		$GLOBALS['aculect_ai_companion_test_scheduled_events'] = array();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		unset( $GLOBALS['aculect_ai_companion_test_db_delta_callback'] );

		parent::tearDown();
	}

	public function test_install_skips_table_probes_when_version_is_current(): void {
		$wpdb            = new IntelligenceInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_intelligence_db_version', '2026.09.05.2', false );

		Installer::install();

		self::assertSame( 0, $wpdb->get_var_calls );
	}

	public function test_activate_verifies_tables_when_version_is_current(): void {
		$wpdb            = new IntelligenceInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_intelligence_db_version', '2026.09.05.2', false );

		Installer::activate();

		self::assertSame( 8, $wpdb->get_var_calls );
	}

	public function test_incomplete_schema_stays_stale_and_recovers_on_next_install(): void {
		$wpdb                    = new IntelligenceInstallerWpdb();
		$GLOBALS['wpdb']         = $wpdb;
		$wpdb->existing_tables   = array_slice( Installer::table_names(), 0, 7 );
		$previous_schema_version = '2026.01.01.1';
		update_option( 'aculect_ai_companion_intelligence_db_version', $previous_schema_version, false );
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;

			return array( 'created five intelligence tables' );
		};

		self::assertFalse( Installer::install() );
		self::assertSame( array( 'cache' ), Installer::missing_table_keys() );
		self::assertSame( $previous_schema_version, get_option( 'aculect_ai_companion_intelligence_db_version' ) );

		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;
			$wpdb->existing_tables    = Installer::table_names();

			return array( 'repaired intelligence schema' );
		};

		self::assertTrue( Installer::install( true ) );
		self::assertSame( array(), Installer::missing_table_keys() );
		self::assertSame( '2026.09.05.2', get_option( 'aculect_ai_companion_intelligence_db_version' ) );
		self::assertCount( 2, $wpdb->db_delta_queries );
	}

	public function test_failed_repair_invalidates_current_version_for_normal_boot_retry(): void {
		$wpdb                  = new IntelligenceInstallerWpdb();
		$GLOBALS['wpdb']       = $wpdb;
		$wpdb->existing_tables = array_slice( Installer::table_names(), 0, 7 );
		update_option( 'aculect_ai_companion_intelligence_db_version', '2026.09.05.2', false );
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function (): array {
			throw new \RuntimeException( 'dbDelta failed' );
		};

		self::assertFalse( Installer::install( true ) );
		self::assertSame( '0', get_option( 'aculect_ai_companion_intelligence_db_version', '0' ) );

		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;
			$wpdb->existing_tables    = Installer::table_names();

			return array( 'repaired intelligence schema' );
		};

		self::assertTrue( Installer::install( true ) );
		self::assertSame( '2026.09.05.2', get_option( 'aculect_ai_companion_intelligence_db_version' ) );
	}

	public function test_normal_boot_backs_off_after_failure_and_forced_repair_clears_state(): void {
		$wpdb                  = new IntelligenceInstallerWpdb();
		$GLOBALS['wpdb']       = $wpdb;
		$wpdb->existing_tables = array_slice( Installer::table_names(), 0, 7 );
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;
			return array();
		};

		self::assertFalse( Installer::install() );
		$failure = Installer::repair_status();
		self::assertSame( 1, $failure['attempts'] );
		self::assertFalse( $failure['blocked'] );
		self::assertGreaterThan( time(), $failure['next_retry_at'] );
		self::assertSame( array( 'cache' ), $failure['missing_tables'] );

		self::assertFalse( Installer::install() );
		self::assertCount( 1, $wpdb->db_delta_queries );

		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;
			$wpdb->existing_tables    = Installer::table_names();
			return array( 'repaired intelligence schema' );
		};

		self::assertTrue( Installer::install( true ) );
		self::assertSame( 0, Installer::repair_status()['attempts'] );
		self::assertCount( 2, $wpdb->db_delta_queries );
	}

	public function test_automatic_repair_stops_after_the_bounded_attempt_limit(): void {
		for ( $attempt = 0; $attempt < 4; ++$attempt ) {
			InstallerRetryState::record_failure( array( 'cache' ), 'tables_missing' );
		}

		$status = Installer::repair_status();
		self::assertSame( 3, $status['attempts'] );
		self::assertTrue( $status['blocked'] );
		self::assertFalse( InstallerRetryState::allows_attempt() );
		self::assertTrue( InstallerRetryState::allows_attempt( true ) );
	}

	public function test_missing_versioned_memory_column_keeps_schema_stale(): void {
		$wpdb                 = new IntelligenceInstallerWpdb();
		$GLOBALS['wpdb']      = $wpdb;
		$wpdb->memory_columns = array_values( array_diff( MemorySchemaMigrator::required_columns(), array( 'content_hash' ) ) );
		update_option( 'aculect_ai_companion_intelligence_db_version', '2026.09.05.2', false );

		self::assertContains( 'memory_schema', Installer::missing_table_keys() );
	}

	public function test_schema_upgrade_schedules_memory_backfill_without_running_it_inline(): void {
		$wpdb            = new IntelligenceInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;
			return array( 'expanded memory schema' );
		};

		self::assertTrue( Installer::install() );
		self::assertSame( '2026.09.05.2', get_option( 'aculect_ai_companion_intelligence_db_version' ) );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( MemorySchemaMigrator::HOOK ) );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.IncorrectTypeHint -- Focused wpdb double remains local to this test file.

/**
 * Minimal wpdb double for intelligence installer hot-path tests.
 */
final class IntelligenceInstallerWpdb {

	public string $prefix = 'wp_';

	public int $get_var_calls = 0;

	/** @var list<string> */
	public array $existing_tables = array(
		'wp_aculect_ai_content_index',
		'wp_aculect_ai_content_chunks',
		'wp_aculect_ai_link_graph',
		'wp_aculect_ai_memory_items',
		'wp_aculect_ai_memory_events',
		'wp_aculect_ai_memory_sync_state',
		'wp_aculect_ai_jobs',
		'wp_aculect_ai_cache',
	);

	/** @var list<string> */
	public array $db_delta_queries = array();

	/** @var list<string> */
	public array $memory_columns = array();

	/**
	 * @var array<int, mixed>
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
		unset( $query );

		++$this->get_var_calls;

		$table = (string) ( $this->last_args[0] ?? '' );

		return in_array( $table, $this->existing_tables, true ) ? $table : '';
	}

	/**
	 * Return the configured memory column names.
	 *
	 * @param string $query  Schema query.
	 * @param int    $column Requested column.
	 * @return list<string>
	 */
	public function get_col( string $query, int $column = 0 ): array {
		unset( $query, $column );
		return array() === $this->memory_columns ? MemorySchemaMigrator::required_columns() : $this->memory_columns;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4';
	}
}
