<?php
/**
 * Durable workflow audit store contract.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

/**
 * Stores only bounded workflow execution summaries.
 *
 * @internal This is an internal audit boundary; it never accepts raw payloads.
 */
interface WorkflowAuditStoreInterface {

	/**
	 * Append one validated summary event.
	 *
	 * @param WorkflowAuditRecord $event Validated summary event.
	 */
	public function append( WorkflowAuditRecord $event ): void;

	/**
	 * Return audit events for one run in chronological order.
	 *
	 * @param string $run_id Durable run ID.
	 * @return list<WorkflowAuditRecord>
	 */
	public function for_run( string $run_id ): array;

	/**
	 * Return the newest bounded audit events.
	 *
	 * @param int $limit Maximum number of rows.
	 * @return list<WorkflowAuditRecord>
	 */
	public function recent( int $limit = 25 ): array;
}
