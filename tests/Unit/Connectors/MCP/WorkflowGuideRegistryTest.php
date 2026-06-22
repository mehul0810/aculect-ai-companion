<?php
/**
 * Tests for MCP workflow guide discovery.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Connectors\MCP\WorkflowGuideRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies compact workflow guides stay bounded and policy-aware.
 */
final class WorkflowGuideRegistryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			7 => (object) array(
				'ID'           => 7,
				'roles'        => array( 'editor' ),
				'display_name' => 'Ed Editor',
				'user_login'   => 'ed',
			),
		);
		McpToolAvailability::set_current_granted_scopes( null );
	}

	public function test_list_guides_is_bounded_and_reports_always_on_workflow_operations(): void {
		$result = ( new WorkflowGuideRegistry() )->list_guides( array( 'detail' => 'summary' ) );

		self::assertTrue( $result['bounded'] );
		self::assertLessThanOrEqual( $result['max_guides'], $result['total'] );
		self::assertSame( 'summary', $result['context'] );

		$guides = array_column( $result['items'], null, 'id' );

		self::assertArrayHasKey( 'content_long_form_draft', $guides );
		self::assertTrue( $guides['content_long_form_draft']['available'] );
		self::assertSame( array(), $guides['content_long_form_draft']['missing_required_operations'] );
		self::assertSame( 'workflows.create_draft', $guides['content_long_form_draft']['required_operations'][1]['ref'] );
		self::assertTrue( $guides['content_long_form_draft']['required_operations'][1]['available'] );
	}

	public function test_available_only_filters_scope_blocked_guides(): void {
		McpToolAvailability::set_current_granted_scopes( array( 'content:read' ) );

		$result = ( new WorkflowGuideRegistry() )->list_guides(
			array(
				'category'       => 'content',
				'available_only' => true,
			)
		);

		self::assertSame( array(), $result['items'] );
		self::assertSame( 0, $result['total'] );
	}

	public function test_get_guide_returns_full_available_admin_steps(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$result = ( new WorkflowGuideRegistry() )->get_guide( array( 'id' => 'site_readiness_audit' ) );

		self::assertSame( 'site_readiness_audit', $result['id'] );
		self::assertTrue( $result['available'] );
		self::assertSame( array(), $result['missing_required_operations'] );
		self::assertSame( 'site_workflow_audit', $result['required_operations'][0]['tool'] );
		self::assertNotEmpty( $result['steps'] );
		self::assertStringContainsString( 'available tools', $result['next_actions'][0] );
	}

	public function test_site_management_and_troubleshooting_guides_are_compact_and_policy_aware(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$result = ( new WorkflowGuideRegistry() )->list_guides(
			array(
				'category' => 'site',
				'detail'   => 'summary',
			)
		);
		$guides = array_column( $result['items'], null, 'id' );

		self::assertArrayHasKey( 'site_management_planning', $guides );
		self::assertArrayHasKey( 'connector_troubleshooting', $guides );
		self::assertSame( 'medium', $guides['site_management_planning']['estimated_response_size'] );
		self::assertSame( 'workflow_guides_list', $guides['connector_troubleshooting']['required_operations'][0]['tool'] );
		self::assertTrue( $guides['connector_troubleshooting']['required_operations'][0]['available'] );
		self::assertLessThan( 12000, strlen( wp_json_encode( $result, JSON_UNESCAPED_SLASHES ) ) );
	}

	public function test_connector_troubleshooting_guide_distinguishes_blockers_without_unsafe_actions(): void {
		McpToolAvailability::set_current_granted_scopes( array( 'content:read' ) );

		$result = ( new WorkflowGuideRegistry() )->get_guide( array( 'id' => 'connector_troubleshooting' ) );
		$steps  = implode( ' ', $result['steps'] );

		self::assertSame( 'connector_troubleshooting', $result['id'] );
		self::assertTrue( $result['available'] );
		self::assertStringContainsString( 'missing scopes', $steps );
		self::assertStringContainsString( 'policy blocks separately from environment failures', $steps );
		self::assertStringContainsString( 'cached tool list remains stale', $steps );
		self::assertStringNotContainsString( 'modify files', $steps );
		self::assertStringNotContainsString( 'raw SQL', $steps );
		self::assertStringNotContainsString( 'arbitrary PHP', $steps );

		$optional = array_column( $result['optional_operations'], null, 'ref' );
		self::assertArrayHasKey( 'actions.discover', $optional );
		self::assertSame( 'wp_abilities_discover', $optional['actions.discover']['tool'] );
		self::assertTrue( $optional['actions.discover']['available'] );
		self::assertFalse( $optional['site_editor.refresh_context']['available'] );
		self::assertSame( 'oauth_scope', $optional['site_editor.refresh_context']['blocked_by'] );
		self::assertSame( array( 'content:draft' ), $optional['site_editor.refresh_context']['missing_scopes'] );
	}

	public function test_get_guide_reports_unknown_ids(): void {
		$result = ( new WorkflowGuideRegistry() )->get_guide( array( 'id' => 'missing' ) );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'guide_not_found', $result['error'] );
	}
}
