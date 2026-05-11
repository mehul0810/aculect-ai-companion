<?php

declare(strict_types=1);

namespace Quark\Connectors\OAuth\Server;

use Defuse\Crypto\Key;
use phpseclib3\Crypt\RSA;
use RuntimeException;

final class KeyManager {

	private const OPTION_ENCRYPTION_KEY = 'quark_oauth_encryption_key';
	private const OPTION_PRIVATE_KEY    = 'quark_oauth_private_key';
	private const OPTION_PUBLIC_KEY     = 'quark_oauth_public_key';

	public static function encryption_key(): string {
		$key = (string) get_option( self::OPTION_ENCRYPTION_KEY, '' );
		if ( '' === $key ) {
			$key = Key::createNewRandomKey()->saveToAsciiSafeString();
			update_option( self::OPTION_ENCRYPTION_KEY, $key, false );
		}

		return $key;
	}

	public static function private_key(): string {
		$key = (string) get_option( self::OPTION_PRIVATE_KEY, '' );
		if ( '' === $key || ! self::is_private_key_valid( $key ) ) {
			self::generate_key_pair();
			$key = (string) get_option( self::OPTION_PRIVATE_KEY, '' );
		}

		if ( '' === $key || ! self::is_private_key_valid( $key ) ) {
			throw new RuntimeException( 'Quark could not generate a valid OAuth private key.' );
		}

		return $key;
	}

	public static function public_key(): string {
		$key = (string) get_option( self::OPTION_PUBLIC_KEY, '' );
		if ( '' === $key || ! self::is_public_key_valid( $key ) ) {
			self::generate_key_pair();
			$key = (string) get_option( self::OPTION_PUBLIC_KEY, '' );
		}

		if ( '' === $key || ! self::is_public_key_valid( $key ) ) {
			throw new RuntimeException( 'Quark could not generate a valid OAuth public key.' );
		}

		return $key;
	}

	public static function delete_keys(): void {
		delete_option( self::OPTION_ENCRYPTION_KEY );
		delete_option( self::OPTION_PRIVATE_KEY );
		delete_option( self::OPTION_PUBLIC_KEY );
	}

	private static function generate_key_pair(): void {
		$key_pair = self::generate_with_openssl();
		if ( null === $key_pair ) {
			$key_pair = self::generate_with_phpseclib();
		}

		if ( null === $key_pair ) {
			throw new RuntimeException( 'Unable to generate OAuth signing keys.' );
		}

		update_option( self::OPTION_PRIVATE_KEY, $key_pair['private'], false );
		update_option( self::OPTION_PUBLIC_KEY, $key_pair['public'], false );
	}

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

	private static function is_private_key_valid( string $key ): bool {
		return false !== openssl_pkey_get_private( $key );
	}

	private static function is_public_key_valid( string $key ): bool {
		return false !== openssl_pkey_get_public( $key );
	}
}
