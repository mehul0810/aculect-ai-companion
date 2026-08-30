<?php
/**
 * Strict transaction command boundary for workflow persistence.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

/** Converts failed transaction commands into bounded store errors. */
final class WorkflowRunTransaction {

	private bool $active = false;

	public function begin(): void {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new WorkflowRunStoreException( 'transaction_begin_failed' );
		}
		$this->active = true;
	}

	public function commit(): void {
		global $wpdb;
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			throw new WorkflowRunStoreException( 'transaction_commit_failed' );
		}
		$this->active = false;
	}

	public function rollback(): void {
		global $wpdb;
		if ( ! $this->active ) {
			return;
		}

		$wpdb->query( 'ROLLBACK' );
		$this->active = false;
	}
}
