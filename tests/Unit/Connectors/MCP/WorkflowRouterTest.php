<?php
/**
 * Tests for MCP workflow request routing.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\WorkflowRouter;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the deterministic first-step workflow router.
 */
final class WorkflowRouterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
	}

	public function test_routes_visual_page_requests_to_layout_aware_content_workflow(): void {
		$result = ( new WorkflowRouter() )->route(
			array(
				'request'                  => 'Create a page from the attached screenshot with a hero, columns, and feature cards.',
				'visual_reference_summary' => 'The image has a hero, three columns, cards, and a CTA.',
			)
		);

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'content_create', $result['intent'] );
		self::assertSame( 'visual_layout', $result['content_mode'] );
		self::assertSame( 'page', $result['post_type'] );
		self::assertSame( 'content_long_form_draft', $result['workflow_guide_id'] );
		self::assertSame( 'intelligence_content_get_context', $result['next_tool'] );
		self::assertContains( 'intelligence_blocks_list_available', $result['recommended_sequence'] );
		self::assertSame( 'workflow_session_start', $result['workflow_session_plan']['start_tool'] );
		self::assertSame( 'visual_layout', $result['workflow_session_plan']['start_arguments']['content_mode'] );
	}

	public function test_routes_capability_discovery_to_directory(): void {
		$result = ( new WorkflowRouter() )->route(
			array(
				'user_goal' => 'What can you do on this WordPress site and which abilities are available?',
			)
		);

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'capability_discovery', $result['intent'] );
		self::assertSame( 'intelligence_capabilities_get_directory', $result['next_tool'] );
		self::assertSame( 'read_only', $result['risk_level'] );
		self::assertSame( array(), $result['blocked_operations'] );
	}

	public function test_routes_site_editor_requests_to_site_editor_intelligence(): void {
		$result = ( new WorkflowRouter() )->route(
			array(
				'request' => 'Review Appearance > Editor and tell me which header template part can be changed.',
			)
		);

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'site_editor', $result['intent'] );
		self::assertSame( 'site_editor_intelligence_review', $result['workflow_guide_id'] );
		self::assertSame( 'site_editor_get_context', $result['next_tool'] );
		self::assertContains( 'site_editor_list_template_parts', $result['recommended_sequence'] );
	}

	public function test_routes_admin_settings_requests_to_admin_menu_intelligence(): void {
		$result = ( new WorkflowRouter() )->route(
			array(
				'request' => 'Find the admin page for this plugin setting before changing it.',
			)
		);

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'admin_menu', $result['intent'] );
		self::assertSame( 'admin_menu_settings_review', $result['workflow_guide_id'] );
		self::assertSame( 'admin_menu_get_context', $result['next_tool'] );
		self::assertContains( 'admin_menu_get_navigation_target', $result['recommended_sequence'] );
	}
}
