<?php
/**
 * Maps durable workflow rows into detached records.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag, Squiz.Commenting.FunctionCommentThrowTag.Missing

namespace Aculect\AICompanion\Workflows\Execution;

use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;
use Throwable;

/**
 * Keeps row validation and encrypted-output handling outside the CAS store.
 */
final class WorkflowRunRecordMapper {

	public function __construct( private WorkflowPayloadVault $vault ) {}

	/**
	 * Convert one validated run row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	public function run( array $row ): WorkflowRunRecord {
		try {
			$state = WorkflowRunState::from( (string) ( $row['state'] ?? '' ) );
		} catch ( \ValueError ) {
			throw new WorkflowRunStoreException( 'stored_run_invalid' );
		}

		$checksum   = (string) ( $row['definition_checksum'] ?? '' );
		$plan_hash  = (string) ( $row['plan_hash'] ?? '' );
		$input_hash = (string) ( $row['input_hash'] ?? '' );
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $checksum ) || ! preg_match( '/^[a-f0-9]{64}$/D', $plan_hash ) || ! preg_match( '/^[a-f0-9]{64}$/D', $input_hash ) ) {
			throw new WorkflowRunStoreException( 'stored_run_invalid' );
		}

		$outcome_code       = (string) ( $row['outcome_code'] ?? '' );
		$waiting_expires_at = isset( $row['waiting_expires_at'] ) ? (string) $row['waiting_expires_at'] : '';
		$approval_hash      = (string) ( $row['approval_reference_hash'] ?? '' );
		if ( '' !== $approval_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', $approval_hash ) ) {
			throw new WorkflowRunStoreException( 'stored_run_invalid' );
		}

		return new WorkflowRunRecord(
			(int) $row['id'],
			(string) $row['run_id'],
			(string) $row['workflow_id'],
			(int) $row['workflow_version'],
			$checksum,
			$plan_hash,
			$input_hash,
			$state,
			(int) $row['state_version'],
			'' === $outcome_code ? null : $outcome_code,
			'' === $waiting_expires_at ? null : $waiting_expires_at,
			'' === $approval_hash ? null : $approval_hash,
			(int) $row['created_by'],
			(int) $row['updated_by'],
			(string) $row['created_at'],
			(string) $row['updated_at']
		);
	}

	/**
	 * Convert one validated step row and decrypt its bounded output.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	public function step( array $row ): WorkflowStepRecord {
		try {
			$state = WorkflowStepState::from( (string) ( $row['state'] ?? '' ) );
		} catch ( \ValueError ) {
			throw new WorkflowRunStoreException( 'stored_step_invalid' );
		}

		$output_json = null;
		$ciphertext  = (string) ( $row['output_ciphertext'] ?? '' );
		if ( '' !== $ciphertext ) {
			try {
				$output_json = $this->vault->open( $ciphertext );
			} catch ( Throwable ) {
				throw new WorkflowRunStoreException( 'stored_output_invalid' );
			}
			if ( ! hash_equals( (string) ( $row['output_hash'] ?? '' ), hash( 'sha256', $output_json ) ) ) { // phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Hash comparison is intentionally expressed as an API call.
				throw new WorkflowRunStoreException( 'stored_output_mismatch' );
			}
		}

		return new WorkflowStepRecord(
			(int) $row['id'],
			(string) $row['run_id'],
			(string) $row['step_id'],
			(int) $row['step_position'],
			(string) $row['adapter_id'],
			(int) $row['adapter_version'],
			(string) $row['ability_id'],
			(string) $row['kind'],
			$state,
			(int) $row['attempt'],
			(int) $row['fence'],
			(string) ( $row['result_code'] ?? '' ),
			'' === (string) ( $row['error_code'] ?? '' ) ? null : (string) $row['error_code'],
			$output_json,
			isset( $row['started_at'] ) && '' !== (string) $row['started_at'] ? (string) $row['started_at'] : null,
			isset( $row['completed_at'] ) && '' !== (string) $row['completed_at'] ? (string) $row['completed_at'] : null,
			(string) $row['updated_at'],
			isset( $row['lease_expires_at'] ) && '' !== (string) $row['lease_expires_at'] ? (string) $row['lease_expires_at'] : null
		);
	}
}
