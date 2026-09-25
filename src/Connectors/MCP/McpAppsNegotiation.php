<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Capability negotiation and tool metadata for the experimental MCP Apps slice.
 */
final class McpAppsNegotiation {

	public const EXTENSION     = 'io.modelcontextprotocol/ui';
	public const MIME_TYPE     = 'text/html;profile=mcp-app';
	public const SITE_INFO_URI = 'ui://aculect/site-info/v1.html';

	private const CAPABILITY_TRANSIENT_PREFIX = 'aculect_ai_companion_mcp_ui_';
	private const CAPABILITY_TTL              = 86400;

	/**
	 * Check whether the experimental feature has been explicitly enabled.
	 */
	public static function enabled(): bool {
		return true === apply_filters( 'aculect_ai_companion_mcp_apps_enabled', false );
	}

	/**
	 * Resolve MCP Apps support for the current authenticated RPC request.
	 *
	 * Initialize based clients are remembered briefly by OAuth access token. The
	 * stateless protocol requires capabilities on every request and never uses
	 * the remembered value as a fallback.
	 *
	 * @param string               $method   JSON-RPC method.
	 * @param array<string, mixed> $body     Complete JSON-RPC request.
	 * @param string               $version  Negotiated MCP protocol version.
	 * @param array<string, mixed> $auth     Authenticated OAuth context.
	 */
	public static function enabled_for_request( string $method, array $body, string $version, array $auth ): bool {
		$token_id = is_string( $auth['token_id'] ?? null ) ? $auth['token_id'] : '';

		if ( ! self::enabled() ) {
			self::forget_session( $token_id );

			return false;
		}

		$params = is_array( $body['params'] ?? null ) ? $body['params'] : array();
		if ( 'initialize' === $method ) {
			if ( ! McpProtocolVersion::uses_initialize( $version ) ) {
				return false;
			}

			$supported = self::client_supports_initialize( $params );
			self::remember_session( $token_id, $supported );

			return $supported;
		}

		if ( McpProtocolVersion::CURRENT === $version ) {
			return self::client_supports_stateless_request( $params );
		}

		return self::remembered_session_supports_apps( $token_id );
	}

	/**
	 * Add the negotiated extension to an initialize capability map.
	 *
	 * @param array<string, mixed> $capabilities Server capability map.
	 * @param bool                 $client_ready Client advertised MCP Apps.
	 * @return array<string, mixed>
	 */
	public static function initialize_capabilities( array $capabilities, bool $client_ready ): array {
		if ( self::enabled() && $client_ready ) {
			$extensions                 = is_array( $capabilities['extensions'] ?? null ) ? $capabilities['extensions'] : array();
			$capabilities['extensions'] = array_merge( $extensions, self::extension_capability() );
		}

		return $capabilities;
	}

	/**
	 * Build the stateless server discovery response.
	 *
	 * @param string[] $versions Supported MCP protocol versions.
	 * @phpstan-param list<string> $versions Supported MCP protocol versions.
	 * @param string   $instructions Server instructions.
	 * @return array<string, mixed>
	 */
	public static function discovery_payload( array $versions, string $instructions ): array {
		$capabilities = array(
			'tools'     => array( 'listChanged' => false ),
			'resources' => array( 'listChanged' => false ),
		);

		if ( self::enabled() ) {
			$capabilities['extensions'] = self::extension_capability();
		}

		return array(
			'supportedVersions' => $versions,
			'capabilities'      => $capabilities,
			'instructions'      => $instructions,
		);
	}

