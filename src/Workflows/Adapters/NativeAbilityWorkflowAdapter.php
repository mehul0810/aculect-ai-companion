<?php
/**
 * Generic workflow adapter for one existing Aculect ability.
 *
 * @package Aculect\AICompanion\Workflows\Adapters
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Adapters;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputValidator;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Throwable;

/**
 * Adapts an already registered ability without creating a second write path.
 *
 * Every call still crosses AbilityExecutionGateway, so OAuth scopes, role
 * policy, WordPress capabilities, confirmation, and execution claims remain
 * authoritative for write-capable adapters.
 */
final class NativeAbilityWorkflowAdapter implements WorkflowAdapterInterface {

	/**
	 * Gateway result statuses that describe an unfinished or non-authoritative
	 * operation rather than a completed ability execution.
	 *
	 * @var list<string>
	 */
	private const NON_COMPLETING_STATUSES = array(
		'preview',
		'confirmation_required',
		'blocked',
		'uncertain',
		'pending',
		'queued',
	);

	private AbilitiesRegistry $abilities;
	private AbilityExecutionGateway $gateway;
	/**
	 * Output schema used to validate the native result.
	 *
	 * @var array<string, mixed>
	 */
	private array $output_schema;

	/**
	 * Create one exact native ability mapping.
	 *
	 * @param string                    $adapter_id      Stable adapter ID.
	 * @param int                       $adapter_version Exact adapter version.
	 * @param string                    $workflow_ability_id Public workflow ability ID.
	 * @param string                    $internal_ability_id Existing dotted ability ID.
	 * @param string                    $kind            Step kind.
	 * @param bool                      $read_only       Read-only declaration.
	 * @param array                     $capabilities   Capability intents.
	 * @param array<string, mixed>|null $output_schema Output schema override.
	 * @param AbilitiesRegistry|null    $abilities Existing ability registry.
	 * @phpstan-param list<string> $capabilities
	 */
	public function __construct(
		private string $adapter_id,
		private int $adapter_version,
		private string $workflow_ability_id,
		private string $internal_ability_id,
		private string $kind,
		private bool $read_only,
		private array $capabilities = array(),
		?array $output_schema = null,
		?AbilitiesRegistry $abilities = null
	) {
		$this->abilities     = $abilities ?? new AbilitiesRegistry();
		$this->gateway       = new AbilityExecutionGateway( $this->abilities );
		$this->output_schema = $output_schema ?? array(
			'type'                 => 'object',
			'additionalProperties' => true,
		);
	}

	public function adapter_id(): string {
		return $this->adapter_id;
	}

	public function adapter_version(): int {
		return $this->adapter_version;
	}

	public function ability_id(): string {
		return $this->workflow_ability_id;
	}

	public function kind(): string {
		return $this->kind;
	}

	public function is_read_only(): bool {
		return $this->read_only;
	}

	public function required_capabilities(): array {
		return $this->capabilities;
	}

	public function input_schema(): array {
		$module = $this->abilities->module( $this->internal_ability_id );

		return null === $module ? array(
			'type'                 => 'object',
			'additionalProperties' => false,
		) : AbilityExecutionGateway::input_schema_for_module( $module );
	}

	public function output_schema(): array {
		return $this->output_schema;
	}

	public function execute( WorkflowPlan $plan, string $step_id, array $arguments, array $auth ): WorkflowAdapterResult {
		$binding = WorkflowPlanStepBinding::from_plan( $plan, $step_id );
		if (
			null === $binding
			|| ! $binding->belongs_to( $plan )
			|| $this->adapter_id !== $binding->adapter_id()
			|| $this->adapter_version !== $binding->adapter_version()
			|| $this->workflow_ability_id !== $binding->ability_id()
			|| $this->kind !== $binding->kind()
		) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH );
		}

		$module = $this->abilities->module( $this->internal_ability_id );
		if ( null === $module || $module->id() !== $this->internal_ability_id || $module->is_read_only() !== $this->read_only ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_ABILITY_CONTRACT_MISMATCH );
		}

		try {
			$input      = WorkflowInputContract::from_value( $arguments );
			$validation = ( new WorkflowInputValidator() )->validate( $input, $this->input_schema() );
			if ( array() !== $validation->missing_paths() || array() !== $validation->invalid_paths() ) {
				return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS );
			}
			$arguments = get_object_vars( $input->value() );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS );
		}

		try {
			$outcome = $this->gateway->execute(
				new AbilityExecutionRequest(
					array(
						'name'      => $this->internal_ability_id,
						'arguments' => $arguments,
					),
					$auth
				)
			);
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE );
		}

		if ( AbilityExecutionGateway::OUTCOME_SUCCESS !== $outcome->type ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_GATEWAY_REJECTED );
		}

		$output = $outcome->data['result'] ?? null;
		if ( ! is_array( $output ) || array_is_list( $output ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
		}
		if ( isset( $output['error'] ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_ABILITY_FAILED );
		}
		if ( $this->is_non_completing_output( $output ) ) {
			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_GATEWAY_REJECTED );
		}

		try {
			$contract   = WorkflowInputContract::from_value( $output );
			$validation = ( new WorkflowInputValidator() )->validate( $contract, $this->output_schema() );
			if ( array() !== $validation->missing_paths() || array() !== $validation->invalid_paths() ) {
				return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
			}

			return WorkflowAdapterResult::success( get_object_vars( $contract->value() ) );
		} catch ( Throwable $throwable ) {
			unset( $throwable );

			return WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_OUTPUT_NOT_AVAILABLE );
		}
	}

	/**
	 * Do not let previews or policy hand-offs become completed workflow steps.
	 *
	 * The gateway deliberately uses a successful transport outcome for
	 * confirmation-required previews so MCP callers can inspect the payload.
	 * A workflow adapter has a stricter contract: only a committed, bounded
	 * ability result may complete a step and unlock dependent dispatch.
	 *
	 * @param array<string, mixed> $output Gateway result payload.
	 */
	private function is_non_completing_output( array $output ): bool {
		if ( true === ( $output['dry_run'] ?? false ) || true === ( $output['confirmation_required'] ?? false ) ) {
			return true;
		}

		$status = isset( $output['status'] ) && is_string( $output['status'] ) ? $output['status'] : '';

		return in_array( $status, self::NON_COMPLETING_STATUSES, true );
	}
}
