<?php
/**
 * Validates exact site origins used by picker thumbnail previews.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Keeps picker media requests restricted to the configured WordPress origin. */
final class McpAppsPickerOrigin {
	/** Return the normalized configured site origin, or an empty string. */
	public static function site(): string {
		$url   = home_url( '/' );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		return self::from_url( $url );
	}

	/**
	 * Normalize an absolute HTTP origin candidate.
	 *
	 * @param string $url Candidate URL.
	 */
	public static function from_url( string $url ): string {
		if ( str_contains( $url, '*' ) || 'null' === strtolower( $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || ! self::valid_host( $host ) ) {
			return '';
		}

		$port         = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		$default_port = 'https' === $scheme ? 443 : 80;
		if ( $port < 1 || $port > 65535 ) {
			return '';
		}

		$authority_host = false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? '[' . $host . ']' : $host;
		return $scheme . '://' . $authority_host . ( $default_port === $port ? '' : ':' . $port );
	}

	private static function valid_host( string $host ): bool {
		if ( '' === $host || preg_match( '/[\x00-\x20\x7f;"\'`\\\\]/', $host ) ) {
			return false;
		}
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) || 'localhost' === $host ) {
			return true;
		}
		if ( strlen( $host ) > 253 ) {
			return false;
		}

		$dns_host = str_ends_with( $host, '.' ) ? substr( $host, 0, -1 ) : $host;
		if ( '' === $dns_host || str_ends_with( $dns_host, '.' ) ) {
			return false;
		}

		foreach ( explode( '.', $dns_host ) as $label ) {
			if ( '' === $label || strlen( $label ) > 63 || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label ) ) {
				return false;
			}
		}

		return true;
	}
}
