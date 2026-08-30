<?php
/**
 * Transaction boundary for workflow definition persistence.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use Throwable;

/**
 * Keeps definition catalog/version writes atomic across the WordPress store.
 */
final class WorkflowDefinitionTransaction {

	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Commands and error codes are fixed by this internal boundary.

	/** Start a storage transaction. */
	public static function begin(): void {
		self::query( 'START TRANSACTION', 'transaction_begin_failed' );
	}

	/** Commit a storage transaction. */
	public static function commit(): void {
		self::query( 'COMMIT', 'transaction_commit_failed' );
	}

	/** Roll back a failed write without masking its original error. */
	public static function rollback(): void {
		global $wpdb;

		try {
			$wpdb->query( 'ROLLBACK' );
		} catch ( Throwable $rollback_exception ) {
			unset( $rollback_exception );
		}
	}

	/**
	 * Execute a required transaction command.
	 *
	 * @param string $query      Fixed transaction command.
	 * @param string $error_code Bounded repository error code.
	 * @throws WorkflowDefinitionRepositoryException When the command fails.
	 */
	private static function query( string $query, string $error_code ): void {
		global $wpdb;

		try {
			$result = $wpdb->query( $query );
		} catch ( Throwable ) {
			$result = false;
		}

		if ( false === $result ) {
			throw new WorkflowDefinitionRepositoryException( $error_code );
		}
	}
}
