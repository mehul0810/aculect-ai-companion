<?php
/**
 * Immutable workflow transition result.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Returns the next snapshot and whether state materially changed.
 */
final readonly class WorkflowTransitionResult {

	public function __construct(
		private WorkflowStateSnapshot $snapshot,
		private bool $changed
	) {
	}

	public function snapshot(): WorkflowStateSnapshot {
		return $this->snapshot;
	}

	public function changed(): bool {
		return $this->changed;
	}
}
