<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;

/**
 * Matches OAuth redirect URIs without weakening hosted-client validation.
 */
final class RedirectUriPolicy {

	/**
	 * Determine whether a requested redirect matches a registered client URI.
	 *
	 * Hosted redirects remain exact matches. RFC 8252 loopback clients may vary
	 * only the port; scheme, host, path, and query must remain registered.
	 *
	 * @param string|string[] $registered_uris Registered redirect URI values.
	 * @param string          $requested_uri   Redirect URI from the authorization request.
	 */
	public static function allows( string|array $registered_uris, string $requested_uri ): bool {
		if ( '' === $requested_uri || ! Helpers::is_allowed_redirect_uri( $requested_uri ) ) {
			return false;
		}

		$registered_uris = is_array( $registered_uris ) ? $registered_uris : array( $registered_uris );
		foreach ( $registered_uris as $registered_uri ) {
			$registered_uri = (string) $registered_uri;
			if ( '' === $registered_uri ) {
				continue;
			}

			if ( hash_equals( $registered_uri, $requested_uri ) ) {
				return true;
			}

			if ( self::loopback_port_variant( $registered_uri, $requested_uri ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Verify that only the port differs between eligible loopback redirect URIs.
	 *
	 * @param string $registered_uri Registered loopback redirect URI.
	 * @param string $requested_uri  Requested loopback redirect URI.
	 */
	private static function loopback_port_variant( string $registered_uri, string $requested_uri ): bool {
		$registered = self::loopback_components( $registered_uri );
		$requested  = self::loopback_components( $requested_uri );

		if ( null === $registered || null === $requested ) {
			return false;
		}

		unset( $registered['port'], $requested['port'] );

		return $registered === $requested;
	}

	/**
	 * Return security-relevant components for an eligible loopback redirect.
	 *
	 * @param string $uri Redirect URI.
	 * @return array{scheme: string, host: string, port: int|null, path: string, query: string}|null
	 */
	private static function loopback_components( string $uri ): ?array {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;

		if ( 'http' !== $scheme || ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) || '' === $path ) {
			return null;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || array_key_exists( 'fragment', $parts ) ) {
			return null;
		}

		if ( null !== $port && ( $port < 1 || $port > 65535 ) ) {
			return null;
		}

		return array(
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
			'path'   => $path,
			'query'  => (string) ( $parts['query'] ?? '' ),
		);
	}
}
