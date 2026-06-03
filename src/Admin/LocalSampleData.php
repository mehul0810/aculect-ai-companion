<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Connectors\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Provides non-persistent admin sample rows for local UI review.
 */
final class LocalSampleData {

	private const ENVIRONMENT_TYPE = 'local';

	/**
	 * Determine whether local sample data may be shown.
	 */
	public function is_enabled(): bool {
		return function_exists( 'wp_get_environment_type' )
			&& self::ENVIRONMENT_TYPE === wp_get_environment_type();
	}

	/**
	 * Return the local sample connection count for listing tabs with no real rows.
	 *
	 * @param int    $count       Real active connection count.
	 * @param string $payload_tab Hydrated payload tab.
	 */
	public function active_session_count( int $count, string $payload_tab ): int {
		if ( ! $this->is_enabled() || $count > 0 ) {
			return $count;
		}

		return in_array( $payload_tab, array( 'connections', 'abilities' ), true )
			? count( $this->active_sessions() )
			: $count;
	}

	/**
	 * Add sample rows to empty local listing payloads.
	 *
	 * @param array<string, mixed> $payload                   Settings payload.
	 * @param string               $payload_tab               Hydrated payload tab.
	 * @param int                  $real_active_session_count Real active connection count.
	 * @return array<string, mixed>
	 */
	public function apply( array $payload, string $payload_tab, int $real_active_session_count ): array {
		if ( ! $this->is_enabled() ) {
			return $payload;
		}

		$applied_tabs = array();

		if ( 'connections' === $payload_tab && $this->has_empty_sessions( $payload ) ) {
			$payload['sessions']        = $this->active_sessions();
			$payload['revokedSessions'] = $this->revoked_sessions();
			$applied_tabs[]             = 'connections';
		}

		if ( 'abilities' === $payload_tab && 0 === $real_active_session_count ) {
			$applied_tabs[] = 'abilities';
		}

		if ( 'activity' === $payload_tab && $this->has_empty_list_payload( $payload['activity'] ?? array() ) ) {
			$payload['activity'] = $this->activity_payload( $payload['activity'] ?? array() );
			$applied_tabs[]      = 'activity';
		}

		if ( 'logs' === $payload_tab && $this->has_empty_logs( $payload ) ) {
			$payload['diagnostics']['loggingEnabled'] = true;
			$payload['diagnostics']['logs']           = $this->logs_payload();
			$applied_tabs[]                           = 'logs';
		}

		if ( 'diagnostics' === $payload_tab && $this->has_empty_diagnostics( $payload ) ) {
			$payload['connectionHealth'] = $this->connection_health_payload();
			$applied_tabs[]              = 'diagnostics';
		}

		$payload['sampleData'] = $this->metadata( $applied_tabs );

		return $payload;
	}

	/**
	 * Check whether the connections payload has no rows to render.
	 *
	 * @param array<string, mixed> $payload Settings payload.
	 */
	private function has_empty_sessions( array $payload ): bool {
		return empty( $payload['sessions'] ) && empty( $payload['revokedSessions'] );
	}

	/**
	 * Check whether a paginated list payload is empty.
	 *
	 * @param mixed $payload List payload.
	 */
	private function has_empty_list_payload( mixed $payload ): bool {
		return is_array( $payload )
			&& empty( $payload['items'] )
			&& 0 === (int) ( $payload['total'] ?? 0 );
	}

	/**
	 * Check whether the logs payload has no rows to render.
	 *
	 * @param array<string, mixed> $payload Settings payload.
	 */
	private function has_empty_logs( array $payload ): bool {
		$diagnostics = $payload['diagnostics'] ?? array();
		if ( ! is_array( $diagnostics ) ) {
			return false;
		}

		return $this->has_empty_list_payload( $diagnostics['logs'] ?? array() );
	}

	/**
	 * Check whether the diagnostics check table has no rows to render.
	 *
	 * @param array<string, mixed> $payload Settings payload.
	 */
	private function has_empty_diagnostics( array $payload ): bool {
		$health = $payload['connectionHealth'] ?? array();

		return ! is_array( $health ) || empty( $health['items'] );
	}

