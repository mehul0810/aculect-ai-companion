<?php
/**
 * Tests for intelligence index schema installation hot paths.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\Database\Installer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies intelligence schema checks stay off normal boot when current.
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

	public function test_install_skips_table_probes_when_version_is_current(): void {
		$wpdb            = new IntelligenceInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_intelligence_db_version', '2026.07.26.1', false );

		Installer::install();

		self::assertSame( 0, $wpdb->get_var_calls );
	}

	public function test_activate_verifies_tables_when_version_is_current(): void {
		$wpdb            = new IntelligenceInstallerWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		update_option( 'aculect_ai_companion_intelligence_db_version', '2026.07.26.1', false );

		Installer::activate();

		self::assertSame( 6, $wpdb->get_var_calls );
	}
}

/**
 * Minimal wpdb double for intelligence installer hot-path tests.
 */
final class IntelligenceInstallerWpdb {

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
