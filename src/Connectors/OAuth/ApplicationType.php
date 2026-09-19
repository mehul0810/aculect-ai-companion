<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

/**
 * Normalizes the bounded OAuth Dynamic Client Registration application type.
 */
final class ApplicationType {

	public const LEGACY = 'legacy';
	public const NATIVE = 'native';
	public const WEB    = 'web';

	/**
	 * Normalize a stored application type without broadening malformed values.
	 *
	 * @param mixed $value Stored application type.
	 */
	public static function from_storage( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';

		return in_array( $value, array( self::WEB, self::NATIVE, self::LEGACY ), true ) ? $value : '';
	}

	/**
	 * Normalize DCR input. Omitted input preserves the pre-profile legacy policy.
	 *
	 * @param mixed $value   Requested application type.
	 * @param bool  $present Whether the request included application_type.
	 */
	public static function from_registration_request( mixed $value, bool $present ): string {
		if ( ! $present ) {
			return self::LEGACY;
		}

		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';

		return in_array( $value, array( self::WEB, self::NATIVE ), true ) ? $value : '';
	}
}
