<?php
/**
 * Deterministic internal workflow adapter registry.
 *
 * @package Aculect\AICompanion\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Adapters;

use Aculect\AICompanion\Workflows\Planning\WorkflowAvailabilitySnapshot;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use RuntimeException;
use Throwable;

/**
 * Resolves exact plan adapter-version keys without exposing a public registry.
 *
 * @internal No WordPress hooks, REST routes, MCP tools, or Abilities API
 * registrations are attached to this registry.
 */
final class WorkflowAdapterRegistry {

	/**
	 * Exact adapter-version map.
	 *
	 * @var array<string, WorkflowAdapterInterface>
	 */
	private array $adapters;

	/**
	 * Create the closed registry.
	 *
	 * @param list<WorkflowAdapterInterface>|null $adapters Explicit adapters for tests/composition.
	 */
	public function __construct( ?array $adapters = null ) {
		$this->adapters = $this->key_by_identity( $adapters ?? array( new ContentPlannerAdapter(), new WordPressReadAdapter() ) );
	}

	/**
	 * Execute one exact plan step through its registered adapter.
	 *
	 * @param WorkflowPlan         $plan      Immutable workflow plan.
	 * @param string               $step_id   Exact plan step ID.
	 * @param array<string, mixed> $arguments Runtime ability arguments.
	 * @param array<string, mixed> $auth      Authenticated gateway context.
	 */
	public function execute( WorkflowPlan $plan, string $step_id, array $arguments, array $auth ): WorkflowAdapterResult {
		return $this->execute_resolved( $plan, $step_id, $arguments, $auth, false );
	}

	/**
	 * Execute one exact plan step only when its owner is read-only at dispatch.
	 *
	 * The read-only descriptor is checked on the same resolved adapter
	 * immediately before its callback, so an earlier availability snapshot
	 * cannot authorize a mutable or replaced write-capable owner.
	 *
	 * @param WorkflowPlan         $plan      Immutable workflow plan.
	 * @param string               $step_id   Exact plan step ID.
	 * @param array<string, mixed> $arguments Runtime ability arguments.
	 * @param array<string, mixed> $auth      Authenticated gateway context.
	 */
	public function execute_read_only( WorkflowPlan $plan, string $step_id, array $arguments, array $auth ): WorkflowAdapterResult {
		return $this->execute_resolved( $plan, $step_id, $arguments, $auth, true );
	}

	/**
	 * Resolve and execute one exact registered adapter owner.
	 *
	 * @param WorkflowPlan         $plan              Immutable workflow plan.
	 * @param string               $step_id           Exact plan step ID.
	 * @param array<string, mixed> $arguments          Runtime ability arguments.
	 * @param array<string, mixed> $auth               Authenticated gateway context.
	 * @param bool                 $require_read_only  Whether dispatch must remain read-only.
	 */
	private function execute_resolved(
		WorkflowPlan $plan,
		string $step_id,
		array $arguments,
		array $auth,
		bool $require_read_only
	): WorkflowAdapterResult {
		$binding = WorkflowPlanStepBinding::from_plan( $plan, $step_id );
		if ( null === $binding || ! $binding->belongs_to( $plan ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_STEP_NOT_FOUND );
		}

		$adapter = $this->adapters[ $binding->adapter_key() ] ?? null;
		if ( null === $adapter ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_ADAPTER_NOT_REGISTERED );
		}

		if ( ! $require_read_only ) {
			if ( ! $this->matches_binding( $adapter, $binding ) ) {
				return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH );
			}

			try {
				return $adapter->execute( $plan, $step_id, $arguments, $auth );
			} catch ( Throwable $throwable ) {
				unset( $throwable );

				return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE );
			}
		}

		try {
			if ( ! $this->matches_binding( $adapter, $binding ) || ! $adapter->is_read_only() ) {
				return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH );
			}

			return $adapter->execute( $plan, $step_id, $arguments, $auth );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE );
		}
	}

	/**
	 * Compare one resolved owner with the exact immutable plan binding.
	 *
	 * @param WorkflowAdapterInterface $adapter Exact registered owner.
	 * @param WorkflowPlanStepBinding  $binding Exact plan binding.
	 */
	private function matches_binding( WorkflowAdapterInterface $adapter, WorkflowPlanStepBinding $binding ): bool {
		return $adapter->adapter_id() === $binding->adapter_id()
			&& $adapter->adapter_version() === $binding->adapter_version()
			&& $adapter->ability_id() === $binding->ability_id()
			&& $adapter->kind() === $binding->kind();
	}

	/**
	 * Return the exact binding owned by every validated registry adapter.
	 *
	 * Availability is not authorization. This snapshot does not evaluate policy,
	 * capabilities, scopes, confirmation, or runtime module state.
	 */
	public function availability_snapshot(): WorkflowAvailabilitySnapshot {
		$bindings = array();

		foreach ( $this->adapters as $key => $adapter ) {
			list( $adapter_id, $adapter_version ) = explode( '@', $key, 2 );
			$bindings[]                           = array(
				'adapter_id'      => $adapter_id,
				'adapter_version' => (int) $adapter_version,
				'ability_id'      => $adapter->ability_id(),
				'kind'            => $adapter->kind(),
			);
		}

		return WorkflowAvailabilitySnapshot::from_value(
			array(
				'availability_schema_version' => WorkflowAvailabilitySnapshot::SCHEMA_VERSION,
				'bindings'                    => $bindings,
			)
		);
	}

	/**
	 * Key adapters deterministically and reject ambiguous ownership.
	 *
	 * @param array $adapters Adapters to register.
	 * @phpstan-param list<WorkflowAdapterInterface> $adapters
	 * @return array<string, WorkflowAdapterInterface>
	 * @throws RuntimeException When an adapter identity is invalid or duplicated.
	 */
	private function key_by_identity( array $adapters ): array {
		$keyed = array();
		foreach ( $adapters as $adapter ) {
			if ( ! $adapter instanceof WorkflowAdapterInterface ) {
				throw new RuntimeException( 'Invalid workflow adapter contract.' );
			}

			$adapter_id      = $adapter->adapter_id();
			$adapter_version = $adapter->adapter_version();
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{1,63}$/D', $adapter_id ) || 1 > $adapter_version ) {
				throw new RuntimeException( 'Invalid workflow adapter identity.' );
			}

			$key = $adapter_id . '@' . $adapter_version;
			if ( isset( $keyed[ $key ] ) ) {
				throw new RuntimeException( 'Duplicate workflow adapter identity.' );
			}
			$keyed[ $key ] = $adapter;
		}

		ksort( $keyed, SORT_STRING );

		return $keyed;
	}
}
