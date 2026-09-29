<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

use Aculect\AICompanion\Admin\McpApprovalQueue;
use Closure;

/**
 * Owns the WordPress-human approval projection for negotiated MCP Apps writes.
 *
 * This collaborator never executes an ability. It resolves an approval binding,
 * creates a pending request from a server-authored preview, and returns bounded
 * results to the existing execution gateway.
 */
final class McpAppApprovalHandoff {

	private AbilitiesRegistry $registry;
	private ToolSafety $safety;

	/**
	 * Timeline recorder for the sanitized approval lifecycle.
	 *
	 * @var Closure(string, array<string, mixed>, array<string, mixed>): void
	 */
	private Closure $timeline_recorder;

	/**
	 * Create one bounded approval handoff coordinator.
	 *
	 * @param AbilitiesRegistry $registry Ability metadata source.
	 * @param ToolSafety        $safety Existing write safety controls.
	 * @param Closure           $timeline_recorder Sanitized event callback.
	 * @phpstan-param Closure(string, array<string, mixed>, array<string, mixed>): void $timeline_recorder
	 */
	public function __construct( AbilitiesRegistry $registry, ToolSafety $safety, Closure $timeline_recorder ) {
		$this->registry          = $registry;
		$this->safety            = $safety;
		$this->timeline_recorder = $timeline_recorder;
	}

	/**
	 * Determine whether this negotiated request uses the human approval flow.
	 *
	 * @param string $tool Internal ability identifier.
	 * @param bool   $is_intelligence_tool Whether this is an intelligence tool.
	 */
	public function is_candidate( string $tool, bool $is_intelligence_tool ): bool {
		return ! $is_intelligence_tool && 'content_workflow.update_post' === $tool && McpAppsNegotiation::request_enabled();
	}

	/**
	 * Bind request state and fail-closed early results before generic claim logic.
	 *
	 * @param string              $tool Tool identifier.
	 * @param array<string,mixed> $args Normalized arguments.
	 * @param array<string,mixed> $auth OAuth context.
	 * @param bool                $is_intelligence_tool Intelligence tool flag.
	 * @param bool                $is_write_tool Write tool flag.
	 * @param bool                $requires_confirmation Confirmation policy result.
	 * @param bool                $is_dry_run Dry-run flag.
	 * @param bool                $has_confirmation_token Legacy token flag.
	 * @param bool                $write_permission_unblocked Direct-write policy flag.
	 * @return array{args:array<string,mixed>,candidate:bool,handoff:bool,store:PendingOperationApprovalStore|null,policy:string,capability_args:list<int>,request:array<string,mixed>|null,status:string,validated:bool,alias:string|null,early_result:array<string,mixed>|null}
	 */
	public function prepare( string $tool, array $args, array $auth, bool $is_intelligence_tool, bool $is_write_tool, bool $requires_confirmation, bool $is_dry_run, bool $has_confirmation_token, bool $write_permission_unblocked ): array {
		$candidate = $this->is_candidate( $tool, $is_intelligence_tool );
		if ( $candidate && ! isset( $args['expected_modified_gmt'] ) ) {
			$target = get_post( absint( $args['id'] ?? 0 ) );
			if ( $target instanceof \WP_Post && current_user_can( 'edit_post', (int) $target->ID ) ) {
				$args['expected_modified_gmt'] = (string) $target->post_modified_gmt;
			}
		}

		$empty = array(
			'args'            => $args,
			'candidate'       => $candidate,
			'handoff'         => false,
			'store'           => null,
			'policy'          => '',
			'capability_args' => array( absint( $args['id'] ?? 0 ) ),
			'request'         => null,
			'status'          => '',
			'validated'       => false,
			'alias'           => null,
			'early_result'    => null,
		);
		if ( $candidate && $has_confirmation_token ) {
			$empty['early_result'] = $this->blocked_payload( 'legacy_confirmation' );
			return $empty;
		}

		$handoff = $candidate && $is_write_tool && $requires_confirmation && ! $is_dry_run && ! $write_permission_unblocked;
		if ( ! $handoff ) {
			return $empty;
		}

		$store   = new PendingOperationApprovalStore();
		$expired = $store->prune_expired( 25 );
		if ( 0 < $expired ) {
			$this->record_event( 'approval_expiry', 'validated', $tool, $auth, 'expired_cleanup_batch', '', $expired );
		}
		$policy  = $this->policy_fingerprint( $tool, $args );
		$request = is_string( $auth['token_id'] ?? null )
			? $store->exact_operation_request( $tool, $args, (string) ( $auth['client_id'] ?? '' ), $auth['token_id'], 'edit_post', $empty['capability_args'], $policy )
			: null;
		$status  = is_array( $request ) ? (string) $request['status'] : '';
		if ( 'pending' === $status && is_array( $request ) ) {
			$empty['early_result'] = $this->pending_payload( $request['review_summary'], $request['expires_at'] );
		} elseif ( 'declined' === $status ) {
			$this->record_event( 'approval_replay', 'blocked', $tool, $auth, 'declined_replay', $this->correlation_reference( $request ) );
			$empty['early_result'] = $this->blocked_payload( 'declined' );
		} elseif ( 'stale' === $status ) {
			$this->record_event( 'approval_stale', 'blocked', $tool, $auth, 'target_changed', $this->correlation_reference( $request ) );
			$empty['early_result'] = $this->blocked_payload( $status );
		}

		$validated = in_array( $status, array( 'approved', 'consumed' ), true );
		$alias     = $validated && is_string( $auth['token_id'] ?? null )
			? $store->execution_alias( $tool, $args, (string) ( $auth['client_id'] ?? '' ), $auth['token_id'], 'edit_post', $empty['capability_args'], $policy )
			: null;
		if ( $validated && null === $alias ) {
			$empty['early_result'] = $this->blocked_payload( 'unavailable' );
		}
		if ( 'consumed' === $status ) {
			$this->record_event( 'approval_replay', 'validated', $tool, $auth, 'consumed_replay', $this->correlation_reference( $request ) );
		}

		$empty['handoff']   = true;
		$empty['store']     = $store;
		$empty['policy']    = $policy;
		$empty['request']   = $request;
		$empty['status']    = $status;
		$empty['validated'] = $validated;
		$empty['alias']     = $alias;
		return $empty;
	}

