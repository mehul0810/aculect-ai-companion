<?php
/**
 * Tests for the private single-step read/proposal execution facade.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Execution
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Execution;

use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterInterface;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterRegistry;
use Aculect\AICompanion\Workflows\Adapters\WorkflowAdapterResult;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Execution\WorkflowReadProposalFacade;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves eligibility, exact readiness, registry-only execution, and privacy.
 */
final class WorkflowReadProposalFacadeTest extends TestCase {

	public function test_one_step_read_and_proposal_plans_execute_only_through_registry(): void {
		foreach ( array( 'read', 'proposal' ) as $kind ) {
			$plan       = $this->plan( kind: $kind );
			$executions = 0;
			$adapter    = $this->adapter(
				$kind,
				static function () use ( &$executions ): WorkflowAdapterResult {
					++$executions;

					return WorkflowAdapterResult::success( array( 'state' => 'ready' ) );
				}
			);
			$result     = $this->facade( $adapter )->execute(
				$plan,
				'read_content',
				array( 'private_argument' => 'never-return-argument' ),
				array( 'client_secret' => 'never-return-auth' )
			);

			self::assertTrue( $result->succeeded() );
			self::assertSame( 'ready', $result->output()->state ?? null );
			self::assertSame( 1, $executions );
			self::assertStringNotContainsString( 'never-return', (string) wp_json_encode( $result->to_array() ) );
		}
	}

	public function test_stale_or_mismatched_exact_binding_fails_before_execution(): void {
		$plan = $this->plan();

		foreach (
			array(
				$this->adapter( 'read', null, 'wordpress', 2 ),
				$this->adapter( 'proposal' ),
				$this->adapter( 'read', null, 'other_adapter' ),
			) as $adapter
		) {
			$result = $this->facade( $adapter )->execute( $plan, 'read_content', array(), array() );

			self::assertSame( WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE, $result->code() );
		}
	}

	public function test_ineligible_plan_shapes_fail_before_registry_execution(): void {
		$cases = array(
			'multi_step'       => $this->plan( extra_step: true ),
			'write'            => $this->plan( kind: 'write', approval_gate: true ),
			'approval_gated'   => $this->plan( kind: 'write', approval_gate: true ),
			'validation_rules' => $this->plan( validation_rule: true ),
			'dependent'        => $this->plan( extra_step: true, dependency: true ),
			'missing_input'    => $this->plan( require_input: true ),
			'invalid_input'    => $this->plan( input_json: '{"post_id":"private-invalid"}' ),
		);

		foreach ( $cases as $case => $plan ) {
			$executions = 0;
			$adapter    = $this->adapter(
				'read',
				static function () use ( &$executions ): WorkflowAdapterResult {
					++$executions;

					return WorkflowAdapterResult::success( array() );
				}
			);
			$result     = $this->facade( $adapter )->execute( $plan, 'read_content', array(), array() );

			self::assertFalse( $result->succeeded(), $case );
			self::assertContains(
				$result->code(),
				array( WorkflowAdapterResult::CODE_INVALID_ARGUMENTS, WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH ),
				$case
			);
			self::assertSame( 0, $executions, $case );
		}
	}

	public function test_missing_step_fails_before_snapshot_or_execution(): void {
		$result = $this->facade( $this->adapter() )->execute( $this->plan(), 'other_step', array(), array() );

		self::assertSame( WorkflowAdapterResult::CODE_STEP_NOT_FOUND, $result->code() );
	}

