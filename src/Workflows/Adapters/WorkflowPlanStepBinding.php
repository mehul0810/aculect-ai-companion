<?php
/**
 * Exact workflow plan step binding.
 *
 * @package Aculect\AICompanion\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Adapters;

use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use stdClass;

/**
 * Extracts an adapter dispatch identity only from an immutable WorkflowPlan.
 *
 * @internal This value deliberately excludes raw workflow input and arguments.
 */
final readonly class WorkflowPlanStepBinding {

	/**
	 * Create a verified plan step binding.
	 *
	 * @param string $plan_hash       Exact plan hash.
	 * @param string $step_id         Exact step ID.
	 * @param string $adapter_id      Adapter ID.
	 * @param int    $adapter_version Adapter version.
	 * @param string $ability_id      Workflow ability ID.
	 * @param string $kind            Workflow step kind.
	 */
	private function __construct(
		private string $plan_hash,
		private string $step_id,
		private string $adapter_id,
		private int $adapter_version,
		private string $ability_id,
		private string $kind
	) {
	}

	/**
	 * Resolve one exact step from a plan, failing closed on malformed identity.
	 *
	 * @param WorkflowPlan $plan    Immutable plan.
	 * @param string       $step_id Exact step ID.
	 */
	public static function from_plan( WorkflowPlan $plan, string $step_id ): ?self {
		if ( '' === $step_id ) {
			return null;
		}

		$identity = $plan->identity();
		$steps    = $identity['steps'] ?? null;
		if ( ! is_array( $steps ) ) {
			return null;
		}

		$match = null;
		foreach ( $steps as $raw_step ) {
			$step = self::step_map( $raw_step );
			if ( null === $step || ( $step['step_id'] ?? null ) !== $step_id ) {
				continue;
			}

			if ( null !== $match ) {
				return null;
			}
			$match = $step;
		}

		if ( null === $match ) {
			return null;
		}

		$adapter_id      = $match['adapter_id'] ?? null;
		$adapter_version = $match['adapter_version'] ?? null;
		$ability_id      = $match['ability_id'] ?? null;
		$kind            = $match['kind'] ?? null;
		if (
			! is_string( $adapter_id ) || '' === $adapter_id
			|| ! is_int( $adapter_version ) || 1 > $adapter_version
			|| ! is_string( $ability_id ) || '' === $ability_id
			|| ! is_string( $kind ) || ! in_array( $kind, array( 'read', 'proposal', 'write' ), true )
		) {
			return null;
		}

		return new self( $plan->hash(), $step_id, $adapter_id, $adapter_version, $ability_id, $kind );
	}

	/**
	 * Return the stable registry key.
	 */
	public function adapter_key(): string {
		return $this->adapter_id . '@' . $this->adapter_version;
	}

	/**
	 * Verify the binding still belongs to the exact plan.
	 *
	 * @param WorkflowPlan $plan Immutable plan.
	 */
	public function belongs_to( WorkflowPlan $plan ): bool {
		return hash_equals( $this->plan_hash, $plan->hash() );
	}

	/**
	 * Return the exact step ID.
	 */
	public function step_id(): string {
		return $this->step_id;
	}

	/**
	 * Return the adapter ID.
	 */
	public function adapter_id(): string {
		return $this->adapter_id;
	}

	/**
	 * Return the adapter version.
	 */
	public function adapter_version(): int {
		return $this->adapter_version;
	}

	/**
	 * Return the slash-separated workflow ability ID.
	 */
	public function ability_id(): string {
		return $this->ability_id;
	}

	/**
	 * Return the workflow step kind.
	 */
	public function kind(): string {
		return $this->kind;
	}

	/**
	 * Normalize one plan-owned step object without accepting other shapes.
	 *
	 * @param mixed $raw_step Plan step value.
	 * @return array<string, mixed>|null
	 */
	private static function step_map( mixed $raw_step ): ?array {
		if ( $raw_step instanceof stdClass ) {
			return get_object_vars( $raw_step );
		}

		return is_array( $raw_step ) && ! array_is_list( $raw_step ) ? $raw_step : null;
	}
}
