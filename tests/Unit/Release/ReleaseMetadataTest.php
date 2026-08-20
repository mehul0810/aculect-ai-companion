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
	private const EXPECTED_VERSION = '0.8.0';

	public function test_release_metadata_is_synchronized_for_current_version(): void {
		$root       = dirname( __DIR__, 3 );
		$plugin     = $this->file_contents( $root . '/aculect-ai-companion.php' );
		$readme     = $this->file_contents( $root . '/readme.txt' );
		$pot        = $this->file_contents( $root . '/languages/aculect-ai-companion.pot' );
		$package    = $this->json_file( $root . '/package.json' );
		$lockfile   = $this->json_file( $root . '/package-lock.json' );
		$log        = $this->json_file( $root . '/changelog.json' );
		$governance = $this->file_contents( $root . '/RELEASE.md' );

		self::assertSame( self::EXPECTED_VERSION, $this->header( $plugin, 'Version' ) );
		self::assertStringContainsString( "define( 'ACULECT_AI_COMPANION_VERSION', '" . self::EXPECTED_VERSION . "' );", $plugin );
		self::assertSame( self::EXPECTED_VERSION, $this->header( $readme, 'Stable tag' ) );
		self::assertSame( '7.1', $this->header( $readme, 'Tested up to' ) );
		self::assertSame( self::EXPECTED_VERSION, (string) ( $package['version'] ?? '' ) );
		self::assertSame( self::EXPECTED_VERSION, (string) ( $lockfile['version'] ?? '' ) );
		self::assertSame( self::EXPECTED_VERSION, (string) ( $lockfile['packages']['']['version'] ?? '' ) );
		self::assertStringContainsString( 'Project-Id-Version: Aculect AI Companion ' . self::EXPECTED_VERSION, $pot );
		self::assertSame( 119, substr_count( $pot, "\nmsgid " ) );
		self::assertStringContainsString( 'msgid "Settings"', $pot );
		self::assertStringContainsString( 'msgid "Review connection request"', $pot );
		self::assertStringContainsString( 'msgid "AI access"', $pot );
		self::assertStringContainsString( 'msgid "Aculect Intelligence"', $pot );

		$release_version = preg_replace( '/-(?:alpha|beta|rc)\.\d+$/', '', (string) ( $package['version'] ?? '' ) );
		self::assertSame( self::EXPECTED_VERSION, $release_version );
		self::assertArrayHasKey( $release_version, $log );
		self::assertSame( '2026-08-20', $log[ $release_version ]['date'] ?? '' );
		foreach ( $log as $version => $entry ) {
			self::assertIsString( $version );
			self::assertIsArray( $entry );
			self::assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $entry['date'] ?? '' ) );
		}
		self::assertStringContainsString( '= ' . $release_version . ' =', $readme );
		self::assertStringContainsString( '8. Changelog tab with the current ' . $release_version . ' release notes.', $readme );
		self::assertStringNotContainsString( '8. Changelog tab with the current 0.7.0 release notes.', $readme );
		foreach ( array( 'New', 'Improved', 'Fixed', 'Reliability' ) as $category ) {
			self::assertArrayHasKey( $category, $log[ $release_version ] );
			self::assertIsArray( $log[ $release_version ][ $category ] );
			foreach ( $log[ $release_version ][ $category ] as $note ) {
				self::assertIsString( $note );
				self::assertStringContainsString( '* ' . $note, $readme );
			}
		}
		self::assertStringContainsString( '= ' . self::EXPECTED_VERSION . " =\n\nExisting OAuth clients are issuer-bound in resumable batches.", $readme );
		self::assertStringContainsString( 'Registration and credential issuance stay unavailable until verified.', $readme );
		self::assertStringContainsString( 'Changing the external site URL does not rebind credentials and may require reconnecting assistants.', $readme );
		self::assertStringContainsString( '`0.7.2` is the current production release', $governance );
		self::assertStringContainsString( '`0.8.0` is the final metadata candidate on `release/0.8.0`', $governance );
		self::assertStringContainsString( 'every reviewed 0.7.3 change is inherited without replay or tree drift', $governance );
		self::assertStringContainsString( 'Keep MCP Apps embedded UI and `ui://` product scope in `0.9.0`', $governance );
		self::assertStringContainsString( 'synchronized to the `0.8.0` metadata candidate', $governance );
		self::assertStringContainsString( 'Production remains `0.7.2` until the owner separately authorizes the exact tag and publication workflow.', $governance );
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

	public function test_published_071_connect_changelog_remains_immutable(): void {
		$root   = dirname( __DIR__, 3 );
		$readme = $this->file_contents( $root . '/readme.txt' );
		$log    = $this->json_file( $root . '/changelog.json' );
		$note   = 'Simplified the Connect workspace around one copyable connection link, a lean AI app chooser, and current ChatGPT and Claude setup guidance.';

		self::assertContains( $note, $log['0.7.1']['New'] ?? array() );
		self::assertStringContainsString( '* ' . $note, $readme );
		self::assertStringNotContainsString( 'current ChatGPT, Claude, and Cursor setup guidance', $readme );
	}

	public function test_chatgpt_dcr_capacity_docs_preserve_public_and_admin_cleanup_boundary(): void {
		$root           = dirname( __DIR__, 3 );
		$chatgpt_readme = $this->file_contents( $root . '/src/Connectors/ChatGPT/README.md' );

		self::assertStringContainsString(
			'same provider and exact registration fingerprint',
			$chatgpt_readme
		);
		self::assertStringContainsString(
			'Public DCR never revokes unrelated dormant registrations.',
			$chatgpt_readme
		);
		self::assertStringContainsString(
			'Only this `manage_options`- and nonce-protected recovery action may revoke an unrelated stale registration',
			$chatgpt_readme
		);
		self::assertStringContainsString(
			'Simultaneous new registrations can therefore briefly overshoot the configured cap',
			$chatgpt_readme
		);
		self::assertStringContainsString(
			'HTTP `503` with the stable `registration_capacity_exceeded` code',
			$chatgpt_readme
		);
		self::assertStringNotContainsString(
			'repository also revokes registrations older than the configured stale window',
			$chatgpt_readme
		);
	}

	public function test_development_advisory_exception_is_complete_and_expiry_dated(): void {
		$root     = dirname( __DIR__, 3 );
		$register = $this->file_contents( $root . '/docs/development-dependency-advisories.md' );
		$packages = array(
			'@wordpress/scripts',
			'@wordpress/e2e-test-utils-playwright',
			'lighthouse',
			'puppeteer-core',
			'@puppeteer/browsers',
			'extract-zip',
			'adm-zip',
			'markdownlint-cli',
			'markdownlint',
			'markdown-it',
			'linkify-it',
			'js-yaml',
		);

		self::assertStringContainsString( 'Last verified: 2026-08-19', $register );
		self::assertStringContainsString( '12 advisory packages (10 high, 2 moderate)', $register );
		self::assertStringContainsString( 'Production tree: zero advisories', $register );
		self::assertStringContainsString( 'declared as `^32.2.0` and currently resolves to 32.6.0', $register );
		self::assertStringContainsString( 'Review deadline: 2026-09-30', $register );
		self::assertStringContainsString( 'Do not use `npm audit fix --force`', $register );
		foreach ( $packages as $package ) {
			self::assertStringContainsString( '`' . $package . '`', $register );
		}
	}

	/**
	 * Return file contents or fail.
	 *
	 * @param string $file File path.
	 */
	private function file_contents( string $file ): string {
		self::assertFileExists( $file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test helper reads repository-local fixtures, not a remote URL.
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
