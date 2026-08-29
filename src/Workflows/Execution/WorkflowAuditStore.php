<?php
/**
 * Durable summary-only workflow audit store.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

use Aculect\AICompanion\Workflows\Database\AuditInstaller;
use Throwable;

/**
 * Persists workflow metadata, bounded outcomes, and field-name summaries.
 */
final class WorkflowAuditStore implements WorkflowAuditStoreInterface {

	private const RETENTION_SECONDS = 2592000;
	private const PRUNE_LIMIT       = 100;

	public function append( WorkflowAuditRecord $event ): void {
		$this->ensure_storage();
		$changed_fields = wp_json_encode( $event->changed_fields() );
		if ( ! is_string( $changed_fields ) ) {
			throw new WorkflowRunStoreException( 'audit_encoding_failed' );
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			AuditInstaller::table_name(),
			array(
				'run_id'                  => $event->run_id(),
				'workflow_id'             => $event->workflow_id(),
				'workflow_version'        => $event->workflow_version(),
				'definition_checksum'     => $event->definition_checksum(),
				'event_type'              => $event->event_type(),
				'step_id'                 => $event->step_id(),
				'actor_id'                => $event->actor_id(),
				'outcome_code'            => $event->outcome_code() ?? '',
				'approval_reference_hash' => $event->approval_reference_hash() ?? '',
				'changed_fields'          => $changed_fields,
				'rollback_note'           => $event->rollback_note(),
				'created_at'              => $event->created_at(),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			throw new WorkflowRunStoreException( 'audit_write_failed' );
		}
		$this->prune_retention();
	}

	public function for_run( string $run_id ): array {
		$this->ensure_storage();
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE run_id = %s ORDER BY created_at ASC, id ASC LIMIT %d',
				AuditInstaller::table_name(),
				$run_id,
				self::PRUNE_LIMIT
			),
			ARRAY_A
		);

		return $this->map_rows( is_array( $rows ) ? $rows : array() );
	}

	public function recent( int $limit = 25 ): array {
		$this->ensure_storage();
		$limit = max( 1, min( 100, $limit ) );
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY created_at DESC, id DESC LIMIT %d',
				AuditInstaller::table_name(),
				$limit
			),
			ARRAY_A
		);

		return $this->map_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Map database rows to validated summary records.
	 *
	 * @param list<array<string, mixed>> $rows Database rows.
	 * @return list<WorkflowAuditRecord>
	 * @throws WorkflowRunStoreException When decoded fields violate the audit contract.
	 * @throws Throwable When a mapped record unexpectedly throws during validation.
	 */
	private function map_rows( array $rows ): array {
		$events = array();
		foreach ( $rows as $row ) {
			$changed_fields_json = trim( (string) ( $row['changed_fields'] ?? '[]' ) );
			$changed_fields      = json_decode( $changed_fields_json, true );
			if ( ! str_starts_with( $changed_fields_json, '[' ) || ! is_array( $changed_fields ) || ! array_is_list( $changed_fields ) ) {
				throw new WorkflowRunStoreException( 'audit_row_invalid' );
			}
			try {
				$events[] = new WorkflowAuditRecord(
					(string) $row['run_id'],
					(string) $row['workflow_id'],
					(int) $row['workflow_version'],
					(string) $row['definition_checksum'],
					(string) $row['event_type'],
					(string) ( $row['step_id'] ?? '' ),
					(int) ( $row['actor_id'] ?? 0 ),
					'' === (string) ( $row['outcome_code'] ?? '' ) ? null : (string) $row['outcome_code'],
					$this->approval_hash( $row ),
					$changed_fields,
					(string) ( $row['rollback_note'] ?? '' ),
					(string) $row['created_at']
				);
			} catch ( Throwable $exception ) {
				if ( $exception instanceof WorkflowRunStoreException ) {
					throw $exception;
				}
				throw new WorkflowRunStoreException( 'audit_row_invalid' );
			}
		}

		return $events;
	}

	/**
	 * Normalize the stored approval hash without exposing a raw reference.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return string|null
	 */
	private function approval_hash( array $row ): ?string {
		$approval_hash = (string) ( $row['approval_reference_hash'] ?? '' );

		return '' === $approval_hash ? null : $approval_hash;
	}

	private function ensure_storage(): void {
		if ( ! AuditInstaller::install() ) {
			throw new WorkflowRunStoreException( 'audit_storage_unavailable' );
		}
	}

	/** Remove a bounded batch of audit rows older than the retention window. */
	private function prune_retention(): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_col' ) || ! method_exists( $wpdb, 'query' ) ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_SECONDS );
		try {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE created_at < %s ORDER BY created_at ASC, id ASC LIMIT %d',
					AuditInstaller::table_name(),
					$cutoff,
					self::PRUNE_LIMIT
				)
			);
		} catch ( Throwable ) {
			return;
		}
		if ( ! is_array( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id < 1 ) {
				continue;
			}
			try {
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', AuditInstaller::table_name(), $id ) );
			} catch ( Throwable ) {
				continue;
			}
		}
	}
}