	/**
	 * Return active connector session samples.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function active_sessions(): array {
		return array(
			$this->session(
				9001,
				'local-chatgpt-demo',
				'ChatGPT Local QA',
				'chatgpt',
				'Local Administrator',
				array( 'Administrator' ),
				array( 'content:read', 'content:draft' ),
				true,
				'2026-06-03 09:25:00',
				'2026-07-03 09:25:00'
			),
			$this->session(
				9002,
				'local-claude-demo',
				'Claude Content Review',
				'claude',
				'Editorial Lead',
				array( 'Editor' ),
				array( 'content:read' ),
				false,
				'2026-06-03 08:40:00',
				'2026-07-03 08:40:00'
			),
			$this->session(
				9003,
				'local-codex-demo',
				'Codex Release Helper',
				'codex',
				'Developer Admin',
				array( 'Administrator', 'Editor' ),
				array( 'content:read', 'content:draft' ),
				true,
				'2026-06-02 18:12:00',
				'2026-07-02 18:12:00'
			),
		);
	}

	/**
	 * Return revoked connector session samples.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function revoked_sessions(): array {
		$session = $this->session(
			9090,
			'local-revoked-demo',
			'Retired Test Assistant',
			'chatgpt',
			'Former Reviewer',
			array( 'Author' ),
			array( 'content:read' ),
			false,
			'2026-05-30 11:05:00',
			'2026-06-01 11:05:00'
		);

		$session['status'] = 'revoked';

		return array( $session );
	}

	/**
	 * Build one connector session sample.
	 *
	 * @param int    $id                       Sample row ID.
	 * @param string $client_id                OAuth client ID.
	 * @param string $client_name              Client display name.
	 * @param string $provider                 Provider key.
	 * @param string $user                     User display name.
	 * @param array  $roles                    User roles.
	 * @param array  $scopes                   Granted scopes.
	 * @param bool   $write_permission_enabled Direct write permission flag.
	 * @param string $last_used_at             Last activity date.
	 * @param string $expires_at               Connection expiry date.
	 * @return array<string, mixed>
	 */
	private function session(
		int $id,
		string $client_id,
		string $client_name,
		string $provider,
		string $user,
		array $roles,
		array $scopes,
		bool $write_permission_enabled,
		string $last_used_at,
		string $expires_at
	): array {
		return array(
			'id'                       => $id,
			'client_id'                => $client_id,
			'client_name'              => $client_name,
			'provider'                 => $provider,
			'user_id'                  => $id - 9000,
			'user'                     => $user,
			'user_roles'               => $roles,
			'scopes'                   => $scopes,
			'resource'                 => Helpers::mcp_resource(),
			'status'                   => 'active',
			'created_at'               => '2026-05-28 10:00:00',
			'last_used_at'             => $last_used_at,
			'expires_at'               => $expires_at,
			'write_permission_enabled' => $write_permission_enabled,
		);
	}

	/**
	 * Return a sample activity payload.
	 *
	 * @param mixed $current_payload Current empty activity payload.
	 * @return array<string, mixed>
	 */
	private function activity_payload( mixed $current_payload ): array {
		$current_payload = is_array( $current_payload ) ? $current_payload : array();
		$items           = $this->activity_items();

		return array(
			'summary'    => $this->activity_summary( $items ),
			'items'      => $items,
			'total'      => count( $items ),
			'page'       => 1,
			'perPage'    => 50,
			'totalPages' => 1,
			'filters'    => $this->activity_filters( $current_payload['filters'] ?? array() ),
			'prevUrl'    => '',
			'nextUrl'    => '',
		);
	}

	/**
	 * Return sample activity rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function activity_items(): array {
		return array(
			$this->activity_item( 9101, 'chatgpt', 'ChatGPT Local QA', 'Local Administrator', 1, 'content.update_item', 'post', 42, 'success', '', 'Updated draft metadata.', array( 'risk_level' => 'publish' ) ),
			$this->activity_item( 9102, 'claude', 'Claude Content Review', 'Editorial Lead', 2, 'comment.reply', 'comment', 118, 'success', '', 'Prepared a reply for moderation.', array( 'risk_level' => 'moderate' ) ),
			$this->activity_item( 9103, 'codex', 'Codex Release Helper', 'Developer Admin', 3, 'media.upload', 'attachment', 77, 'success', '', 'Uploaded a placeholder image.', array( 'risk_level' => 'write' ) ),
			$this->activity_item( 9104, 'chatgpt', 'ChatGPT Local QA', 'Local Administrator', 1, 'taxonomy.assign_terms', 'term', 12, 'success', '', 'Assigned editorial categories.', array( 'risk_level' => 'write' ) ),
			$this->activity_item( 9105, 'claude', 'Claude Content Review', 'Editorial Lead', 2, 'content.publish_item', 'post', 43, 'error', 'capability_denied', 'WordPress denied publishing for this user.', array( 'risk_level' => 'publish' ) ),
		);
	}

	/**
	 * Build one sample activity row.
	 *
	 * @param int                  $id          Row ID.
	 * @param string               $provider    Provider key.
	 * @param string               $client_name Client display name.
	 * @param string               $user        User display name.
	 * @param int                  $user_id     User ID.
	 * @param string               $action      Activity action.
	 * @param string               $target_type Target type.
	 * @param int                  $target_id   Target ID.
	 * @param string               $status      Result status.
	 * @param string               $error_code  Error code.
	 * @param string               $message     Activity message.
	 * @param array<string, mixed> $context     Sanitized context.
	 * @return array<string, mixed>
	 */
	private function activity_item(
		int $id,
		string $provider,
		string $client_name,
		string $user,
		int $user_id,
		string $action,
		string $target_type,
		int $target_id,
		string $status,
		string $error_code,
		string $message,
		array $context
	): array {
		return array(
			'id'          => $id,
			'created_at'  => gmdate( 'Y-m-d H:i:s', strtotime( '2026-06-03 09:30:00' ) - ( ( $id - 9101 ) * 2700 ) ),
			'provider'    => $provider,
			'client_id'   => 'sample-' . $provider,
			'client_name' => $client_name,
			'user_id'     => $user_id,
			'user'        => $user,
			'action'      => $action,
			'target_type' => $target_type,
			'target_id'   => $target_id,
			'status'      => $status,
			'error_code'  => $error_code,
			'message'     => $message,
			'context'     => $context,
			'risk_level'  => (string) ( $context['risk_level'] ?? '' ),
		);
	}

