<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Capability negotiation and tool metadata for the experimental MCP Apps slice.
 */
final class McpAppsNegotiation {

	public const EXTENSION          = 'io.modelcontextprotocol/ui';
	public const MIME_TYPE          = 'text/html;profile=mcp-app';
	public const SITE_INFO_URI      = 'ui://aculect/site-info/v1.html';
	public const POST_UPDATE_URI    = 'ui://aculect/post-update/v1.html';
	public const PATTERN_PICKER_URI = 'ui://aculect/pattern-picker/v1.html';

	private const SESSION_PURPOSE        = 'aculect.mcp-apps-session.v1';
	private const SESSION_TTL            = 86400;
	private const MAX_FUTURE_SKEW        = 60;
	private static bool $request_enabled = false;

	/**
	 * Run one ability inside the server-derived Apps negotiation context.
	 *
	 * @template T
	 * @param bool         $enabled Whether this authenticated request negotiated Apps.
	 * @param callable():T $callback Ability execution.
	 * @return T
	 */
	public static function with_request_enabled( bool $enabled, callable $callback ): mixed {
		$previous              = self::$request_enabled;
		self::$request_enabled = $enabled;
		try {
			return $callback();
		} finally {
			self::$request_enabled = $previous;
		}
	}

	/**
	 * Report the active authenticated-request negotiation state.
	 */
	public static function request_enabled(): bool {
		return self::enabled() && self::$request_enabled;
	}

	/**
	 * Check whether the experimental feature has been explicitly enabled.
	 */
	public static function enabled(): bool {
		return true === apply_filters( 'aculect_ai_companion_mcp_apps_enabled', false );
	}

	/**
	 * Resolve MCP Apps support for the current authenticated RPC request.
	 *
	 * Legacy clients are scoped by their protocol session ID, not the OAuth
	 * access token, because one token can be shared by parallel client sessions
	 * and is rotated during refresh. The stateless protocol requires capabilities
	 * on every request and never uses session state as a fallback.
	 *
	 * @param string               $method   JSON-RPC method.
	 * @param array<string, mixed> $body     Complete JSON-RPC request.
	 * @param string               $version  Negotiated MCP protocol version.
	 * @param array<string, mixed> $auth     Authenticated OAuth context.
	 * @param string               $session_id Protocol session identifier, when supplied.
	 */
	public static function enabled_for_request( string $method, array $body, string $version, array $auth, string $session_id = '' ): bool {
		if ( ! self::enabled() ) {
			return false;
		}

		$params = is_array( $body['params'] ?? null ) ? $body['params'] : array();
		if ( 'initialize' === $method ) {
			if ( ! McpProtocolVersion::uses_initialize( $version ) ) {
				return false;
			}

			return self::client_supports_initialize( $params );
		}

		if ( McpProtocolVersion::CURRENT === $version ) {
			return self::client_supports_stateless_request( $params );
		}

		return self::remembered_session_supports_apps( $session_id, $auth );
	}

