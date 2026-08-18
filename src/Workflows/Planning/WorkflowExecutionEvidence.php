<?php
/**
 * Immutable workflow execution-boundary evidence.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Carries bounded completion, failure, or safe-cancellation evidence.
 */
final readonly class WorkflowExecutionEvidence {

	private const MAX_CODE_BYTES = 64;

	/**
	 * Create execution evidence.
	 *
	 * @param string $plan_hash     Bound plan hash.
	 * @param string $outcome       completed, failed, or cancelled.
	 * @param string $code          Bounded outcome code.
	 * @param bool   $safe_boundary Whether a running cancellation is fenced at a safe boundary.
	 * @throws WorkflowPlanningException When execution evidence is malformed.
	 */
	public function __construct(
		private string $plan_hash,
		private string $outcome,
		private string $code,
		private bool $safe_boundary = false
	) {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $plan_hash )
			|| ! in_array( $outcome, array( 'completed', 'failed', 'cancelled' ), true )
			|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $code )
			|| strlen( $code ) > self::MAX_CODE_BYTES
		) {
			throw new WorkflowPlanningException( 'invalid_request', '$.execution_evidence' );
		}
	}

	/**
	 * Whether evidence belongs to the exact plan.
	 *
	 * @param WorkflowPlan $plan Bound plan.
	 */
	public function matches( WorkflowPlan $plan ): bool {
		return hash_equals( $plan->hash(), $this->plan_hash );
	}

	/**
	 * Return the outcome.
	 */
	public function outcome(): string {
		return $this->outcome;
	}

	/**
	 * Return the bounded outcome code.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Whether cancellation is externally proven safe.
	 */
	public function safe_boundary(): bool {
		return $this->safe_boundary;
	}
}
