<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Connectors\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * Provides non-persistent admin sample rows for local UI review.
 */
final class LocalSampleData {

	public const OPTION_FIRST_INSTALLED_AT = 'aculect_ai_companion_first_installed_at';

	private const ENVIRONMENT_TYPE = 'local';
	private const HOUR_IN_SECONDS  = 3600;

	private int $now_utc;

	private int $installed_at;

	public function __construct( ?int $now_utc = null, ?int $installed_at = null ) {
		$this->now_utc      = max( 1, null !== $now_utc ? $now_utc : time() );
		$this->installed_at = $this->resolve_installed_at( $installed_at );
	}

	/**
	 * Persist and return the first-known install timestamp.
	 *
	 * @param int|null $timestamp Optional UTC timestamp to persist when missing.
	 */
	public static function ensure_first_installed_at( ?int $timestamp = null ): int {
		$current = (int) get_option( self::OPTION_FIRST_INSTALLED_AT, 0 );
		if ( $current > 0 ) {
			return $current;
		}

		$timestamp = max( 1, null !== $timestamp ? $timestamp : time() );

		if ( add_option( self::OPTION_FIRST_INSTALLED_AT, $timestamp, '', false ) ) {
			return $timestamp;
		}

		$current = (int) get_option( self::OPTION_FIRST_INSTALLED_AT, 0 );
		if ( $current > 0 ) {
			return $current;
		}

		update_option( self::OPTION_FIRST_INSTALLED_AT, $timestamp, false );

		return $timestamp;
	}

	/**
	 * Determine whether local sample data may be shown.
	 */
	public function is_enabled(): bool {
		return function_exists( 'wp_get_environment_type' )
			&& self::ENVIRONMENT_TYPE === wp_get_environment_type();
	}

