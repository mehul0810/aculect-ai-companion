<?php
/**
 * Release metadata tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Release
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Release;

use Aculect\AICompanion\Connectors\Helpers;
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
		$governance = $this->file_contents( $root . '/RELEASE.md' );

		self::assertSame( '0.7.1', $this->header( $plugin, 'Version' ) );
		self::assertStringContainsString( "define( 'ACULECT_AI_COMPANION_VERSION', '0.7.1' );", $plugin );
		self::assertSame( '0.7.1', $this->header( $readme, 'Stable tag' ) );
		self::assertSame( '0.7.1', (string) ( $package['version'] ?? '' ) );
		self::assertSame( '0.7.1', (string) ( $lockfile['version'] ?? '' ) );
		self::assertSame( '0.7.1', (string) ( $lockfile['packages']['']['version'] ?? '' ) );

		$release_version = preg_replace( '/-(?:alpha|beta|rc)\.\d+$/', '', (string) ( $package['version'] ?? '' ) );
		self::assertSame( '0.7.1', $release_version );
		self::assertArrayHasKey( $release_version, $log );
		foreach ( $log as $version => $entry ) {
			self::assertIsString( $version );
			self::assertIsArray( $entry );
			self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $entry['date'] ?? '' ) );
		}
		self::assertStringContainsString( '= ' . $release_version . ' =', $readme );
		self::assertStringContainsString( '8. Changelog tab with the current ' . $release_version . ' release notes.', $readme );
		self::assertStringNotContainsString( '8. Changelog tab with the current 0.7.0 release notes.', $readme );
		self::assertStringContainsString( '`0.7.1` is the current production release.', $governance );
		self::assertStringContainsString( '`0.7.2` is the active patch train on `release/0.7.2`', $governance );
	}

	public function test_prerelease_workflow_builds_published_prereleases_only(): void {
		$root     = dirname( __DIR__, 3 );
		$workflow = $this->file_contents( $root . '/.github/workflows/prerelease.yml' );

		self::assertStringContainsString( 'types: [published]', $workflow );
		self::assertStringContainsString( 'if: github.event.release.prerelease', $workflow );
	}

	public function test_public_oauth_authorize_docs_match_discovery_contract(): void {
		$root           = dirname( __DIR__, 3 );
		$readme         = $this->file_contents( $root . '/README.md' );
		$chatgpt_readme = $this->file_contents( $root . '/src/Connectors/ChatGPT/README.md' );
		$endpoint       = Helpers::authorization_endpoint();

		self::assertSame( 'https://example.com/oauth/authorize', $endpoint );
		self::assertStringContainsString( '- OAuth authorization: `/oauth/authorize`', $readme );
		self::assertStringContainsString( '- Authorization endpoint: `/oauth/authorize`', $chatgpt_readme );
		self::assertStringNotContainsString( '- OAuth authorization: `/wp-json/aculect-ai-companion/v1/oauth/authorize`', $readme );
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
