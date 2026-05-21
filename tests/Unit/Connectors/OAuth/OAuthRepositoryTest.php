<?php
/**
 * Tests for OAuth repository token handling helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\Repositories\AccessTokenRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\AuthCodeRepository;
use Aculect\AICompanion\Connectors\OAuth\Repositories\RefreshTokenRepository;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies token material is reduced to deterministic hashes before storage.
 */
final class OAuthRepositoryTest extends TestCase {

	public function test_access_refresh_and_auth_code_identifiers_are_hashed_consistently(): void {
		$raw = 'raw-token-material';

		$access_hash  = $this->hash(new AccessTokenRepository(), $raw);
		$refresh_hash = $this->hash(new RefreshTokenRepository(), $raw);
		$code_hash    = $this->hash(new AuthCodeRepository(), $raw);

		self::assertSame(hash('sha256', $raw), $access_hash);
		self::assertSame($access_hash, $refresh_hash);
		self::assertSame($access_hash, $code_hash);
		self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $access_hash);
		self::assertNotSame($raw, $access_hash);
	}

	/**
	 * Invoke the private hash helper on a repository.
	 *
	 * @param object $repository Repository instance.
	 * @param string $raw        Raw identifier.
	 */
	private function hash( object $repository, string $raw ): string {
		$reflection = new ReflectionMethod( $repository, 'hash_identifier' );

		return (string) $reflection->invokeArgs( $repository, array( $raw ) );
	}
}
