<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Server;

use Aculect\AICompanion\Diagnostics\Logger;
use Defuse\Crypto\Key;
use phpseclib3\Crypt\RSA;
use RuntimeException;

/**
 * Manages OAuth signing and encryption keys.
 *
 * Keys are generated lazily and stored in non-autoloaded WordPress options to
 * avoid filesystem permission issues on managed WordPress hosts. The private
 * signing key and the Defuse encryption key are encrypted at rest through
 * SecretsVault; the public key is stored as-is because it is public material.
 *
 * When the vault master key changes, stored secrets become undecryptable. That
 * is handled by regenerating the key pair: existing access tokens stop
 * validating and connected AI clients re-authorize through the normal OAuth
 * challenge. No site content data is lost.
 */
final class KeyManager {

	private const OPTION_ENCRYPTION_KEY = 'aculect_ai_companion_oauth_encryption_key';
	private const OPTION_PRIVATE_KEY    = 'aculect_ai_companion_oauth_private_key';
	private const OPTION_PUBLIC_KEY     = 'aculect_ai_companion_oauth_public_key';
	private const OPTION_KEY_CHECK      = 'aculect_ai_companion_oauth_key_check';

	/**
	 * Return the Defuse encryption key used by league/oauth2-server.
	 */
	public static function encryption_key(): string {
		self::assert_vault_available();
		self::guard_master_key_rotation();

		$key = self::read_secret_option( self::OPTION_ENCRYPTION_KEY );
		if ( '' === $key ) {
			$key = Key::createNewRandomKey()->saveToAsciiSafeString();
			self::write_secret_option( self::OPTION_ENCRYPTION_KEY, $key );
		}

		return $key;
	}

	/**
	 * Return a valid private signing key, generating one when needed.
	 *
	 * @throws RuntimeException When a valid private key cannot be generated.
	 */
	public static function private_key(): string {
		self::assert_vault_available();
		self::guard_master_key_rotation();

		$key = self::read_secret_option( self::OPTION_PRIVATE_KEY );
		if ( '' === $key || ! self::is_private_key_valid( $key ) ) {
			self::generate_key_pair();
			$key = self::read_secret_option( self::OPTION_PRIVATE_KEY );
		}

		if ( '' === $key || ! self::is_private_key_valid( $key ) ) {
			throw new RuntimeException( 'Aculect AI Companion could not generate a valid OAuth private key.' );
		}

		return $key;
	}

	/**
	 * Return a valid public signing key, generating one when needed.
	 *
	 * @throws RuntimeException When a valid public key cannot be generated.
	 */
	public static function public_key(): string {
		self::assert_vault_available();
		self::guard_master_key_rotation();

		$key = (string) get_option( self::OPTION_PUBLIC_KEY, '' );
		if ( '' === $key || ! self::is_public_key_valid( $key ) ) {
			self::generate_key_pair();
			$key = (string) get_option( self::OPTION_PUBLIC_KEY, '' );
		}

		if ( '' === $key || ! self::is_public_key_valid( $key ) ) {
			throw new RuntimeException( 'Aculect AI Companion could not generate a valid OAuth public key.' );
		}

		return $key;
	}

	/**
	 * Check whether secrets are currently stored encrypted.
	 *
	 * Used by diagnostics so site owners can verify the at-rest posture and
	 * see whether the dedicated constant is active.
	 *
	 * @return array{encrypted: bool, vault_available: bool, sodium_available: bool, dedicated_constant: bool, database_key: bool, plaintext_secret_present: bool}
	 */
	public static function storage_status(): array {
		$stored = (string) get_option( self::OPTION_PRIVATE_KEY, '' );
		$sodium = SecretsVault::sodium_available();

		return array(
			'encrypted'                => '' !== $stored && SecretsVault::is_encrypted( $stored ),
			'vault_available'          => $sodium && SecretsVault::is_available(),
			'sodium_available'         => $sodium,
			'dedicated_constant'       => SecretsVault::uses_dedicated_constant(),
			'database_key'             => $sodium && SecretsVault::uses_database_key(),
			'plaintext_secret_present' => '' !== $stored && ! SecretsVault::is_encrypted( $stored ),
		);
	}

	/**
	 * Delete stored OAuth keys during full uninstall cleanup.
	 */
	public static function delete_keys(): void {
		delete_option( self::OPTION_ENCRYPTION_KEY );
		delete_option( self::OPTION_PRIVATE_KEY );
		delete_option( self::OPTION_PUBLIC_KEY );
		delete_option( self::OPTION_KEY_CHECK );
		SecretsVault::delete_database_key();
	}

	/**
	 * Read a secret option, decrypting and migrating legacy plaintext rows.
	 *
	 * @param string $option Option name.
	 */
	private static function read_secret_option( string $option ): string {
		$stored = (string) get_option( $option, '' );
		if ( '' === $stored ) {
			return '';
		}

		if ( SecretsVault::is_encrypted( $stored ) ) {
			return SecretsVault::decrypt( $stored );
		}

		// Legacy plaintext row from <= 0.5.0: migrate in place.
		self::write_secret_option( $option, $stored );

		return $stored;
	}