	/**
	 * Build common MCP tool metadata and optionally link the site-info view.
	 *
	 * @param string                     $ability_id Ability identifier.
	 * @param list<array<string, mixed>> $security Security scheme metadata.
	 * @param string                     $invoking Invocation status copy.
	 * @param string                     $invoked Completion status copy.
	 * @param bool                       $apps_enabled Negotiated Apps support.
	 * @return array<string, mixed>
	 */
	public static function tool_metadata( string $ability_id, array $security, string $invoking, string $invoked, bool $apps_enabled ): array {
		$metadata = array(
			'securitySchemes'                => $security,
			'openai/toolInvocation/invoking' => $invoking,
			'openai/toolInvocation/invoked'  => $invoked,
		);

		if ( $apps_enabled && 'site.get_info' === $ability_id ) {
			$metadata['ui'] = array(
				'resourceUri' => self::SITE_INFO_URI,
				'visibility'  => array( 'model' ),
			);
		}

		return $metadata;
	}

	/**
	 * Check the initialize client's declared UI content types.
	 *
	 * @param array<string, mixed> $params Initialize parameters.
	 */
	private static function client_supports_initialize( array $params ): bool {
		$capabilities = is_array( $params['capabilities'] ?? null ) ? $params['capabilities'] : array();
		$extensions   = is_array( $capabilities['extensions'] ?? null ) ? $capabilities['extensions'] : array();

		return self::supports_extension( $extensions );
	}

	/**
	 * Check stateless per-request client capabilities.
	 *
	 * @param array<string, mixed> $params Request parameters.
	 */
	private static function client_supports_stateless_request( array $params ): bool {
		$meta         = is_array( $params['_meta'] ?? null ) ? $params['_meta'] : array();
		$capabilities = $meta['io.modelcontextprotocol/clientCapabilities'] ?? array();
		$extensions   = is_array( $capabilities ) && is_array( $capabilities['extensions'] ?? null ) ? $capabilities['extensions'] : array();

		return self::supports_extension( $extensions );
	}

	/**
	 * Confirm that the client accepts the MCP Apps HTML profile.
	 *
	 * @param array<string, mixed> $extensions Client extension map.
	 */
	private static function supports_extension( array $extensions ): bool {
		$extension  = $extensions[ self::EXTENSION ] ?? array();
		$mime_types = is_array( $extension ) && is_array( $extension['mimeTypes'] ?? null ) ? $extension['mimeTypes'] : array();

		return in_array( self::MIME_TYPE, $mime_types, true );
	}

	/**
	 * Return the server's supported MCP Apps extension descriptor.
	 *
	 * @return array<string, array{mimeTypes: list<string>}>
	 */
	private static function extension_capability(): array {
		return array(
			self::EXTENSION => array(
				'mimeTypes' => array( self::MIME_TYPE ),
			),
		);
	}

	/**
	 * Remember the legacy initialize negotiation for one OAuth access token.
	 *
	 * @param string $token_id OAuth access token identifier.
	 * @param bool   $supported Client support state.
	 */
	private static function remember_session( string $token_id, bool $supported ): void {
		if ( '' === $token_id ) {
			return;
		}

		$key = self::transient_key( $token_id );
		if ( $supported ) {
			set_transient( $key, true, self::CAPABILITY_TTL );
		} else {
			delete_transient( $key );
		}
	}

	/**
	 * Check whether this OAuth client has a recent initialize negotiation.
	 *
	 * @param string $token_id OAuth access token identifier.
	 */
	private static function remembered_session_supports_apps( string $token_id ): bool {
		return '' !== $token_id && true === get_transient( self::transient_key( $token_id ) );
	}

	/**
	 * Clear one client's cached negotiation.
	 *
	 * @param string $token_id OAuth access token identifier.
	 */
	private static function forget_session( string $token_id ): void {
		if ( '' !== $token_id ) {
			delete_transient( self::transient_key( $token_id ) );
		}
	}

	/**
	 * Hash OAuth token IDs so transient names reveal no credentials.
	 *
	 * @param string $token_id OAuth access token identifier.
	 */
	private static function transient_key( string $token_id ): string {
		return self::CAPABILITY_TRANSIENT_PREFIX . hash( 'sha256', $token_id );
	}
}
