<?php
/**
 * Tests for activity storage schema repair.
 *
 * @package Aculect\AICompanion\Tests\Unit\Activity
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Activity;

use Aculect\AICompanion\Activity\Database\Installer;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused installer tests replace wpdb with a local test double.

/**
 * Verifies activity storage is checked on activation and repaired on demand.
 */
final class InstallerTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb                          = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		unset( $GLOBALS['aculect_ai_companion_test_db_delta_callback'] );
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

	public function test_current_schema_repairs_missing_multisite_activity_table(): void {
		$wpdb            = new ActivityInstallerWpdb();
		$wpdb->prefix    = 'wp_21_';
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_activity_db_version', '2026.05.20.1', false );
		$GLOBALS['aculect_ai_companion_test_db_delta_callback'] = static function ( string $sql ) use ( $wpdb ): array {
			$wpdb->db_delta_queries[] = $sql;
			$wpdb->table_exists       = true;

			return array( 'created activity table' );
		};

		self::assertTrue( Installer::install( true ) );
		self::assertSame( array(), Installer::missing_table_keys() );
		self::assertCount( 1, $wpdb->db_delta_queries );
		self::assertStringContainsString( 'wp_21_aculect_ai_companion_activity', $wpdb->db_delta_queries[0] );
	}

	public function test_failed_activity_repair_keeps_missing_store_visible(): void {
		$wpdb            = new ActivityInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_activity_db_version', '2026.05.20.1', false );

		self::assertFalse( Installer::install( true ) );
		self::assertSame( array( 'activity' ), Installer::missing_table_keys() );
	}

	public function test_activation_checks_current_multisite_activity_table(): void {
		$wpdb               = new ActivityInstallerWpdb();
		$wpdb->prefix       = 'wp_21_';
		$wpdb->table_exists = true;
		$GLOBALS['wpdb']    = $wpdb;
		update_option( 'aculect_ai_companion_activity_db_version', '2026.05.20.1', false );

		Installer::activate();

		self::assertSame( 1, $wpdb->get_var_calls );
		self::assertSame( array(), Installer::missing_table_keys() );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionComment.IncorrectTypeHint -- Focused wpdb double remains local to this test file.

/**
 * Minimal wpdb double for activity installer repair tests.
 */
final class ActivityInstallerWpdb {

	public string $prefix     = 'wp_';
	public bool $table_exists = false;
	public int $get_var_calls = 0;

	/** @var array<int, mixed> */
	private array $last_args = array();

	/** @var list<string> */
	public array $db_delta_queries = array();

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

		return $this->table_exists ? (string) ( $this->last_args[0] ?? '' ) : '';
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARSET=utf8mb4';
	}
}
