<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Centralizes MCP dry-run and confirmation safety controls.
 */
final class ToolSafety {

	public const OPTION_CONFIRMATION_GROUPS = 'aculect_ai_companion_confirmation_groups';

	private const CONFIRMATION_TTL    = 600;
	private const CONSUMED_RESULT_TTL = 3600;
	private const IDEMPOTENCY_TTL     = 86400;
	private const CONTROL_KEYS        = array( 'dry_run', 'confirmation_token', 'idempotency_key' );

	/**
	 * Return selectable non-read-only ability groups.
	 *
	 * @return list<string>
	 */
	public function available_confirmation_groups(): array {
		$groups = array();

		foreach ( ( new AbilitiesRegistry() )->definitions() as $definition ) {
			if ( (bool) ( $definition['readOnly'] ?? true ) ) {
				continue;
			}

			$group = sanitize_text_field( (string) ( $definition['group'] ?? '' ) );
			if ( '' !== $group ) {
				$groups[] = $group;
			}
		}

		return array_values( array_unique( $groups ) );
	}

	/**
	 * Return ability groups configured to require confirmation for every write.
	 *
	 * @return list<string>
	 */
	public function confirmation_groups(): array {
		$stored = get_option( self::OPTION_CONFIRMATION_GROUPS, array() );
		return is_array( $stored ) ? $this->sanitize_groups( $stored ) : array();
	}

	/**
	 * Persist confirmation-required ability groups.
	 *
	 * @param array<mixed> $groups Raw group names.
	 */
	public function save_confirmation_groups( array $groups ): void {
		update_option( self::OPTION_CONFIRMATION_GROUPS, $this->sanitize_groups( $groups ), false );
	}

	/**
	 * Delete stored safety settings.
	 */
	public static function delete(): void {
		delete_option( self::OPTION_CONFIRMATION_GROUPS );
	}

	/**
	 * Check whether a tool call is a dry run.
	 *
	 * @param array<mixed> $args Tool arguments.
	 */
	public function is_dry_run( array $args ): bool {
		return ! empty( $args['dry_run'] );
	}

	/**
	 * Return confirmation token lifetime in seconds.
	 */
	public function confirmation_ttl(): int {
		return self::CONFIRMATION_TTL;
	}

	/**
	 * Strip safety control arguments before hashing or executing a tool.
	 *
	 * @param array<mixed> $args Tool arguments.
	 * @return array<mixed>
	 */
	public function strip_control_args( array $args ): array {
		foreach ( self::CONTROL_KEYS as $key ) {
			unset( $args[ $key ] );
		}

		return $args;
	}

