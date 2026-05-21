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

	private const REQUEST_TIMEOUT = 8;

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
			$this->check_approval_screen_target(),
		);

		$result = array(
			'ranAt'   => gmdate( 'Y-m-d H:i:s' ),
			'summary' => $this->summary_status( $items ),
			'items'   => $items,
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
		delete_option( self::OPTION_LAST_RESULT );
	}

	/**
	 * Verify the connection URL is suitable for external AI tools.
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
			'If Cloudflare is enabled, temporarily disable Bot Fight Mode and avoid Flexible SSL on proxied DNS for this domain while connecting and using the AI tool.',
			array(
				'url'        => $url,
				'httpStatus' => $status,
			)
		);
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
			'details' => array(
				'connectionUrl'                  => Helpers::mcp_resource(),
				'protectedResourceMetadataUrl'   => Helpers::protected_resource_metadata_url(),
				'authorizationServerMetadataUrl' => Helpers::authorization_metadata_url(),
			),
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
