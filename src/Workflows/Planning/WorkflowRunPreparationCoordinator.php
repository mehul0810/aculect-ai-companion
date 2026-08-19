<?php
/**
 * Pure workflow run-preparation coordinator.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;

/**
 * Composes planning primitives without storage, authorization, or execution.
 */
final class WorkflowRunPreparationCoordinator {

	public function __construct(
		private readonly WorkflowPlanBuilder $plan_builder = new WorkflowPlanBuilder(),
		private readonly WorkflowTransitionGuard $transition_guard = new WorkflowTransitionGuard(),
		private readonly WorkflowPlanReadinessEvaluator $readiness_evaluator = new WorkflowPlanReadinessEvaluator()
	) {
	}

	/**
	 * Prepare one workflow only through deterministic pre-execution boundaries.
	 *
	 * @param WorkflowDefinition           $definition   Validated immutable definition.
	 * @param WorkflowInputContract        $input        Normalized input contract.
	 * @param WorkflowAvailabilitySnapshot $availability Detached requirement availability.
	 */
	public function prepare(
		WorkflowDefinition $definition,
		WorkflowInputContract $input,
		WorkflowAvailabilitySnapshot $availability
	): WorkflowPreparationResult {
		$plan       = $this->plan_builder->build( $definition, $input );
		$transition = $this->transition_guard->transition(
			WorkflowStateSnapshot::created(),
			new WorkflowTransitionRequest( WorkflowTransitionAction::PREPARE, plan: $plan )
		);
		$snapshot   = $transition->snapshot();

		if ( WorkflowRunState::WAITING_FOR_INPUT === $snapshot->state() ) {
			return new WorkflowPreparationResult(
				$plan,
				$snapshot,
				null,
				WorkflowReadinessEvidence::unchecked( $plan )
			);
		}

		$readiness = $this->readiness_evaluator->evaluate( $plan, $availability );
		$dry_run   = WorkflowDryRun::from_plan( $plan );
		$snapshot  = $this->transition_guard->transition(
			$snapshot,
			new WorkflowTransitionRequest( WorkflowTransitionAction::BUILD_DRY_RUN, plan: $plan, dry_run: $dry_run )
		)->snapshot();

		return new WorkflowPreparationResult( $plan, $snapshot, $dry_run, $readiness );
	}
}
