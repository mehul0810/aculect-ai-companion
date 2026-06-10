<?php
/**
 * Encryption at rest for OAuth secrets stored in wp_options.
 *
 * @package Aculect\AICompanion\Connectors\OAuth\Server
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth\Server;

/**
 * Encrypts plugin secrets with a dedicated wp-config constant or fallback key.
 *
 * Key material prefers ACULECT_AI_COMPANION_ENCRYPTION_KEY. When the constant
 * is absent, a random database-managed key is generated so setup can proceed
 * without plaintext OAuth secrets. WordPress auth salts are deliberately not
 * used: salts may be rotated by hosts, and in weak installs they may not
 * provide a reliable secret boundary for OAuth keys.
 *
 * If the active master key changes, decryption fails and callers regenerate the
 * affected OAuth keys; connected AI clients re-authorize through the normal
 * OAuth challenge. No site content data is lost.
 */
final class SecretsVault {

	private const PREFIX              = 'v1:';
	private const HKDF_INFO           = 'aculect-ai-companion-oauth-v1';
	private const NONCE_LENGTH        = 24;
	private const OPTION_DATABASE_KEY = 'aculect_ai_companion_secret_storage_key';

	/**
	 * Encrypt a secret for option storage.
	 *
	 * @param string $plaintext Secret value.
	 * @return string Versioned ciphertext, or '' when encryption is unavailable.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! self::is_available() ) {
			return '';
		}

		$nonce      = random_bytes( self::NONCE_LENGTH );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, self::master_key() );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe option storage, not obfuscation.
		return self::PREFIX . base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored option value.
	 * @return string Plaintext, or '' when the value cannot be decrypted.
	 */
	public static function decrypt( string $stored ): string {
		if ( ! self::is_encrypted( $stored ) || ! self::is_available() ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe option storage, not obfuscation.
		$binary = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $binary || strlen( $binary ) <= self::NONCE_LENGTH ) {
			return '';
		}

		$nonce      = substr( $binary, 0, self::NONCE_LENGTH );
		$ciphertext = substr( $binary, self::NONCE_LENGTH );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::master_key() );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Check whether a stored value uses the encrypted format.
	 *
	 * @param string $stored Stored option value.
	 */
	public static function is_encrypted( string $stored ): bool {
		return str_starts_with( $stored, self::PREFIX );
	}

	/**
	 * Check whether authenticated encryption is available on this host.
	 */
	public static function is_available(): bool {
		return self::sodium_available() && ( self::uses_dedicated_constant() || self::uses_database_key() );
	}

	/**
	 * Check whether PHP sodium secretbox support exists.
	 */
	public static function sodium_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
	}

	/**
	 * Check whether the admin defined the dedicated encryption constant.
	 */
	public static function uses_dedicated_constant(): bool {
		return strlen( self::dedicated_constant_value() ) >= 32;
	}

	/**
	 * Check whether the fallback database-managed key is available.
	 *
	 * Calling this method may lazily generate the fallback key when the
	 * dedicated constant is absent.
	 */
	public static function uses_database_key(): bool {
		return ! self::uses_dedicated_constant() && strlen( self::database_key_value() ) >= 32;
	}

	/**
	 * Delete the fallback database-managed key during full uninstall cleanup.
	 */
	public static function delete_database_key(): void {
		delete_option( self::OPTION_DATABASE_KEY );
	}

	/**
	 * Read the optional wp-config encryption constant.
	 */
	private static function dedicated_constant_value(): string {
		if ( ! defined( 'ACULECT_AI_COMPANION_ENCRYPTION_KEY' ) ) {
			return '';
		}

		$value = constant( 'ACULECT_AI_COMPANION_ENCRYPTION_KEY' );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Read or create the database-managed fallback key.
	 */
	private static function database_key_value(): string {
		$stored = get_option( self::OPTION_DATABASE_KEY, '' );
		if ( is_string( $stored ) && strlen( trim( $stored ) ) >= 32 ) {
			return trim( $stored );
		}

		$generated = bin2hex( random_bytes( 32 ) );
		if ( add_option( self::OPTION_DATABASE_KEY, $generated, '', false ) ) {
			return $generated;
		}

		$stored = get_option( self::OPTION_DATABASE_KEY, '' );
		if ( is_string( $stored ) && strlen( trim( $stored ) ) >= 32 ) {
			return trim( $stored );
		}

		update_option( self::OPTION_DATABASE_KEY, $generated, false );

		return $generated;
	}

	/**
	 * Return a short, non-reversible identifier for the active master key.
	 *
	 * Stored beside the encrypted options so key rotation is detected as a
	 * deliberate state instead of silent decryption failures.
	 */
	public static function key_check_value(): string {
		if ( ! self::is_available() ) {
			return '';
		}

		return hash( 'sha256', 'aculect-key-check|' . self::master_key() );
	}

	/**
	 * Derive the 32-byte master key.
	 */
	private static function master_key(): string {
		$key_material = self::dedicated_constant_value();
		if ( '' === $key_material ) {
			$key_material = self::database_key_value();
		}

		return hash_hkdf( 'sha256', $key_material, 32, self::HKDF_INFO );
	}
}
