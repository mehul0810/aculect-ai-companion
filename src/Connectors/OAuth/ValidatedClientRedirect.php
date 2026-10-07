<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\OAuth;

/**
 * Sends OAuth responses through WordPress's safe-redirect boundary.
 */
final class ValidatedClientRedirect {

	/**
	 * Redirect to an already validated OAuth client callback.
	 *
	 * OAuth clients legitimately use external callback hosts, so the host from
	 * the validated redirect URI is allowed only for this operation. WordPress
	 * reparses the final response location and falls back locally if its host no
	 * longer matches that validated destination.
	 *
	 * @param string $redirect_uri Validated client redirect URI.
	 * @param string $location     Final OAuth response location.
	 */
	public static function send( string $redirect_uri, string $location ): bool {
		if ( ! self::matches_validated_destination( $redirect_uri, $location ) ) {
			return false;
		}

		if ( self::has_ipv6_literal_host( $redirect_uri ) ) {
			nocache_headers();
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- WordPress rejects IPv6 literals before its allowlist; scheme, host, port, and path were matched to the validated callback above.
			return wp_redirect( $location, 302, 'Aculect AI Companion OAuth' );
		}

		$allow_client_host = static fn( array $hosts, string $host ): array => self::allow_validated_host( $hosts, $host, $redirect_uri );

		add_filter( 'allowed_redirect_hosts', $allow_client_host, 10, 2 );
		try {
			nocache_headers();
			return wp_safe_redirect( $location, 302, 'Aculect AI Companion OAuth' );
		} finally {
			remove_filter( 'allowed_redirect_hosts', $allow_client_host, 10 );
		}
	}

	/**
	 * Allow only the redirect host parsed from the validated OAuth callback.
	 *
	 * @param string[] $hosts        WordPress redirect host allowlist.
	 * @param string   $host         Host parsed by WordPress from the final location.
	 * @param string   $redirect_uri Validated client redirect URI.
	 * @return string[]
	 */
	private static function allow_validated_host( array $hosts, string $host, string $redirect_uri ): array {
		$validated_uri  = function_exists( 'wp_sanitize_redirect' ) ? wp_sanitize_redirect( $redirect_uri ) : $redirect_uri;
		$validated_host = wp_parse_url( $validated_uri, PHP_URL_HOST );
		if ( ! is_string( $validated_host ) || '' === $validated_host || '' === $host ) {
			return $hosts;
		}

		if ( hash_equals( strtolower( $validated_host ), strtolower( $host ) ) ) {
			$hosts[] = $host;
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Verify the final OAuth response kept the validated callback destination.
	 *
	 * OAuth response parameters may extend the query string, but they must not
	 * change the callback's scheme, host, effective port, or path.
	 *
	 * @param string $redirect_uri Validated client redirect URI.
	 * @param string $location     Final OAuth response location.
	 */
	private static function matches_validated_destination( string $redirect_uri, string $location ): bool {
		$validated = self::destination_components( $redirect_uri );
		$final     = self::destination_components( $location );

		return null !== $validated && null !== $final && $validated === $final;
	}

	/**
	 * Return redirect components that must remain fixed in the final response.
	 *
	 * @param string $uri OAuth redirect URI.
	 * @return array{scheme: string, host: string, port: int|null, path: string}|null
	 */
	private static function destination_components( string $uri ): ?array {
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || array_key_exists( 'fragment', $parts ) ) {
			return null;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = trim( strtolower( (string) ( $parts['host'] ?? '' ) ), '[]' );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( '' === $scheme || '' === $host ) {
			return null;
		}

		if ( null === $port ) {
			$port = match ( $scheme ) {
				'https' => 443,
				'http' => 80,
				default => null,
			};
		}

		return array(
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
			'path'   => (string) ( $parts['path'] ?? '' ),
		);
	}

	/**
	 * Detect a validated IPv6 literal that WordPress core cannot safe-redirect.
	 *
	 * WordPress rejects the colon in an IPv6 host before applying its redirect
	 * host allowlist. These destinations use the strict component match above
	 * before falling back to the core sanitized redirect sink.
	 *
	 * @param string $redirect_uri Validated client redirect URI.
	 */
	private static function has_ipv6_literal_host( string $redirect_uri ): bool {
		$host = wp_parse_url( $redirect_uri, PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return false;
		}

		return false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
	}
}
