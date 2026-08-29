<?php
/**
 * No-op workflow audit sink for pure compositions.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

/**
 * Keeps planning/unit compositions free from database side effects.
 */
final class NullWorkflowAuditStore implements WorkflowAuditStoreInterface {

	public function append( WorkflowAuditRecord $event ): void {
		unset( $event );
	}

	/**
	 * Return no events for one run.
	 *
	 * @param string $run_id Durable run ID.
	 * @return list<WorkflowAuditRecord>
	 */
	public function for_run( string $run_id ): array {
		unset( $run_id );
		return array();
	}

	/**
	 * Return no recent events.
	 *
	 * @param int $limit Maximum number of rows.
	 * @return list<WorkflowAuditRecord>
	 */
	public function recent( int $limit = 25 ): array {
		unset( $limit );
		return array();
	}
}
