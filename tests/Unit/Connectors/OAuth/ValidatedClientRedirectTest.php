<?php
/**
 * Tests for the validated OAuth client redirect boundary.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\ValidatedClientRedirect;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies only the previously validated callback host becomes redirect-safe.
 */
final class ValidatedClientRedirectTest extends TestCase {

	public function test_only_the_validated_client_host_is_allowed(): void {
		$hosts  = array( 'example.com' );
		$method = new ReflectionMethod( ValidatedClientRedirect::class, 'allow_validated_host' );

		$allowed  = $method->invoke( null, $hosts, 'client.example', 'https://CLIENT.example/oauth/callback' );
		$rejected = $method->invoke( null, $hosts, 'evil.example', 'https://client.example/oauth/callback' );

		self::assertSame( array( 'example.com', 'client.example' ), $allowed );
		self::assertSame( $hosts, $rejected );
	}

	public function test_malformed_validated_uri_does_not_extend_the_allowlist(): void {
		$hosts  = array( 'example.com' );
		$method = new ReflectionMethod( ValidatedClientRedirect::class, 'allow_validated_host' );

		self::assertSame( $hosts, $method->invoke( null, $hosts, 'evil.example', 'not-a-url' ) );
	}

	public function test_final_response_must_keep_the_validated_destination(): void {
		$method = new ReflectionMethod( ValidatedClientRedirect::class, 'matches_validated_destination' );

		self::assertTrue(
			$method->invoke(
				null,
				'https://client.example/oauth/callback?tenant=one',
				'https://CLIENT.example:443/oauth/callback?tenant=one&code=abc&state=ok'
			)
		);
		self::assertTrue(
			$method->invoke(
				null,
				'http://[::1]:49152/oauth/callback',
				'http://[::1]:49152/oauth/callback?code=abc'
			)
		);
		self::assertFalse( $method->invoke( null, 'https://client.example/oauth/callback', 'https://evil.example/oauth/callback?code=abc' ) );
		self::assertFalse( $method->invoke( null, 'https://client.example/oauth/callback', 'https://client.example/other?code=abc' ) );
		self::assertFalse( $method->invoke( null, 'http://[::1]:49152/oauth/callback', 'http://user@[::1]:49152/oauth/callback?code=abc' ) );
		self::assertFalse( $method->invoke( null, 'http://[::1]:49152/oauth/callback', 'http://[::1]:49153/oauth/callback?code=abc' ) );
	}

	public function test_ipv6_literal_detection_excludes_malformed_bracketed_hosts(): void {
		$method = new ReflectionMethod( ValidatedClientRedirect::class, 'has_ipv6_literal_host' );

		self::assertTrue( $method->invoke( null, 'http://[::1]:49152/oauth/callback' ) );
		self::assertTrue( $method->invoke( null, 'https://[2001:db8::1]/oauth/callback' ) );
		self::assertFalse( $method->invoke( null, 'http://[localhost]:49152/oauth/callback' ) );
		self::assertFalse( $method->invoke( null, 'https://client.example/oauth/callback' ) );
	}
}
