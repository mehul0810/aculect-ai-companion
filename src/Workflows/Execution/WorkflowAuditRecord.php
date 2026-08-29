<?php
/**
 * Bounded summary of one workflow audit event.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

/**
 * Keeps audit events free of raw payloads, secrets, and opaque approval text.
 */
final readonly class WorkflowAuditRecord {

	private const MAX_CHANGED_FIELDS = 32;

	/**
	 * Create a validated summary event.
	 *
	 * @param string      $run_id                   Stable run ID.
	 * @param string      $workflow_id              Stable workflow ID.
	 * @param int         $workflow_version         Pinned definition version.
	 * @param string      $definition_checksum      Pinned definition checksum.
	 * @param string      $event_type               Closed event type.
	 * @param string      $step_id                  Optional step ID.
	 * @param int         $actor_id                 WordPress actor ID.
	 * @param string|null $outcome_code             Bounded outcome code.
	 * @param string|null $approval_reference_hash  Hash of opaque approval reference.
	 * @param array       $changed_fields           Names only, never field values.
	 * @param string      $rollback_note            Bounded rollback guidance.
	 * @param string      $created_at               UTC timestamp.
	 * @throws WorkflowRunStoreException When the summary violates the audit contract.
	 * @phpstan-param list<string>        $changed_fields
	 */
	public function __construct(
		private string $run_id,
		private string $workflow_id,
		private int $workflow_version,
		private string $definition_checksum,
		private string $event_type,
		private string $step_id,
		private int $actor_id,
		private ?string $outcome_code,
		private ?string $approval_reference_hash,
		private array $changed_fields,
		private string $rollback_note,
		private string $created_at
	) {
		if (
			1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{1,63}$/D', $run_id )
			|| 1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $workflow_id )
			|| $workflow_version < 1
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $definition_checksum )
			|| 1 !== preg_match( '/^[a-z][a-z0-9_]{1,31}$/D', $event_type )
			|| ( '' !== $step_id && 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $step_id ) )
			|| ( $actor_id < 0 )
			|| ( null !== $outcome_code && 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $outcome_code ) )
			|| ( null !== $approval_reference_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', $approval_reference_hash ) )
			|| count( $changed_fields ) > self::MAX_CHANGED_FIELDS
			|| strlen( $rollback_note ) > 255
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $created_at )
		) {
			throw new WorkflowRunStoreException( 'audit_event_invalid' );
		}

		foreach ( $changed_fields as $field ) {
			if ( ! is_string( $field ) || strlen( $field ) > 96 || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_.-]*$/D', $field ) ) {
				throw new WorkflowRunStoreException( 'audit_event_invalid' );
			}
		}
	}

	/** Return the durable run ID. */
	public function run_id(): string {
		return $this->run_id;
	}

	/** Return the immutable workflow ID. */
	public function workflow_id(): string {
		return $this->workflow_id;
	}

	/** Return the pinned workflow version. */
	public function workflow_version(): int {
		return $this->workflow_version;
	}

	/** Return the pinned definition checksum. */
	public function definition_checksum(): string {
		return $this->definition_checksum;
	}

	/** Return the closed event type. */
	public function event_type(): string {
		return $this->event_type;
	}

	/** Return the optional step ID. */
	public function step_id(): string {
		return $this->step_id;
	}

	/** Return the actor ID. */
	public function actor_id(): int {
		return $this->actor_id;
	}

	/** Return the bounded outcome code. */
	public function outcome_code(): ?string {
		return $this->outcome_code;
	}

	/** Return the one-way approval reference hash. */
	public function approval_reference_hash(): ?string {
		return $this->approval_reference_hash;
	}

	/**
	 * Return the changed field names.
	 *
	 * @return list<string>
	 */
	public function changed_fields(): array {
		return $this->changed_fields;
	}

	/** Return bounded rollback guidance. */
	public function rollback_note(): string {
		return $this->rollback_note;
	}

	/** Return the UTC creation timestamp. */
	public function created_at(): string {
		return $this->created_at;
	}

	/**
	 * Return an API-safe summary without opaque approval references.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'run_id'              => $this->run_id,
			'workflow_id'         => $this->workflow_id,
			'workflow_version'    => $this->workflow_version,
			'definition_checksum' => $this->definition_checksum,
			'event_type'          => $this->event_type,
			'step_id'             => $this->step_id,
			'actor_id'            => $this->actor_id,
			'outcome_code'        => $this->outcome_code,
			'approval_recorded'   => null !== $this->approval_reference_hash,
			'changed_fields'      => $this->changed_fields,
			'rollback_note'       => $this->rollback_note,
			'created_at'          => $this->created_at,
		);
	}
}
