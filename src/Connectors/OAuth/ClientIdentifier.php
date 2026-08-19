<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

/**
 * Distinguishes unsupported Client ID Metadata Document identifiers from DCR IDs.
 */
final class ClientIdentifier {

	/**
	 * Return whether an identifier is an HTTP(S) CIMD document URL.
	 *
	 * @param string $client_id OAuth client identifier.
	 */
	public static function is_metadata_document( string $client_id ): bool {
		$parts = wp_parse_url( trim( $client_id ) );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		return in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true )
			&& '' !== (string) ( $parts['host'] ?? '' );
	}
}
