<?php
/**
 * Workflow approval parsing and server-token projection.
 *
 * @package Aculect\AICompanion\Workflows\Connectors
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Connectors;

use Aculect\AICompanion\Workflows\Execution\WorkflowApprovalTokenStore;
use Aculect\AICompanion\Workflows\Planning\WorkflowApprovalEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Throwable;

/**
 * Keeps approval authorization and its public response metadata cohesive.
 */
final class WorkflowApprovalService {

	public function __construct( private readonly WorkflowApprovalTokenStore $tokens ) {}

	/**
	 * Parse caller evidence and require an exact server token for gated plans.
	 *
	 * @param mixed               $value Approval object.
	 * @param WorkflowPlan        $plan Exact plan.
	 * @param string              $run_id Durable run identifier.
	 * @param array<string,mixed> $auth Authenticated actor context.
	 * @return WorkflowApprovalEvidence|array<string,mixed>|null
	 */
	public function parse( mixed $value, WorkflowPlan $plan, string $run_id, array $auth ): WorkflowApprovalEvidence|array|null {
		if ( null === $value ) {
			return null;
		}
		$value = WorkflowAbilitySupport::map( $value );
		if ( null === $value || ! isset( $value['reference'], $value['approved_gates'], $value['approved'] ) || ! is_string( $value['reference'] ) || ! is_array( $value['approved_gates'] ) || ! is_bool( $value['approved'] ) ) {
			return array(
				'error'   => 'invalid_approval',
				'message' => 'Approval evidence must include reference, approved_gates, and approved.',
			);
		}
		if ( array() !== $plan->approval_gate_step_ids() ) {
			if ( ! $value['approved'] || $value['approved_gates'] !== $plan->approval_gate_step_ids() ) {
				return array(
					'error'   => 'approval_mismatch',
					'message' => 'Approval evidence does not match this workflow plan.',
				);
			}
			if ( ! $this->tokens->consume( $value['reference'], $run_id, $plan, $auth ) ) {
				return array(
					'error'   => 'approval_unverified',
					'message' => 'Approval must be issued by the server for this actor, run, plan, and gate set.',
				);
			}
		}

		try {
			return new WorkflowApprovalEvidence( $plan->hash(), array_values( array_map( 'strval', $value['approved_gates'] ) ), $value['reference'], $value['approved'] );
		} catch ( Throwable ) {
			return array(
				'error'   => 'invalid_approval',
				'message' => 'Approval evidence is invalid for this workflow plan.',
			);
		}
	}

	/**
	 * Add a server-issued token to a gated response.
	 *
	 * @param array<string,mixed> $payload Response payload.
	 * @param string              $run_id Durable run identifier.
	 * @param WorkflowPlan        $plan Exact plan.
	 * @param array<string,mixed> $auth Authenticated actor context.
	 * @return array<string,mixed>
	 */
	public function add_token_payload( array $payload, string $run_id, WorkflowPlan $plan, array $auth ): array {
		if ( array() === $plan->approval_gate_step_ids() ) {
			return $payload;
		}

		try {
			$token = $this->tokens->issue( $run_id, $plan, $auth );
		} catch ( Throwable ) {
			$payload['approval_status'] = 'unavailable';

			return $payload;
		}

		return array_merge(
			$payload,
			array(
				'approval_status'       => 'issued',
				'approval_token'        => $token,
				'approval_expires_in'   => $this->tokens->ttl(),
				'approval_instructions' => 'Review the dry run, then include this server-issued token as approval.reference with approved=true and the exact approval_gate_ids.',
			)
		);
	}
}
