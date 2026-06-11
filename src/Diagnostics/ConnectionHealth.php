<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Diagnostics;

use Aculect\AICompanion\Connectors\Helpers;
use WP_Error;

/**
 * Runs admin-triggered checks for the AI assistant connection flow.
 */
final class ConnectionHealth {

	public const OPTION_LAST_RESULT = 'aculect_ai_companion_connection_health';

	private const OPTION_TRANSIENT_PROBE = 'aculect_ai_companion_connection_health_transient_probe';
	private const REQUEST_TIMEOUT        = 8;
	private const CLOUDFLARE_RULE        = '(starts_with(http.request.uri.path, "/wp-json/aculect-ai-companion/v1/") or starts_with(http.request.uri.path, "/.well-known/oauth-") or http.request.uri.path eq "/oauth/authorize")';

	/**
	 * Run the connection checks and persist the latest result.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$items = array(
			$this->check_https_url(),
			$this->check_rest_route_shape(),
			$this->check_protected_resource_metadata(),
			$this->check_authorization_metadata(),
			$this->check_mcp_auth_challenge(),
			$this->check_cloudflare_compatibility(),
			$this->check_mcp_tool_manifest(),
			$this->check_approval_screen_target(),
			$this->check_transient_persistence(),
			$this->check_secret_storage(),
		);

		$result = array(
			'ranAt'   => gmdate( 'Y-m-d H:i:s' ),
			'summary' => $this->summary_status( $items ),
			'items'   => $items,
			'system'  => $this->system_info(),
			'details' => array(
				'connectionUrl'                     => Helpers::mcp_resource(),
				'protectedResourceMetadataUrl'      => Helpers::protected_resource_metadata_url(),
				'authorizationServerMetadataUrl'    => Helpers::authorization_metadata_url(),
				'authorizationEndpoint'             => Helpers::authorization_endpoint(),
				'tokenEndpoint'                     => Helpers::token_endpoint(),
				'dynamicClientRegistrationEndpoint' => Helpers::registration_endpoint(),
			),
		);

		update_option( self::OPTION_LAST_RESULT, $result, false );

		return $result;
	}

	/**
	 * Return the latest saved diagnostics result or an empty state.
	 *
	 * @return array<string, mixed>
	 */
	public function last_result(): array {
		$result = get_option( self::OPTION_LAST_RESULT, array() );
		if ( array() === $result ) {
			return $this->empty_result();
		}

		return is_array( $result ) ? $this->sanitize_result( $result ) : $this->empty_result();
	}

	/**
	 * Delete stored diagnostics state.
	 */
	public static function delete(): void {
		$probe = get_option( self::OPTION_TRANSIENT_PROBE, array() );
		if ( is_array( $probe ) && isset( $probe['key'] ) ) {
			$key = sanitize_key( (string) $probe['key'] );
			if ( '' !== $key ) {
				delete_transient( $key );
			}
		}

		delete_option( self::OPTION_LAST_RESULT );
		delete_option( self::OPTION_TRANSIENT_PROBE );
	}

