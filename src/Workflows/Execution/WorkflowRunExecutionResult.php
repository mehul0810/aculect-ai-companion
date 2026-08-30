<?php
/**
 * Detached result of one bounded workflow runner tick.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;

/**
 * Returns one persisted run, optional step, and optional adapter result.
 */
final readonly class WorkflowRunExecutionResult {

	/**
	 * Create a runner tick result.
	 *
	 * @param WorkflowRunRecord          $run        Current durable run.
	 * @param WorkflowStepRecord|null    $step       Claimed or completed step.
	 * @param WorkflowAdapterResult|null $adapter_result Adapter result.
	 * @param bool                       $progressed Whether state advanced.
	 */
	public function __construct(
		private WorkflowRunRecord $run,
		private ?WorkflowStepRecord $step,
		private ?WorkflowAdapterResult $adapter_result,
		private bool $progressed
	) {
	}

	public function run(): WorkflowRunRecord {
		return $this->run; }
	public function step(): ?WorkflowStepRecord {
		return $this->step; }
	public function adapter_result(): ?WorkflowAdapterResult {
		return $this->adapter_result; }
	public function progressed(): bool {
		return $this->progressed; }
}
