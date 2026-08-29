<?php
/**
 * Tests for custom workflow admin registration.
 *
 * @package Aculect\AICompanion\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\WorkflowAdminPage;
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
}
