<?php
/**
 * Release metadata tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Release
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;

/**
 * Verifies release metadata stays synchronized across package surfaces.
 */
final class ReleaseMetadataTest extends TestCase {

	public function test_release_metadata_is_synchronized_for_current_version(): void {
		$root      = dirname( __DIR__, 3 );
		$plugin   = $this->file_contents( $root . '/aculect-ai-companion.php' );
		$readme   = $this->file_contents( $root . '/readme.txt' );
		$package  = $this->json_file( $root . '/package.json' );
		$lockfile = $this->json_file( $root . '/package-lock.json' );
		$log      = $this->json_file( $root . '/changelog.json' );

		self::assertSame( '0.5.3', $this->header( $plugin, 'Version' ) );
		self::assertStringContainsString( "define( 'ACULECT_AI_COMPANION_VERSION', '0.5.3' );", $plugin );
		self::assertSame( '0.5.3', $this->header( $readme, 'Stable tag' ) );
		self::assertSame( '0.5.3', (string) ( $package['version'] ?? '' ) );
		self::assertSame( '0.5.3', (string) ( $lockfile['version'] ?? '' ) );
		self::assertSame( '0.5.3', (string) ( $lockfile['packages']['']['version'] ?? '' ) );
		self::assertArrayHasKey( '0.5.3', $log );
		foreach ( $log as $version => $entry ) {
			self::assertIsString( $version );
			self::assertIsArray( $entry );
			self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $entry['date'] ?? '' ) );
		}
		self::assertStringContainsString( '= 0.5.3 =', $readme );
	}

	/**
	 * Return file contents or fail.
	 *
	 * @param string $file File path.
	 */
	private function file_contents( string $file ): string {
		self::assertFileExists( $file );

		$contents = file_get_contents( $file );
		self::assertIsString( $contents );

		return $contents;
	}

	/**
	 * Return decoded JSON data.
	 *
	 * @param string $file File path.
	 * @return array<string, mixed>
	 */
	private function json_file( string $file ): array {
		$decoded = json_decode( $this->file_contents( $file ), true );

		self::assertIsArray( $decoded );

		return $decoded;
	}

	/**
	 * Return one metadata header from a text file.
	 *
	 * @param string $contents File contents.
	 * @param string $header   Header name.
	 */
	private function header( string $contents, string $header ): string {
		$pattern = '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':\s*(.+)$/mi';

		return preg_match( $pattern, $contents, $matches ) ? trim( $matches[1] ) : '';
	}
}
