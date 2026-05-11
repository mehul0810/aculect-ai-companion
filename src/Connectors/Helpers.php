<?php

declare(strict_types=1);

namespace Quark\Connectors;

final class Helpers {

	public const REST_NAMESPACE              = 'quark/v1';
	public const MCP_ROUTE                   = 'quark/v1/mcp';
	public const AUTHORIZATION_METADATA      = 'oauth-authorization-server';
	public const PROTECTED_RESOURCE_METADATA = 'oauth-protected-resource';
	public const DEFAULT_SCOPES              = array( 'content:read', 'content:draft' );

	public static function issuer(): string {
		return self::normalize_url( self::external_base_url() );
	}

	public static function mcp_resource(): string {
		return self::normalize_url( self::external_rest_url( self::MCP_ROUTE ) );
	}

	public static function resource_path( ?string $resource = null ): string {
		$resource_url = null !== $resource && '' !== $resource ? $resource : self::mcp_resource();
		$path         = (string) wp_parse_url( $resource_url, PHP_URL_PATH );
		return untrailingslashit( $path );
	}

	public static function authorization_endpoint(): string {
		return self::external_rest_url( self::REST_NAMESPACE . '/oauth/authorize' );
	}

	public static function token_endpoint(): string {
		return self::external_rest_url( self::REST_NAMESPACE . '/oauth/token' );
	}

	public static function registration_endpoint(): string {
		return self::external_rest_url( self::REST_NAMESPACE . '/oauth/register' );
	}

	public static function authorization_metadata_url( ?string $issuer = null ): string {
		$issuer = self::normalize_url( null !== $issuer && '' !== $issuer ? $issuer : self::issuer() );
		$path   = (string) wp_parse_url( $issuer, PHP_URL_PATH );
		return self::origin_from_url( $issuer ) . '/.well-known/' . self::AUTHORIZATION_METADATA . untrailingslashit( $path );
	}

	public static function protected_resource_metadata_url( ?string $resource = null ): string {
		$resource = self::normalize_url( null !== $resource && '' !== $resource ? $resource : self::mcp_resource() );
		return self::origin_from_url( $resource ) . '/.well-known/' . self::PROTECTED_RESOURCE_METADATA . self::resource_path( $resource );
	}

	public static function supported_scopes(): array {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$scopes = apply_filters( 'quark/connectors/supported_scopes', self::DEFAULT_SCOPES );
		if ( ! is_array( $scopes ) ) {
			return self::DEFAULT_SCOPES;
		}

		$scopes = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $scope ): string => (string) preg_replace( '/[^a-zA-Z0-9:_\-.]/', '', (string) $scope ),
						$scopes
					)
				)
			)
		);
		return array() === $scopes ? self::DEFAULT_SCOPES : $scopes;
	}

	public static function normalize_url( string $url ): string {
		return untrailingslashit( esc_url_raw( $url ) );
	}

	public static function normalize_resource( string $resource ): string {
		return self::normalize_url( $resource );
	}

	public static function external_base_url(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$external_url = (string) apply_filters( 'quark/connectors/external_url', '' );
		$external_url = untrailingslashit( $external_url );

		if ( '' !== $external_url ) {
			$validated = wp_http_validate_url( $external_url );
			if ( false !== $validated ) {
				return untrailingslashit( $validated );
			}

			_doing_it_wrong(
				'quark/connectors/external_url',
				esc_html__( 'External connector URL must be a valid absolute URL. Falling back to home_url().', 'quark' ),
				'0.1.0'
			);
		}

		return untrailingslashit( home_url( '/' ) );
	}

	public static function external_rest_url( string $path ): string {
		return self::external_base_url() . '/wp-json/' . ltrim( $path, '/' );
	}

	public static function origin_from_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return self::external_base_url();
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		return '' === $host ? self::external_base_url() : $scheme . '://' . $host . $port;
	}

	public static function is_allowed_redirect_uri( string $uri ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$allowed = apply_filters( 'quark/connectors/allowed_redirect_uri', null, $uri );
		if ( is_bool( $allowed ) ) {
			return $allowed;
		}

		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path   = (string) ( $parts['path'] ?? '' );

		if ( '' !== (string) ( $parts['fragment'] ?? '' ) ) {
			return false;
		}

		if ( 'https' === $scheme ) {
			return true;
		}

		if ( 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) && '' !== $path ) {
			return true;
		}

		return false;
	}

	public static function provider_from_client( string $client_name, array $redirect_uris ): string {
		$haystack = strtolower( $client_name . ' ' . implode( ' ', $redirect_uris ) );

		if ( str_contains( $haystack, 'chatgpt.com' ) || str_contains( $haystack, 'chatgpt' ) || str_contains( $haystack, 'openai' ) ) {
			return 'chatgpt';
		}

		if ( str_contains( $haystack, 'claude' ) || str_contains( $haystack, 'anthropic' ) || str_contains( $haystack, 'localhost' ) ) {
			return 'claude';
		}

		return 'mcp';
	}
}
