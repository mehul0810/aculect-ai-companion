<?php
/**
 * Durable workflow step lifecycle states.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

/** Closed state vocabulary for one workflow step. */
enum WorkflowStepState: string {

	case PENDING   = 'pending';
	case RUNNING   = 'running';
	case COMPLETED = 'completed';
	case FAILED    = 'failed';
	case CANCELLED = 'cancelled';

	/** Whether a step cannot be executed again. */
	public function is_terminal(): bool {
		return in_array( $this, array( self::COMPLETED, self::FAILED, self::CANCELLED ), true );
	}
}