	/**
	 * Create a protocol session ID for an opted-in legacy MCP Apps client.
	 *
	 * The value is stateless and carries no OAuth credential. Its MAC binds the
	 * UI negotiation to the stable OAuth client, WordPress user, and site, allowing
	 * the same initialized session to continue after access-token rotation.
	 *
	 * @param array<string, mixed> $params Initialize parameters.
	 * @param array<string, mixed> $auth   Authenticated OAuth context.
	 * @param string               $session_id Client-provided protocol session ID.
	 */
	public static function create_legacy_session_id( array $params, array $auth, string $session_id = '' ): ?string {
		if ( ! self::enabled() || ! self::client_supports_initialize( $params ) ) {
			return null;
		}

		$client_id = is_string( $auth['client_id'] ?? null ) ? $auth['client_id'] : '';
		$user_id   = is_numeric( $auth['user_id'] ?? null ) ? (int) $auth['user_id'] : 0;
		if ( '' === $client_id || $user_id < 1 ) {
			return null;
		}

		if ( '' !== $session_id && self::valid_legacy_session_id( $session_id, $client_id, $user_id ) ) {
			return $session_id;
		}

		try {
			$nonce = bin2hex( random_bytes( 12 ) );
			$salt  = wp_salt( 'auth' );
		} catch ( \Throwable ) {
			return null;
		}

		if ( '' === $salt ) {
			return null;
		}

		$issued_at = time();
		if ( $issued_at > 0xffffffff ) {
			return null;
		}
		$timestamp = sprintf( '%08x', $issued_at );
		$mac       = self::session_mac( $timestamp, $nonce, $client_id, $user_id, $salt );
		return $timestamp . $nonce . substr( $mac, 0, 32 );
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
		} elseif ( $apps_enabled && 'content_workflow.update_post' === $ability_id ) {
			$metadata['ui'] = array(
				'resourceUri' => self::POST_UPDATE_URI,
				'visibility'  => array( 'model' ),
			);
		} elseif ( $apps_enabled && 'intelligence.patterns.list_available' === $ability_id ) {
			$metadata['ui'] = array(
				'resourceUri' => self::PATTERN_PICKER_URI,
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
	 * Check a stateless legacy MCP Apps session ID and its authenticated binding.
	 *
	 * @param string               $session_id Protocol session identifier.
	 * @param array<string, mixed> $auth       Authenticated OAuth context.
	 */
	private static function remembered_session_supports_apps( string $session_id, array $auth ): bool {
		$client_id = is_string( $auth['client_id'] ?? null ) ? $auth['client_id'] : '';
		$user_id   = is_numeric( $auth['user_id'] ?? null ) ? (int) $auth['user_id'] : 0;

		return '' !== $client_id && $user_id > 0 && self::valid_legacy_session_id( $session_id, $client_id, $user_id );
	}

	/**
	 * Validate the timestamp and 128-bit MAC in a stateless protocol session identifier.
	 *
	 * @param string $session_id Protocol session identifier.
	 * @param string $client_id  Authenticated OAuth client identifier.
	 * @param int    $user_id    Authenticated WordPress user ID.
	 */
	private static function valid_legacy_session_id( string $session_id, string $client_id, int $user_id ): bool {
		if ( 1 !== preg_match( '/\A([a-f0-9]{8})([a-f0-9]{24})([a-f0-9]{32})\z/', $session_id, $matches ) ) {
			return false;
		}

		$issued_at = hexdec( $matches[1] );
		$now       = time();
		if ( $issued_at > $now + self::MAX_FUTURE_SKEW || $issued_at < $now - self::SESSION_TTL ) {
			return false;
		}

		try {
			$salt = wp_salt( 'auth' );
		} catch ( \Throwable ) {
			return false;
		}
		if ( '' === $salt ) {
			return false;
		}

		$expected_mac = substr( self::session_mac( $matches[1], $matches[2], $client_id, $user_id, $salt ), 0, 32 );
		return hash_equals( $expected_mac, $matches[3] );
	}

	/**
	 * Calculate a domain-separated session MAC bound to the authenticated client, user, and site.
	 *
	 * @param string $timestamp Timestamp component from the ID.
	 * @param string $nonce     Random nonce component from the ID.
	 * @param string $client_id Authenticated OAuth client identifier.
	 * @param int    $user_id   Authenticated WordPress user ID.
	 * @param string $salt      WordPress authentication salt.
	 */
	private static function session_mac( string $timestamp, string $nonce, string $client_id, int $user_id, string $salt ): string {
		$binding = self::SESSION_PURPOSE . "\0" . $timestamp . "\0" . $nonce . "\0" . get_current_blog_id() . "\0" . $client_id . "\0" . $user_id;
		return hash_hmac( 'sha256', $binding, $salt );
	}
}
