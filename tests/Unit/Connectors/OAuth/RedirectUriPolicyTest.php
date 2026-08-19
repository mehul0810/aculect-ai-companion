<?php
/**
 * Tests for OAuth redirect URI matching.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\RedirectUriPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies hosted redirects stay exact while loopback ports may vary.
 */
final class RedirectUriPolicyTest extends TestCase {

	public function test_hosted_https_redirect_requires_an_exact_match(): void {
		$registered = 'https://claude.ai/api/mcp/auth_callback?tenant=one';

		self::assertTrue( RedirectUriPolicy::allows( $registered, $registered ) );
		self::assertFalse( RedirectUriPolicy::allows( $registered, 'https://claude.ai:8443/api/mcp/auth_callback?tenant=one' ) );
		self::assertFalse( RedirectUriPolicy::allows( $registered, 'https://claude.ai/api/mcp/auth_callback?tenant=two' ) );
		self::assertFalse( RedirectUriPolicy::allows( $registered, 'https://claude.com/api/mcp/auth_callback?tenant=one' ) );
	}

	public function test_application_profiles_reject_wildcards_credentials_and_fragments(): void {
		self::assertFalse( RedirectUriPolicy::allows_registration( 'https://*.example.com/callback', 'web' ) );
		self::assertFalse( RedirectUriPolicy::allows_registration( 'https://user@example.com/callback', 'web' ) );
		self::assertFalse( RedirectUriPolicy::allows_registration( 'https://example.com/callback#fragment', 'web' ) );
		self::assertFalse( RedirectUriPolicy::allows_registration( 'http://user@localhost/callback', 'native' ) );
		self::assertFalse( RedirectUriPolicy::allows_registration( 'http://localhost/callback#fragment', 'legacy' ) );
	}

	/**
	 * Verify a supported loopback redirect may use an ephemeral port.
	 *
	 * @param string $registered Registered loopback redirect.
	 * @param string $requested  Requested loopback redirect.
	 */
	#[DataProvider( 'allowed_loopback_variants' )]
	public function test_eligible_loopback_redirect_may_vary_only_the_port( string $registered, string $requested ): void {
		self::assertTrue( RedirectUriPolicy::allows( $registered, $requested ) );
	}

	/**
	 * Return accepted registered and requested loopback redirect pairs.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function allowed_loopback_variants(): array {
		return array(
			'localhost adds ephemeral port'        => array( 'http://localhost/callback', 'http://localhost:3118/callback' ),
			'localhost replaces registered port'   => array( 'http://localhost:7777/callback', 'http://localhost:3118/callback' ),
			'ipv4 loopback adds ephemeral port'    => array( 'http://127.0.0.1/callback', 'http://127.0.0.1:49152/callback' ),
			'query remains exact while port moves' => array( 'http://localhost/callback?flow=oauth', 'http://localhost:8123/callback?flow=oauth' ),
		);
	}

	/**
	 * Verify redirect changes other than an eligible loopback port fail closed.
	 *
	 * @param string $registered Registered redirect.
	 * @param string $requested  Requested redirect.
	 */
	#[DataProvider( 'rejected_redirect_variants' )]
	public function test_redirect_variants_fail_closed( string $registered, string $requested ): void {
		self::assertFalse( RedirectUriPolicy::allows( $registered, $requested ) );
	}

	/**
	 * Return rejected registered and requested redirect pairs.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function rejected_redirect_variants(): array {
		return array(
			'loopback host changes'        => array( 'http://localhost/callback', 'http://127.0.0.1:3118/callback' ),
			'lookalike host'               => array( 'http://localhost/callback', 'http://localhost.example:3118/callback' ),
			'path changes'                 => array( 'http://localhost/callback', 'http://localhost:3118/other' ),
			'query changes'                => array( 'http://localhost/callback?flow=one', 'http://localhost:3118/callback?flow=two' ),
			'query is added'               => array( 'http://localhost/callback', 'http://localhost:3118/callback?flow=two' ),
			'fragment is added'            => array( 'http://localhost/callback', 'http://localhost:3118/callback#code' ),
			'user information is added'    => array( 'http://localhost/callback', 'http://user@localhost:3118/callback' ),
			'non-loopback HTTP host'       => array( 'http://example.com/callback', 'http://example.com:3118/callback' ),
			'IPv6 port is not generalized' => array( 'http://[::1]/callback', 'http://[::1]:3118/callback' ),
		);
	}
}