	/**
	 * Summarize sample activity rows.
	 *
	 * @param list<array<string, mixed>> $items Activity items.
	 * @return array<string, int>
	 */
	private function activity_summary( array $items ): array {
		$assistants = array();
		$summary    = array(
			'total'      => count( $items ),
			'successes'  => 0,
			'failures'   => 0,
			'assistants' => 0,
			'highRisk'   => 0,
			'content'    => 0,
			'comments'   => 0,
			'media'      => 0,
		);

		foreach ( $items as $item ) {
			'success' === (string) $item['status'] ? ++$summary['successes'] : ++$summary['failures'];
			$assistants[] = (string) $item['client_name'];

			if ( in_array( (string) $item['risk_level'], array( 'publish', 'destructive', 'system' ), true ) ) {
				++$summary['highRisk'];
			}

			$this->increment_activity_type( $summary, (string) $item['target_type'] );
		}

		$summary['assistants'] = count( array_unique( $assistants ) );

		return $summary;
	}

	/**
	 * Increment one summary bucket by target type.
	 *
	 * @param array<string, int> $summary     Summary accumulator.
	 * @param string             $target_type Activity target type.
	 */
	private function increment_activity_type( array &$summary, string $target_type ): void {
		if ( in_array( $target_type, array( 'post', 'page', 'term', 'taxonomy' ), true ) ) {
			++$summary['content'];
		} elseif ( 'comment' === $target_type ) {
			++$summary['comments'];
		} elseif ( 'attachment' === $target_type ) {
			++$summary['media'];
		}
	}

	/**
	 * Preserve current activity filters when replacing empty rows.
	 *
	 * @param mixed $filters Current filters.
	 * @return array<string, mixed>
	 */
	private function activity_filters( mixed $filters ): array {
		$defaults = array(
			'page'      => 1,
			'action'    => '',
			'status'    => '',
			'user_id'   => 0,
			'assistant' => '',
			'search'    => '',
			'range'     => '7d',
		);

		return is_array( $filters ) ? array_merge( $defaults, $filters ) : $defaults;
	}

	/**
	 * Return a sample diagnostic logs payload.
	 *
	 * @return array<string, mixed>
	 */
	private function logs_payload(): array {
		$items = array(
			$this->log_item( 9201, 'info', 'oauth.registered', 'chatgpt', 'POST', '/wp-json/aculect-ai-companion/v1/oauth/register', 201, '', 'Registered a local sample OAuth client.', array( 'client_id' => 'local-chatgpt-demo' ) ),
			$this->log_item( 9202, 'warning', 'mcp.challenge_checked', 'claude', 'GET', '/wp-json/aculect-ai-companion/v1/mcp', 401, '', 'Connection URL returned an OAuth challenge.', array( 'expected' => 'bearer' ) ),
			$this->log_item( 9203, 'error', 'mcp.tool_denied', 'claude', 'POST', '/wp-json/aculect-ai-companion/v1/mcp', 403, 'capability_denied', 'A sample write action was blocked by WordPress capabilities.', array( 'tool' => 'content.publish_item' ) ),
			$this->log_item( 9204, 'info', 'oauth.token_refreshed', 'codex', 'POST', '/wp-json/aculect-ai-companion/v1/oauth/token', 200, '', 'Refreshed a local sample access token.', array( 'rotation' => 'refresh_token' ) ),
		);

		return array(
			'items'      => $items,
			'total'      => count( $items ),
			'page'       => 1,
			'perPage'    => 50,
			'totalPages' => 1,
			'prevUrl'    => '',
			'nextUrl'    => '',
		);
	}