	/**
	 * Add sample rows to empty local review payloads, excluding Connections.
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

		if ( 'abilities' === $payload_tab && 0 === $real_active_session_count ) {
			$applied_tabs[] = 'abilities';
		}

		if ( 'activity' === $payload_tab && $this->has_empty_list_payload( $payload['activity'] ?? array() ) ) {
			$payload['activity'] = $this->activity_payload( $payload['activity'] ?? array() );
			$applied_tabs[]      = 'activity';
		}

		if ( 'learning' === $payload_tab && $this->has_empty_learning_suggestions( $payload ) ) {
			$payload['learningSuggestions'] = $this->learning_suggestions_payload();
			$applied_tabs[]                 = 'learning';
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
	 * Check whether the learning suggestion queue has no rows to render.
	 *
	 * @param array<string, mixed> $payload Settings payload.
	 */
	private function has_empty_learning_suggestions( array $payload ): bool {
		$learning = $payload['learningSuggestions'] ?? array();
		if ( ! is_array( $learning ) ) {
			return false;
		}

		return empty( $learning['items'] ) && 0 === (int) ( $learning['summary']['total'] ?? 0 );
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
			$this->activity_item( 'sample-activity-1', 0, 'chatgpt', 'ChatGPT Local QA', 'Local Administrator', 1, 'content.update_item', 'post', 42, 'success', '', 'Updated draft metadata.', array( 'risk_level' => 'publish' ) ),
			$this->activity_item( 'sample-activity-2', 1, 'claude', 'Claude Content Review', 'Editorial Lead', 2, 'comment.reply', 'comment', 118, 'success', '', 'Prepared a reply for moderation.', array( 'risk_level' => 'moderate' ) ),
			$this->activity_item( 'sample-activity-3', 2, 'codex', 'Codex Release Helper', 'Developer Admin', 3, 'media.upload', 'attachment', 77, 'success', '', 'Uploaded a placeholder image.', array( 'risk_level' => 'write' ) ),
			$this->activity_item( 'sample-activity-4', 3, 'chatgpt', 'ChatGPT Local QA', 'Local Administrator', 1, 'taxonomy.assign_terms', 'term', 12, 'success', '', 'Assigned editorial categories.', array( 'risk_level' => 'write' ) ),
			$this->activity_item( 'sample-activity-5', 4, 'claude', 'Claude Content Review', 'Editorial Lead', 2, 'content.publish_item', 'post', 43, 'error', 'capability_denied', 'WordPress denied publishing for this user.', array( 'risk_level' => 'publish' ) ),
		);
	}

	/**
	 * Build one sample activity row.
	 *
	 * @param string               $id          Row ID.
	 * @param int                  $sequence    Row sequence.
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
		string $id,
		int $sequence,
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
			'created_at'  => $this->format_datetime(
				$this->bounded_timestamp(
					$this->now_utc - ( 1800 + ( $sequence * 2700 ) ),
					$this->installed_at,
					$this->now_utc
				)
			),
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
			'is_sample'   => true,
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
			$this->log_item( 'sample-log-1', 0, 'info', 'oauth.registered', 'chatgpt', 'POST', '/wp-json/aculect-ai-companion/v1/oauth/register', 201, '', 'Registered a local sample OAuth client.', array( 'client_id' => 'local-chatgpt-demo' ) ),
			$this->log_item( 'sample-log-2', 1, 'warning', 'mcp.challenge_checked', 'claude', 'GET', '/wp-json/aculect-ai-companion/v1/mcp', 401, '', 'Connection URL returned an OAuth challenge.', array( 'expected' => 'bearer' ) ),
			$this->log_item( 'sample-log-3', 2, 'error', 'mcp.tool_denied', 'claude', 'POST', '/wp-json/aculect-ai-companion/v1/mcp', 403, 'capability_denied', 'A sample write action was blocked by WordPress capabilities.', array( 'tool' => 'content.publish_item' ) ),
			$this->log_item( 'sample-log-4', 3, 'info', 'oauth.token_refreshed', 'codex', 'POST', '/wp-json/aculect-ai-companion/v1/oauth/token', 200, '', 'Refreshed a local sample access token.', array( 'rotation' => 'refresh_token' ) ),
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
	 * Return sample learning suggestions for local UI review.
	 *
	 * @return array<string, mixed>
	 */
	private function learning_suggestions_payload(): array {
		$items = array(
			$this->learning_suggestion(
				'learn_local_brand',
				0,
				'brand',
				'The generated homepage copy sounded more casual than the saved brand profile.',
				'Prioritize concise, enterprise-oriented language before making tone inferences.',
				'Claude suggested a playful headline after reading a brand profile that favors direct product language.',
				'high',
				'pending',
				'Claude Content Review',
				'claude',
				2
			),
			$this->learning_suggestion(
				'learn_local_content',
				1,
				'content',
				'The assistant asked for custom HTML even though block markup is required.',
				'Remind clients to use registered blocks, patterns, and validation before writing content.',
				'The tool response included the block validation guardrail but the assistant still tried raw markup.',
				'medium',
				'approved',
				'ChatGPT Local QA',
				'chatgpt',
				1
			),
			$this->learning_suggestion(
				'learn_local_developer',
				2,
				'developer',
				'Runtime context did not mention that commands should not run from MCP.',
				'Clarify that developer intelligence is read-only implementation context and not command execution.',
				'Codex requested command-level details from a read-only intelligence response.',
				'low',
				'dismissed',
				'Codex Release Helper',
				'codex',
				3
			),
		);

		return array(
			'items'   => $items,
			'summary' => array(
				'total'     => count( $items ),
				'pending'   => 1,
				'approved'  => 1,
				'dismissed' => 1,
			),
		);
	}

	/**
	 * Build one local learning suggestion row.
	 *
	 * @param string $id               Suggestion ID.
	 * @param int    $sequence         Row sequence.
	 * @param string $domain           Intelligence domain.
	 * @param string $issue            Suggestion issue.
	 * @param string $suggested_update Suggested improvement.
	 * @param string $evidence         Bounded evidence.
	 * @param string $confidence       Confidence level.
	 * @param string $status           Review status.
	 * @param string $client_name      Sample client name.
	 * @param string $provider         Sample provider slug.
	 * @param int    $user_id          Sample WordPress user ID.
	 * @return array<string, mixed>
	 */
	private function learning_suggestion(
		string $id,
		int $sequence,
		string $domain,
		string $issue,
		string $suggested_update,
		string $evidence,
		string $confidence,
		string $status,
		string $client_name,
		string $provider,
		int $user_id
	): array {
		$created_at = $this->bounded_timestamp(
			$this->now_utc - ( ( 4 + $sequence ) * self::HOUR_IN_SECONDS ),
			$this->installed_at,
			$this->now_utc
		);
		$updated_at = $this->bounded_timestamp(
			$created_at + ( 20 * 60 ),
			$created_at,
			$this->now_utc
		);

		return array(
			'id'               => $id,
			'domain'           => $domain,
			'issue'            => $issue,
			'evidence'         => $evidence,
			'suggested_update' => $suggested_update,
			'confidence'       => $confidence,
			'status'           => $status,
			'created_at'       => $this->format_iso8601( $created_at ),
			'updated_at'       => $this->format_iso8601( $updated_at ),
			'review_note'      => '',
			'source'           => array(
				'provider'    => $provider,
				'client_id'   => 'local-' . $provider . '-client',
				'client_name' => $client_name,
				'user_id'     => $user_id,
			),
			'is_sample'        => true,
		);
	}

	/**
	 * Build one sample diagnostic log row.
	 *
	 * @param string               $id             Row ID.
	 * @param int                  $sequence       Row sequence.
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
		string $id,
		int $sequence,
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
			'created_at'     => $this->format_datetime(
				$this->bounded_timestamp(
					$this->now_utc - ( 1200 + ( $sequence * 1800 ) ),
					$this->installed_at,
					$this->now_utc
				)
			),
			'level'          => $level,
			'event'          => $event,
			'provider'       => $provider,
			'request_method' => $request_method,
			'request_route'  => $request_route,
			'http_status'    => $http_status,
			'error_code'     => $error_code,
			'message'        => $message,
			'context'        => $context,
			'is_sample'      => true,
		);
	}

	/**
	 * Return sample connection health data.
	 *
	 * @return array<string, mixed>
	 */
	private function connection_health_payload(): array {
		return array(
			'ranAt'   => $this->format_datetime(
				$this->bounded_timestamp(
					$this->now_utc - 900,
					$this->installed_at,
					$this->now_utc
				)
			),
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
			'is_sample'   => true,
		);
	}

	/**
	 * Return sample-data metadata for the React app.
	 *
	 * @param array $applied_tabs Tabs where empty rows were replaced.
	 * @phpstan-param list<string> $applied_tabs
	 * @return array<string, mixed>
	 */
	private function metadata( array $applied_tabs ): array {
		return array(
			'enabled'         => true,
			'environmentType' => self::ENVIRONMENT_TYPE,
			'tabs'            => array( 'abilities', 'activity', 'learning', 'diagnostics', 'logs' ),
			'appliedTabs'     => array_values( array_unique( $applied_tabs ) ),
			'message'         => __( 'Preview data - these are examples, not real connections or activity.', 'aculect-ai-companion' ),
		);
	}

	private function resolve_installed_at( ?int $installed_at ): int {
		$installed_at = null !== $installed_at ? $installed_at : self::ensure_first_installed_at( $this->now_utc );

		if ( $installed_at <= 0 ) {
			return $this->now_utc;
		}

		return min( $installed_at, $this->now_utc );
	}

	private function bounded_timestamp( int $preferred, int $minimum, int $maximum ): int {
		if ( $maximum < $minimum ) {
			$maximum = $minimum;
		}

		return max( $minimum, min( $preferred, $maximum ) );
	}

	private function format_datetime( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	private function format_iso8601( int $timestamp ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
