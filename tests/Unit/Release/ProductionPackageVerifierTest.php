<?php
/**
 * Production package verifier tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Release
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Release;

use PHPUnit\Framework\TestCase;

/**
 * Verifies that release packages contain the Composer runtime required at boot.
 */
final class ProductionPackageVerifierTest extends TestCase {

	public function test_verifier_rejects_package_without_runtime_autoloader(): void {
		$package = $this->package_fixture();

		try {
			$result = $this->run_verifier( $package );

			self::assertSame( 1, $result['status'] );
			self::assertStringContainsString( 'vendor/autoload.php', $result['output'] );
		} finally {
			$this->remove_directory( dirname( $package ) );
		}
	}

	public function test_verifier_loads_required_oauth_runtime_interface_from_package_autoloader(): void {
		$package = $this->package_fixture();
		$this->write_file(
			$package . '/vendor/autoload.php',
			"<?php\nnamespace League\\OAuth2\\Server\\Repositories;\ninterface AccessTokenRepositoryInterface {}\n"
		);

		try {
			$result = $this->run_verifier( $package );

			self::assertSame( 0, $result['status'] );
			self::assertStringContainsString( 'Production package verification passed.', $result['output'] );
		} finally {
			$this->remove_directory( dirname( $package ) );
		}
	}

	/**
	 * Create the minimal production package fixture.
	 *
	 * @return string
	 */
	private function package_fixture(): string {
		$parent = sys_get_temp_dir() . '/aculect-package-' . bin2hex( random_bytes( 8 ) );
		$package = $parent . '/aculect-ai-companion';

		self::assertTrue( mkdir( $package . '/build', 0755, true ) );
		foreach ( array( 'index.asset.php', 'index.js', 'style-index.css', 'style-index-rtl.css' ) as $asset ) {
			$this->write_file( $package . '/build/' . $asset, '' );
		}

		return $package;
	}

	/**
	 * Run the production package verifier for a fixture.
	 *
	 * @param string $package Package directory.
	 * @return array{status: int, output: string}
	 */
	private function run_verifier( string $package ): array {
		$root    = dirname( __DIR__, 3 );
		$command = sprintf(
			'%s %s %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( $root . '/bin/verify-production-package.php' ),
			escapeshellarg( $package )
		);
		$output  = array();
		$status  = 0;

		exec( $command, $output, $status );

		return array(
			'status' => $status,
			'output' => implode( "\n", $output ),
		);
	}

	/**
	 * Write a fixture file, creating its parent directory.
	 *
	 * @param string $file File path.
	 * @param string $contents File contents.
	 */
	private function write_file( string $file, string $contents ): void {
		$directory = dirname( $file );
		if ( ! is_dir( $directory ) ) {
			self::assertTrue( mkdir( $directory, 0755, true ) );
		}

		self::assertNotFalse( file_put_contents( $file, $contents ) );
	}

	/**
	 * Remove a temporary fixture directory.
	 *
	 * @param string $directory Directory path.
	 */
	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}

		rmdir( $directory );
	}
}
