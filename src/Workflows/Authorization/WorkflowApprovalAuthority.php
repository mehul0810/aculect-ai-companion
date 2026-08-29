<?php
/**
 * Server-issued workflow approval authority.
 *
 * @package Aculect\AICompanion\Workflows\Authorization
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Authorization;

use Aculect\AICompanion\Workflows\Planning\WorkflowApprovalEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;

/**
 * Mints and consumes one-time approval capabilities bound to a workflow run.
 *
 * Approval JSON is intentionally not trusted. The only accepted value is a
 * token previously issued by this authority and stored in a short-lived
 * WordPress transient with actor, client, provider, plan, and gate bindings.
 */
final class WorkflowApprovalAuthority {

	private const PREFIX          = 'aculect_wf_approval_';
	private const TOKEN_PREFIX    = 'wfa_';
	private const CONSUMED_PREFIX = 'aculect_wf_approval_consumed_';
	private const TTL_SECONDS     = 600;

	/**
	 * Issue an opaque one-time approval token.
	 *
	 * @param string               $run_id Durable run ID.
	 * @param WorkflowPlan         $plan   Exact workflow plan.
	 * @param array<string, mixed> $auth   Authenticated request context.
	 */
	public function issue( string $run_id, WorkflowPlan $plan, array $auth ): string {
		if ( '' === $run_id || (int) ( $auth['user_id'] ?? 0 ) < 1 || ! function_exists( 'set_transient' ) ) {
			return '';
		}

		try {
			$token = self::TOKEN_PREFIX . bin2hex( random_bytes( 24 ) );
		} catch ( \Throwable ) {
			return '';
		}
		$now   = time();
		$value = array(
			'binding'    => $this->binding_hash( $run_id, $plan, $auth ),
			'gates'      => $plan->approval_gate_step_ids(),
			'issued_at'  => $now,
			'expires_at' => $now + self::TTL_SECONDS,
			'consumed'   => false,
		);

		return set_transient( $this->key( $token ), $value, self::TTL_SECONDS ) ? $token : '';
	}

	/**
	 * Consume a token and return immutable approval evidence.
	 *
	 * @param string               $token  Opaque server-issued token.
	 * @param string               $run_id Durable run ID.
	 * @param WorkflowPlan         $plan   Exact workflow plan.
	 * @param array<string, mixed> $auth   Authenticated request context.
	 */
	public function consume( string $token, string $run_id, WorkflowPlan $plan, array $auth ): ?WorkflowApprovalEvidence {
		if ( ! $this->valid_token( $token ) || ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) || ! function_exists( 'add_option' ) || ! function_exists( 'delete_option' ) ) {
			return null;
		}

		$key   = $this->key( $token );
		$value = get_transient( $key );
		if ( ! is_array( $value ) || true === ( $value['consumed'] ?? false ) || (int) ( $value['expires_at'] ?? 0 ) <= time() ) {
			if ( ! is_array( $value ) && function_exists( 'delete_option' ) ) {
				delete_option( $this->consumed_key( $token ) );
			}
			return null;
		}
		if ( ! is_string( $value['binding'] ?? null ) || ! hash_equals( $value['binding'], $this->binding_hash( $run_id, $plan, $auth ) ) ) {
			return null;
		}
		$stored_gates = is_array( $value['gates'] ?? null ) ? array_values( array_map( 'strval', $value['gates'] ) ) : array();
		if ( $stored_gates !== $plan->approval_gate_step_ids() ) {
			return null;
		}

		// add_option is an atomic insert in WordPress. Keeping this marker while
		// the transient is live closes the concurrent double-consume race that a
		// get-then-set transient update would otherwise leave open.
		if ( function_exists( 'add_option' ) && ! add_option( $this->consumed_key( $token ), time(), '', false ) ) {
			return null;
		}
		$value['consumed'] = true;
		$remaining         = max( 1, (int) $value['expires_at'] - time() );
		if ( ! set_transient( $key, $value, $remaining ) ) {
			if ( function_exists( 'delete_option' ) ) {
				delete_option( $this->consumed_key( $token ) );
			}
			return null;
		}

		try {
			return new WorkflowApprovalEvidence( $plan->hash(), $stored_gates, $token, true );
		} catch ( \Throwable ) {
			return null;
		}
	}

	/** Return the public lifetime clients may display for a new token. */
	public function ttl(): int {
		return self::TTL_SECONDS;
	}

	/**
	 * Build the transient key without storing the raw token in the key.
	 *
	 * @param string $token Opaque approval token.
	 */
	private function key( string $token ): string {
		return self::PREFIX . hash( 'sha256', $token );
	}

	/**
	 * Build the atomic one-time-consume marker key.
	 *
	 * @param string $token Opaque approval token.
	 */
	private function consumed_key( string $token ): string {
		return self::CONSUMED_PREFIX . hash( 'sha256', $token );
	}

	/**
	 * Bind the token to identity and exact plan metadata without retaining input.
	 *
	 * @param string               $run_id Run ID.
	 * @param WorkflowPlan         $plan   Exact plan.
	 * @param array<string, mixed> $auth   Auth context.
	 */
	private function binding_hash( string $run_id, WorkflowPlan $plan, array $auth ): string {
		$value = array(
			'run_id'    => $run_id,
			'plan_hash' => $plan->hash(),
			'user_id'   => (int) ( $auth['user_id'] ?? 0 ),
			'client_id' => $this->identity_hash( $auth['client_id'] ?? '' ),
			'provider'  => $this->identity_hash( $auth['provider'] ?? 'mcp' ),
		);

		return hash( 'sha256', (string) wp_json_encode( $value ) );
	}

	/**
	 * Hash identity metadata instead of persisting raw caller values.
	 *
	 * @param mixed $value Candidate identity value.
	 */
	private function identity_hash( mixed $value ): string {
		return hash( 'sha256', is_scalar( $value ) ? substr( trim( (string) $value ), 0, 128 ) : '' );
	}

	private function valid_token( string $token ): bool {
		return 1 === preg_match( '/^wfa_[a-f0-9]{48}$/D', $token );
	}
}
