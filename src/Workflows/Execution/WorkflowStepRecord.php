<?php
/**
 * Detached durable workflow step record.
 *
 * @package Aculect\AICompanion\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Execution;

/**
 * Public-safe projection of one persisted step row.
 *
 * Output payloads are returned only through a separately bounded accessor.
 */
final readonly class WorkflowStepRecord {

	/**
	 * Create a detached step projection.
	 *
	 * @param int               $id              Database identity.
	 * @param string            $run_id          Parent run ID.
	 * @param string            $step_id         Stable step ID.
	 * @param int               $position        Ordered plan position.
	 * @param string            $adapter_id      Adapter ID.
	 * @param int               $adapter_version Adapter version.
	 * @param string            $ability_id      Workflow ability ID.
	 * @param string            $kind            Step kind.
	 * @param WorkflowStepState $state           Durable step state.
	 * @param int               $attempt         Execution attempt count.
	 * @param int               $fence           Monotonic claim fence.
	 * @param string            $result_code    Closed result code.
	 * @param string|null       $error_code      Closed failure code.
	 * @param string|null       $output_json     Decrypted bounded output JSON.
	 * @param string|null       $started_at      UTC start timestamp.
	 * @param string|null       $completed_at    UTC completion timestamp.
	 * @param string            $updated_at      UTC update timestamp.
	 * @param string|null       $lease_expires_at UTC lease deadline.
	 */
	public function __construct(
		private int $id,
		private string $run_id,
		private string $step_id,
		private int $position,
		private string $adapter_id,
		private int $adapter_version,
		private string $ability_id,
		private string $kind,
		private WorkflowStepState $state,
		private int $attempt,
		private int $fence,
		private string $result_code,
		private ?string $error_code,
		private ?string $output_json,
		private ?string $started_at,
		private ?string $completed_at,
		private string $updated_at,
		private ?string $lease_expires_at = null
	) {
	}

	public function id(): int {
		return $this->id; }
	public function run_id(): string {
		return $this->run_id; }
	public function step_id(): string {
		return $this->step_id; }
	public function position(): int {
		return $this->position; }
	public function adapter_id(): string {
		return $this->adapter_id; }
	public function adapter_version(): int {
		return $this->adapter_version; }
	public function ability_id(): string {
		return $this->ability_id; }
	public function kind(): string {
		return $this->kind; }
	public function state(): WorkflowStepState {
		return $this->state; }
	public function attempt(): int {
		return $this->attempt; }
	public function fence(): int {
		return $this->fence; }
	public function result_code(): string {
		return $this->result_code; }
	public function error_code(): ?string {
		return $this->error_code; }
	public function output_json(): ?string {
		return $this->output_json; }
	public function started_at(): ?string {
		return $this->started_at; }
	public function completed_at(): ?string {
		return $this->completed_at; }
	public function updated_at(): string {
		return $this->updated_at; }
	public function lease_expires_at(): ?string {
		return $this->lease_expires_at; }

	/**
	 * Return a public-safe representation without output values.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'               => $this->id,
			'run_id'           => $this->run_id,
			'step_id'          => $this->step_id,
			'position'         => $this->position,
			'adapter_id'       => $this->adapter_id,
			'adapter_version'  => $this->adapter_version,
			'ability_id'       => $this->ability_id,
			'kind'             => $this->kind,
			'state'            => $this->state->value,
			'attempt'          => $this->attempt,
			'fence'            => $this->fence,
			'result_code'      => $this->result_code,
			'error_code'       => $this->error_code,
			'started_at'       => $this->started_at,
			'completed_at'     => $this->completed_at,
			'lease_expires_at' => $this->lease_expires_at,
			'updated_at'       => $this->updated_at,
		);
	}
}
