<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors;

/**
 * Shared connector URL, metadata, and provider helpers.
 */
final class Helpers {

	public const REST_NAMESPACE              = 'aculect-ai-companion/v1';
	public const MCP_ROUTE                   = 'aculect-ai-companion/v1/mcp';
	public const AUTHORIZATION_METADATA      = 'oauth-authorization-server';
	public const PROTECTED_RESOURCE_METADATA = 'oauth-protected-resource';
	public const DEFAULT_SCOPES              = array( 'content:read', 'content:draft' );

	/**
	 * Return the external site issuer used by OAuth metadata.
	 */
	public static function issuer(): string {
		return self::normalize_url( self::external_base_url() );
	}

	/**
	 * Return the authorization-server issuer used for MCP resource metadata.
	 */
	public static function authorization_server_issuer(): string {
		return self::mcp_resource();
	}

	/**
	 * Return the canonical MCP resource URL.
	 */
	public static function mcp_resource(): string {
		return self::normalize_url( self::external_rest_url( self::MCP_ROUTE ) );
	}

	/**
	 * Return the path component for a resource URL.
	 *
	 * @param string|null $resource Optional resource URL.
	 * @return string
	 */
	public static function resource_path( ?string $resource = null ): string {
		$resource_url = null !== $resource && '' !== $resource ? $resource : self::mcp_resource();
		$path         = (string) wp_parse_url( $resource_url, PHP_URL_PATH );
		return untrailingslashit( $path );
	}

	/**
	 * Return the OAuth authorization endpoint URL.
	 */
	public static function authorization_endpoint(): string {
		return self::external_rest_url( self::REST_NAMESPACE . '/oauth/authorize' );
	}

	/**
	 * Return the OAuth token endpoint URL.
	 */
	public static function token_endpoint(): string {
		return self::external_rest_url( self::REST_NAMESPACE . '/oauth/token' );
	}

	/**
	 * Return the OAuth Dynamic Client Registration endpoint URL.
	 */
	public static function registration_endpoint(): string {
		return self::external_rest_url( self::REST_NAMESPACE . '/oauth/register' );
	}

	/**
	 * Return the OAuth authorization-server metadata URL.
	 *
	 * @param string|null $issuer Optional issuer URL.
	 * @return string
	 */
	public static function authorization_metadata_url( ?string $issuer = null ): string {
		$issuer = self::normalize_url( null !== $issuer && '' !== $issuer ? $issuer : self::authorization_server_issuer() );
		$path   = (string) wp_parse_url( $issuer, PHP_URL_PATH );
		return self::origin_from_url( $issuer ) . '/.well-known/' . self::AUTHORIZATION_METADATA . untrailingslashit( $path );
	}

	/**
	 * Return the OAuth protected-resource metadata URL.
	 *
	 * @param string|null $resource Optional resource URL.
	 * @return string
	 */
	public static function protected_resource_metadata_url( ?string $resource = null ): string {
		if ( null === $resource || '' === $resource ) {
			return self::origin_from_url( self::mcp_resource() ) . '/.well-known/' . self::PROTECTED_RESOURCE_METADATA;
		}

		$resource = self::normalize_url( $resource );
		return self::origin_from_url( $resource ) . '/.well-known/' . self::PROTECTED_RESOURCE_METADATA . self::resource_path( $resource );
	}

	/**
	 * Return supported OAuth scopes, allowing extension by filter.
	 *
	 * @return string[]
	 */
	public static function supported_scopes(): array {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$scopes = apply_filters( 'aculect-ai-companion/connectors/supported_scopes', self::DEFAULT_SCOPES );
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

	/**
	 * Normalize a URL for comparison and metadata output.
	 *
	 * @param string $url URL to normalize.
	 * @return string
	 */
	public static function normalize_url( string $url ): string {
		return untrailingslashit( esc_url_raw( $url ) );
	}

	/**
	 * Normalize an OAuth resource URL.
	 *
	 * @param string $resource Resource URL.
	 * @return string
	 */
	public static function normalize_resource( string $resource ): string {
		return self::normalize_url( $resource );
	}

	/**
	 * Return the externally reachable site base URL.
	 */
	public static function external_base_url(): string {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$external_url = (string) apply_filters( 'aculect-ai-companion/connectors/external_url', '' );
		$external_url = untrailingslashit( $external_url );

		if ( '' !== $external_url ) {
			$validated = wp_http_validate_url( $external_url );
			if ( false !== $validated ) {
				return untrailingslashit( $validated );
			}

			_doing_it_wrong(
				'aculect-ai-companion/connectors/external_url',
				esc_html__( 'External connector URL must be a valid absolute URL. Falling back to home_url().', 'aculect-ai-companion' ),
				'0.1.0'
			);
		}

		return untrailingslashit( home_url( '/' ) );
	}

	/**
	 * Build an externally reachable REST URL.
	 *
	 * @param string $path REST path without the /wp-json prefix.
	 * @return string
	 */
	public static function external_rest_url( string $path ): string {
		return self::external_base_url() . '/wp-json/' . ltrim( $path, '/' );
	}

	/**
	 * Return the origin portion of a URL.
	 *
	 * @param string $url URL to parse.
	 * @return string
	 */
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

	/**
	 * Validate redirect URIs accepted during Dynamic Client Registration.
	 *
	 * @param string $uri Redirect URI supplied by the OAuth client.
	 * @return bool
	 */
	public static function is_allowed_redirect_uri( string $uri ): bool {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$allowed = apply_filters( 'aculect-ai-companion/connectors/allowed_redirect_uri', null, $uri );
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

	/**
	 * Infer provider label from the DCR client metadata.
	 *
	 * @param string   $client_name   Client display name.
	 * @param string[] $redirect_uris Redirect URIs.
	 * @return string
	 */
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
