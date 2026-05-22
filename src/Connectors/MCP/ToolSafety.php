<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Centralizes MCP dry-run and confirmation safety controls.
 */
final class ToolSafety {

	public const OPTION_CONFIRMATION_GROUPS = 'aculect_ai_companion_confirmation_groups';

	private const CONFIRMATION_TTL = 600;
	private const CONTROL_KEYS     = array( 'dry_run', 'confirmation_token' );

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
		$status = isset( $args['status'] ) && is_scalar( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';

		return match ( $tool ) {
			'content.create_item' => match ( $status ) {
				'trash' => 'destructive',
				'publish' => 'publish',
				default => 'draft',
			},
			'content.update_item' => match ( $status ) {
				'trash' => 'destructive',
				'publish' => 'publish',
				default => 'update',
			},
			'comments.create_item' => 'approve' === $status ? 'publish' : 'draft',
			'comments.update_item' => match ( $status ) {
				'trash', 'spam' => 'destructive',
				'approve' => 'publish',
				default => 'update',
			},
			'wp_abilities.run' => 'system',
			'media.upload_item',
			'taxonomy.create_term',
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
	 * Validate and consume a confirmation token.
	 *
	 * @param string               $tool Internal ability ID.
	 * @param array<mixed>         $args Tool arguments.
	 * @param array<string, mixed> $auth OAuth context.
	 */
	public function consume_confirmation_token( string $tool, array $args, array $auth ): bool {
		$token = isset( $args['confirmation_token'] ) && is_scalar( $args['confirmation_token'] )
			? sanitize_text_field( (string) $args['confirmation_token'] )
			: '';

		if ( '' === $token ) {
			return false;
		}

		$key    = $this->transient_key( $token );
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) ) {
			return false;
		}

		$valid = hash_equals( (string) ( $stored['payload_hash'] ?? '' ), $this->payload_hash( $tool, $args, $auth ) )
			&& hash_equals( $tool, (string) ( $stored['tool'] ?? '' ) )
			&& (int) ( $stored['user_id'] ?? 0 ) === (int) ( $auth['user_id'] ?? 0 )
			&& hash_equals( sanitize_text_field( (string) ( $auth['client_id'] ?? '' ) ), (string) ( $stored['client_id'] ?? '' ) )
			&& hash_equals( sanitize_key( (string) ( $auth['provider'] ?? 'mcp' ) ), (string) ( $stored['provider'] ?? '' ) );

		if ( $valid ) {
			delete_transient( $key );
		}

		return $valid;
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
