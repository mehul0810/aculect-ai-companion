<?php
/**
 * Immutable workflow approval evidence.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Binds an approved decision to one exact plan and gate set.
 */
final readonly class WorkflowApprovalEvidence {

	private const MAX_REFERENCE_BYTES = 128;

	/**
	 * Create approval evidence.
	 *
	 * @param string $plan_hash          Bound plan hash.
	 * @param array  $approved_gate_ids  Exact gate IDs.
	 * @param string $approval_reference Bounded opaque reference.
	 * @param bool   $approved           Approval decision.
	 * @throws WorkflowPlanningException When evidence is malformed or unbounded.
	 * @phpstan-param list<string> $approved_gate_ids
	 */
	public function __construct(
		private string $plan_hash,
		private array $approved_gate_ids,
		private string $approval_reference,
		private bool $approved
	) {
		if ( ! self::valid_hash( $plan_hash ) || '' === $approval_reference || strlen( $approval_reference ) > self::MAX_REFERENCE_BYTES || ! self::valid_ids( $approved_gate_ids ) ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.approval' );
		}
	}

	/**
	 * Return the bounded opaque approval reference.
	 */
	public function reference(): string {
		return $this->approval_reference;
	}

	/**
	 * Whether evidence exactly approves the plan's gates.
	 *
	 * @param WorkflowPlan $plan Bound plan.
	 */
	public function matches( WorkflowPlan $plan ): bool {
		return $this->approved
			&& hash_equals( $plan->hash(), $this->plan_hash )
			&& $plan->approval_gate_step_ids() === $this->approved_gate_ids;
	}

	/**
	 * Validate a SHA-256 hash.
	 *
	 * @param string $hash Candidate hash.
	 */
	private static function valid_hash( string $hash ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/D', $hash );
	}

	/**
	 * Validate a bounded ordered ID list.
	 *
	 * @param array $ids IDs.
	 * @phpstan-param list<string> $ids
	 */
	private static function valid_ids( array $ids ): bool {
		if ( count( $ids ) > 50 ) {
			return false;
		}

		foreach ( $ids as $id ) {
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $id ) ) {
				return false;
			}
		}

		return true;
	}
}
