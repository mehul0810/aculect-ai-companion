<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

/**
 * Sanitizes diagnostic context before it is stored.
 */
final class LogSanitizer {

	private const MAX_DEPTH         = 4;
	private const MAX_ITEMS         = 30;
	private const MAX_STRING_LENGTH = 500;

	/**
	 * Remove sensitive values and normalize context for storage.
	 *
	 * @param array<string, mixed> $context Raw context.
	 * @return array<string, mixed>
	 */
	public function sanitize_context( array $context ): array {
		return $this->sanitize_array( $context, 0 );
	}

	/**
	 * Return a URL suitable for diagnostics by dropping query, fragment, and credentials.
	 *
	 * @param string $url Raw URL.
	 */
	public function sanitize_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		if ( '' === $scheme || '' === $host ) {
			return sanitize_text_field( $path );
		}

		return esc_url_raw( $scheme . '://' . $host . $port . $path );
	}

	/**
	 * Extract safe redirect URI host labels for diagnostics.
	 *
	 * @param string[] $redirect_uris Redirect URIs.
	 * @return string[]
	 */
	public function redirect_hosts( array $redirect_uris ): array {
		$hosts = array();

		foreach ( $redirect_uris as $uri ) {
			$parts = wp_parse_url( $uri );
			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				$hosts[] = strtolower( sanitize_text_field( (string) $parts['host'] ) );
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Recursively sanitize context arrays.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $depth Current recursion depth.
	 * @return mixed
	 */
	private function sanitize_value( mixed $value, int $depth ): mixed {
		if ( $depth >= self::MAX_DEPTH ) {
			return '[max-depth]';
		}

		if ( is_array( $value ) ) {
			return $this->sanitize_array( $value, $depth + 1 );
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		if ( is_scalar( $value ) ) {
			return $this->truncate( sanitize_text_field( (string) $value ) );
		}

		return '[unsupported]';
	}

	/**
	 * Sanitize an array while dropping sensitive keys.
	 *
	 * @param array<mixed> $items Raw array.
	 * @param int          $depth Current recursion depth.
	 * @return array<mixed>
	 */
	private function sanitize_array( array $items, int $depth ): array {
		$output = array();
		$count  = 0;

		foreach ( $items as $key => $value ) {
			if ( $count >= self::MAX_ITEMS ) {
				$output['truncated'] = true;
				break;
			}

			$key_string = (string) $key;
			if ( '' !== $key_string && $this->is_sensitive_key( $key_string ) ) {
				continue;
			}

			$output_key            = is_int( $key ) ? $key : sanitize_key( $key_string );
			$output[ $output_key ] = $this->sanitize_value( $value, $depth );
			++$count;
		}

		return $output;
	}

	/**
	 * Determine whether a context key is too sensitive to store.
	 *
	 * @param string $key Context key.
	 */
	private function is_sensitive_key( string $key ): bool {
		$key = strtolower( str_replace( array( '-', ' ' ), '_', $key ) );

		if ( in_array( $key, array( 'code', 'state' ), true ) ) {
			return true;
		}

		foreach ( $this->sensitive_fragments() as $fragment ) {
			if ( str_contains( $key, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return sensitive key fragments that should never be stored.
	 *
	 * @return string[]
	 */
	private function sensitive_fragments(): array {
		return array(
			'authorization',
			'auth_code',
			'authorization_code',
			'body',
			'client_secret',
			'code_challenge',
			'code_verifier',
			'cookie',
			'nonce',
			'oauth_code',
			'password',
			'raw',
			'refresh_token',
			'secret',
			'set_cookie',
			'token',
		);
	}

	/**
	 * Truncate long scalar values.
	 *
	 * @param string $value Raw scalar value.
	 */
	private function truncate( string $value ): string {
		if ( strlen( $value ) <= self::MAX_STRING_LENGTH ) {
			return $value;
		}

		return substr( $value, 0, self::MAX_STRING_LENGTH ) . '...';
	}
}
