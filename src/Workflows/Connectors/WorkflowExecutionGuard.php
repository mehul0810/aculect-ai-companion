<?php
/**
 * Server-side execution and cancellation guard for custom workflows.
 *
 * @package Aculect\AICompanion\Workflows\Connectors
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Connectors;

use Aculect\AICompanion\Workflows\Authorization\WorkflowApprovalAuthority;
use Aculect\AICompanion\Workflows\Authorization\WorkflowExecutionAuthorization;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunRecord;
use Aculect\AICompanion\Workflows\Execution\WorkflowRunStoreInterface;
use Aculect\AICompanion\Workflows\Execution\WorkflowStepState;
use Aculect\AICompanion\Workflows\Planning\WorkflowApprovalEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowExecutionEvidence;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use Throwable;
use stdClass;

/**
 * Keeps caller-provided approval and safe-stop claims outside the connector.
 *
 * Approval tokens are consumed only after they match the exact run, plan, and
 * authenticated request. Cancellation is allowed only after the durable store
 * proves that no step still owns an execution lease.
 */
final class WorkflowExecutionGuard {

	public function __construct( private WorkflowApprovalAuthority $approvals ) {
	}
	/**
	 * Resolve a one-time approval token and issue request-local authorization.
	 *
	 * @param mixed               $value Approval object.
	 * @param string              $run_id Run ID bound to the approval.
	 * @param WorkflowPlan        $plan Exact plan.
	 * @param array<string,mixed> $auth Authenticated request context.
	 * @return array{approval:WorkflowApprovalEvidence|null,authorization:WorkflowExecutionAuthorization|null,error:array<string,mixed>|null}
	 */
	public function resolve_approval( mixed $value, string $run_id, WorkflowPlan $plan, array $auth ): array {
		if ( null === $value ) {
			return array(
				'approval'      => null,
				'authorization' => null,
				'error'         => null,
			);
		}
		$value = $this->map( $value );
		$token = null === $value ? null : ( $value['approval_token'] ?? null );
		if ( null === $value || ! is_string( $token ) || '' === $token ) {
			return $this->approval_error( 'Approval must use the one-time approval_token issued by the workflow connector.' );
		}
		$approval = $this->approvals->consume( $token, $run_id, $plan, $auth );
		if ( null === $approval ) {
			return $this->approval_error( 'Approval evidence is invalid for this workflow plan.' );
		}
		try {
			$authorization = WorkflowExecutionAuthorization::issue( $run_id, $plan, $auth );
		} catch ( Throwable ) {
			return $this->approval_error( 'The workflow authorization could not be established.' );
		}

		return array(
			'approval'      => $approval,
			'authorization' => $authorization,
			'error'         => null,
		);
	}

	/**
	 * Verify a running run has reached a durable cancellation boundary.
	 *
	 * @param WorkflowRunRecord         $run Run record.
	 * @param WorkflowPlan              $plan Exact workflow plan.
	 * @param array<string,mixed>       $args Connector arguments.
	 * @param WorkflowRunStoreInterface $runs Durable run store.
	 * @return WorkflowExecutionEvidence|array<string,mixed>|null
	 */
	public function cancellation_evidence( WorkflowRunRecord $run, WorkflowPlan $plan, array $args, WorkflowRunStoreInterface $runs ): WorkflowExecutionEvidence|array|null {
		if ( WorkflowRunState::RUNNING !== $run->state() ) {
			return null;
		}
		if ( true !== ( $args['safe_stop'] ?? false ) ) {
			return $this->error( 'cancel_not_allowed', 'Set safe_stop after the current step reaches a safe boundary.' );
		}
		try {
			foreach ( $runs->steps( $run->run_id() ) as $step ) {
				if ( WorkflowStepState::RUNNING === $step->state() ) {
					return $this->error( 'cancel_not_allowed', 'The current step is still running; cancellation must wait for a durable safe boundary.' );
				}
				if ( WorkflowStepState::FAILED === $step->state() && 'execution_uncertain' === $step->error_code() ) {
					return $this->error( 'cancel_not_allowed', 'The run has an uncertain execution result; reconcile it before cancellation.' );
				}
			}
		} catch ( Throwable ) {
			return $this->error( 'cancel_not_allowed', 'The run is not at a proven safe boundary.' );
		}
		try {
			return new WorkflowExecutionEvidence( $plan->hash(), 'cancelled', 'safe_stop', true );
		} catch ( Throwable ) {
			return $this->error( 'cancel_not_allowed', 'The run is not at a proven safe boundary.' );
		}
	}

	/**
	 * Return a bounded invalid-approval result.
	 *
	 * @param string $message Public-safe failure message.
	 * @return array{approval:null,authorization:null,error:array{status:string,error:string,message:string,bounded:bool}}
	 */
	private function approval_error( string $message ): array {
		return array(
			'approval'      => null,
			'authorization' => null,
			'error'         => $this->error( 'invalid_approval', $message ),
		);
	}

	/**
	 * Convert an object-like value to an associative array.
	 *
	 * @param mixed $value Candidate map.
	 * @return array<string,mixed>|null
	 */
	private function map( mixed $value ): ?array {
		if ( $value instanceof stdClass ) {
			$value = get_object_vars( $value );
		}

		return is_array( $value ) && ! array_is_list( $value ) ? $value : null;
	}

	/**
	 * Build a bounded guard error.
	 *
	 * @param string $code Error code.
	 * @param string $message Public-safe failure message.
	 * @return array{status:string,error:string,message:string,bounded:bool}
	 */
	private function error( string $code, string $message ): array {
		return array(
			'status'  => 'error',
			'error'   => $code,
			'message' => $message,
			'bounded' => true,
		);
	}
}