	/**
	 * Create a pending row only from a supported, exact server-authored diff.
	 *
	 * @param string                        $tool Tool ID.
	 * @param array<string,mixed>           $args Exact request arguments.
	 * @param array<string,mixed>           $preview_args Preview arguments.
	 * @param array<string,mixed>           $preview Server-authored dry-run result.
	 * @param array<string,mixed>           $auth OAuth context.
	 * @param PendingOperationApprovalStore $store Approval store.
	 * @param string                        $policy_fingerprint Current policy digest.
	 * @param int[]                         $capability_args Current capability arguments.
	 * @phpstan-param list<int>             $capability_args Current capability arguments.
	 * @param string|null                   $target_fingerprint Target digest captured before dry-run.
	 * @return array<string,mixed>
	 */
	public function create_pending( string $tool, array $args, array $preview_args, array $preview, array $auth, PendingOperationApprovalStore $store, string $policy_fingerprint, array $capability_args, ?string $target_fingerprint ): array {
		if ( null === $target_fingerprint ) {
			$this->record_event( 'approval_stale', 'blocked', $tool, $auth, 'target_unavailable' );
			return $this->blocked_payload( 'unsupported' );
		}
		$summary = $this->summary_from_preview( $tool, $args, $preview );
		if ( null === $summary ) {
			$this->record_event( 'approval_mismatch', 'blocked', $tool, $auth, 'summary_unsupported' );
			return $this->blocked_payload( 'unsupported' );
		}

		$created = $store->create(
			$tool,
			$args,
			$summary,
			(string) ( $auth['client_id'] ?? '' ),
			'edit_post',
			$capability_args,
			(string) ( $auth['token_id'] ?? '' ),
			$policy_fingerprint,
			$target_fingerprint
		);
		if ( is_wp_error( $created ) ) {
			if ( 'approval_target_changed' === $created->get_error_code() ) {
				$this->record_event( 'approval_stale', 'blocked', $tool, $auth, 'target_changed' );
				return $this->blocked_payload( 'stale' );
			}
			$existing = $store->exact_operation_request( $tool, $args, (string) ( $auth['client_id'] ?? '' ), (string) ( $auth['token_id'] ?? '' ), 'edit_post', $capability_args, $policy_fingerprint );
			if ( is_array( $existing ) && 'pending' === $existing['status'] ) {
				return $this->pending_payload( $existing['review_summary'], $existing['expires_at'] );
			}
			return $this->blocked_payload( 'unavailable' );
		}

		$request = $store->find_pending_for_current_actor( $created );
		if ( ! is_array( $request ) ) {
			return $this->blocked_payload( 'unavailable' );
		}
		$this->record_event( 'approval_pending', 'issued', $tool, $auth, 'human_review_requested', $this->correlation_reference( $request ) );

		return $this->pending_payload( $request['review_summary'], $request['expires_at'] );
	}

