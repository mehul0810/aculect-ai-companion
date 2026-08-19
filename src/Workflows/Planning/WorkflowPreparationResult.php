<?php
/**
 * Immutable pure workflow preparation result.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Binds one plan to its pre-execution snapshot, dry run, and readiness evidence.
 */
final readonly class WorkflowPreparationResult {

	/**
	 * Create a verified preparation result.
	 *
	 * @param WorkflowPlan              $plan      Immutable plan.
	 * @param WorkflowStateSnapshot     $snapshot Prepared or waiting-input snapshot.
	 * @param WorkflowDryRun|null       $dry_run   Deterministic dry run for complete input.
	 * @param WorkflowReadinessEvidence $readiness Bound dependency evidence.
	 * @throws WorkflowPlanningException When result evidence is inconsistent.
	 */
	public function __construct(
		private WorkflowPlan $plan,
		private WorkflowStateSnapshot $snapshot,
		private ?WorkflowDryRun $dry_run,
		private WorkflowReadinessEvidence $readiness
	) {
		$snapshot_plan = $snapshot->plan();
		if ( null === $snapshot_plan || ! hash_equals( $plan->hash(), $snapshot_plan->hash() ) ) {
			throw new WorkflowPlanningException( 'evidence_mismatch', '$.snapshot.plan' );
		}
		if ( null !== $dry_run && ! hash_equals( $plan->hash(), $dry_run->plan_hash() ) ) {
			throw new WorkflowPlanningException( 'evidence_mismatch', '$.dry_run.plan_hash' );
		}
		$snapshot_dry_run = $snapshot->dry_run();
		if ( ( null !== $snapshot_dry_run ) !== ( null !== $dry_run ) ) {
			throw new WorkflowPlanningException( 'evidence_mismatch', '$.dry_run' );
		}
		if ( null !== $dry_run && null !== $snapshot_dry_run && ! hash_equals( $snapshot_dry_run->canonical_json(), $dry_run->canonical_json() ) ) {
			throw new WorkflowPlanningException( 'evidence_mismatch', '$.dry_run' );
		}
		if ( null !== $readiness->binding_error_for( $plan ) ) {
			throw new WorkflowPlanningException( 'evidence_mismatch', '$.readiness' );
		}
		if ( WorkflowRunState::WAITING_FOR_INPUT === $snapshot->state() && null !== $dry_run ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.dry_run' );
		}
		if ( WorkflowRunState::DRY_RUN_READY === $snapshot->state() && null === $dry_run ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.dry_run' );
		}
		if ( ! in_array( $snapshot->state(), array( WorkflowRunState::WAITING_FOR_INPUT, WorkflowRunState::DRY_RUN_READY ), true ) ) {
			throw new WorkflowPlanningException( 'invalid_snapshot', '$.state' );
		}
	}

	public function plan(): WorkflowPlan {
		return $this->plan;
	}

	public function snapshot(): WorkflowStateSnapshot {
		return $this->snapshot;
	}

	public function dry_run(): ?WorkflowDryRun {
		return $this->dry_run;
	}

	public function readiness(): WorkflowReadinessEvidence {
		return $this->readiness;
	}
}
