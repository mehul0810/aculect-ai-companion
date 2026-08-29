<?php
/**
 * Tests for custom workflow MCP declarations and auth boundaries.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilityModuleContract;
use Aculect\AICompanion\Connectors\MCP\AbilityModuleFactory;
use Aculect\AICompanion\Connectors\MCP\Modules\CustomWorkflowAbilityModules;
use Aculect\AICompanion\Workflows\Connectors\WorkflowAbilityConnector;
use PHPUnit\Framework\TestCase;

/**
 * Keeps connector operations discoverable while preserving direct-call denial.
 */
final class CustomWorkflowAbilityModulesTest extends TestCase {

	public function test_custom_workflow_surface_has_nine_closed_modules(): void {
		$modules = ( new CustomWorkflowAbilityModules( new AbilityModuleFactory() ) )->all();

		self::assertSame(
			array(
				'content_workflow.list',
				'content_workflow.get',
				'content_workflow.prepare',
				'content_workflow.dry_run',
				'content_workflow.execute',
				'content_workflow.resume',
				'content_workflow.cancel',
				'content_workflow.status',
				'content_workflow.result',
			),
			array_keys( $modules )
		);

		AbilityModuleContract::validate( $modules );
		self::assertFalse( $modules['content_workflow.execute']->is_read_only() );
		self::assertFalse( $modules['content_workflow.cancel']->is_read_only() );
		self::assertSame( array( 'run_id', 'input' ), $modules['content_workflow.execute']->input_schema()['required'] ?? array() );
	}

	public function test_connector_fails_closed_when_called_without_gateway_auth(): void {
		$connector = new WorkflowAbilityConnector( auth_provider: static fn (): array => array() );

		$result = $connector->list_workflows( array() );

		self::assertSame( 'error', $result['status'] ?? null );
		self::assertSame( 'auth_unavailable', $result['error'] ?? null );
		self::assertTrue( (bool) ( $result['bounded'] ?? false ) );
	}
}