	/**
	 * Verify transients persist between requests.
	 *
	 * Confirmation tokens, idempotency replay, and OAuth rate limiting are
	 * transient-backed; a broken or non-shared object cache silently breaks
	 * all three, so surface it as a diagnostic instead.
	 *
	 * @return array<string, mixed>
	 */
	private function check_transient_persistence(): array {
		$probe = get_option( self::OPTION_TRANSIENT_PROBE, array() );
		if ( is_array( $probe ) && isset( $probe['key'], $probe['value'], $probe['created_at'] ) ) {
			$key        = sanitize_key( (string) $probe['key'] );
			$value      = sanitize_text_field( (string) $probe['value'] );
			$created_at = absint( $probe['created_at'] );
			$readback   = '' === $key ? false : get_transient( $key );

			delete_option( self::OPTION_TRANSIENT_PROBE );
			if ( '' !== $key ) {
				delete_transient( $key );
			}

			if ( $readback === $value && time() - $created_at <= 10 * MINUTE_IN_SECONDS ) {
				return $this->item( 'transient_persistence', 'pass', 'Transient storage persisted across diagnostics runs; confirmation tokens and rate limits can survive request boundaries.' );
			}

			return $this->item(
				'transient_persistence',
				'fail',
				'Transient storage did not persist across diagnostics runs. Confirmation tokens, idempotency replay, and OAuth rate limiting may not work between requests.',
				'Check the object cache configuration. On multi-server hosting, confirm a shared persistent object cache (Redis or Memcached) is configured.'
			);
		}

		$key   = 'aculect_ai_companion_health_probe_' . bin2hex( random_bytes( 6 ) );
		$value = bin2hex( random_bytes( 10 ) );
		set_transient( $key, $value, 10 * MINUTE_IN_SECONDS );
		update_option(
			self::OPTION_TRANSIENT_PROBE,
			array(
				'key'        => $key,
				'value'      => $value,
				'created_at' => time(),
			),
			false
		);

		return $this->item(
			'transient_persistence',
			'warn',
			'Transient storage probe was created. Run diagnostics again to verify persistence across request boundaries.',
			'Run AI Companion diagnostics one more time. A pass on the second run confirms confirmation tokens, idempotency replay, and OAuth rate limiting can persist.'
		);
	}

	/**
	 * Report the at-rest posture of stored OAuth secrets.
	 *
	 * @return array<string, mixed>
	 */
	private function check_secret_storage(): array {
		$status = \Aculect\AICompanion\Connectors\OAuth\Server\KeyManager::storage_status();

		if ( true !== $status['sodium_available'] ) {
			return $this->item(
				'secret_storage',
				'fail',
				'The PHP sodium extension is unavailable, so OAuth signing keys cannot be securely encrypted.',
				'Ask the host to enable the sodium extension (bundled with PHP 7.2+).'
			);
		}

		if ( true === $status['plaintext_secret_present'] ) {
			return $this->item(
				'secret_storage',
				'warn',
				'Legacy plaintext OAuth key material was detected and will be migrated to encrypted storage on the next OAuth key access.',
				'Run diagnostics again after reconnecting an AI client to confirm encrypted storage.'
			);
		}

		if ( true === $status['dedicated_constant'] ) {
			return $this->item( 'secret_storage', 'pass', 'OAuth keys are encrypted at rest with the dedicated ACULECT_AI_COMPANION_ENCRYPTION_KEY constant.' );
		}

		if ( true === $status['database_key'] ) {
			return $this->item(
				'secret_storage',
				'warn',
				'OAuth keys are encrypted at rest with an auto-generated database-managed key.',
				'For stronger production hardening, define ACULECT_AI_COMPANION_ENCRYPTION_KEY in wp-config.php with at least 32 random characters, then reconnect AI clients.'
			);
		}

		return $this->item(
			'secret_storage',
			'fail',
			'Encrypted OAuth secret storage is unavailable.',
			'Ask the host to enable the PHP sodium extension, or define ACULECT_AI_COMPANION_ENCRYPTION_KEY in wp-config.php after sodium is available.'
		);
	}

	/**
	 * Validate the public MCP URL scheme.
	 *
	 * @return array<string, mixed>
	 */
	private function check_https_url(): array {
		$url    = Helpers::mcp_resource();
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( 'https' === $scheme ) {
			return $this->item( 'https_url', 'pass', 'Connection URL uses HTTPS.', 'No action needed.', array( 'host' => $host ) );
		}

		if ( 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return $this->item( 'https_url', 'warn', 'Connection URL is local HTTP.', 'Use a public HTTPS URL before connecting a hosted AI tool.', array( 'host' => $host ) );
		}

		return $this->item( 'https_url', 'fail', 'Connection URL is not HTTPS.', 'Set the WordPress site URL to HTTPS or provide a valid external HTTPS connector URL.', array( 'host' => $host ) );
	}

