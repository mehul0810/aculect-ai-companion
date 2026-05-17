<?php
/**
 * Tests for diagnostic logger behavior.
 *
 * @package Aculect\AICompanion\Tests\Unit\Diagnostics
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Diagnostics\Logger;
use Aculect\AICompanion\Diagnostics\LogSettings;
use Aculect\AICompanion\Diagnostics\LogSinkInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the logger respects opt-in settings.
 */
final class LoggerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$_SERVER['REQUEST_METHOD']                    = 'POST';
		$_SERVER['REQUEST_URI']                       = '/wp-json/aculect-ai-companion/v1/oauth/register?client_secret=hidden';
	}

	public function test_disabled_logging_does_not_insert(): void {
		$sink   = new ArrayLogSink();
		$logger = new Logger( $sink );

		self::assertFalse(
			$logger->info(
				'dcr.received',
				'Dynamic client registration request received.',
				array( 'provider' => 'chatgpt' )
			)
		);

		self::assertSame( array(), $sink->entries );
	}

	public function test_enabled_logging_sanitizes_and_inserts(): void {
		LogSettings::set_enabled( true );
		$sink   = new ArrayLogSink();
		$logger = new Logger( $sink );

		self::assertTrue(
			$logger->warning(
				'dcr.invalid_redirect_uri',
				'Invalid redirect URI.',
				array(
					'provider'      => 'chatgpt',
					'error_code'    => 'invalid_redirect_uri',
					'client_secret' => 'secret-value',
				),
				null,
				400
			)
		);

		self::assertCount( 1, $sink->entries );
		self::assertSame( 'dcr.invalid_redirect_uri', $sink->entries[0]['event'] );
		self::assertSame( 'warning', $sink->entries[0]['level'] );
		self::assertSame( 400, $sink->entries[0]['http_status'] );
		self::assertSame( 'chatgpt', $sink->entries[0]['provider'] );
		self::assertSame( 'invalid_redirect_uri', $sink->entries[0]['error_code'] );
		self::assertSame( '/wp-json/aculect-ai-companion/v1/oauth/register', $sink->entries[0]['request_route'] );
		self::assertArrayNotHasKey( 'client_secret', $sink->entries[0]['context'] );
		self::assertSame( 1, $sink->prune_count );
	}
}

/**
 * In-memory sink for logger tests.
 */
final class ArrayLogSink implements LogSinkInterface {

	/**
	 * @var array<int, array<string, mixed>>
	 */
	public array $entries = array();

	public int $prune_count = 0;

	/**
	 * Persist one diagnostic log entry.
	 *
	 * @param array<string, mixed> $entry Log entry data.
	 */
	public function insert( array $entry ): bool {
		$this->entries[] = $entry;

		return true;
	}

	/**
	 * Prune expired diagnostic log entries.
	 *
	 * @param int $retention_days Retention window.
	 */
	public function prune( int $retention_days ): int {
		unset( $retention_days );

		++$this->prune_count;

		return 0;
	}
}
