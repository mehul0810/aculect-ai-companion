<?php
/**
 * Tests for custom workflow admin registration.
 *
 * @package Aculect\AICompanion\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\WorkflowAdminPage;
use Aculect\AICompanion\Admin\WorkflowAdminService;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinition;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRecord;
use Aculect\AICompanion\Workflows\Definitions\WorkflowDefinitionRepositoryInterface;
use Aculect\AICompanion\Workflows\Execution\WorkflowAuditStoreInterface;
use PHPUnit\Framework\TestCase;

/** Verifies the page is registered under the existing AI Companion settings area. */
final class WorkflowAdminPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_admin_pages'] = array(
			'menu'    => array(),
			'options' => array(),
			'submenu' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_hooks']       = array(
			'actions' => array(),
			'filters' => array(),
		);
	}

	public function test_registers_guided_workflow_page_and_mutation_actions(): void {
		( new WorkflowAdminPage() )->register();

		self::assertCount( 1, $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'] );
		self::assertSame( 'options-general.php', $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'][0]['parent_slug'] );
		self::assertSame( 'aculect-ai-companion-workflows', $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'][0]['menu_slug'] );
		self::assertSame( 'manage_options', $GLOBALS['aculect_ai_companion_test_admin_pages']['submenu'][0]['capability'] );

		$actions = array_column( $GLOBALS['aculect_ai_companion_test_hooks']['actions'], 'hook_name' );
		self::assertContains( 'admin_post_aculect_ai_companion_save_workflow', $actions );
		self::assertContains( 'admin_post_aculect_ai_companion_disable_workflow', $actions );
	}

	public function test_renders_a_json_rehydrated_workflow_record(): void {
		$service    = new WorkflowAdminService();
		$fields     = array(
			'workflow_id'    => 'rehydrated_workflow',
			'template_id'    => 'blank',
			'name'           => 'Rehydrated workflow',
			'description'    => 'Render a persisted workflow record.',
			'target_mode'    => 'existing',
			'post_types'     => 'post',
			'input_fields'   => "title:string:required\npost_id:integer:required",
			'step_abilities' => 'content/get-item',
			'step_arguments' => '{"step_1":{"id":"{{input.post_id}}"}}',
			'write_policy'   => 'proposal_only',
			'status'         => 'draft',
		);
		$definition = WorkflowDefinition::from_json( $service->definition_from_input( $fields, 7 )->canonical_json() );
		$record     = new WorkflowDefinitionRecord(
			1,
			'rehydrated_workflow',
			'draft',
			1,
			0,
			'blank',
			1,
			7,
			7,
			1,
			'2026-08-29 00:00:00',
			'2026-08-29 00:00:00',
			$definition,
			array( 'deleted_role' )
		);
		$repository = $this->createMock( WorkflowDefinitionRepositoryInterface::class );
		$repository->method( 'list' )->willReturn( array( $record ) );
		$repository->method( 'get' )->willReturn( $record );
		$audit = $this->createMock( WorkflowAuditStoreInterface::class );
		$audit->method( 'recent' )->willReturn( array() );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test controls the read-only workflow selection for render coverage.
		$previous_get = $_GET;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Test controls the read-only workflow selection for render coverage.
		$_GET = array( 'workflow_id' => 'rehydrated_workflow' );
		ob_start();
		try {
			( new WorkflowAdminPage( new WorkflowAdminService( $repository, null, null, $audit ) ) )->render();
			$html = (string) ob_get_contents();
		} finally {
			ob_end_clean();
			$_GET = $previous_get;
		}

		self::assertStringContainsString( 'id="aculect-workflow-template"', $html );
		self::assertStringContainsString( ">title:string:required\npost_id:integer:required</textarea>", $html );
		self::assertStringContainsString( '>content/get-item</textarea>', $html );
		self::assertStringContainsString( 'value="proposal_only" selected', $html );
		self::assertStringContainsString( 'name="allowed_roles_present" value="1"', $html );
		self::assertStringContainsString( 'Unregistered role: deleted_role (remove to resolve)', $html );
		self::assertStringContainsString( '"custom_post_type_creation":{"name":"Custom post type content creation"', $html );
		self::assertStringContainsString( '"step_arguments":"{}"', $html );
	}
}