	/**
	 * Consume the one-use decision and emit a redacted authorization audit event.
	 *
	 * @param PendingOperationApprovalStore $store Approval store.
	 * @param string                        $tool Ability identifier.
	 * @param array                         $args Normalized operation arguments.
	 * @param array                         $auth OAuth context.
	 * @param array                         $capability_args Current capability arguments.
	 * @param string                        $policy Current policy fingerprint.
	 * @param array|null                    $request Bound pending/approval request.
	 * @phpstan-param list<int> $capability_args Current capability arguments.
	 * @phpstan-param array<string,mixed> $args Normalized operation arguments.
	 * @phpstan-param array<string,mixed> $auth OAuth context.
	 * @phpstan-param array<string,mixed>|null $request Bound pending/approval request.
	 */
	public function consume( PendingOperationApprovalStore $store, string $tool, array $args, array $auth, array $capability_args, string $policy, ?array $request ): bool {
		$consumed = is_string( $auth['token_id'] ?? null ) && $store->consume_approved_operation( $tool, $args, (string) ( $auth['client_id'] ?? '' ), $auth['token_id'], 'edit_post', $capability_args, $policy );
		$this->record_event( 'approval_consumed', $consumed ? 'validated' : 'blocked', $tool, $auth, $consumed ? 'one_use_claimed' : 'consume_rejected', $this->correlation_reference( $request ) );
		return $consumed;
	}

	/**
	 * Record an ability result after execution without operation arguments.
	 *
	 * @param string     $tool Ability identifier.
	 * @param array      $auth OAuth context.
	 * @param array      $result Tool result.
	 * @param array|null $request Bound pending/approval request.
	 * @phpstan-param array<string,mixed> $auth OAuth context.
	 * @phpstan-param array<string,mixed> $result Tool result.
	 * @phpstan-param array<string,mixed>|null $request Bound pending/approval request.
	 */
	public function record_execution_result( string $tool, array $auth, array $result, ?array $request ): void {
		$partial = 'partial_write' === ( $result['error'] ?? '' );
		$this->record_event( 'approval_execution', isset( $result['error'] ) && ! $partial ? 'blocked' : 'validated', $tool, $auth, isset( $result['error'] ) ? sanitize_key( (string) $result['error'] ) : 'ability_returned', $this->correlation_reference( $request ) );
	}

	/**
	 * Record a bounded activity event with no operation arguments or credentials.
	 *
	 * @param string $event Audit event name.
	 * @param string $status Bounded event status.
	 * @param string $tool Ability identifier.
	 * @param array  $auth OAuth context.
	 * @param string $result_class Sanitized event result class.
	 * @param string $reference Opaque approval correlation value.
	 * @param int    $expired_count Number of expired rows purged.
	 * @phpstan-param array<string,mixed> $auth OAuth context.
	 */
	public function record_event( string $event, string $status, string $tool, array $auth, string $result_class, string $reference = '', int $expired_count = 0 ): void {
		$metadata = array(
			'method'              => 'tools/call',
			'tool'                => $tool,
			'status'              => $status,
			'result_class'        => $result_class,
			'confirmation_policy' => 'wordpress_human_approval',
			'approval_ref'        => $reference,
		);
		if ( 0 < $expired_count ) {
			$metadata['expired_count'] = min( 25, $expired_count );
		}
		( $this->timeline_recorder )( $event, $metadata, $auth );
	}

	/**
	 * Return a shortened keyed operation fingerprint for joining sanitized audit events.
	 *
	 * @param array|null $request Bound pending/approval request.
	 * @phpstan-param array<string,mixed>|null $request Bound pending/approval request.
	 */
	private function correlation_reference( ?array $request ): string {
		return is_array( $request ) ? PendingOperationApprovalStore::audit_reference( $request ) : '';
	}

	/**
	 * Return the approval-required preview result for a dry-run App call.
	 *
	 * @param array<string,mixed> $preview Dry-run preview.
	 * @return array<string,mixed>
	 */
	public function preview_payload( array $preview ): array {
		$preview['approval_required'] = true;
		$preview['status']            = 'preview';
		return $preview;
	}