	public function test_descriptor_and_execution_failures_map_to_closed_results_without_leaks(): void {
		$throwing_descriptor = $this->adapter();
		$throwing_descriptor->method( 'ability_id' )->willThrowException( new RuntimeException( 'private descriptor detail' ) );
		$descriptor_result = $this->facade( $throwing_descriptor )->execute(
			$this->plan(),
			'read_content',
			array( 'secret' => 'private-argument' ),
			array( 'secret' => 'private-auth' )
		);

		$throwing_execution = $this->adapter(
			'read',
			static function (): WorkflowAdapterResult {
				throw new RuntimeException( 'private execution detail' );
			}
		);
		$execution_result   = $this->facade( $throwing_execution )->execute(
			$this->plan(),
			'read_content',
			array( 'secret' => 'private-argument' ),
			array( 'secret' => 'private-auth' )
		);

		foreach ( array( $descriptor_result, $execution_result ) as $result ) {
			$encoded = (string) wp_json_encode( $result->to_array() );
			self::assertSame( WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE, $result->code() );
			self::assertStringNotContainsString( 'private', $encoded );
			self::assertArrayNotHasKey( 'output', $result->to_array() );
		}
	}

	public function test_non_read_only_or_throwing_dispatch_descriptor_never_executes(): void {
		foreach (
			array(
				false,
				static function (): bool {
					throw new RuntimeException( 'private read-only descriptor detail' );
				},
			) as $descriptor
		) {
			$executions = 0;
			$adapter    = $this->adapter(
				'read',
				static function () use ( &$executions ): WorkflowAdapterResult {
					++$executions;

					return WorkflowAdapterResult::success( array() );
				},
				'wordpress', // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Exact machine ID.
				1,
				$descriptor
			);

			$result = $this->facade( $adapter )->execute( $this->plan(), 'read_content', array(), array() );

			self::assertFalse( $result->succeeded() );
			self::assertContains(
				$result->code(),
				array( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH, WorkflowAdapterResult::CODE_EXECUTION_NOT_AVAILABLE )
			);
			self::assertSame( 0, $executions );
			self::assertStringNotContainsString( 'private', (string) wp_json_encode( $result->to_array() ) );
		}
	}

	public function test_owner_becoming_write_capable_after_snapshot_fails_at_dispatch(): void {
		$read_only  = true;
		$executions = 0;
		$kind_calls = 0;
		$adapter    = $this->adapter(
			static function () use ( &$kind_calls, &$read_only ): string {
				++$kind_calls;
				if ( 2 === $kind_calls ) {
					$read_only = false;
				}

				return 'read';
			},
			static function () use ( &$executions ): WorkflowAdapterResult {
				++$executions;

				return WorkflowAdapterResult::success( array() );
			},
			'wordpress', // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Exact machine ID.
			1,
			static function () use ( &$read_only ): bool {
				return $read_only;
			}
		);

		$result = $this->facade( $adapter )->execute( $this->plan(), 'read_content', array(), array() );

		self::assertSame( WorkflowAdapterResult::CODE_STEP_CONTRACT_MISMATCH, $result->code() );
		self::assertSame( 2, $kind_calls, 'Availability and dispatch must both observe the same registry owner.' );
		self::assertSame( 0, $executions );
	}

	public function test_registry_failure_is_preserved_and_success_output_remains_detached(): void {
		$failure = $this->facade(
			$this->adapter( 'read', static fn (): WorkflowAdapterResult => WorkflowAdapterResult::failure( WorkflowAdapterResult::CODE_GATEWAY_REJECTED ) )
		)->execute( $this->plan(), 'read_content', array(), array() );
		self::assertSame( WorkflowAdapterResult::CODE_GATEWAY_REJECTED, $failure->code() );

		$success               = $this->facade(
			$this->adapter( 'read', static fn (): WorkflowAdapterResult => WorkflowAdapterResult::success( array( 'nested' => array( 'state' => 'ready' ) ) ) )
		)->execute( $this->plan(), 'read_content', array(), array() );
		$output                = $success->output();
		$output->nested->state = 'mutated';

		self::assertSame( 'ready', $success->output()->nested->state ?? null );
	}

