<?php
/**
 * Tests for OAuth secret encryption storage.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\OAuth\Server\KeyManager;
use Aculect\AICompanion\Connectors\OAuth\Server\SecretsVault;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the no-constant fallback still encrypts OAuth key material.
 */
final class SecretsVaultTest extends TestCase {

	protected function tearDown(): void {
		KeyManager::delete_keys();

		parent::tearDown();
	}

	public function test_database_managed_key_encrypts_and_decrypts_without_constant(): void {
		if ( ! SecretsVault::sodium_available() ) {
			self::markTestSkipped( 'The sodium extension is required for secret storage tests.' );
		}

		self::assertFalse( SecretsVault::uses_dedicated_constant() );
		self::assertTrue( SecretsVault::uses_database_key() );

		$stored = SecretsVault::encrypt( 'oauth-secret-value' );

		self::assertStringStartsWith( 'v1:', $stored );
		self::assertSame( 'oauth-secret-value', SecretsVault::decrypt( $stored ) );
		self::assertIsString( get_option( 'aculect_ai_companion_secret_storage_key', '' ) );
		self::assertGreaterThanOrEqual( 32, strlen( (string) get_option( 'aculect_ai_companion_secret_storage_key', '' ) ) );
	}

	public function test_key_manager_stores_oauth_secrets_encrypted_with_database_managed_key(): void {
		if ( ! SecretsVault::sodium_available() ) {
			self::markTestSkipped( 'The sodium extension is required for secret storage tests.' );
		}

		$encryption_key = KeyManager::encryption_key();
		$private_key    = KeyManager::private_key();

		self::assertNotSame( '', $encryption_key );
		self::assertStringContainsString( 'BEGIN', $private_key );
		self::assertStringStartsWith( 'v1:', (string) get_option( 'aculect_ai_companion_oauth_encryption_key', '' ) );
		self::assertStringStartsWith( 'v1:', (string) get_option( 'aculect_ai_companion_oauth_private_key', '' ) );
		self::assertIsString( get_option( 'aculect_ai_companion_secret_storage_key', '' ) );
	}
}
