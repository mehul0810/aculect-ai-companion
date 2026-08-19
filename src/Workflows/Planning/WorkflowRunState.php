<?php
/**
 * Workflow run lifecycle states.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Closed state vocabulary shared by planning and future durable execution.
 */
enum WorkflowRunState: string {

	case CREATED              = 'created';
	case PREPARED             = 'prepared';
	case WAITING_FOR_INPUT    = 'waiting_for_input';
	case DRY_RUN_READY        = 'dry_run_ready';
	case WAITING_FOR_APPROVAL = 'waiting_for_approval';
	case RUNNING              = 'running';
	case COMPLETED            = 'completed';
	case FAILED               = 'failed';
	case CANCELLED            = 'cancelled';

	/**
	 * Whether no transition may leave this state.
	 */
	public function is_terminal(): bool {
		return in_array( $this, array( self::COMPLETED, self::FAILED, self::CANCELLED ), true );
	}
}