	/**
	 * Verify the generated REST route has the expected public shape.
	 *
	 * @return array<string, mixed>
	 */
	private function check_rest_route_shape(): array {
		$path = (string) wp_parse_url( Helpers::mcp_resource(), PHP_URL_PATH );

		if ( str_contains( $path, '/wp-json/' . Helpers::MCP_ROUTE ) ) {
			return $this->item( 'rest_route_shape', 'pass', 'Connection URL points to the MCP REST route.' );
		}

		return $this->item( 'rest_route_shape', 'warn', 'Connection URL does not look like the expected REST route.', 'Confirm pretty REST API URLs are reachable and no proxy rewrites the /wp-json path.', array( 'path' => $path ) );
	}

	/**
	 * Verify protected-resource metadata can be reached publicly.
	 *
	 * @return array<string, mixed>
	 */
	private function check_protected_resource_metadata(): array {
		$url      = Helpers::protected_resource_metadata_url();
		$response = $this->get_json( $url );

		if ( 'pass' !== $response['status'] ) {
			$response['id'] = 'protected_resource_metadata';
			return $response;
		}

		$data = $response['json'];
		if ( ! is_array( $data ) || Helpers::mcp_resource() !== (string) ( $data['resource'] ?? '' ) ) {
			return $this->item( 'protected_resource_metadata', 'fail', 'Resource metadata loaded but did not describe this connection URL.', 'Flush permalinks and check whether a cache or proxy is serving stale metadata.', array( 'url' => $url ) );
		}

		return $this->item( 'protected_resource_metadata', 'pass', 'Resource metadata is reachable.', 'No action needed.', array( 'url' => $url ) );
	}

	/**
	 * Verify authorization-server metadata can be reached publicly.
	 *
	 * @return array<string, mixed>
	 */
	private function check_authorization_metadata(): array {
		$url      = Helpers::authorization_metadata_url();
		$response = $this->get_json( $url );

		if ( 'pass' !== $response['status'] ) {
			$response['id'] = 'authorization_metadata';
			return $response;
		}

		$data = $response['json'];
		if ( ! is_array( $data ) || '' === (string) ( $data['registration_endpoint'] ?? '' ) || '' === (string) ( $data['authorization_endpoint'] ?? '' ) ) {
			return $this->item( 'authorization_metadata', 'fail', 'Authorization metadata loaded but is missing connection endpoints.', 'Flush permalinks and clear any cache for /.well-known OAuth metadata URLs.', array( 'url' => $url ) );
		}

		return $this->item( 'authorization_metadata', 'pass', 'Authorization metadata is reachable.', 'No action needed.', array( 'url' => $url ) );
	}

	/**
	 * Verify the MCP endpoint returns an OAuth bearer challenge before login.
	 *
	 * @return array<string, mixed>
	 */
	private function check_mcp_auth_challenge(): array {
		$url      = Helpers::mcp_resource();
		$response = $this->remote_get( $url );

		if ( $response['error'] instanceof WP_Error ) {
			return $this->item( 'mcp_auth_challenge', 'fail', 'Connection URL could not be reached.', $response['error']->get_error_message(), array( 'url' => $url ) );
		}

		$status = (int) $response['status'];
		if ( 403 === $status ) {
			return $this->blocked_item( 'mcp_auth_challenge', $url, $status );
		}

		$challenge = strtolower( (string) ( $response['headers']['www-authenticate'] ?? '' ) );
		if ( 401 === $status && str_contains( $challenge, 'bearer' ) ) {
			return $this->item(
				'mcp_auth_challenge',
				'pass',
				'Connection URL returns the expected authorization challenge.',
				'No action needed.',
				array(
					'url'        => $url,
					'httpStatus' => $status,
				)
			);
		}

		return $this->item(
			'mcp_auth_challenge',
			'fail',
			'Connection URL did not return the expected authorization challenge.',
			'Check security plugins, Cloudflare bot features, and server rules for this REST path.',
			array(
				'url'        => $url,
				'httpStatus' => $status,
			)
		);
	}

