<?php
/**
 * Detached metadata for one workflow step adapter.
 *
 * @package Aculect\AICompanion\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Adapters;

/**
 * Exposes adapter contracts to internal planning and future admin surfaces.
 *
 * @internal This descriptor is not a public REST or MCP registration.
 */
final readonly class WorkflowAdapterDescriptor {

	/**
	 * Create one immutable adapter descriptor.
	 *
	 * @param string               $adapter_id      Stable adapter ID.
	 * @param int                  $adapter_version Exact adapter version.
	 * @param string               $ability_id      Slash-separated workflow ability ID.
	 * @param string               $kind            Step kind.
	 * @param bool                 $read_only       Whether the adapter never writes.
	 * @param array                $capabilities   WordPress capability intents.
	 * @param array<string, mixed> $input_schema   Adapter input schema.
	 * @param array<string, mixed> $output_schema  Adapter output schema.
	 * @phpstan-param list<string> $capabilities
	 */
	public function __construct(
		private string $adapter_id,
		private int $adapter_version,
		private string $ability_id,
		private string $kind,
		private bool $read_only,
		private array $capabilities,
		private array $input_schema,
		private array $output_schema
	) {
	}

	/** Return the stable adapter ID. */
	public function adapter_id(): string {
		return $this->adapter_id;
	}

	/** Return the exact adapter version. */
	public function adapter_version(): int {
		return $this->adapter_version;
	}

	/** Return the workflow ability ID. */
	public function ability_id(): string {
		return $this->ability_id;
	}

	/** Return the step kind. */
	public function kind(): string {
		return $this->kind;
	}

	/** Whether this adapter is read-only. */
	public function is_read_only(): bool {
		return $this->read_only;
	}

	/**
	 * Return declared capability intents.
	 *
	 * @return list<string>
	 */
	public function required_capabilities(): array {
		return $this->capabilities;
	}

	/**
	 * Return a detached input schema.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return $this->input_schema;
	}

	/**
	 * Return a detached output schema.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return $this->output_schema;
	}

	/**
	 * Return a stable internal representation.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'adapter_id'      => $this->adapter_id,
			'adapter_version' => $this->adapter_version,
			'ability_id'      => $this->ability_id,
			'kind'            => $this->kind,
			'read_only'       => $this->read_only,
			'capabilities'    => $this->capabilities,
			'input_schema'    => $this->input_schema,
			'output_schema'   => $this->output_schema,
		);
	}
}
