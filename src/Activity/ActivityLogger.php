<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Activity;

use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;

/**
 * Records connected AI actions with sanitized metadata only.
 */
final class ActivityLogger {

	private const OPTION_LAST_PRUNED_AT = 'aculect_ai_companion_activity_last_pruned_at';
	private const PRUNE_INTERVAL        = 3600;
	private const DEFAULT_RETENTION     = 90;
	private const TIMELINE_EVENTS       = array(
		'initialize',
		'tools_list',
		'oauth_consent_approval',
		'token_exchange',
		'token_refresh',
		'tool_call_start',
		'tool_call_end',
		'blocked_by',
		'confirmation_issued',
		'confirmation_validated',
		'error',
	);

	private ActivityRepository $repository;

	public function __construct( ?ActivityRepository $repository = null ) {
		$this->repository = $repository ?? new ActivityRepository();
	}

	/**
	 * Record one MCP tool call with sanitized metadata only.
	 *
	 * @param string               $action Tool action.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @param array<string, mixed> $result Tool result.
	 * @param array<string, mixed> $auth   OAuth authentication context.
	 */
	public function record_tool_call( string $action, array $args, array $result, array $auth ): bool {
		$target = $this->target( $action, $args, $result );
		$status = isset( $result['error'] ) ? 'error' : 'success';
		$risk   = ( new ToolSafety() )->risk_level( $action, $args );

		$inserted = $this->repository->insert(
			array(
				'provider'    => (string) ( $auth['provider'] ?? 'mcp' ),
				'client_id'   => (string) ( $auth['client_id'] ?? '' ),
				'client_name' => (string) ( $auth['client_name'] ?? '' ),
				'user_id'     => (int) ( $auth['user_id'] ?? 0 ),
				'action'      => $action,
				'target_type' => $target['type'],
				'target_id'   => $target['id'],
				'status'      => $status,
				'error_code'  => 'error' === $status ? (string) ( $result['error'] ?? 'tool_error' ) : '',
				'message'     => 'error' === $status ? (string) ( $result['message'] ?? 'AI action failed.' ) : 'AI action completed.',
				'context'     => $this->context( $action, $args, $result, $risk, $auth ),
			)
		);

		if ( $inserted ) {
			$this->maybe_prune();
		}

		return $inserted;
	}

	/**
	 * Build sanitized activity context without storing request payload values.
	 *
	 * @param string               $action Tool action.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @param array<string, mixed> $result Tool result.
	 * @param string               $risk   Tool risk level.
	 * @param array<string, mixed> $auth   OAuth authentication context.
	 * @return array<string, mixed>
	 */
	private function context( string $action, array $args, array $result, string $risk, array $auth = array() ): array {
		$context = array(
			'argument_keys' => $this->argument_keys( $args ),
			'risk_level'    => $risk,
			'metadata'      => $this->safe_argument_metadata( $action, $args ),
			'result'        => $this->result_metadata( $result ),
		);

		if ( true === ( $auth['write_permission_used'] ?? false ) ) {
			$context['write_permission'] = array(
				'used'         => true,
				'access_level' => ConnectionAccessLevel::normalize( (string) ( $auth['access_level'] ?? '' ) ),
			);
		}

		return $context;
	}

	/**
	 * Record an administrator lifecycle action for user-level AI access.
	 *
	 * @param string               $action         Admin access action.
	 * @param int                  $target_user_id Affected WordPress user ID.
	 * @param int                  $actor_user_id  Administrator user ID.
	 * @param string               $message        Admin-safe activity message.
	 * @param array<string, mixed> $metadata       Sanitized extra metadata.
	 */
	public function record_user_access_event( string $action, int $target_user_id, int $actor_user_id, string $message, array $metadata = array() ): bool {
		$metadata = array_merge(
			array(
				'target_user_id' => max( 0, $target_user_id ),
				'actor_user_id'  => max( 0, $actor_user_id ),
			),
			$this->safe_event_metadata( $metadata )
		);

		$inserted = $this->repository->insert(
			array(
				'provider'    => 'admin',
				'client_id'   => '',
				'client_name' => 'WordPress Admin',
				'user_id'     => $actor_user_id,
				'action'      => $action,
				'target_type' => 'user',
				'target_id'   => $target_user_id,
				'status'      => 'success',
				'error_code'  => '',
				'message'     => $message,
				'context'     => array(
					'metadata' => $metadata,
				),
			)
		);

		if ( $inserted ) {
			$this->maybe_prune();
		}

		return $inserted;
	}

