<?php
/**
 * Tests for diagnostic log repository normalization.
 *
 * @package Aculect\AICompanion\Tests\Unit\Diagnostics
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Diagnostics\LogRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies database payload normalization.
 */
final class LogRepositoryTest extends TestCase {

	public function test_prepare_entry_normalizes_payload_for_database_storage(): void {
		$prepared = $this->prepareEntry(
			array(
				'level'          => 'debug',
				'event'          => 'Token Issued!!!',
				'provider'       => 'ChatGPT',
				'request_method' => 'post',
				'request_route'  => '/wp-json/aculect-ai-companion/v1/oauth/token',
				'http_status'    => 200,
				'error_code'     => 'invalid redirect uri',
				'message'        => "Completed\nwith whitespace.",
				'context'        => array(
					'provider'   => 'chatgpt',
					'grant_type' => 'authorization_code',
				),
			)
		);

		self::assertSame( 'info', $prepared['data']['level'] );
		self::assertSame( 'tokenissued', $prepared['data']['event'] );
		self::assertSame( 'chatgpt', $prepared['data']['provider'] );
		self::assertSame( 'POST', $prepared['data']['request_method'] );
		self::assertSame( 200, $prepared['data']['http_status'] );
		self::assertSame( 'invalidredirecturi', $prepared['data']['error_code'] );
		self::assertSame( 'Completed with whitespace.', $prepared['data']['message'] );
		self::assertSame(
			array(
				'provider'   => 'chatgpt',
				'grant_type' => 'authorization_code',
			),
			json_decode( $prepared['data']['context'], true )
		);
		self::assertCount( 10, $prepared['formats'] );
	}

	/**
	 * Invoke private repository normalization for focused unit coverage.
	 *
	 * @param array<string, mixed> $entry Raw entry.
	 * @return array{data: array<string, mixed>, formats: string[]}
	 */
	private function prepareEntry( array $entry ): array {
		$reflection = new ReflectionMethod( new LogRepository(), 'prepare_entry' );

		return $reflection->invokeArgs( new LogRepository(), array( $entry ) );
	}
}
