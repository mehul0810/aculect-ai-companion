<?php
/**
 * Tests for diagnostic log schema installation decisions.
 *
 * @package Aculect\AICompanion\Tests\Unit\Diagnostics
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Diagnostics\Database\Installer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies diagnostic log storage is repaired when needed.
 */
final class InstallerTest extends TestCase {

	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb                           = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	public function test_installs_schema_when_table_is_missing_even_if_version_is_current(): void {
		self::assertTrue( $this->shouldInstallSchema( '2026.05.17.1', false ) );
	}

	public function test_installs_schema_when_stored_version_is_old(): void {
		self::assertTrue( $this->shouldInstallSchema( '2026.05.17.0', true ) );
	}

	public function test_skips_schema_install_when_current_table_exists(): void {
		self::assertFalse( $this->shouldInstallSchema( '2026.05.17.1', true ) );
	}

	public function test_install_skips_table_probe_when_version_is_current(): void {
		$wpdb            = new DiagnosticsInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_logs_db_version', '2026.05.17.1', false );

		Installer::install();

		self::assertSame( 0, $wpdb->get_var_calls );
	}

	public function test_activate_verifies_table_when_version_is_current(): void {
		$wpdb            = new DiagnosticsInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_logs_db_version', '2026.05.17.1', false );

		Installer::activate();

		self::assertSame( 1, $wpdb->get_var_calls );
	}

	/**
	 * Invoke the private schema decision helper for focused unit coverage.
	 *
	 * @param string $installed_db_version Stored schema version.
	 * @param bool   $table_exists         Whether the table exists.
	 */
	private function shouldInstallSchema( string $installed_db_version, bool $table_exists ): bool {
		$reflection = new ReflectionMethod( Installer::class, 'should_install_schema' );

		return (bool) $reflection->invokeArgs( null, array( $installed_db_version, $table_exists ) );
	}
}

/**
 * Minimal wpdb double for diagnostics installer hot-path tests.
 */
final class DiagnosticsInstallerWpdb {

	public string $prefix = 'wp_';

	public int $get_var_calls = 0;

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

		return (string) ( $this->last_args[0] ?? '' );
	}
}
