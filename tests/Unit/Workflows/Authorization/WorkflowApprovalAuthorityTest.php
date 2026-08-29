<?php
/**
 * Tests for server-issued custom workflow approval capabilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Workflows\Authorization
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Workflows\Authorization;

use Aculect\AICompanion\Workflows\Authorization\WorkflowApprovalAuthority;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Planning\WorkflowInputContract;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlan;
use Aculect\AICompanion\Workflows\Planning\WorkflowPlanBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Proves approval evidence is opaque, identity-bound, plan-bound, and one-time.
 */
final class WorkflowApprovalAuthorityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_transients'] = array();
	}

	public function test_issue_and_consume_bind_approval_to_actor_and_exact_plan_once(): void {
		$authority = new WorkflowApprovalAuthority();
		$plan      = $this->plan( '{"brief":"Approve this draft"}' );
		$auth      = array(
			'user_id'   => 7,
			'client_id' => 'chatgpt-client',
			'provider'  => 'chatgpt',
		);
		$token     = $authority->issue( 'run-approval-1', $plan, $auth );

		self::assertMatchesRegularExpression( '/^wfa_[a-f0-9]{48}$/', $token );
		self::assertNull(
			$authority->consume(
				$token,
				'run-approval-1',
				$plan,
				array_merge( $auth, array( 'user_id' => 8 ) )
			)
		);

		$evidence = $authority->consume( $token, 'run-approval-1', $plan, $auth );
		self::assertNotNull( $evidence );
		self::assertTrue( $evidence?->matches( $plan ) );
		self::assertSame( $token, $evidence?->reference() );
		self::assertNull( $authority->consume( $token, 'run-approval-1', $plan, $auth ), 'A consumed approval must not be replayable.' );
	}

	public function test_plan_binding_rejects_a_token_for_a_different_input(): void {
		$authority = new WorkflowApprovalAuthority();
		$plan      = $this->plan( '{"brief":"Original plan"}' );
		$other     = $this->plan( '{"brief":"Different plan"}' );
		$auth      = array(
			'user_id'   => 7,
			'client_id' => 'client-1',
			'provider'  => 'chatgpt',
		);
		$token     = $authority->issue( 'run-approval-2', $plan, $auth );

		self::assertNull( $authority->consume( $token, 'run-approval-2', $other, $auth ) );
		self::assertNotNull( $authority->consume( $token, 'run-approval-2', $plan, $auth ) );
	}

	private function plan( string $input_json ): WorkflowPlan {
		$path = dirname( __DIR__, 3 ) . '/fixtures/workflows/definitions/ordered-multi-step-v1.json';
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Repository-owned fixture.
		self::assertIsString( $json );

		return ( new WorkflowPlanBuilder() )->build( WorkflowDefinition::from_json( $json ), WorkflowInputContract::from_json( $input_json ) );
	}
}