	/**
	 * Surface Cloudflare edge-layer setup guidance for connector traffic.
	 *
	 * @return array<string, mixed>
	 */
	private function check_cloudflare_compatibility(): array {
		$signals = $this->cloudflare_request_signals();
		$details = array(
			'detected'        => array() !== $signals,
			'detected_by'     => $signals,
			'rule_expression' => self::CLOUDFLARE_RULE,
			'connector_paths' => array(
				'/wp-json/aculect-ai-companion/v1/',
				'/.well-known/oauth-',
				'/oauth/authorize',
			),
			'guidance'        => array(
				'Do not challenge Aculect connector routes with browser challenges, Under Attack mode, Bot Fight Mode, Super Bot Fight Mode, or WAF challenge actions.',
				'Use a narrow skip rule for Aculect routes only; do not bypass all /wp-json/ traffic.',
				'Use Cloudflare Full or Full (strict) SSL/TLS for proxied connector hostnames, not Flexible.',
				'If diagnostics logs are empty after a failed connection or tool call, the request may have been blocked before WordPress loaded.',
			),
		);

		if ( array() !== $signals ) {
			return $this->item(
				'cloudflare_compatibility',
				'warn',
				'Cloudflare headers were detected for this request.',
				'Create a narrow Cloudflare rule that skips browser challenges and bot challenges for Aculect connector routes, and avoid Flexible SSL/TLS on the connector hostname.',
				$details
			);
		}

		return $this->item(
			'cloudflare_compatibility',
			'pass',
			'Cloudflare was not detected from this WordPress request.',
			'This is a best-effort check. If OAuth or MCP failures produce no WordPress diagnostic logs, review CDN, WAF, and Cloudflare rules for Aculect connector routes.',
			$details
		);
	}

	/**
	 * Verify the local MCP tools/list manifest has client-safe tool descriptors.
	 *
	 * @return array<string, mixed>
	 */
	private function check_mcp_tool_manifest(): array {
		$manifest = new McpToolManifest();
		$summary  = $manifest->summary_for_current_user();
		$details  = array_merge(
			$summary,
			array(
				'ability_policy' => $manifest->ability_policy_context(),
			)
		);

		if ( 0 === (int) ( $summary['tool_count'] ?? 0 ) ) {
			return $this->item(
				'mcp_tool_manifest',
				'fail',
				'MCP tools/list did not expose any tools for the current WordPress user.',
				'Check ability settings and role-specific tool access before reconnecting the assistant.',
				$details
			);
		}

		if ( array() !== ( $summary['duplicate_tool_names'] ?? array() ) || array() !== ( $summary['invalid_tool_names'] ?? array() ) ) {
			return $this->item(
				'mcp_tool_manifest',
				'fail',
				'MCP tools/list contains tool names that may be rejected by strict clients.',
				'Export the MCP tool manifest and check duplicate_tool_names or invalid_tool_names.',
				$details
			);
		}

		return $this->item(
			'mcp_tool_manifest',
			'pass',
			sprintf(
				/* translators: %d: MCP tool count. */
				'MCP tools/list exposes %d tools with Claude-safe names.',
				(int) $summary['tool_count']
			),
			'If Claude reports fewer tools after a plugin update, remove and re-add the Claude custom connector, then compare an exported MCP tool manifest.',
			$details
		);
	}

