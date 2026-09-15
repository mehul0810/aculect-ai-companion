<?php
/**
 * Native health projection tests.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\NativeHealthSummary;
use PHPUnit\Framework\TestCase;

/**
 * Cached native counters must fail closed and exclude other fields.
 */
final class NativeHealthSummaryTest extends TestCase {

	protected function tearDown(): void {
		delete_transient( 'health-check-site-status-result' );
		parent::tearDown();
	}

	public function test_projects_only_native_counts_with_unknown_freshness(): void {
		set_transient( 'health-check-site-status-result', '{"good":8,"recommended":2,"critical":1,"private":"excluded"}' );
		$result = NativeHealthSummary::read();
		self::assertSame(
			array(
				'good'        => 8,
				'recommended' => 2,
				'critical'    => 1,
			),
			$result['counts']
		);
		self::assertSame( 'unknown', $result['freshness'] );
		self::assertFalse( $result['tests_executed'] );
		self::assertStringNotContainsString( 'excluded', (string) wp_json_encode( $result ) );
	}

	public function test_rejects_missing_and_malformed_results(): void {
		foreach ( array( false, array(), new \stdClass(), 'null', '[]', '{}', '{"good":-1,"recommended":0,"critical":0}', '{"good":"1","recommended":0,"critical":0}' ) as $raw ) {
			set_transient( 'health-check-site-status-result', $raw );
			self::assertSame( 'unavailable', NativeHealthSummary::read()['status'] );
		}
	}

	public function test_native_counter_bounds_and_depth_fail_closed(): void {
		foreach ( array( str_repeat( ' ', 2049 ), '{"good":1.5,"recommended":0,"critical":0}', '{"good":100001,"recommended":0,"critical":0}', '{"good":1,"recommended":0}', '{"good":{"nested":{"nested":{}}},"recommended":0,"critical":0}' ) as $raw ) {
			set_transient( 'health-check-site-status-result', $raw );
			self::assertSame( 'unavailable', NativeHealthSummary::read()['status'] );
		}
	}
}
