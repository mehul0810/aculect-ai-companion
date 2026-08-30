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

	private const TOKEN_BYTES         = 32;
	private const TOKEN_TTL           = 600;
	private const TOKEN_PATTERN       = '/^[a-f0-9]{64}$/D';
	private const TOKEN_OPTION_PREFIX = 'aculect_ai_companion_workflow_approval_';
	private const CLAIM_OPTION_PREFIX = 'aculect_ai_companion_workflow_approval_claim_';
	private const CLAIM_PRUNE_BATCH   = 100;

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
		$now = time();
		$this->prune_expired_claims( $now );

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
				'expires_at'        => $now + self::TOKEN_TTL,
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

		$now    = time();
		$key    = $this->key( $token );
		$stored = get_transient( $key );
		$this->prune_expired_claims( $now );
		if ( ! is_array( $stored ) || ! empty( $stored['consumed'] ) || (int) ( $stored['expires_at'] ?? 0 ) <= $now ) {
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

		// A unique, non-autoloaded options row is the shared single-use
		// authority. The INSERT is one atomic database claim; no read/modify/write
		// flag or process-local lock can let two workers consume the token.
		return $this->claim_consumption( $token, (int) $stored['expires_at'] );
	}

	/**
	 * Build a hash-only transient key; raw references are never used as keys.
	 *
	 * @param string $token Opaque reference.
	 */
	private function key( string $token ): string {
		return self::TOKEN_OPTION_PREFIX . hash( 'sha256', $token );
	}

	/**
	 * Build the unique option used as an atomic single-use claim.
	 *
	 * @param string $token Opaque reference.
	 */
	private function consumed_key( string $token ): string {
		return self::CLAIM_OPTION_PREFIX . hash( 'sha256', $token );
	}

	/**
	 * Claim a token through a shared unique option row.
	 *
	 * @param string $token      Opaque reference.
	 * @param int    $expires_at Absolute marker expiry.
	 */
	private function claim_consumption( string $token, int $expires_at ): bool {
		$consumed_key = $this->consumed_key( $token );
		if ( $this->uses_test_options() ) {
			return add_option( $consumed_key, (string) $expires_at, '', false );
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			return false;
		}

		try {
			if ( $this->uses_sqlite_options( $wpdb ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SQLite unique insert is the single-use approval CAS.
				$added = $wpdb->query(
					$wpdb->prepare(
						"INSERT OR IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
						$consumed_key,
						(string) $expires_at
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- INSERT IGNORE is the single-use approval CAS; Core add_option() can overwrite on a duplicate-key race.
				$added = $wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
						$consumed_key,
						(string) $expires_at
					)
				);
			}
		} catch ( Throwable ) {
			return false;
		}

		$this->invalidate_option_caches( array( $consumed_key ) );

		return 1 === $added;
	}

	/**
	 * Remove a bounded batch of expired claim markers.
	 *
	 * Marker expiry is stored as a timestamp so cleanup can remain bounded and
	 * cannot make a still-valid transient reusable. Cleanup is best effort; a
	 * storage failure never weakens the atomic claim path.
	 *
	 * @param int $now Current timestamp.
	 */
	private function prune_expired_claims( int $now ): void {
		if ( $this->uses_test_options() ) {
			$pruned = 0;
			foreach ( array_keys( $GLOBALS['aculect_ai_companion_test_options'] ) as $option_name ) {
				if ( $pruned >= self::CLAIM_PRUNE_BATCH ) {
					break;
				}
				if ( ! str_starts_with( (string) $option_name, self::CLAIM_OPTION_PREFIX ) ) {
					continue;
				}
				$expires_at = (int) $GLOBALS['aculect_ai_companion_test_options'][ $option_name ];
				if ( 0 < $expires_at && $expires_at < $now ) {
					unset( $GLOBALS['aculect_ai_companion_test_options'][ $option_name ] );
					++$pruned;
				}
			}
			return;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			return;
		}

		$like = $wpdb->esc_like( self::CLAIM_OPTION_PREFIX ) . '%';
		try {
			if ( $this->uses_sqlite_options( $wpdb ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded SQLite cleanup only removes expired, plugin-owned non-autoloaded claim markers.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS INTEGER) > 0 AND CAST(option_value AS INTEGER) < %d LIMIT %d",
						$like,
						$now,
						self::CLAIM_PRUNE_BATCH
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded cleanup only removes expired, plugin-owned non-autoloaded claim markers.
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) > 0 AND CAST(option_value AS UNSIGNED) < %d LIMIT %d",
						$like,
						$now,
						self::CLAIM_PRUNE_BATCH
					)
				);
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
		}

		$this->invalidate_option_caches( array() );
	}

	/**
	 * Check whether the authoritative options backend is SQLite.
	 *
	 * @param object $wpdb WordPress database adapter.
	 */
	private function uses_sqlite_options( object $wpdb ): bool {
		if ( isset( $wpdb->is_mysql ) && false === (bool) $wpdb->is_mysql ) {
			return true;
		}

		return class_exists( 'WP_SQLite_DB', false ) && $wpdb instanceof \WP_SQLite_DB;
	}

	/**
	 * Check whether the lightweight PHPUnit option store is active.
	 */
	private function uses_test_options(): bool {
		return isset( $GLOBALS['aculect_ai_companion_test_options'] ) && is_array( $GLOBALS['aculect_ai_companion_test_options'] );
	}

	/**
	 * Invalidate positive and negative option-cache entries after direct SQL.
	 *
	 * @param array<string> $option_names Option names changed by this store.
	 * @phpstan-param list<string> $option_names
	 */
	private function invalidate_option_caches( array $option_names ): void {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		try {
			foreach ( $option_names as $option_name ) {
				wp_cache_delete( $option_name, 'options' );
			}
			wp_cache_delete( 'notoptions', 'options' );
		} catch ( Throwable $exception ) {
			unset( $exception );
		}
	}
}