	/**
	 * Persist a secret option encrypted when the vault is available.
	 *
	 * @param string $option Option name.
	 * @param string $value  Secret value.
	 * @throws RuntimeException When encrypted secret storage is unavailable.
	 */
	private static function write_secret_option( string $option, string $value ): void {
		$encrypted = SecretsVault::encrypt( $value );
		if ( '' === $encrypted ) {
			throw new RuntimeException( 'Aculect AI Companion cannot store OAuth secrets until encrypted secret storage is available.' );
		}

		update_option( $option, $encrypted, false );
		update_option( self::OPTION_KEY_CHECK, SecretsVault::key_check_value(), false );
	}

	/**
	 * Require encrypted secret storage before any OAuth secret is used.
	 *
	 * A dedicated wp-config constant is preferred, but when it is not present a
	 * random database-managed key is used so OAuth setup does not fall back to
	 * plaintext secrets.
	 *
	 * @throws RuntimeException When encrypted secret storage is unavailable.
	 */
	private static function assert_vault_available(): void {
		if ( SecretsVault::is_available() ) {
			return;
		}

		$had_keys = '' !== (string) get_option( self::OPTION_ENCRYPTION_KEY, '' )
			|| '' !== (string) get_option( self::OPTION_PRIVATE_KEY, '' )
			|| '' !== (string) get_option( self::OPTION_PUBLIC_KEY, '' );

		if ( $had_keys ) {
			delete_option( self::OPTION_ENCRYPTION_KEY );
			delete_option( self::OPTION_PRIVATE_KEY );
			delete_option( self::OPTION_PUBLIC_KEY );
			delete_option( self::OPTION_KEY_CHECK );

			if ( class_exists( Logger::class ) ) {
				( new Logger() )->warning(
					'oauth.secret_vault_unavailable',
					'OAuth key material was cleared because encrypted secret storage is unavailable.',
					array( 'error_code' => 'secret_vault_unavailable' )
				);
			}
		}

		throw new RuntimeException( 'Encrypted secret storage is unavailable. Ask the host to enable the PHP sodium extension.' );
	}

	/**
	 * Detect master-key rotation and regenerate keys instead of failing.
	 *
	 * Runs before any secret read. When the stored key-check no longer
	 * matches the active master key, every encrypted secret is unreadable;
	 * deleting them triggers lazy regeneration and clients re-authorize.
	 */
	private static function guard_master_key_rotation(): void {
		if ( ! SecretsVault::is_available() ) {
			return;
		}

		$stored_check = (string) get_option( self::OPTION_KEY_CHECK, '' );
		if ( '' === $stored_check ) {
			return;
		}

		if ( hash_equals( $stored_check, SecretsVault::key_check_value() ) ) {
			return;
		}

		delete_option( self::OPTION_ENCRYPTION_KEY );
		delete_option( self::OPTION_PRIVATE_KEY );
		delete_option( self::OPTION_PUBLIC_KEY );
		delete_option( self::OPTION_KEY_CHECK );

		if ( class_exists( Logger::class ) ) {
			( new Logger() )->warning(
				'oauth.key_rotation_detected',
				'OAuth secret encryption key changed. Signing keys were regenerated; connected AI clients must re-authorize.',
				array( 'error_code' => 'key_rotation' )
			);
		}
	}

	/**
	 * Generate and persist an RSA key pair.
	 *
	 * @throws RuntimeException When no supported key generator can produce keys.
	 */
	private static function generate_key_pair(): void {
		$key_pair = self::generate_with_openssl();
		if ( null === $key_pair ) {
			$key_pair = self::generate_with_phpseclib();
		}

		if ( null === $key_pair ) {
			throw new RuntimeException( 'Unable to generate OAuth signing keys.' );
		}

		self::write_secret_option( self::OPTION_PRIVATE_KEY, $key_pair['private'] );
		update_option( self::OPTION_PUBLIC_KEY, $key_pair['public'], false );
	}

	/**
	 * Generate an RSA key pair using the OpenSSL extension.
	 *
	 * @return array{private: string, public: string}|null
	 */
	private static function generate_with_openssl(): ?array {
		$resource = openssl_pkey_new(
			array(
				'digest_alg'       => 'sha256',
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		if ( false === $resource ) {
			return null;
		}

		$private_key = '';
		$exported    = openssl_pkey_export( $resource, $private_key );
		$details     = openssl_pkey_get_details( $resource );

		if ( false === $exported || ! is_array( $details ) || empty( $details['key'] ) || '' === $private_key ) {
			return null;
		}

		return array(
			'private' => $private_key,
			'public'  => (string) $details['key'],
		);
	}

	/**
	 * Generate an RSA key pair using phpseclib as a fallback.
	 *
	 * @return array{private: string, public: string}|null
	 */
	private static function generate_with_phpseclib(): ?array {
		$private_key = RSA::createKey( 2048 );
		$private_pem = $private_key->toString( 'PKCS8' );
		$public_pem  = $private_key->getPublicKey()->toString( 'PKCS8' );

		if ( ! self::is_private_key_valid( $private_pem ) || ! self::is_public_key_valid( $public_pem ) ) {
			return null;
		}

		return array(
			'private' => $private_pem,
			'public'  => $public_pem,
		);
	}

	/**
	 * Validate a private PEM key.
	 *
	 * @param string $key Private key PEM.
	 * @return bool
	 */
	private static function is_private_key_valid( string $key ): bool {
		return false !== openssl_pkey_get_private( $key );
	}

	/**
	 * Validate a public PEM key.
	 *
	 * @param string $key Public key PEM.
	 * @return bool
	 */
	private static function is_public_key_valid( string $key ): bool {
		return false !== openssl_pkey_get_public( $key );
	}
}