	/**
	 * Verify generated approval-screen URLs stay on this WordPress site.
	 *
	 * @return array<string, mixed>
	 */
	private function check_approval_screen_target(): array {
		$authorization_origin = Helpers::origin_from_url( Helpers::authorization_endpoint() );
		$connection_origin    = Helpers::origin_from_url( Helpers::mcp_resource() );

		if ( $authorization_origin !== $connection_origin ) {
			return $this->item(
				'approval_screen_target',
				'warn',
				'Approval screen URL uses a different origin than the connection URL.',
				'Make sure your WordPress Address and Site Address use the same public HTTPS domain.',
				array(
					'authorizationOrigin' => $authorization_origin,
					'connectionOrigin'    => $connection_origin,
				)
			);
		}

		return $this->item( 'approval_screen_target', 'pass', 'Approval screen target matches the connection URL origin.' );
	}

	/**
	 * Fetch and decode a JSON endpoint.
	 *
	 * @param string $url Public URL.
	 * @return array<string, mixed>
	 */
	private function get_json( string $url ): array {
		$response = $this->remote_get( $url );

		if ( $response['error'] instanceof WP_Error ) {
			return $this->item( 'metadata_request', 'fail', 'Metadata URL could not be reached.', $response['error']->get_error_message(), array( 'url' => $url ) );
		}

		$status = (int) $response['status'];
		if ( 403 === $status ) {
			return $this->blocked_item( 'metadata_request', $url, $status );
		}

		if ( 200 !== $status ) {
			return $this->item(
				'metadata_request',
				'fail',
				'Metadata URL returned an unexpected HTTP status.',
				'Check permalink rules, REST API availability, and any proxy or security layer for this URL.',
				array(
					'url'        => $url,
					'httpStatus' => $status,
				)
			);
		}

		$json = json_decode( (string) $response['body'], true );
		if ( ! is_array( $json ) ) {
			return $this->item( 'metadata_request', 'fail', 'Metadata URL did not return JSON.', 'Clear caches and confirm the /.well-known OAuth metadata URLs are handled by WordPress.', array( 'url' => $url ) );
		}

		return array(
			'status' => 'pass',
			'json'   => $json,
		);
	}