	/**
	 * Return the risk level for a tool call.
	 *
	 * @param string       $tool Internal ability ID.
	 * @param array<mixed> $args Tool arguments.
	 */
	public function risk_level( string $tool, array $args ): string {
		$status         = isset( $args['status'] ) && is_scalar( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$comment_status = match ( $status ) {
			'pending', 'unapproved', 'unapprove' => 'hold',
			'approved' => 'approve',
			default => $status,
		};

		return match ( $tool ) {
			'content.create_item' => match ( $status ) {
				'trash' => 'destructive',
				'future', 'publish' => 'publish',
				default => 'draft',
			},
			'content_workflow.create_draft' => 'draft',
			'content.update_item' => match ( $status ) {
				'trash' => 'destructive',
				'future', 'publish' => 'publish',
				default => 'update',
			},
			'content_workflow.update_post' => array_key_exists( 'content', $args ) || array_key_exists( 'section_map', $args ) ? 'destructive' : 'update',
			'comments.create_item' => 'approve' === $comment_status ? 'publish' : 'draft',
			'comments.update_item' => match ( $comment_status ) {
				'trash', 'spam' => 'destructive',
				'approve' => 'publish',
				default => 'update',
			},
			'comments.bulk_update' => match ( $comment_status ) {
				'trash', 'spam' => 'destructive',
				'approve' => 'publish',
				default => 'update',
			},
			'media.delete_item' => 'destructive',
			'wp_abilities.run' => 'system',
			'content.update_seo',
			'content_index.refresh_batch',
			'memory.save',
			'seo_workflow.update_rankmath',
			'media.rename_file',
			'media.update_item',
			'media.upload_item',
			'taxonomy.create_term',
			'taxonomy.set_term_image',
			'taxonomy.update_term' => 'update',
			default => 'read',
		};
	}

	/**
	 * Determine whether the tool call requires explicit confirmation.
	 *
	 * @param string       $tool Internal ability ID.
	 * @param array<mixed> $args Tool arguments.
	 */
	public function requires_confirmation( string $tool, array $args ): bool {
		$risk = $this->risk_level( $tool, $args );
		if ( in_array( $risk, array( 'publish', 'destructive', 'system' ), true ) ) {
			return true;
		}

		if ( 'comments.bulk_update' === $tool ) {
			return true;
		}

		$definition = ( new AbilitiesRegistry() )->definitions()[ $tool ] ?? array();
		if ( (bool) ( $definition['readOnly'] ?? true ) ) {
			return false;
		}

		$group = (string) ( $definition['group'] ?? '' );
		return '' !== $group && in_array( $group, $this->confirmation_groups(), true );
	}

	/**
	 * Create a confirmation token bound to the exact call payload.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<mixed>         $args Tool arguments.
	 * @param array<string, mixed> $auth OAuth context.
	 */
	public function issue_confirmation_token( string $tool, array $args, array $auth ): string {
		$token = bin2hex( random_bytes( 16 ) );

		set_transient(
			$this->transient_key( $token ),
			array(
				'payload_hash' => $this->payload_hash( $tool, $args, $auth ),
				'tool'         => $tool,
				'user_id'      => (int) ( $auth['user_id'] ?? 0 ),
				'client_id'    => sanitize_text_field( (string) ( $auth['client_id'] ?? '' ) ),
				'provider'     => sanitize_key( (string) ( $auth['provider'] ?? 'mcp' ) ),
				'expires_at'   => time() + self::CONFIRMATION_TTL,
			),
			self::CONFIRMATION_TTL
		);

		return $token;
	}

	/**
	 * Validate a confirmation token without consuming it.
	 *
	 * Consumption happens in finalize_confirmation_token() only after the
	 * write succeeds, so a lost response plus a client retry replays the
	 * stored result instead of executing the write twice.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<mixed>         $args Tool arguments.
	 * @param array<string, mixed> $auth OAuth context.
	 */
	public function validate_confirmation_token( string $tool, array $args, array $auth ): bool {
		$stored = $this->stored_confirmation( $args );

		return array() !== $stored
			&& empty( $stored['consumed'] )
			&& $this->confirmation_matches( $stored, $tool, $args, $auth );
	}

	/**
	 * Mark a confirmation token consumed and remember the successful result.
	 *
	 * @param string               $tool   Internal ability ID.
	 * @param array<mixed>         $args   Tool arguments.
	 * @param array<string, mixed> $auth   OAuth context.
	 * @param array<string, mixed> $result Successful tool result.
	 */
	public function finalize_confirmation_token( string $tool, array $args, array $auth, array $result ): void {
		$token = $this->confirmation_token_arg( $args );
		if ( '' === $token ) {
			return;
		}

		set_transient(
			$this->transient_key( $token ),
			array(
				'payload_hash' => $this->payload_hash( $tool, $args, $auth ),
				'tool'         => $tool,
				'user_id'      => (int) ( $auth['user_id'] ?? 0 ),
				'client_id'    => sanitize_text_field( (string) ( $auth['client_id'] ?? '' ) ),
				'provider'     => sanitize_key( (string) ( $auth['provider'] ?? 'mcp' ) ),
				'consumed'     => true,
				'result'       => $result,
			),
			self::CONSUMED_RESULT_TTL
		);
	}

	/**
	 * Return the stored result for an already-consumed confirmation token.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<mixed>         $args Tool arguments.
	 * @param array<string, mixed> $auth OAuth context.
	 * @return array<string, mixed>|null
	 */
	public function confirmation_replay( string $tool, array $args, array $auth ): ?array {
		$stored = $this->stored_confirmation( $args );
		if ( array() === $stored || empty( $stored['consumed'] ) || ! $this->confirmation_matches( $stored, $tool, $args, $auth ) ) {
			return null;
		}

		$result             = is_array( $stored['result'] ?? null ) ? $stored['result'] : array( 'status' => 'success' );
		$result['replayed'] = true;

		return $result;
	}

	/**
	 * Return the replayed result for an idempotency key, if one exists.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<mixed>         $args Tool arguments.
	 * @param array<string, mixed> $auth OAuth context.
	 * @return array<string, mixed>|null Stored result, an idempotency_key_reuse
	 *                                   error for payload mismatches, or null.
	 */
	public function idempotent_replay( string $tool, array $args, array $auth ): ?array {
		$key = $this->idempotency_key_arg( $args );
		if ( '' === $key ) {
			return null;
		}

		$stored = get_transient( $this->idempotency_transient_key( $key, $auth ) );
		if ( ! is_array( $stored ) ) {
			return null;
		}

		if ( ! hash_equals( (string) ( $stored['payload_hash'] ?? '' ), $this->payload_hash( $tool, $args, $auth ) ) ) {
			return array(
				'status'  => 'error',
				'error'   => 'idempotency_key_reuse',
				'message' => 'This idempotency_key was already used with different arguments. Use a new key for new work.',
			);
		}

		$result             = is_array( $stored['result'] ?? null ) ? $stored['result'] : array( 'status' => 'success' );
		$result['replayed'] = true;

		return $result;
	}

	/**
	 * Remember a successful write so retries replay instead of re-executing.
	 *
	 * @param string               $tool   Internal ability ID.
	 * @param array<mixed>         $args   Tool arguments.
	 * @param array<string, mixed> $auth   OAuth context.
	 * @param array<string, mixed> $result Successful tool result.
	 */
	public function remember_write_result( string $tool, array $args, array $auth, array $result ): void {
		$this->finalize_confirmation_token( $tool, $args, $auth, $result );

		$key = $this->idempotency_key_arg( $args );
		if ( '' === $key ) {
			return;
		}

		set_transient(
			$this->idempotency_transient_key( $key, $auth ),
			array(
				'payload_hash' => $this->payload_hash( $tool, $args, $auth ),
				'result'       => $result,
			),
			self::IDEMPOTENCY_TTL
		);
	}

	/**
	 * Read the raw confirmation token argument.
	 *
	 * @param array<mixed> $args Tool arguments.
	 */
	private function confirmation_token_arg( array $args ): string {
		return isset( $args['confirmation_token'] ) && is_scalar( $args['confirmation_token'] )
			? sanitize_text_field( (string) $args['confirmation_token'] )
			: '';
	}

	/**
	 * Read the client-supplied idempotency key argument.
	 *
	 * @param array<mixed> $args Tool arguments.
	 */
	private function idempotency_key_arg( array $args ): string {
		$key = isset( $args['idempotency_key'] ) && is_scalar( $args['idempotency_key'] )
			? sanitize_text_field( (string) $args['idempotency_key'] )
			: '';

		return substr( $key, 0, 128 );
	}

	/**
	 * Build the idempotency transient key, bound to the OAuth identity.
	 *
	 * @param string               $key  Client idempotency key.
	 * @param array<string, mixed> $auth OAuth context.
	 */
	private function idempotency_transient_key( string $key, array $auth ): string {
		$identity = (int) ( $auth['user_id'] ?? 0 ) . '|' . sanitize_text_field( (string) ( $auth['client_id'] ?? '' ) );

		return 'aculect_ai_companion_idem_' . hash( 'sha256', $identity . '|' . $key );
	}

	/**
	 * Load the stored confirmation row for the supplied token argument.
	 *
	 * @param array<mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	private function stored_confirmation( array $args ): array {
		$token = $this->confirmation_token_arg( $args );
		if ( '' === $token ) {
			return array();
		}

		$stored = get_transient( $this->transient_key( $token ) );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Check a stored confirmation row against the current call identity.
	 *
	 * @param array<string, mixed> $stored Stored confirmation row.
	 * @param string               $tool   Internal ability ID.
	 * @param array<mixed>         $args   Tool arguments.
	 * @param array<string, mixed> $auth   OAuth context.
	 */
	private function confirmation_matches( array $stored, string $tool, array $args, array $auth ): bool {
		return hash_equals( (string) ( $stored['payload_hash'] ?? '' ), $this->payload_hash( $tool, $args, $auth ) )
			&& hash_equals( $tool, (string) ( $stored['tool'] ?? '' ) )
			&& (int) ( $stored['user_id'] ?? 0 ) === (int) ( $auth['user_id'] ?? 0 )
			&& hash_equals( sanitize_text_field( (string) ( $auth['client_id'] ?? '' ) ), (string) ( $stored['client_id'] ?? '' ) )
			&& hash_equals( sanitize_key( (string) ( $auth['provider'] ?? 'mcp' ) ), (string) ( $stored['provider'] ?? '' ) );
	}

	/**
	 * Return the confirmation payload hash for the call.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<mixed>         $args Tool arguments.
	 * @param array<string, mixed> $auth OAuth context.
	 */
	private function payload_hash( string $tool, array $args, array $auth ): string {
		$payload = array(
			'tool'      => $tool,
			'arguments' => $this->normalize_for_hash( $this->strip_control_args( $args ) ),
			'user_id'   => (int) ( $auth['user_id'] ?? 0 ),
			'client_id' => sanitize_text_field( (string) ( $auth['client_id'] ?? '' ) ),
			'provider'  => sanitize_key( (string) ( $auth['provider'] ?? 'mcp' ) ),
		);

		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * Normalize a value for deterministic hashing.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private function normalize_for_hash( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		ksort( $value );
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->normalize_for_hash( $item );
		}

		return $value;
	}

	/**
	 * Build a transient key from a token without storing the token itself.
	 *
	 * @param string $token Raw confirmation token.
	 */
	private function transient_key( string $token ): string {
		return 'aculect_ai_companion_confirmation_' . hash( 'sha256', $token );
	}

	/**
	 * Sanitize selected confirmation groups.
	 *
	 * @param array<mixed> $groups Raw group names.
	 * @return list<string>
	 */
	private function sanitize_groups( array $groups ): array {
		$available = $this->available_confirmation_groups();
		$sanitized = array();

		foreach ( $groups as $group ) {
			if ( ! is_scalar( $group ) ) {
				continue;
			}

			$group = sanitize_text_field( (string) $group );
			if ( in_array( $group, $available, true ) ) {
				$sanitized[] = $group;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}
}
