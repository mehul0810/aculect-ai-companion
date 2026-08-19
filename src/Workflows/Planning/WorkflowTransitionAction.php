<?php
/**
 * Workflow transition actions.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Closed action vocabulary for the pure transition guard.
 */
enum WorkflowTransitionAction: string {

	case PREPARE              = 'prepare';
	case RESUME_WITH_INPUT    = 'resume_with_input';
	case BUILD_DRY_RUN        = 'build_dry_run';
	case REQUEST_APPROVAL     = 'request_approval';
	case RESUME_WITH_APPROVAL = 'resume_with_approval';
	case START                = 'start';
	case COMPLETE             = 'complete';
	case FAIL                 = 'fail';
	case CANCEL               = 'cancel';
}
