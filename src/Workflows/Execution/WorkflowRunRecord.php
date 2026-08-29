<?php
/**
 * Detached durable workflow run record.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

use Aculect\AICompanion\Workflows\Planning\WorkflowRunState;

/**
 * Public-safe projection of one persisted run row.
 *
 * Encrypted input, plan JSON, and result payloads are intentionally absent.
 */
final readonly class WorkflowRunRecord {

	/**
	 * Create a detached run projection.
	 *
	 * @param int              $id                  Database identity.
	 * @param string           $run_id              Stable run ID.
	 * @param string           $workflow_id         Stable workflow ID.
	 * @param int              $workflow_version    Pinned definition version.
	 * @param string           $definition_checksum Pinned definition checksum.
	 * @param string           $plan_hash           Pinned plan hash.
	 * @param string           $input_hash          Normalized input hash.
	 * @param WorkflowRunState $state              Durable lifecycle state.
	 * @param int              $state_version       Optimistic state fence.
	 * @param string|null      $outcome_code        Bounded terminal outcome code.
	 * @param string|null      $waiting_expires_at  UTC expiry for waiting states.
	 * @param string|null      $approval_reference_hash One-way hash of approval evidence.
	 * @param int              $created_by          Creating actor.
	 * @param int              $updated_by          Last actor.
	 * @param string           $created_at          UTC creation timestamp.
	 * @param string           $updated_at          UTC update timestamp.
	 * @throws WorkflowRunStoreException When the stored approval hash is malformed.
	 */
	public function __construct(
		private int $id,
		private string $run_id,
		private string $workflow_id,
		private int $workflow_version,
		private string $definition_checksum,
		private string $plan_hash,
		private string $input_hash,
		private WorkflowRunState $state,
		private int $state_version,
		private ?string $outcome_code,
		private ?string $waiting_expires_at,
		private ?string $approval_reference_hash,
		private int $created_by,
		private int $updated_by,
		private string $created_at,
		private string $updated_at
	) {
		if ( null !== $this->approval_reference_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', $this->approval_reference_hash ) ) {
			throw new WorkflowRunStoreException( 'stored_run_invalid' );
		}
	}

	public function id(): int {
		return $this->id; }
	public function run_id(): string {
		return $this->run_id; }
	public function workflow_id(): string {
		return $this->workflow_id; }
	public function workflow_version(): int {
		return $this->workflow_version; }
	public function definition_checksum(): string {
		return $this->definition_checksum; }
	public function plan_hash(): string {
		return $this->plan_hash; }
	public function input_hash(): string {
		return $this->input_hash; }
	public function state(): WorkflowRunState {
		return $this->state; }
	public function state_version(): int {
		return $this->state_version; }
	public function outcome_code(): ?string {
		return $this->outcome_code; }
	public function waiting_expires_at(): ?string {
		return $this->waiting_expires_at; }
	public function approval_reference_hash(): ?string {
		return $this->approval_reference_hash; }
	public function created_by(): int {
		return $this->created_by; }
	public function updated_by(): int {
		return $this->updated_by; }
	public function created_at(): string {
		return $this->created_at; }
	public function updated_at(): string {
		return $this->updated_at; }

	/**
	 * Return an API-safe representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                  => $this->id,
			'run_id'              => $this->run_id,
			'workflow_id'         => $this->workflow_id,
			'workflow_version'    => $this->workflow_version,
			'definition_checksum' => $this->definition_checksum,
			'plan_hash'           => $this->plan_hash,
			'input_hash'          => $this->input_hash,
			'state'               => $this->state->value,
			'state_version'       => $this->state_version,
			'outcome_code'        => $this->outcome_code,
			'waiting_expires_at'  => $this->waiting_expires_at,
			'approval_recorded'   => null !== $this->approval_reference_hash,
			'created_by'          => $this->created_by,
			'updated_by'          => $this->updated_by,
			'created_at'          => $this->created_at,
			'updated_at'          => $this->updated_at,
		);
	}
}
