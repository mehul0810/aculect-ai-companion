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

	public function test_post_date_payload_rejects_invalid_dates(): void {
		$payload = $this->postDatePayload( '2026-02-31T09:30:00' );

		self::assertSame( 'invalid_date', $payload['error']['error'] );
	}

	public function test_post_date_payload_rejects_empty_dates(): void {
		$payload = $this->postDatePayload( '' );

		self::assertSame( 'invalid_date', $payload['error']['error'] );
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
}
