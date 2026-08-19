<?php
/**
 * Internal workflow step adapter contract.
 *
 * @package Aculect\AICompanion\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Adapters;

use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;

/**
 * Declares one exact, internal adapter-version contract.
 *
 * @internal Workflow adapters are runner composition details, not public
 * REST, MCP, or WordPress Abilities API contracts.
 */
interface WorkflowAdapterInterface {

	/**
	 * Return the workflow adapter ID.
	 */
	public function adapter_id(): string;

	/**
	 * Return the exact workflow adapter version.
	 */
	public function adapter_version(): int;

	/**
	 * Return the slash-separated workflow ability ID.
	 */
	public function ability_id(): string;

	/**
	 * Return the supported workflow step kind.
	 */
	public function kind(): string;

	/**
	 * Whether the adapter is limited to read-only execution.
	 */
	public function is_read_only(): bool;

	/**
	 * Return capability intents enforced by the execution gateway/module.
	 *
	 * @return list<string>
	 */
	public function required_capabilities(): array;

	/**
	 * Return the exact adapter input schema.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array;

	/**
	 * Return the successful output-object declaration.
	 *
	 * This schema describes WorkflowAdapterResult::output(), not the internal
	 * status/code envelope returned by WorkflowAdapterResult::to_array().
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array;

	/**
	 * Execute an exact plan-bound step through its authoritative boundary.
	 *
	 * @param WorkflowPlan         $plan      Immutable plan containing the step identity.
	 * @param string               $step_id   Exact step ID to bind.
	 * @param array<string, mixed> $arguments Runtime arguments for the existing ability.
	 * @param array<string, mixed> $auth      Authenticated gateway context.
	 */
	public function execute( WorkflowPlan $plan, string $step_id, array $arguments, array $auth ): WorkflowAdapterResult;
}
