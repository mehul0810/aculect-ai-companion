<?php
/**
 * Tests for diagnostic log sanitization.
 *
 * @package Aculect\AICompanion\Tests\Unit\Diagnostics
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Diagnostics\LogSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies sensitive diagnostic context cannot be stored.
 */
final class LogSanitizerTest extends TestCase {

	public function test_sensitive_keys_are_dropped_recursively(): void {
		$sanitizer = new LogSanitizer();

		$result = $sanitizer->sanitize_context(
			array(
				'provider'      => 'chatgpt',
				'error_code'    => 'invalid_redirect_uri',
				'access_token'  => 'token-value',
				'nested'        => array(
					'client_secret' => 'secret-value',
					'redirect_host' => 'chatgpt.com',
				),
				'authorization' => 'Bearer secret',
			)
		);

		self::assertSame( 'chatgpt', $result['provider'] );
		self::assertSame( 'invalid_redirect_uri', $result['error_code'] );
		self::assertArrayNotHasKey( 'access_token', $result );
		self::assertArrayNotHasKey( 'authorization', $result );
		self::assertArrayNotHasKey( 'client_secret', $result['nested'] );
		self::assertSame( 'chatgpt.com', $result['nested']['redirect_host'] );
	}

	public function test_urls_are_logged_without_query_fragment_or_credentials(): void {
		$sanitizer = new LogSanitizer();

		self::assertSame(
			'https://example.com/oauth/callback',
			$sanitizer->sanitize_url( 'https://user:pass@example.com/oauth/callback?code=secret#frag' )
		);
	}

	public function test_redirect_hosts_are_unique_and_lowercase(): void {
		$sanitizer = new LogSanitizer();

		self::assertSame(
			array( 'chatgpt.com', 'claude.ai' ),
			$sanitizer->redirect_hosts(
				array(
					'https://ChatGPT.com/a',
					'https://chatgpt.com/b',
					'https://claude.ai/c',
				)
			)
		);
	}
}
