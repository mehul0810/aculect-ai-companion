<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

/**
 * Supported OAuth token endpoint client authentication methods.
 */
final class TokenEndpointAuthMethod {

	public const NONE                = 'none';
	public const CLIENT_SECRET_POST  = 'client_secret_post';
	public const CLIENT_SECRET_BASIC = 'client_secret_basic';
	public const DEFAULT             = self::CLIENT_SECRET_POST;

	/**
	 * Return supported token endpoint auth methods for metadata.
	 *
	 * @return list<string>
	 */
	public static function supported(): array {
		return array(
			self::CLIENT_SECRET_POST,
			self::CLIENT_SECRET_BASIC,
			self::NONE,
		);
	}

	/**
	 * Normalize a Dynamic Client Registration auth method request.
	 *
	 * @param mixed $value Requested token endpoint auth method.
	 */
	public static function from_registration_request( mixed $value ): string {
		if ( null === $value ) {
			return self::DEFAULT;
		}

		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$method = trim( (string) $value );

		return in_array( $method, self::supported(), true ) ? $method : '';
	}

	/**
	 * Whether the method represents a confidential OAuth client.
	 *
	 * @param string $method Token endpoint auth method.
	 */
	public static function is_confidential( string $method ): bool {
		return self::NONE !== $method;
	}
}
