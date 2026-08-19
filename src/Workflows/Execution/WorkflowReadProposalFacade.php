<?php
/**
 * Private single-step read/proposal workflow execution facade.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterRegistry;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Adapters\WorkflowPlanStepBinding;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanReadinessEvaluator;
use stdClass;
use Throwable;

/**
 * Executes only complete, ungated one-step read/proposal plans.
 *
 * @internal This facade has no WordPress hooks, public protocol surface,
 * persistence, retries, claims, or write execution.
 */
final class WorkflowReadProposalFacade {

	public function __construct(
		private readonly WorkflowAdapterRegistry $registry = new WorkflowAdapterRegistry(),
		private readonly WorkflowPlanReadinessEvaluator $readiness_evaluator = new WorkflowPlanReadinessEvaluator()
	) {
	}

	/**
	 * Execute one eligible plan step through the private adapter registry.
	 *
	 * @param WorkflowPlan         $plan      Immutable workflow plan.
	 * @param string               $step_id   Exact plan step ID.
	 * @param array<string, mixed> $arguments Runtime adapter arguments.
	 * @param array<string, mixed> $auth      Authenticated gateway context.
	 */
	public function execute( WorkflowPlan $plan, string $step_id, array $arguments, array $auth ): WorkflowAdapterResult {
		if ( ! $plan->is_input_ready() ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS );
		}

		$identity = $plan->identity();
		$steps    = $identity['steps'] ?? null;
		if ( ! is_array( $steps ) || ! array_is_list( $steps ) || 1 !== count( $steps ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH );
		}

		if (
			array() !== $plan->approval_gate_step_ids()
			|| $plan->requires_validation()
			|| ! $this->has_no_dependencies( $steps[0] )
		) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH );
		}

		$binding = WorkflowPlanStepBinding::from_plan( $plan, $step_id );
		if ( null === $binding ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_STEP_NOT_FOUND );
		}
		if ( ! in_array( $binding->kind(), array( 'read', 'proposal' ), true ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH );
		}

		try {
			$readiness = $this->readiness_evaluator->evaluate( $plan, $this->registry->availability_snapshot() );
			if (
				null !== $readiness->binding_error_for( $plan )
				|| null !== $readiness->validation_error_for( $plan )
				|| null !== $readiness->requirements_error()
			) {
				return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE );
			}

			return $this->registry->execute_read_only( $plan, $step_id, $arguments, $auth );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE );
		}
	}

	/**
	 * Confirm the sole normalized plan step has no prerequisites.
	 *
	 * @param mixed $step Normalized plan step object.
	 */
	private function has_no_dependencies( mixed $step ): bool {
		if ( $step instanceof stdClass ) {
			$step = get_object_vars( $step );
		}

		return is_array( $step )
			&& ! array_is_list( $step )
			&& array_key_exists( 'depends_on', $step )
			&& array() === $step['depends_on'];
	}
}
