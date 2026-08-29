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
		$this->prune_consumed_markers();

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
		if ( ! $this->valid_token( $token ) || ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) || ! function_exists( 'get_option' ) || ! function_exists( 'add_option' ) || ! function_exists( 'delete_option' ) ) {
			return null;
		}

		$key   = $this->key( $token );
		$value = get_transient( $key );
		$now   = time();
		if ( ! is_array( $value ) || true === ( $value['consumed'] ?? false ) || (int) ( $value['expires_at'] ?? 0 ) <= $now ) {
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
		$consumed_key = $this->consumed_key( $token );
		$marker       = get_option( $consumed_key, false );
		if ( is_numeric( $marker ) && (int) $marker <= $now ) {
			delete_option( $consumed_key );
		}
		if ( ! add_option( $consumed_key, $now + self::TTL_SECONDS, '', false ) ) {
			return null;
		}
		$value['consumed'] = true;
		$remaining         = max( 1, (int) $value['expires_at'] - $now );
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
	 * Remove approval transients and one-time-consume markers during opt-in uninstall.
	 *
	 * WordPress stores ordinary transients as option rows when an external object
	 * cache is not active. Enumerate those rows first so the normal transient API
	 * can invalidate any matching cache entries, then run a scoped SQL cleanup for
	 * rows that may have been left behind by an interrupted uninstall.
	 */
	public static function uninstall(): void {
		if ( ! function_exists( 'delete_option' ) ) {
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'esc_like' ) || ! isset( $wpdb->options ) ) {
			return;
		}

		$option_prefixes = array(
			'_transient_' . self::PREFIX,
			'_transient_timeout_' . self::PREFIX,
			self::CONSUMED_PREFIX,
		);
		$patterns        = array_map(
			fn ( string $prefix ): string => $wpdb->esc_like( $prefix ) . '%',
			$option_prefixes
		);
		$names           = array();
		try {
			$names = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
					(string) $wpdb->options,
					$patterns[0],
					$patterns[1],
					$patterns[2]
				)
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$names = array();
		}

		if ( is_array( $names ) ) {
			foreach ( $names as $name ) {
				if ( ! is_string( $name ) ) {
					continue;
				}
				if ( str_starts_with( $name, '_transient_timeout_' . self::PREFIX ) ) {
					$transient = substr( $name, strlen( '_transient_timeout_' ) );
					if ( function_exists( 'delete_transient' ) ) {
						delete_transient( $transient );
					} else {
						delete_option( $name );
					}
					continue;
				}
				if ( str_starts_with( $name, '_transient_' . self::PREFIX ) ) {
					$transient = substr( $name, strlen( '_transient_' ) );
					if ( function_exists( 'delete_transient' ) ) {
						delete_transient( $transient );
					} else {
						delete_option( $name );
					}
					continue;
				}
				if ( str_starts_with( $name, self::CONSUMED_PREFIX ) ) {
					delete_option( $name );
				}
			}
		}

		try {
			$wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
					(string) $wpdb->options,
					$patterns[0],
					$patterns[1],
					$patterns[2]
				)
			);
		} catch ( \Throwable $exception ) {
			unset( $exception );
			// Uninstall remains best effort when an optional options table is unavailable.
		}
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

	/**
	 * Remove a bounded batch of expired atomic-consume markers.
	 *
	 * WordPress options do not have a native per-row TTL. The marker stores its
	 * expiry timestamp and issuance opportunistically prunes a small batch so
	 * replay protection remains atomic without accumulating permanent options.
	 */
	private function prune_consumed_markers(): void {
		if ( ! function_exists( 'delete_option' ) ) {
			return;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'esc_like' ) || ! isset( $wpdb->options ) ) {
			return;
		}

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s LIMIT %d',
					(string) $wpdb->options,
					$wpdb->esc_like( self::CONSUMED_PREFIX ) . '%',
					25
				),
				ARRAY_A
			);
		} catch ( \Throwable ) {
			return;
		}
		if ( ! is_array( $rows ) ) {
			return;
		}

		$now = time();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['option_name'] ?? null ) ) {
				continue;
			}
			$expires_at = (int) ( $row['option_value'] ?? 0 );
			if ( $expires_at > 0 && $expires_at <= $now ) {
				delete_option( $row['option_name'] );
			}
		}
	}
}