	/**
	 * Build one sample diagnostic log row.
	 *
	 * @param int                  $id             Row ID.
	 * @param string               $level          Log level.
	 * @param string               $event          Event name.
	 * @param string               $provider       Provider key.
	 * @param string               $request_method HTTP method.
	 * @param string               $request_route  Request route.
	 * @param int                  $http_status    HTTP status.
	 * @param string               $error_code     Error code.
	 * @param string               $message        Log message.
	 * @param array<string, mixed> $context        Sanitized context.
	 * @return array<string, mixed>
	 */
	private function log_item(
		int $id,
		string $level,
		string $event,
		string $provider,
		string $request_method,
		string $request_route,
		int $http_status,
		string $error_code,
		string $message,
		array $context
	): array {
		return array(
			'id'             => $id,
			'created_at'     => gmdate( 'Y-m-d H:i:s', strtotime( '2026-06-03 09:20:00' ) - ( ( $id - 9201 ) * 1800 ) ),
			'level'          => $level,
			'event'          => $event,
			'provider'       => $provider,
			'request_method' => $request_method,
			'request_route'  => $request_route,
			'http_status'    => $http_status,
			'error_code'     => $error_code,
			'message'        => $message,
			'context'        => $context,
		);
	}

	/**
	 * Return sample connection health data.
	 *
	 * @return array<string, mixed>
	 */
	private function connection_health_payload(): array {
		return array(
			'ranAt'   => '2026-06-03 09:35:00',
			'summary' => 'warn',
			'items'   => array(
				$this->health_item( 'https_url', 'pass', 'Connection URL uses HTTPS.', 'No action needed.', array( 'host' => wp_parse_url( Helpers::mcp_resource(), PHP_URL_HOST ) ) ),
				$this->health_item( 'rest_route_shape', 'pass', 'Connection URL points to the MCP REST route.', 'No action needed.', array( 'route' => Helpers::MCP_ROUTE ) ),
				$this->health_item( 'protected_resource_metadata', 'pass', 'Resource metadata is reachable.', 'No action needed.', array( 'url' => Helpers::protected_resource_metadata_url() ) ),
				$this->health_item( 'authorization_metadata', 'warn', 'Authorization metadata should be checked from a public HTTPS hostname.', 'Use a tunnel or public test domain before connecting a hosted assistant.', array( 'environment' => self::ENVIRONMENT_TYPE ) ),
				$this->health_item( 'mcp_auth_challenge', 'fail', 'Sample failure for checking remediation layout.', 'Confirm security plugins and proxy rules allow the MCP REST path.', array( 'httpStatus' => 403 ) ),
			),
			'system'  => array(
				'site_url'          => site_url(),
				'rest_url'          => rest_url(),
				'connection_url'    => Helpers::mcp_resource(),
				'wordpress_version' => get_bloginfo( 'version' ),
				'php_version'       => PHP_VERSION,
				'environment_type'  => self::ENVIRONMENT_TYPE,
				'debug_mode'        => defined( 'WP_DEBUG' ) && WP_DEBUG ? 'Enabled' : 'Disabled',
			),
			'details' => array(
				'connectionUrl'                     => Helpers::mcp_resource(),
				'protectedResourceMetadataUrl'      => Helpers::protected_resource_metadata_url(),
				'authorizationServerMetadataUrl'    => Helpers::authorization_metadata_url(),
				'authorizationEndpoint'             => Helpers::authorization_endpoint(),
				'tokenEndpoint'                     => Helpers::token_endpoint(),
				'dynamicClientRegistrationEndpoint' => Helpers::registration_endpoint(),
			),
		);
	}

	/**
	 * Build one connection health item.
	 *
	 * @param string               $id          Check ID.
	 * @param string               $status      Check status.
	 * @param string               $message     Result message.
	 * @param string               $remediation Remediation text.
	 * @param array<string, mixed> $details     Safe details.
	 * @return array<string, mixed>
	 */
	private function health_item( string $id, string $status, string $message, string $remediation, array $details ): array {
		return array(
			'id'          => $id,
			'status'      => $status,
			'message'     => $message,
			'remediation' => $remediation,
			'details'     => $details,
		);
	}

	/**
	 * Return sample-data metadata for the React app.
	 *
	 * @param array $applied_tabs Tabs where empty rows were replaced.
	 * @return array<string, mixed>
	 */
	private function metadata( array $applied_tabs ): array {
		return array(
			'enabled'         => true,
			'environmentType' => self::ENVIRONMENT_TYPE,
			'tabs'            => array( 'connections', 'abilities', 'activity', 'diagnostics', 'logs' ),
			'appliedTabs'     => array_values( array_unique( $applied_tabs ) ),
			'message'         => __( 'Local sample data is available because WP_ENVIRONMENT_TYPE is local. Empty listing views can show non-persistent sample rows.', 'aculect-ai-companion' ),
		);
	}
}
