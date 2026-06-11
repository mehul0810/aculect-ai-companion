<?php
/**
 * Tests for content ability helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ContentAbilities;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies content date handling stays deterministic before WordPress writes.
 */
final class ContentAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	public function test_post_date_payload_accepts_site_local_iso_date(): void {
		$payload = $this->postDatePayload( '2026-06-01T09:30:00' );

		self::assertSame( '2026-06-01 09:30:00', $payload['post_date'] );
		self::assertSame( '2026-06-01 09:30:00', $payload['post_date_gmt'] );
	}

	public function test_post_date_payload_accepts_timezone_offset_date(): void {
		$payload = $this->postDatePayload( '2026-06-01T09:30:00+05:30' );

		self::assertSame( '2026-06-01 04:00:00', $payload['post_date'] );
		self::assertSame( '2026-06-01 04:00:00', $payload['post_date_gmt'] );
	}

	public function test_post_date_payload_uses_site_timezone_for_offset_date(): void {
		update_option( 'timezone_string', 'Asia/Kolkata' );

		$payload = $this->postDatePayload( '2026-06-01T09:30:00+05:30' );

		self::assertSame( '2026-06-01 09:30:00', $payload['post_date'] );
		self::assertSame( '2026-06-01 04:00:00', $payload['post_date_gmt'] );
	}

	public function test_post_date_payload_uses_site_timezone_for_local_date(): void {
		update_option( 'timezone_string', 'Asia/Kolkata' );

		$payload = $this->postDatePayload( '2026-06-01T09:30:00' );

		self::assertSame( '2026-06-01 09:30:00', $payload['post_date'] );
		self::assertSame( '2026-06-01 04:00:00', $payload['post_date_gmt'] );
	}

	public function test_post_date_payload_rejects_invalid_dates(): void {
		$payload = $this->postDatePayload( '2026-02-31T09:30:00' );

		self::assertSame( 'invalid_date', $payload['error']['error'] );
	}

	public function test_post_date_payload_rejects_empty_dates(): void {
		$payload = $this->postDatePayload( '' );

		self::assertSame( 'invalid_date', $payload['error']['error'] );
	}

	public function test_writable_status_supports_future_and_rejects_invalid_values(): void {
		self::assertSame( 'future', $this->writableStatus( 'future' ) );
		self::assertSame( '', $this->writableStatus( 'scheduled' ) );
	}

	/**
	 * Invoke the private date payload helper for focused validation.
	 *
	 * @return array<string, mixed>
	 */
	private function postDatePayload( string $date ): array {
		$reflection = new ReflectionMethod( ContentAbilities::class, 'post_date_payload_from_data' );
		$reflection->setAccessible( true );

		return $reflection->invoke( new ContentAbilities(), array( 'date' => $date ) );
	}

	/**
	 * Invoke the protected status helper for focused validation.
	 */
	private function writableStatus( string $status ): string {
		$reflection = new ReflectionMethod( ContentAbilities::class, 'writable_status' );
		$reflection->setAccessible( true );

		return (string) $reflection->invoke( new ContentAbilities(), $status );
	}
}
