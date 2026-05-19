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

	public function test_installs_schema_when_table_is_missing_even_if_version_is_current(): void {
		self::assertTrue( $this->shouldInstallSchema( '2026.05.17.1', false ) );
	}

	public function test_installs_schema_when_stored_version_is_old(): void {
		self::assertTrue( $this->shouldInstallSchema( '2026.05.17.0', true ) );
	}

	public function test_skips_schema_install_when_current_table_exists(): void {
		self::assertFalse( $this->shouldInstallSchema( '2026.05.17.1', true ) );
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