	/**
	 * Record a bounded MCP/OAuth session timeline event.
	 *
	 * @param string               $event    Timeline event type.
	 * @param array<string, mixed> $metadata Support-safe event metadata.
	 * @param array<string, mixed> $auth     OAuth or connection context.
	 */
	public function record_timeline_event( string $event, array $metadata = array(), array $auth = array() ): bool {
		$event    = $this->timeline_event( $event );
		$status   = $this->timeline_status( (string) ( $metadata['status'] ?? 'success' ) );
		$context  = $this->timeline_context( $event, $metadata, $auth );
		$inserted = $this->repository->insert(
			array(
				'provider'    => (string) ( $auth['provider'] ?? $metadata['provider'] ?? 'mcp' ),
				'client_id'   => $this->hashed_identifier( (string) ( $auth['client_id'] ?? $metadata['client_id'] ?? '' ) ),
				'client_name' => (string) ( $auth['client_name'] ?? $metadata['client_name'] ?? '' ),
				'user_id'     => (int) ( $auth['user_id'] ?? $metadata['user_id'] ?? 0 ),
				'action'      => 'mcp.timeline.' . $event,
				'target_type' => 'mcp_session',
				'target_id'   => null,
				'status'      => in_array( $status, array( 'error', 'blocked' ), true ) ? 'error' : 'success',
				'error_code'  => (string) ( $context['error_code'] ?? '' ),
				'message'     => $this->timeline_message( $event, $status, $context ),
				'context'     => $context,
			)
		);

		if ( $inserted ) {
			$this->maybe_prune();
		}

		return $inserted;
	}

	/**
	 * Extract target metadata from the request and result.
	 *
	 * @param string               $action Tool action.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @param array<string, mixed> $result Tool result.
	 * @return array{type: string, id: int|null}
	 */
	private function target( string $action, array $args, array $result ): array {
		return match ( $action ) {
			'content.create_item', 'content.update_item', 'content.update_seo', 'content_workflow.create_draft', 'content_workflow.update_post', 'content_media.apply_image', 'seo_workflow.update_rankmath' => array(
				'type' => sanitize_key( (string) ( $result['type'] ?? $args['post_type'] ?? 'content' ) ),
				'id'   => $this->first_id( $result, $args, array( 'id', 'post_id' ) ),
			),
			'taxonomy.create_term', 'taxonomy.update_term' => array(
				'type' => sanitize_key( (string) ( $result['taxonomy'] ?? $args['taxonomy'] ?? 'term' ) ),
				'id'   => $this->first_id( $result, $args, array( 'id', 'term_id' ) ),
			),
			'media.upload_item', 'media.upload_image_data' => array(
				'type' => 'attachment',
				'id'   => $this->first_id( $result, $args, array( 'id', 'post_id' ) ),
			),
			'plugin_lifecycle.install_plugin', 'plugin_lifecycle.update_plugin', 'plugin_lifecycle.activate_plugin', 'plugin_lifecycle.deactivate_plugin' => array(
				'type' => 'plugin',
				'id'   => null,
			),
			'comments.create_item', 'comments.update_item' => array(
				'type' => 'comment',
				'id'   => $this->first_id( $result, $args, array( 'id' ) ),
			),
			'redirects.create' => array(
				'type' => 'redirect',
				'id'   => $this->first_id( $result, $args, array( 'id' ) ),
			),
			'wp_abilities.run' => array(
				'type' => 'wp_ability',
				'id'   => null,
			),
			'content_index.refresh_batch', 'content_batch.status' => array(
				'type' => 'intelligence_job',
				'id'   => null,
			),
			'content_internal_link.suggestions_create', 'content_internal_link.suggestions_list', 'content_internal_link.suggestion_review', 'content_internal_link.suggestion_apply' => array(
				'type' => 'internal_link_suggestion',
				'id'   => null,
			),
			'memory.save', 'memory.list' => array(
				'type' => 'memory',
				'id'   => null,
			),
			default => array(
				'type' => 'mcp_tool',
				'id'   => null,
			),
		};
	}

	/**
	 * Return the first positive ID from result or arguments.
	 *
	 * @param array<string, mixed> $result Result data.
	 * @param array<string, mixed> $args   Argument data.
	 * @param string[]             $keys   Candidate keys.
	 */
	private function first_id( array $result, array $args, array $keys ): ?int {
		foreach ( $keys as $key ) {
			$id = absint( $result[ $key ] ?? $args[ $key ] ?? 0 );
			if ( $id > 0 ) {
				return $id;
			}
		}

		return null;
	}

