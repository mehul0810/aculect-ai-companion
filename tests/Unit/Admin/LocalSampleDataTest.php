<?php
/**
 * Local sample data tests.
 *
 * @package Aculect\AICompanion\Tests\Unit
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\LocalSampleData;
use PHPUnit\Framework\TestCase;

final class LocalSampleDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_environment_type'] = 'local';
	}

	public function test_missing_first_install_option_is_backfilled_once(): void {
		$now     = 1704456000;
		$samples = new LocalSampleData( $now );

		self::assertSame( $now, (int) get_option( LocalSampleData::OPTION_FIRST_INSTALLED_AT, 0 ) );

		$samples->apply(
			array(
				'sessions'        => array(),
				'revokedSessions' => array(),
			),
			'connections',
			0
		);

		self::assertSame( $now, (int) get_option( LocalSampleData::OPTION_FIRST_INSTALLED_AT, 0 ) );
		self::assertSame( $now, LocalSampleData::ensure_first_installed_at( $now + 3600 ) );
	}

	public function test_connections_never_receive_local_sample_rows(): void {
		$installed_at = 1704067200;
		$now          = 1704456000;
		$samples      = new LocalSampleData( $now, $installed_at );

		$payload = $samples->apply(
			array(
				'sessions'        => array(),
				'revokedSessions' => array(),
			),
			'connections',
			0
		);

		self::assertSame( array(), $payload['sessions'] );
		self::assertSame( array(), $payload['revokedSessions'] );
		self::assertSame( array(), $payload['sampleData']['appliedTabs'] );
	}

	public function test_activity_learning_logs_and_diagnostics_rows_are_flagged_and_bounded(): void {
		$installed_at = 1704067200;
		$now          = 1704456000;
		$samples      = new LocalSampleData( $now, $installed_at );

		$activity = $samples->apply(
			array(
				'activity' => array(
					'items' => array(),
					'total' => 0,
				),
			),
			'activity',
			0
		);
		self::assertTrue( $activity['activity']['items'][0]['is_sample'] );
		self::assertGreaterThanOrEqual( $installed_at, $this->utc_timestamp( (string) $activity['activity']['items'][0]['created_at'] ) );
		self::assertLessThanOrEqual( $now, $this->utc_timestamp( (string) $activity['activity']['items'][0]['created_at'] ) );

		$learning = $samples->apply(
			array(
				'learningSuggestions' => array(
					'items'   => array(),
					'summary' => array( 'total' => 0 ),
				),
			),
			'learning',
			0
		);
		self::assertTrue( $learning['learningSuggestions']['items'][0]['is_sample'] );
		self::assertStringEndsWith( 'Z', (string) $learning['learningSuggestions']['items'][0]['created_at'] );
		self::assertGreaterThanOrEqual( $installed_at, strtotime( (string) $learning['learningSuggestions']['items'][0]['created_at'] ) );
		self::assertLessThanOrEqual( $now, strtotime( (string) $learning['learningSuggestions']['items'][0]['updated_at'] ) );

		$logs = $samples->apply(
			array(
				'diagnostics' => array(
					'logs' => array(
						'items' => array(),
						'total' => 0,
					),
				),
			),
			'logs',
			0
		);
		self::assertTrue( $logs['diagnostics']['logs']['items'][0]['is_sample'] );
		self::assertGreaterThanOrEqual( $installed_at, $this->utc_timestamp( (string) $logs['diagnostics']['logs']['items'][0]['created_at'] ) );
		self::assertLessThanOrEqual( $now, $this->utc_timestamp( (string) $logs['diagnostics']['logs']['items'][0]['created_at'] ) );

		$diagnostics = $samples->apply(
			array(
				'connectionHealth' => array(
					'items' => array(),
				),
			),
			'diagnostics',
			0
		);
		self::assertTrue( $diagnostics['connectionHealth']['items'][0]['is_sample'] );
		self::assertGreaterThanOrEqual( $installed_at, $this->utc_timestamp( (string) $diagnostics['connectionHealth']['ranAt'] ) );
		self::assertLessThanOrEqual( $now, $this->utc_timestamp( (string) $diagnostics['connectionHealth']['ranAt'] ) );
	}

	private function utc_timestamp( string $value ): int {
		return (int) strtotime( $value . ' UTC' );
	}
}