	public function test_source_has_only_private_registry_execution_and_no_runtime_wiring(): void {
		$root   = dirname( __DIR__, 4 );
		$source = file_get_contents( $root . '/src/Workflows/Execution/WorkflowReadProposalFacade.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned source guard.
		self::assertIsString( $source );
		self::assertSame( 1, substr_count( $source, '$this->registry->execute_read_only(' ) );
		self::assertDoesNotMatchRegularExpression(
			'/(?:->gateway->execute\s*\(|->adapter->execute\s*\(|register_rest_route|wp_register_ability|add_action|add_filter|\$wpdb|get_option|update_option|add_option|delete_option|set_transient|wp_cache_|WorkflowSession|ExecutionClaim|OAuth)/i',
			$source
		);

		$plugin_source = file_get_contents( $root . '/src/Plugin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned source guard.
		self::assertIsString( $plugin_source );
		self::assertStringNotContainsString( 'WorkflowReadProposalFacade', $plugin_source );
	}

	private function facade( WorkflowAdapterInterface $adapter ): WorkflowReadProposalFacade {
		return new WorkflowReadProposalFacade( new WorkflowAdapterRegistry( array( $adapter ) ) );
	}

	/**
	 * Create an exact adapter double for facade composition.
	 *
	 * @param string|callable $kind            Exact workflow step kind or descriptor callback.
	 * @param callable|null   $execute         Optional execution callback.
	 * @param string          $adapter_id      Exact adapter ID.
	 * @param int             $adapter_version Exact adapter version.
	 * @param bool|callable   $read_only       Read-only state or descriptor callback.
	 * @return WorkflowAdapterInterface&MockObject
	 */
	private function adapter(
		string|callable $kind = 'read',
		?callable $execute = null,
		string $adapter_id = 'wordpress', // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Exact machine ID.
		int $adapter_version = 1,
		bool|callable $read_only = true
	): WorkflowAdapterInterface {
		$adapter = $this->createMock( WorkflowAdapterInterface::class );
		$adapter->method( 'adapter_id' )->willReturn( $adapter_id );
		$adapter->method( 'adapter_version' )->willReturn( $adapter_version );
		$adapter->method( 'ability_id' )->willReturn( 'content/get-item' );
		if ( is_callable( $kind ) ) {
			$adapter->method( 'kind' )->willReturnCallback( $kind );
		} else {
			$adapter->method( 'kind' )->willReturn( $kind );
		}
		if ( is_callable( $read_only ) ) {
			$adapter->method( 'is_read_only' )->willReturnCallback( $read_only );
		} else {
			$adapter->method( 'is_read_only' )->willReturn( $read_only );
		}
		$adapter->method( 'execute' )->willReturnCallback(
			$execute ?? static fn (): WorkflowAdapterResult => WorkflowAdapterResult::success( array() )
		);

		return $adapter;
	}

	private function plan(
		string $kind = 'read',
		bool $extra_step = false,
		bool $approval_gate = false,
		bool $validation_rule = false,
		bool $dependency = false,
		bool $require_input = false,
		string $input_json = '{}'
	): WorkflowPlan {
		$path = dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/proposal-only-v1.json';
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned fixture.
		self::assertIsString( $json );
		$definition = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
		self::assertIsArray( $definition );

		$definition['steps'][0]['kind'] = $kind;
		if ( 'write' === $kind ) {
			$definition['write_policy']['mode'] = 'draft_only';
			$approval_gate                      = true;
		}
		if ( $extra_step ) {
			$definition['steps'][] = array(
				'step_id'         => 'second_step',
				'adapter_id'      => 'wordpress',
				'adapter_version' => 1,
				'ability_id'      => 'content/get-item',
				'kind'            => 'read',
				'arguments'       => array(),
				'depends_on'      => $dependency ? array( 'read_content' ) : array(),
			);
		}
		$definition['approval_gates']   = $approval_gate ? array( 'read_content' ) : array();
		$definition['validation_rules'] = $validation_rule ? array(
			array(
				'rule_id'  => 'check_input',
				'severity' => 'error',
			),
		) : array();
		if ( $require_input ) {
			$definition['input_schema']['required'] = array( 'post_id' );
		}

		return ( new WorkflowPlanBuilder() )->build(
			WorkflowDefinition::from_json( (string) wp_json_encode( $definition ) ),
			WorkflowInputContract::from_json( $input_json )
		);
	}
}