	/**
	 * Return sanitized argument keys without argument values.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return string[]
	 */
	private function argument_keys( array $args ): array {
		return array_values(
			array_filter(
				array_map(
					static fn( string $key ): string => sanitize_key( $key ),
					array_keys( $args )
				)
			)
		);
	}

	/**
	 * Build a safe metadata summary from arguments without storing content bodies.
	 *
	 * @param string               $action Tool action.
	 * @param array<string, mixed> $args   Tool arguments.
	 * @return array<string, mixed>
	 */
	private function safe_argument_metadata( string $action, array $args ): array {
		$metadata = array(
			'action' => $action,
		);

		foreach ( array( 'post_type', 'status', 'taxonomy', 'id', 'plugin', 'slug', 'suggestion_id', 'source_id', 'target_id', 'term_id', 'post_id', 'update_mode', 'job_key', 'source_type', 'target', 'block_type', 'placement', 'provider' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_scalar( $args[ $key ] ) ) {
				$metadata[ $key ] = is_numeric( $args[ $key ] ) ? absint( $args[ $key ] ) : sanitize_text_field( (string) $args[ $key ] );
			}
		}

		if ( isset( $args['url'] ) && is_scalar( $args['url'] ) ) {
			$host = (string) wp_parse_url( (string) $args['url'], PHP_URL_HOST );
			if ( '' !== $host ) {
				$metadata['source_host'] = strtolower( sanitize_text_field( $host ) );
			}
		}

		if ( 'wp_abilities.run' === $action && isset( $args['id'] ) && is_scalar( $args['id'] ) ) {
			$metadata['wp_ability_id'] = sanitize_text_field( (string) $args['id'] );
		}

		return $metadata;
	}

	/**
	 * Build a safe result summary.
	 *
	 * @param array<string, mixed> $result Tool result.
	 * @return array<string, mixed>
	 */
	private function result_metadata( array $result ): array {
		$metadata = array();

		foreach ( array( 'id', 'post_id', 'attachment_id', 'type', 'status', 'workflow', 'taxonomy', 'mime_type', 'target', 'block_type', 'changed', 'verified' ) as $key ) {
			if ( isset( $result[ $key ] ) && is_scalar( $result[ $key ] ) ) {
				$metadata[ $key ] = is_numeric( $result[ $key ] ) ? absint( $result[ $key ] ) : sanitize_text_field( (string) $result[ $key ] );
			}
		}

		if ( isset( $result['error'] ) && is_scalar( $result['error'] ) ) {
			$metadata['error'] = sanitize_key( (string) $result['error'] );
		}

		return $metadata;
	}

	/**
	 * Sanitize scalar admin-event metadata.
	 *
	 * @param array<string, mixed> $metadata Raw metadata.
	 * @return array<string, mixed>
	 */
	private function safe_event_metadata( array $metadata ): array {
		$safe = array();

		foreach ( $metadata as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}

			$safe[ $key ] = is_numeric( $value ) ? absint( $value ) : sanitize_text_field( (string) $value );
		}

