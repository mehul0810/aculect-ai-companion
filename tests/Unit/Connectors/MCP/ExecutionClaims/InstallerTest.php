<?php
/**
 * Tests for the execution-claims schema lifecycle.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP\ExecutionClaims
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP\ExecutionClaims;

use Aculect\AICompanion\Connectors\MCP\ExecutionClaims\Installer;
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
}

final class ExecutionClaimsInstallerWpdb {
	public string $prefix     = 'wp_';
	public bool $table_exists = false;
	public string $schema_sql = '';
	/**
	 * Captured prepared queries.
	 *
	 * @var list<array{query: string, args: list<mixed>}>
	 */
	public array $prepared = array();
	/**
	 * Capture a prepared query.
	 *
	 * @param string $query   SQL template.
	 * @param mixed  ...$args Prepared values.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared[] = array(
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
		return $this->table_exists ? $this->prefix . 'aculect_ai_companion_execution_claims' : '';
	}
	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}
	public function query( string $query ): int {
		unset( $query );
		$this->table_exists = false;
		return 1;
	}
}
