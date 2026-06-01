<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

/**
 * Builds stable fingerprints for equivalent Dynamic Client Registration input.
 */
final class ClientRegistrationFingerprint {

	/**
	 * Return a canonical redirect URI list for set-style comparisons.
	 *
	 * @param string[] $redirect_uris Valid redirect URIs.
	 * @return list<string>
	 */
	public static function canonical_redirect_uris( array $redirect_uris ): array {
		$canonical = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( string $uri ): string => trim( $uri ),
						$redirect_uris
					)
				)
			)
		);

		sort( $canonical, SORT_STRING );

		return $canonical;
	}

	/**
	 * Encode redirect URIs in canonical order for storage.
	 *
	 * @param string[] $redirect_uris Valid redirect URIs.
	 */
	public static function encoded_redirect_uris( array $redirect_uris ): ?string {
		$encoded = wp_json_encode( self::canonical_redirect_uris( $redirect_uris ) );

		return false === $encoded ? null : $encoded;
	}

	/**
	 * Build a stable fingerprint from redirect URIs.
	 *
	 * @param string[] $redirect_uris Valid redirect URIs.
	 */
	public static function from_redirect_uris( array $redirect_uris ): ?string {
		$encoded = self::encoded_redirect_uris( $redirect_uris );

		return null === $encoded ? null : self::from_encoded_redirect_uris( $encoded );
	}

	/**
	 * Build a stable fingerprint from stored redirect URI JSON.
	 *
	 * @param string $encoded_redirect_uris Stored redirect URI JSON.
	 */
	public static function from_encoded_redirect_uris( string $encoded_redirect_uris ): ?string {
		$decoded = json_decode( $encoded_redirect_uris, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$encoded = self::encoded_redirect_uris( array_map( 'strval', $decoded ) );

		return null === $encoded ? null : hash( 'sha256', $encoded );
	}
}
