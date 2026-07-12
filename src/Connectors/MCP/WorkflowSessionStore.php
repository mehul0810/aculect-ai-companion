<?php
/**
 * Transient-backed MCP workflow session state.
 *
 * @package Aculect\AICompanion\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Stores bounded workflow progress so MCP clients can resume multi-step work.
 */
final class WorkflowSessionStore {

	private const TRANSIENT_PREFIX = 'aculect_ai_companion_workflow_session_';
	private const TTL              = 43200;
	private const STATES           = array(
		'routed',
		'started',
		'prepared',
		'validated',
		'draft_created',
		'updated',
		'seo_applied',
		'needs_review',
		'failed',
		'complete',
	);

	/**
	 * Start a bounded session.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function start( array $args ): array {
		$workflow = $this->key( $args['workflow'] ?? $args['workflow_id'] ?? 'general', 80 );
		$state    = $this->state( $args['state'] ?? 'started' );
		$session  = array(
			'id'           => $this->new_id(),
			'workflow'     => '' === $workflow ? 'general' : $workflow,
			'state'        => $state,
			'created_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'updated_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'user_id'      => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
			'target'       => $this->target( $args ),
			'context'      => $this->context( $args ),
			'events'       => array(
				$this->event( $state, 'Workflow session started.', $args ),
			),
			'next_actions' => $this->next_actions_for_state( $state ),
		);

		$this->write( $session );

		return array(
			'status'           => 'success',
			'workflow_session' => $session,
			'next_actions'     => $session['next_actions'],
		);
	}

	/**
	 * Read a stored session.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function get( array $args ): array {
		$id      = $this->id( $args['workflow_session_id'] ?? $args['id'] ?? '' );
		$session = '' === $id ? array() : $this->read_for_current_user( $id );

		if ( array() === $session ) {
			return array(
				'status'  => 'error',
				'error'   => 'workflow_session_not_found',
				'message' => 'No workflow session was found for that ID.',
			);
		}

		return array(
			'status'           => 'success',
			'workflow_session' => $session,
			'next_actions'     => $this->next_actions_for_state( (string) ( $session['state'] ?? 'started' ) ),
		);
	}

	/**
	 * Advance a stored session.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	public function update( array $args ): array {
		$id      = $this->id( $args['workflow_session_id'] ?? $args['id'] ?? '' );
		$session = '' === $id ? array() : $this->read_for_current_user( $id );

		if ( array() === $session ) {
			return array(
				'status'  => 'error',
				'error'   => 'workflow_session_not_found',
				'message' => 'No workflow session was found for that ID.',
			);
		}

		$state                   = $this->state( $args['state'] ?? $session['state'] ?? 'started' );
		$session['state']        = $state;
		$session['updated_at']   = gmdate( 'Y-m-d\TH:i:s\Z' );
		$session['next_actions'] = $this->next_actions_for_state( $state );
		$session['events']       = array_slice(
			array_merge(
				(array) ( $session['events'] ?? array() ),
				array(
					$this->event(
						$state,
						$this->text( $args['message'] ?? 'Workflow session updated.', 240 ),
						$args
					),
				)
			),
			-20
		);

		$this->write( $session );

		return array(
			'status'           => 'success',
			'workflow_session' => $session,
			'next_actions'     => $session['next_actions'],
		);
	}

	/**
	 * Advance a session from an internal workflow response.
	 *
	 * @param string               $id     Session ID.
	 * @param string               $state  New state.
	 * @param string               $tool   Tool name.
	 * @param array<string, mixed> $result Tool result.
	 * @return array<string, mixed>
	 */
	public function advance_from_tool_result( string $id, string $state, string $tool, array $result ): array {
		$id = $this->id( $id );
		if ( '' === $id ) {
			return array();
		}

		return $this->update(
			array(
				'workflow_session_id' => $id,
				'state'               => isset( $result['error'] ) ? 'failed' : $state,
				'message'             => isset( $result['error'] )
					? sprintf( '%s failed: %s', $tool, (string) ( $result['message'] ?? $result['error'] ) )
					: sprintf( '%s completed.', $tool ),
				'tool'                => $tool,
				'post_id'             => $result['post_id'] ?? null,
			)
		);
	}