	/**
	 * Return a safe blocked result without a reusable credential.
	 *
	 * @param string $reason Bounded failure reason.
	 * @return array<string,mixed>
	 */
	public function blocked_payload( string $reason ): array {
		$messages = array(
			'declined'            => 'This exact operation was declined and remains blocked until its approval expires. Change the requested operation or wait for expiry before requesting a new decision.',
			'stale'               => 'The target changed after review. Refresh the preview and obtain a new WordPress approval.',
			'legacy_confirmation' => 'This negotiated App write cannot use an MCP confirmation token. Request and complete its WordPress approval instead.',
			'unsupported'         => 'WordPress cannot provide a complete safe approval summary for these requested changes. No operation was run.',
			'unavailable'         => 'WordPress could not safely load or store this approval. No operation was run.',
		);
		return array(
			'status'   => 'blocked',
			'error'    => 'approval_' . sanitize_key( $reason ),
			'message'  => $messages[ $reason ] ?? $messages['unavailable'],
			'no_write' => true,
		);
	}

	/**
	 * Build the App and text-only view of a pending human decision.
	 *
	 * @param array<string,mixed> $summary Bounded review summary.
	 * @param string              $expires_at UTC expiration timestamp.
	 * @return array<string,mixed>
	 */
	private function pending_payload( array $summary, string $expires_at ): array {
		$post = get_post( (int) $summary['target_id'] );
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'read_post', (int) $post->ID ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $this->blocked_payload( 'unavailable' );
		}
		$queue_url         = admin_url( 'options-general.php?page=' . McpApprovalQueue::PAGE_SLUG );
		$expires_timestamp = strtotime( $expires_at . ' UTC' );
		return array(
			'schema'            => McpAppsPostUpdateResult::SCHEMA,
			'outcome'           => 'approval_pending',
			'status'            => 'approval_pending',
			'approval_required' => true,
			'risk_level'        => $summary['risk_level'],
			'risk_categories'   => $summary['risk_categories'],
			'approval'          => array(
				'expires_at' => gmdate( 'Y-m-d\\TH:i:s\\Z', false === $expires_timestamp ? 0 : $expires_timestamp ),
				'queue'      => esc_url_raw( $queue_url ),
				'decision'   => 'wordpress_admin',
			),
			'approval_target'   => sanitize_text_field( (string) $summary['target'] ),
			'content'           => array(
				'title'          => sanitize_text_field( (string) $post->post_title ),
				'type'           => sanitize_key( $post->post_type ),
				'status'         => sanitize_key( $post->post_status ),
				'completed_at'   => '',
				'revision'       => '',
				'target_id'      => (int) $post->ID,
				'target_version' => $summary['target_version'],
			),
			'changes'           => $summary['changes'],
			'links'             => array(
				'view'           => '',
				'edit'           => '',
				'compare'        => '',
				'undo'           => '',
				'approval_queue' => $queue_url,
			),
			'undo'              => array(
				'available'     => false,
				'safe_snapshot' => false,
				'post_id'       => 0,
				'revision_id'   => 0,
				'reason'        => 'approval_pending',
			),
		);
	}

	/**
	 * Project only the exact normalized server diff into an encrypted review summary.
	 *
	 * @param string              $tool Tool ID.
	 * @param array<string,mixed> $args Exact normalized arguments.
	 * @param array<string,mixed> $preview Server-authored dry-run result.
	 * @return array<string,mixed>|null
	 */
	private function summary_from_preview( string $tool, array $args, array $preview ): ?array {
		$post_id = absint( $args['id'] ?? 0 );
		$post    = get_post( $post_id );
		$diff    = is_array( $preview['diff'] ?? null ) ? $preview['diff'] : array();
		$fields  = is_array( $diff['fields'] ?? null ) ? $diff['fields'] : array();
		$version = is_string( $args['expected_modified_gmt'] ?? null ) ? $args['expected_modified_gmt'] : '';
		if ( array() !== array_intersect( array( 'meta_title', 'meta_description', 'focus_keywords', 'workflow_session_id' ), array_keys( $args ) ) ) {
			return null;
		}
		if ( 'content_workflow.update_post' !== $tool || ! $post instanceof \WP_Post || (int) ( $preview['target']['id'] ?? 0 ) !== $post_id || ! current_user_can( 'edit_post', $post_id ) || '' === $version || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $version ) || true !== ( $preview['dry_run'] ?? null ) || ! isset( $diff['unsupported'] ) || array() !== $diff['unsupported'] || ! array_is_list( $fields ) || 0 === count( $fields ) || 20 < count( $fields ) ) {
			return null;
		}

		$changes         = array();
		$risk_categories = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! is_string( $field['field'] ?? null ) || true !== ( $field['changed'] ?? null ) ) {
				return null;
			}
			$before = $this->diff_value( $field['before'] ?? null );
			$after  = $this->diff_value( $field['after'] ?? null );
			if ( null === $before || null === $after ) {
				return null;
			}
			$label           = sanitize_text_field( str_replace( array( '.', '_' ), ' ', $field['field'] ) );
			$changes[]       = array(
				'label'  => '' === $label ? 'Content field' : $label,
				'before' => $before,
				'after'  => $after,
			);
			$risk_categories = array_merge( $risk_categories, $this->risk_categories( $field['field'], $field['after'] ?? null ) );
		}

		$risk_categories = array_values( array_unique( $risk_categories ) );
		$risk_level      = in_array( 'destructive', $risk_categories, true ) ? 'destructive' : ( in_array( 'publication', $risk_categories, true ) ? 'publish' : 'update' );
		$title           = sanitize_text_field( (string) $post->post_title );
		$type_object     = get_post_type_object( $post->post_type );
		$type_label      = is_object( $type_object ) && isset( $type_object->labels->singular_name )
			? sanitize_text_field( (string) $type_object->labels->singular_name )
			: sanitize_key( $post->post_type );
		return array(
			'operation'       => 'Update WordPress content',
			'target'          => sprintf( '%1$s #%2$d: %3$s', $type_label, $post_id, $title ),
			'target_id'       => $post_id,
			'target_version'  => $version,
			'risk_level'      => $risk_level,
			'risk_categories' => $risk_categories,
			'changes'         => $changes,
		);
	}

	/**
	 * Convert one bounded server diff entry to plain text.
	 *
	 * @param mixed $entry Server-authored diff entry.
	 */
	private function diff_value( mixed $entry ): ?string {
		if ( ! is_array( $entry ) || true !== ( $entry['available'] ?? null ) || ! array_key_exists( 'value', $entry ) ) {
			return null;
		}
		$value = $entry['value'];
		if ( is_array( $value ) && is_string( $value['summary'] ?? null ) ) {
			$text = $value['summary'];
			if ( is_int( $value['length'] ?? null ) ) {
				$text .= sprintf( ' (%d bytes; bounded summary)', $value['length'] );
			}
		} elseif ( is_scalar( $value ) || null === $value ) {
			$text = is_bool( $value ) ? ( $value ? 'Yes' : 'No' ) : ( null === $value ? 'Empty' : (string) $value );
		} else {
			return null;
		}
		$text = sanitize_textarea_field( $text );
		return '' === $text ? 'Empty' : substr( $text, 0, 2048 );
	}

	/**
	 * Classify only fields actually present in the server-authored diff.
	 *
	 * @param string $field Changed field name.
	 * @param mixed  $after Proposed field value.
	 * @return list<string>
	 */
	private function risk_categories( string $field, mixed $after ): array {
		$category = 'content';
		if ( in_array( $field, array( 'status', 'post_status' ), true ) ) {
			$value    = is_array( $after ) ? sanitize_key( (string) ( $after['value'] ?? '' ) ) : '';
			$category = 'trash' === $value ? 'destructive' : ( in_array( $value, array( 'publish', 'future' ), true ) ? 'publication' : 'content' );
		} elseif ( str_contains( $field, 'author' ) ) {
			$category = 'authorship';
		} elseif ( str_contains( $field, 'term' ) || str_contains( $field, 'taxonomy' ) ) {
			$category = 'taxonomy';
		} elseif ( str_contains( $field, 'media' ) || str_contains( $field, 'image' ) ) {
			$category = 'media';
		}
		return array( $category );
	}

	/**
	 * Bind approval to the current write policy and current target capability.
	 *
	 * @param string              $tool Tool identifier.
	 * @param array<string,mixed> $args Arguments.
	 */
	private function policy_fingerprint( string $tool, array $args ): string {
		$groups = $this->safety->confirmation_groups();
		$scopes = $this->registry->required_scopes( $tool );
		sort( $groups );
		sort( $scopes );
		$policy = array(
			'version'             => 'wordpress-human-approval.v1',
			'tool'                => $tool,
			'risk_level'          => AbilityExecutionGateway::tool_risk_level( $tool, $args ),
			'confirmation_groups' => array_values( $groups ),
			'required_scopes'     => array_values( $scopes ),
			'enabled_abilities'   => $this->registry->enabled_ids(),
			'role_policy_enabled' => RoleAbilitiesPolicy::is_editing_enabled(),
			'role_policies'       => get_option( RoleAbilitiesPolicy::OPTION_ROLE_ABILITIES, array() ),
			'capability'          => 'edit_post',
		);
		return hash( 'sha256', (string) wp_json_encode( $policy ) );
	}
}
