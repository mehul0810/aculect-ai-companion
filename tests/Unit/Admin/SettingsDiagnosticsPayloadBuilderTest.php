<?php
/**
 * Diagnostics settings payload builder tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\SettingsDiagnosticsPayloadBuilder;
use Aculect\AICompanion\Tests\Support\FakeSettingsDiagnosticsPayloadWpdb;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/Support/FakeSettingsDiagnosticsPayloadWpdb.php';

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Scoped repository fixture is restored in tearDown().
/**
 * Verifies diagnostics payload hydration and pagination stay bounded.
 */
final class SettingsDiagnosticsPayloadBuilderTest extends TestCase {

	private mixed $original_wpdb = null;

	private array $original_get = array();

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only test fixture state.
		$this->original_get                           = $_GET;
		$GLOBALS['wpdb']                              = new FakeSettingsDiagnosticsPayloadWpdb();
		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$_GET = array();
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	public function test_empty_log_payload_preserves_compatibility_shape(): void {
		self::assertSame(
			array(
				'items'      => array(),
				'total'      => 0,
				'page'       => 1,
				'perPage'    => 50,
				'totalPages' => 1,
				'prevUrl'    => '',
				'nextUrl'    => '',
			),
			SettingsDiagnosticsPayloadBuilder::empty_logs_payload()
		);
	}

	public function test_build_does_not_query_logs_until_logs_are_requested(): void {
		$wpdb    = $GLOBALS['wpdb'];
		$payload = ( new SettingsDiagnosticsPayloadBuilder( 'https://example.com/wp-admin/options-general.php?page=aculect-ai-companion' ) )->build();

		self::assertFalse( $payload['loggingEnabled'] );
		self::assertSame( 0, $payload['logs']['total'] );
		self::assertFalse( $wpdb->has_query_fragment( 'wp_aculect_ai_companion_logs' ) );
		self::assertTrue( $wpdb->has_query_fragment( 'wp_aculect_ai_companion_oauth_clients' ) );
	}

	public function test_build_clamps_log_page_and_preserves_navigation_urls(): void {
		update_option( 'aculect_ai_companion_logging_enabled', '1', false );
		$_GET['logs_page'] = '999';
		$wpdb              = $GLOBALS['wpdb'];
		$wpdb->log_total   = 51;

		$payload = ( new SettingsDiagnosticsPayloadBuilder( 'https://example.com/wp-admin/options-general.php?page=aculect-ai-companion' ) )->build( true );

		self::assertTrue( $payload['loggingEnabled'] );
		self::assertSame( 51, $payload['logs']['total'] );
		self::assertSame( 2, $payload['logs']['page'] );
		self::assertSame( 2, $payload['logs']['totalPages'] );
		self::assertStringContainsString( 'tab=logs', $payload['logs']['prevUrl'] );
		self::assertStringContainsString( 'logs_page=1', $payload['logs']['prevUrl'] );
		self::assertSame( '', $payload['logs']['nextUrl'] );
		self::assertTrue( $wpdb->has_query_fragment( 'wp_aculect_ai_companion_logs' ) );
	}
}
