<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;

/**
 * Owns the non-secret database binding for the canonical OAuth issuer.
 */
final class IssuerBinding {

	/**
	 * Return the exact canonical authorization-server issuer.
	 */
	public static function issuer(): string {
		return Helpers::issuer();
	}

	/**
	 * Return the fixed-width database identity for the current issuer.
	 */
	public static function hash(): string {
		return hash( 'sha256', self::issuer() );
	}

	/**
	 * Verify a stored issuer identity without accepting blank legacy rows.
	 *
	 * @param mixed $stored_hash Stored fixed-width issuer identity.
	 */
	public static function matches( mixed $stored_hash ): bool {
		$stored_hash = is_scalar( $stored_hash ) ? strtolower( trim( (string) $stored_hash ) ) : '';

		return 64 === strlen( $stored_hash ) && hash_equals( self::hash(), $stored_hash );
	}
}
