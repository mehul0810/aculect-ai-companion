<?php
/**
 * Tests for the execution-claims schema lifecycle.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP\ExecutionClaims
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP\ExecutionClaims;

use Aculect\AICompanion\Plugin;
use Aculect\AICompanion\Connectors\MCP\ExecutionClaims\Installer;
use Aculect\AICompanion\Connectors\OAuth\IssuerBinding;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused installer test double.

final class InstallerTest extends TestCase {

	private mixed $original_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->original_wpdb                          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		unset( $GLOBALS['aculect_ai_companion_test_db_delta_callback'] );
	}

	protected function tearDown(): void {
		if ( null === $this->original_wpdb ) {
			unset( $GLOBALS['wpdb'] );
		} else {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		}
		unset( $GLOBALS['aculect_ai_companion_test_db_delta_callback'] );
		parent::tearDown();
	}

	public function test_install_creates_exact_hash_only_transactional_schema(): void {
		$wpdb            = new ExecutionClaimsInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->schema_sql   = $sql;
			$wpdb->table_exists = true;
			return array( 'created table' );
		};

		self::assertTrue( Installer::install( true ) );
		self::assertStringContainsString( 'wp_aculect_ai_companion_execution_claims', $wpdb->schema_sql );
		self::assertStringContainsString( 'confirmation_key_hash char(64) DEFAULT NULL', $wpdb->schema_sql );
		self::assertStringContainsString( 'idempotency_key_hash char(64) DEFAULT NULL', $wpdb->schema_sql );
		self::assertStringContainsString( 'UNIQUE KEY confirmation_key_hash', $wpdb->schema_sql );
		self::assertStringContainsString( 'UNIQUE KEY idempotency_key_hash', $wpdb->schema_sql );
		self::assertStringContainsString( 'ENGINE=InnoDB', $wpdb->schema_sql );
		self::assertStringNotContainsString( 'confirmation_token', $wpdb->schema_sql );
		self::assertStringNotContainsString( 'idempotency_key varchar', $wpdb->schema_sql );
		self::assertSame( '2026.08.19.1', get_option( 'aculect_ai_companion_execution_claims_db_version' ) );
	}

	public function test_activation_repairs_missing_prefixed_table_and_uninstall_is_scoped(): void {
		$wpdb            = new ExecutionClaimsInstallerWpdb();
		$wpdb->prefix    = 'wp_7_';
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_execution_claims_db_version', '2026.08.19.1', false );
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->schema_sql   = $sql;
			$wpdb->table_exists = true;
			return array( 'repaired table' );
		};

		Installer::activate();
		self::assertStringContainsString( 'wp_7_aculect_ai_companion_execution_claims', $wpdb->schema_sql );
		Installer::uninstall();
		self::assertSame( 'DROP TABLE IF EXISTS %i', $wpdb->prepared[ array_key_last( $wpdb->prepared ) ]['query'] );
		self::assertSame( array( 'wp_7_aculect_ai_companion_execution_claims' ), $wpdb->prepared[ array_key_last( $wpdb->prepared ) ]['args'] );
		self::assertFalse( get_option( 'aculect_ai_companion_execution_claims_db_version', false ) );
	}

	public function test_default_plugin_boot_path_repairs_missing_table_with_current_version(): void {
		$wpdb            = new ExecutionClaimsInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_execution_claims_db_version', '2026.08.19.1', false );
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->schema_sql   = $sql;
			$wpdb->table_exists = true;
			return array( 'repaired table during normal boot' );
		};

		self::assertTrue( Installer::install() );
		self::assertTrue( $wpdb->table_exists );
		self::assertSame( 2, $wpdb->get_var_calls );
		self::assertSame( '2026.08.19.1', get_option( 'aculect_ai_companion_execution_claims_db_version' ) );
	}

	public function test_failed_lazy_repair_invalidates_version_and_retries_on_next_boot(): void {
		$wpdb            = new ExecutionClaimsInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_execution_claims_db_version', '2026.08.19.1', false );
		$attempts = 0;
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb, &$attempts ): array {
			$wpdb->schema_sql = $sql;
			++$attempts;
			if ( 2 === $attempts ) {
				$wpdb->table_exists = true;
			}
			return array();
		};

		self::assertFalse( Installer::install() );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_execution_claims_db_version', 'missing' ) );
		self::assertTrue( Installer::install() );
		self::assertSame( 2, $attempts );
		self::assertSame( '2026.08.19.1', get_option( 'aculect_ai_companion_execution_claims_db_version' ) );
	}

	public function test_plugin_boot_repairs_missing_claims_table_with_current_schema_version(): void {
		$wpdb            = new ExecutionClaimsInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		$GLOBALS['aculect_ai_companion_test_options']           = array(
			'aculect_ai_companion_execution_claims_db_version' => '2026.08.19.1',
			'aculect_ai_companion_oauth_db_version'        => '2026.08.19.1',
			'aculect_ai_companion_oauth_legacy_migrated'   => '1',
			'aculect_ai_companion_oauth_issuer_backfill'   => IssuerBinding::hash(),
			'aculect_ai_companion_logs_db_version'         => '2026.05.17.1',
			'aculect_ai_companion_activity_db_version'     => '2026.05.20.1',
			'aculect_ai_companion_intelligence_db_version' => '2026.09.05.2',
			'aculect_ai_companion_workflows_db_version'    => '2026.08.19.1',
			'aculect_ai_companion_oauth_prune_lock_expires_at' => 'outcome:success:0123456789abcdef0123456789abcdef:' . ( time() + 3600 ),
		);
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			if ( str_contains( $sql, 'aculect_ai_companion_execution_claims' ) ) {
				$wpdb->schema_sql   = $sql;
				$wpdb->table_exists = true;
			}
			return array( 'repaired claims table' );
		};

		Plugin::instance()->boot();

		self::assertTrue( $wpdb->table_exists );
		self::assertStringContainsString( 'aculect_ai_companion_execution_claims', $wpdb->schema_sql );
	}
}

final class ExecutionClaimsInstallerWpdb {
	public string $prefix     = 'wp_';
	public bool $table_exists = false;
	public string $schema_sql = '';
	public int $get_var_calls = 0;
	/**
	 * Captured prepared queries.
	 *
	 * @var list<array{query: string, args: list<mixed>}>
	 */
	public array $prepared = array();
	/**
	 * Arguments captured by the latest prepared query.
	 *
	 * @var list<mixed>
	 */
	private array $last_prepared_args = array();
	/**
	 * Capture a prepared query.
	 *
	 * @param string $query   SQL template.
	 * @param mixed  ...$args Prepared values.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->last_prepared_args = $args;
		$this->prepared[]         = array(
			'query' => $query,
			'args'  => $args,
		);
		return $query;
	}
	public function esc_like( string $value ): string {
		return $value;
	}
	public function get_var( string $query ): string {
		unset( $query );
		++$this->get_var_calls;
		$table = (string) ( $this->last_prepared_args[0] ?? '' );
		if ( $this->prefix . 'aculect_ai_companion_execution_claims' === $table ) {
			return $this->table_exists ? $table : '';
		}
		return $table;
	}
	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
	public function query( string $query ): int {
		if ( str_contains( strtoupper( $query ), 'DROP TABLE' ) ) {
			$this->table_exists = false;
		}
		return 1;
	}
}