		return $safe;
	}

	/**
	 * Normalize timeline event names into the accepted event contract.
	 *
	 * @param string $event Timeline event type.
	 */
	private function timeline_event( string $event ): string {
		$event = sanitize_key( str_replace( array( '/', '-' ), '_', strtolower( $event ) ) );

		return in_array( $event, self::TIMELINE_EVENTS, true ) ? $event : 'error';
	}

	/**
	 * Normalize timeline status values while preserving blocked detail in context.
	 *
	 * @param string $status Timeline event status.
	 */
	private function timeline_status( string $status ): string {
		$status = sanitize_key( strtolower( $status ) );

		return in_array( $status, array( 'success', 'error', 'blocked', 'started', 'issued', 'validated' ), true ) ? $status : 'success';
	}

	/**
	 * Build support-safe timeline context without request bodies or OAuth material.
	 *
	 * @param string               $event    Timeline event type.
	 * @param array<string, mixed> $metadata Raw metadata.
	 * @param array<string, mixed> $auth     OAuth or connection context.
	 * @return array<string, mixed>
	 */
	private function timeline_context( string $event, array $metadata, array $auth ): array {
		$client_id = (string) ( $auth['client_id'] ?? $metadata['client_id'] ?? '' );
		$provider  = (string) ( $auth['provider'] ?? $metadata['provider'] ?? 'mcp' );
		$user_id   = (int) ( $auth['user_id'] ?? $metadata['user_id'] ?? 0 );
		$tool      = isset( $metadata['tool'] ) && is_scalar( $metadata['tool'] ) ? sanitize_text_field( (string) $metadata['tool'] ) : '';
		$risk      = isset( $metadata['risk_level'] ) && is_scalar( $metadata['risk_level'] ) ? sanitize_key( (string) $metadata['risk_level'] ) : '';

		$context = array(
			'timeline_event' => $event,
			'session_hash'   => $this->hashed_identifier( implode( '|', array( $provider, $client_id, (string) $user_id ) ) ),
			'provider'       => sanitize_key( $provider ),
			'client_hash'    => $this->hashed_identifier( $client_id ),
			'user_id'        => max( 0, $user_id ),
			'status'         => $this->timeline_status( (string) ( $metadata['status'] ?? 'success' ) ),
		);

		foreach ( array( 'method', 'grant_type', 'result_class', 'blocked_by', 'confirmation_policy', 'identity_status', 'refresh_token_state', 'recovery_action' ) as $key ) {
			if ( isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ) {
				$context[ $key ] = sanitize_key( (string) $metadata[ $key ] );
			}
		}

		if ( isset( $metadata['connection_id'] ) && is_numeric( $metadata['connection_id'] ) ) {
			$context['connection_id'] = max( 0, (int) $metadata['connection_id'] );
		}

		if ( isset( $metadata['connection_client_id'] ) && is_scalar( $metadata['connection_client_id'] ) ) {
			$context['connection_client_hash'] = $this->hashed_identifier( (string) $metadata['connection_client_id'] );
		}

		if ( '' !== $tool ) {
			$context['tool_name'] = $tool;
		}

		if ( '' !== $risk ) {
			$context['risk_level'] = $risk;
		}

		if ( isset( $metadata['duration_ms'] ) && is_numeric( $metadata['duration_ms'] ) ) {
			$context['duration_ms'] = max( 0, (int) $metadata['duration_ms'] );
		}

		if ( isset( $metadata['target_summary'] ) && is_scalar( $metadata['target_summary'] ) ) {
			$context['target_summary'] = substr( sanitize_text_field( (string) $metadata['target_summary'] ), 0, 160 );
		}

		if ( isset( $metadata['error_code'] ) && is_scalar( $metadata['error_code'] ) ) {
			$context['error_code'] = substr( sanitize_key( (string) $metadata['error_code'] ), 0, 100 );
		}

		return $context;
	}

	/**
	 * Return a deterministic non-reversible grouping identifier.
	 *
	 * @param string $identifier Raw identifier.
	 */
	private function hashed_identifier( string $identifier ): string {
		$identifier = trim( $identifier );

		return '' === $identifier ? '' : 'sha256:' . substr( hash( 'sha256', $identifier ), 0, 32 );
	}

	/**
	 * Build a compact admin-safe activity message.
	 *
	 * @param string               $event   Timeline event type.
	 * @param string               $status  Timeline event status.
	 * @param array<string, mixed> $context Sanitized timeline context.
	 */
	private function timeline_message( string $event, string $status, array $context = array() ): string {
		if ( 'token_refresh' === $event && 'error' === $status && 'unavailable_pre_auth' === ( $context['identity_status'] ?? '' ) ) {
			return __( 'Refresh was rejected before a WordPress identity was available. This request did not authenticate a WordPress session. Reconnect the assistant to restore access.', 'aculect-ai-companion' );
		}

		return sprintf(
			/* translators: 1: timeline event, 2: event status. */
			__( 'MCP session timeline event %1$s recorded with %2$s status.', 'aculect-ai-companion' ),
			$event,
			$status
		);
	}

	/**
	 * Prune activity rows at most hourly.
	 */
	private function maybe_prune(): void {
		$last_pruned_at = absint( get_option( self::OPTION_LAST_PRUNED_AT, 0 ) );
		if ( time() - $last_pruned_at < self::PRUNE_INTERVAL ) {
			return;
		}

		$retention_days = absint( apply_filters( 'aculect_ai_companion_activity_retention_days', self::DEFAULT_RETENTION ) );
		$this->repository->prune( max( 1, $retention_days ) );
		update_option( self::OPTION_LAST_PRUNED_AT, time(), false );
	}
}
