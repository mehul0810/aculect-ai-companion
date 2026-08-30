<?php
/**
 * Server-issued approval confirmations for custom workflows.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanningException;
use Throwable;

/**
 * Issues short-lived, single-use confirmations bound to one workflow run.
 *
 * Caller-provided approval fields are descriptive only. The connector accepts
 * them after this store proves that the opaque reference was issued by the
 * server for the same actor, run, plan, and ordered gate set.
 */
final class WorkflowApprovalTokenStore {

	private const TOKEN_BYTES   = 32;
	private const TOKEN_TTL     = 600;
	private const TOKEN_PATTERN = '/^[a-f0-9]{64}$/D';

	/**
	 * Return the confirmation lifetime in seconds.
	 */
	public function ttl(): int {
		return self::TOKEN_TTL;
	}

	/**
	 * Issue one opaque confirmation reference for an exact workflow decision.
	 *
	 * @param string               $run_id Durable run identifier.
	 * @param WorkflowPlan         $plan   Exact pinned plan.
	 * @param array<string, mixed> $auth   Authenticated actor context.
	 * @throws WorkflowPlanningException When secure token generation fails.
	 */
	public function issue( string $run_id, WorkflowPlan $plan, array $auth ): string {
		try {
			$token = bin2hex( random_bytes( self::TOKEN_BYTES ) );
		} catch ( Throwable ) {
			throw new WorkflowPlanningException( 'approval_unavailable', '$.approval' );
		}

		$stored = set_transient(
			$this->key( $token ),
			array(
				'run_id'            => $run_id,
				'plan_hash'         => $plan->hash(),
				'approval_gate_ids' => $plan->approval_gate_step_ids(),
				'user_id'           => (int) ( $auth['user_id'] ?? 0 ),
				'client_id'         => sanitize_text_field( (string) ( $auth['client_id'] ?? '' ) ),
				'provider'          => sanitize_key( (string) ( $auth['provider'] ?? 'mcp' ) ),
				'consumed'          => false,
			),
			self::TOKEN_TTL
		);
		if ( ! $stored ) {
			throw new WorkflowPlanningException( 'approval_unavailable', '$.approval' );
		}

		return $token;
	}

	/**
	 * Consume a reference only when its server record matches the exact call.
	 *
	 * @param string               $token  Opaque server-issued reference.
	 * @param string               $run_id Durable run identifier.
	 * @param WorkflowPlan         $plan   Exact pinned plan.
	 * @param array<string, mixed> $auth   Authenticated actor context.
	 */
	public function consume( string $token, string $run_id, WorkflowPlan $plan, array $auth ): bool {
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			return false;
		}

		$stored = get_transient( $this->key( $token ) );
		if ( ! is_array( $stored ) || ! empty( $stored['consumed'] ) ) {
			return false;
		}

		$stored_gates = isset( $stored['approval_gate_ids'] ) && is_array( $stored['approval_gate_ids'] )
			? array_values( array_map( 'strval', $stored['approval_gate_ids'] ) )
			: array();
		if (
			(string) ( $stored['run_id'] ?? '' ) !== $run_id
			|| ! hash_equals( (string) ( $stored['plan_hash'] ?? '' ), $plan->hash() )
			|| $stored_gates !== $plan->approval_gate_step_ids()
			|| (int) ( $stored['user_id'] ?? 0 ) !== (int) ( $auth['user_id'] ?? 0 )
			|| (string) ( $stored['client_id'] ?? '' ) !== sanitize_text_field( (string) ( $auth['client_id'] ?? '' ) )
			|| (string) ( $stored['provider'] ?? '' ) !== sanitize_key( (string) ( $auth['provider'] ?? 'mcp' ) )
		) {
			return false;
		}

		// Keep a consumed marker for the short replay window. A second request
		// therefore fails closed even when the underlying transient is still live.
		$stored['consumed'] = true;
		if ( ! set_transient( $this->key( $token ), $stored, self::TOKEN_TTL ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Build a hash-only transient key; raw references are never used as keys.
	 *
	 * @param string $token Opaque reference.
	 */
	private function key( string $token ): string {
		return 'aculect_ai_companion_workflow_approval_' . hash( 'sha256', $token );
	}
}