	/**
	 * Read a session by ID.
	 *
	 * @param string $id Session ID.
	 * @return array<string, mixed>
	 */
	private function read( string $id ): array {
		$stored = get_transient( $this->transient_key( $id ) );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Read a session only when it belongs to the current user.
	 *
	 * @param string $id Session ID.
	 * @return array<string, mixed>
	 */
	private function read_for_current_user( string $id ): array {
		$session = $this->read( $id );
		if ( array() === $session || ! $this->can_access_session( $session ) ) {
			return array();
		}

		return $session;
	}

	/**
	 * Return whether the current user owns a stored session.
	 *
	 * @param array<string, mixed> $session Session state.
	 */
	private function can_access_session( array $session ): bool {
		$owner_id = absint( $session['user_id'] ?? 0 );
		$current  = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		$current  = absint( $current );

		return $owner_id > 0 && $current > 0 && $owner_id === $current;
	}

	/**
	 * Persist a session.
	 *
	 * @param array<string, mixed> $session Session data.
	 */
	private function write( array $session ): void {
		set_transient( $this->transient_key( (string) $session['id'] ), $session, self::TTL );
	}

	/**
	 * Build a transient key.
	 *
	 * @param string $id Session ID.
	 */
	private function transient_key( string $id ): string {
		return self::TRANSIENT_PREFIX . hash( 'sha256', $id );
	}

	/**
	 * Build one event.
	 *
	 * @param string               $state Event state.
	 * @param string               $message Event message.
	 * @param array<string, mixed> $args Source arguments.
	 * @return array<string, mixed>
	 */
	private function event( string $state, string $message, array $args ): array {
		return array(
			'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'state'   => $state,
			'message' => $this->text( $message, 240 ),
			'tool'    => $this->key( $args['tool'] ?? '', 100 ),
			'post_id' => absint( $args['post_id'] ?? 0 ),
		);
	}

	/**
	 * Return next actions for a state.
	 *
	 * @param string $state Workflow state.
	 * @return list<string>
	 */
	private function next_actions_for_state( string $state ): array {
		return match ( $state ) {
			'routed' => array( 'Call the recommended context and workflow tools returned by workflow_route_request.' ),
			'started' => array( 'Call the relevant intelligence context, then call content_workflow_prepare_post or the selected workflow guide.' ),
			'prepared' => array( 'Draft serialized block markup, validate blocks, then call the write workflow with this workflow_session_id.' ),
			'validated' => array( 'Call content_workflow_create_draft or content_workflow_update_post with confirmation if required.' ),
			'draft_created' => array( 'Review the draft edit URL, apply SEO/media/taxonomy follow-ups, then mark the session complete.' ),
			'updated' => array( 'Review changed content and any warnings, then mark the session complete when the user accepts it.' ),
			'seo_applied' => array( 'Review Rank Math fields in WordPress, then mark the session complete if no content edits remain.' ),
			'failed' => array( 'Inspect the failed event, call plugin_incident_report if this is a plugin workflow issue, or retry with corrected arguments.' ),
			'complete' => array( 'No further workflow action is required.' ),
			default => array( 'Continue the workflow with the next available recommended tool.' ),
		};
	}

	/**
	 * Return a sanitized target.
	 *
	 * @param array<string, mixed> $args Source arguments.
	 * @return array<string, mixed>
	 */
	private function target( array $args ): array {
		return array(
			'type'      => $this->key( $args['target_type'] ?? $args['post_type'] ?? '', 60 ),
			'id'        => absint( $args['target_id'] ?? $args['post_id'] ?? $args['existing_post_id'] ?? 0 ),
			'title'     => $this->text( $args['title'] ?? '', 160 ),
			'operation' => $this->key( $args['operation'] ?? '', 80 ),
		);
	}

	/**
	 * Return bounded context.
	 *
	 * @param array<string, mixed> $args Source arguments.
	 * @return array<string, mixed>
	 */
	private function context( array $args ): array {
		return array(
			'brief'        => $this->text( $args['brief'] ?? $args['request'] ?? '', 500 ),
			'provider'     => $this->key( $args['provider'] ?? '', 40 ),
			'content_mode' => $this->key( $args['content_mode'] ?? '', 40 ),
			'intent'       => $this->key( $args['intent'] ?? '', 60 ),
		);
	}

	/**
	 * Generate a session ID.
	 */
	private function new_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'wf_' . str_replace( '-', '', wp_generate_uuid4() );
		}

		return 'wf_' . substr( hash( 'sha256', uniqid( 'aculect_workflow_', true ) ), 0, 32 );
	}

	/**
	 * Sanitize a session ID.
	 *
	 * @param mixed $value Raw value.
	 */
	private function id( mixed $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = preg_replace( '/[^A-Za-z0-9_-]+/', '', $value ) ?? '';

		return substr( $value, 0, 80 );
	}

	/**
	 * Sanitize a key.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum length.
	 */
	private function key( mixed $value, int $limit ): string {
		return substr( sanitize_key( is_scalar( $value ) ? (string) $value : '' ), 0, $limit );
	}

	/**
	 * Sanitize text.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Maximum length.
	 */
	private function text( mixed $value, int $limit ): string {
		return substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, $limit );
	}

	/**
	 * Sanitize state.
	 *
	 * @param mixed $value Raw value.
	 */
	private function state( mixed $value ): string {
		$state = $this->key( $value, 40 );

		return in_array( $state, self::STATES, true ) ? $state : 'started';
	}
}
