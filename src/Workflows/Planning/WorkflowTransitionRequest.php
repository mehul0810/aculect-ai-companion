<?php
/**
 * Immutable workflow transition request.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Carries one action and its exact optional evidence.
 */
final readonly class WorkflowTransitionRequest {

	/**
	 * Create a transition request.
	 *
	 * @param WorkflowTransitionAction       $action             Requested action.
	 * @param WorkflowPlan|null              $plan               Candidate or bound plan.
	 * @param WorkflowDryRun|null            $dry_run            Candidate dry-run.
	 * @param WorkflowApprovalEvidence|null  $approval         Approval evidence.
	 * @param WorkflowReadinessEvidence|null $readiness       Readiness evidence.
	 * @param WorkflowExecutionEvidence|null $execution       Completion/failure/cancel evidence.
	 * @param string|null                    $failure_code        Bounded explicit failure code.
	 * @throws WorkflowPlanningException When the request contains malformed bounded evidence.
	 */
	public function __construct(
		private WorkflowTransitionAction $action,
		private ?WorkflowPlan $plan = null,
		private ?WorkflowDryRun $dry_run = null,
		private ?WorkflowApprovalEvidence $approval = null,
		private ?WorkflowReadinessEvidence $readiness = null,
		private ?WorkflowExecutionEvidence $execution = null,
		private ?string $failure_code = null
	) {
		if ( null !== $failure_code && 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $failure_code ) ) {
			throw new WorkflowPlanningException( 'failure_code_invalid', '$.failure_code' );
		}
	}

	public function action(): WorkflowTransitionAction {
		return $this->action;
	}

	public function plan(): ?WorkflowPlan {
		return $this->plan;
	}

	public function dry_run(): ?WorkflowDryRun {
		return $this->dry_run;
	}

	public function approval(): ?WorkflowApprovalEvidence {
		return $this->approval;
	}

	public function readiness(): ?WorkflowReadinessEvidence {
		return $this->readiness;
	}

	public function execution(): ?WorkflowExecutionEvidence {
		return $this->execution;
	}

	public function failure_code(): ?string {
		return $this->failure_code;
	}
}