	/**
	 * Perform one remote GET request with safe defaults.
	 *
	 * @param string $url Public URL.
	 * @return array{status: int, headers: array<string, string>, body: string, error: WP_Error|null}
	 */
	private function remote_get( string $url ): array {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 0,
				'headers' => array(),
				'body'    => '',
				'error'   => $response,
			);
		}

		$headers = wp_remote_retrieve_headers( $response );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => array_change_key_case( is_array( $headers ) ? $headers : array(), CASE_LOWER ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'error'   => null,
		);
	}

	/**
	 * Build a Cloudflare/security-layer remediation result.
	 *
	 * @param string $id     Check ID.
	 * @param string $url    URL that was requested.
	 * @param int    $status HTTP status.
	 * @return array<string, mixed>
	 */
	private function blocked_item( string $id, string $url, int $status ): array {
		return $this->item(
			$id,
			'fail',
			'The request reached a blocking layer before the plugin could answer.',
			'If Cloudflare is enabled, use the Cloudflare compatibility diagnostic guidance to skip browser and bot challenges for Aculect connector routes, and avoid Flexible SSL on proxied DNS for this domain.',
			array(
				'url'        => $url,
				'httpStatus' => $status,
			)
		);
	}

	/**
	 * Return Cloudflare request headers visible to WordPress.
	 *
	 * @return array<string, string>
	 */
	private function cloudflare_request_signals(): array {
		$headers = array(
			'HTTP_CF_RAY'           => 'cf-ray',
			'HTTP_CF_VISITOR'       => 'cf-visitor',
			'HTTP_CF_CONNECTING_IP' => 'cf-connecting-ip',
			'HTTP_CF_IPCOUNTRY'     => 'cf-ipcountry',
			'HTTP_CF_MITIGATED'     => 'cf-mitigated',
		);

		$signals = array();
		foreach ( $headers as $server_key => $label ) {
			$value = isset( $_SERVER[ $server_key ] ) && is_scalar( $_SERVER[ $server_key ] )
				? sanitize_text_field( (string) $_SERVER[ $server_key ] )
				: '';
			if ( '' !== $value ) {
				$signals[ $label ] = $value;
			}
		}

		return $signals;
	}

	/**
	 * Build one diagnostics row.
	 *
	 * @param string               $id          Stable check ID.
	 * @param string               $status      pass, warn, or fail.
	 * @param string               $message     User-facing status.
	 * @param string               $remediation User-facing next action.
	 * @param array<string, mixed> $details     Developer details.
	 * @return array<string, mixed>
	 */
	private function item( string $id, string $status, string $message, string $remediation = '', array $details = array() ): array {
		return array(
			'id'          => sanitize_key( $id ),
			'status'      => in_array( $status, array( 'pass', 'warn', 'fail' ), true ) ? $status : 'warn',
			'message'     => sanitize_text_field( $message ),
			'remediation' => sanitize_text_field( $remediation ),
			'details'     => $this->sanitize_details( $details ),
		);
	}

	/**
	 * Derive the summary status from check results.
	 *
	 * @param array<int, array<string, mixed>> $items Check rows.
	 */
	private function summary_status( array $items ): string {
		$statuses = array_map( static fn ( array $item ): string => (string) ( $item['status'] ?? 'warn' ), $items );
		if ( in_array( 'fail', $statuses, true ) ) {
			return 'fail';
		}

		if ( in_array( 'warn', $statuses, true ) ) {
			return 'warn';
		}

		return 'pass';
	}

	/**
	 * Sanitize a stored result before passing it to the admin UI.
	 *
	 * @param array<string, mixed> $result Stored result.
	 * @return array<string, mixed>
	 */
	private function sanitize_result( array $result ): array {
		$items = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();

		return array(
			'ranAt'   => sanitize_text_field( (string) ( $result['ranAt'] ?? '' ) ),
			'summary' => in_array( (string) ( $result['summary'] ?? '' ), array( 'pass', 'warn', 'fail' ), true ) ? (string) $result['summary'] : '',
			'items'   => array_values(
				array_filter(
					array_map(
						function ( mixed $item ): array {
							return is_array( $item )
								? $this->item(
									(string) ( $item['id'] ?? 'connection_check' ),
									(string) ( $item['status'] ?? 'warn' ),
									(string) ( $item['message'] ?? '' ),
									(string) ( $item['remediation'] ?? '' ),
									is_array( $item['details'] ?? null ) ? $item['details'] : array()
								)
								: array();
						},
						$items
					)
				)
			),
			'system'  => $this->sanitize_details( is_array( $result['system'] ?? null ) ? $result['system'] : array() ),
			'details' => $this->sanitize_details( is_array( $result['details'] ?? null ) ? $result['details'] : array() ),
		);
	}

	/**
	 * Return the no-run admin state.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_result(): array {
		return array(
			'ranAt'   => '',
			'summary' => '',
			'items'   => array(),
			'system'  => $this->system_info(),
			'details' => array(
				'connectionUrl'                  => Helpers::mcp_resource(),
				'protectedResourceMetadataUrl'   => Helpers::protected_resource_metadata_url(),
				'authorizationServerMetadataUrl' => Helpers::authorization_metadata_url(),
			),
		);
	}

	/**
	 * Return support-safe system details for the diagnostics screen.
	 *
	 * @return array<string, mixed>
	 */
	private function system_info(): array {
		return $this->sanitize_details(
			array(
				'site_url'          => home_url( '/' ),
				'rest_url'          => rest_url(),
				'connection_url'    => Helpers::mcp_resource(),
				'wordpress_version' => get_bloginfo( 'version' ),
				'php_version'       => PHP_VERSION,
				'environment_type'  => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				'debug_mode'        => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'enabled' : 'disabled',
			)
		);
	}

	/**
	 * Sanitize developer-facing details without secrets.
	 *
	 * @param array<string, mixed> $details Raw details.
	 * @return array<string, mixed>
	 */
	private function sanitize_details( array $details ): array {
		return ( new LogSanitizer() )->sanitize_context( $details );
	}
}
