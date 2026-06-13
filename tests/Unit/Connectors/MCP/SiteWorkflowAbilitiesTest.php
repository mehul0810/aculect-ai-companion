<?php
/**
 * Tests for MCP site workflow abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Connectors\MCP\SiteWorkflowAbilities;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';

/**
 * Verifies read-only site audit workflow output.
 */
final class SiteWorkflowAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']          = array(
			'permalink_structure' => '/%postname%/',
			'active_plugins'      => array(),
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps']      = array();
		$GLOBALS['aculect_ai_companion_test_using_https']      = true;
		$GLOBALS['aculect_ai_companion_test_environment_type'] = 'production';
		$GLOBALS['aculect_ai_companion_test_theme']            = array(
			'Name'       => 'Twenty Twenty-Six',
			'Version'    => '1.0.0',
			'Stylesheet' => 'twentytwentysix',
			'Template'   => 'twentytwentysix',
		);
		$GLOBALS['aculect_ai_companion_test_core_updates']     = array();
		$GLOBALS['aculect_ai_companion_test_plugin_updates']   = array();
		$GLOBALS['aculect_ai_companion_test_theme_updates']    = array();
		$GLOBALS['aculect_ai_companion_test_cron_array']       = array( time() + HOUR_IN_SECONDS => array( 'example_hook' => array() ) );
		$GLOBALS['aculect_ai_companion_test_current_user_id']  = 1;
		$GLOBALS['aculect_ai_companion_test_users']            = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
	}

	public function test_site_audit_returns_healthy_bounded_findings(): void {
		$result = ( new SiteWorkflowAbilities() )->audit();

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'site_workflow_audit', $result['workflow'] );
		self::assertSame( 'healthy', $result['summary']['overall_severity'] );
		self::assertSame( 0, $result['summary']['counts']['critical'] );
		self::assertSame( 0, $result['summary']['counts']['warning'] );
		self::assertCount( 8, $result['findings'] );
		self::assertSame( array( 'site_get_health' ), array_column( $result['next_actions'], 'tool' ) );
		self::assertArrayHasKey( 'site_get_info', $result['operation_entries'] );
		self::assertArrayHasKey( 'site_get_health', $result['operation_entries'] );
	}

	public function test_site_audit_reports_warning_findings(): void {
		$GLOBALS['aculect_ai_companion_test_options']['permalink_structure'] = '';
		$GLOBALS['aculect_ai_companion_test_environment_type']               = 'staging';
		$GLOBALS['aculect_ai_companion_test_plugin_updates']                 = array( 'akismet/akismet.php' => (object) array() );
		$GLOBALS['aculect_ai_companion_test_cron_array']                     = array();

		$result       = ( new SiteWorkflowAbilities() )->audit();
		$findings     = array_column( $result['findings'], null, 'id' );
		$next_actions = array_column( $result['next_actions'], null, 'finding' );

		self::assertSame( 'warning', $result['summary']['overall_severity'] );
		self::assertSame( 'warning', $findings['permalinks']['severity'] );
		self::assertSame( 'warning', $findings['environment']['severity'] );
		self::assertSame( 'warning', $findings['updates']['severity'] );
		self::assertSame( 'warning', $findings['cron']['severity'] );
		self::assertSame( 'site_get_settings', $next_actions['permalinks']['tool'] );
		self::assertSame( 1, $findings['updates']['evidence']['plugins'] );
	}

	public function test_site_audit_reports_critical_findings(): void {
		$GLOBALS['aculect_ai_companion_test_using_https'] = false;
		$GLOBALS['aculect_ai_companion_test_theme']       = array(
			'Name'       => '',
			'Version'    => '',
			'Stylesheet' => '',
			'Template'   => '',
		);

		$result   = ( new SiteWorkflowAbilities() )->audit();
		$findings = array_column( $result['findings'], null, 'id' );

		self::assertSame( 'critical', $result['summary']['overall_severity'] );
		self::assertSame( 'critical', $findings['https']['severity'] );
		self::assertSame( 'critical', $findings['active_theme']['severity'] );
		self::assertSame( 'site_get_info', $findings['https']['required_tool'] );
		self::assertSame( 'site_list_themes', $findings['active_theme']['required_tool'] );
	}

	public function test_site_audit_requires_manage_options(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );

		$result = ( new SiteWorkflowAbilities() )->audit();

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_site_audit_is_derived_from_read_only_site_dependencies(): void {
		$registry   = new AbilitiesRegistry();
		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 1, $registry );

		self::assertArrayHasKey( 'site_audit', $operations['workflows'] );
		self::assertSame( 'site_workflow_audit', $operations['workflows']['site_audit']['tool'] );
		self::assertTrue( $operations['workflows']['site_audit']['read_only'] );
		self::assertTrue( $operations['workflows']['site_audit']['derived'] );
		self::assertSame( array( 'site.get_info', 'site.get_health' ), $operations['workflows']['site_audit']['dependency_ids'] );
		self::assertSame( array( 'site_get_info', 'site_get_health' ), $operations['workflows']['site_audit']['dependency_tools'] );
	}

	public function test_site_audit_descriptor_and_output_schema_are_exposed(): void {
		$tools   = ( new McpController() )->tool_manifest_for_current_user();
		$by_name = array_column( $tools['tools'], null, 'name' );

		self::assertArrayHasKey( 'site_workflow_audit', $by_name );
		self::assertTrue( $by_name['site_workflow_audit']['annotations']['readOnlyHint'] );
		self::assertSame( 'object', $by_name['site_workflow_audit']['inputSchema']['type'] );
		self::assertArrayHasKey( 'findings', $by_name['site_workflow_audit']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'summary', $by_name['site_workflow_audit']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'operation_entries', $by_name['site_workflow_audit']['outputSchema']['properties'] );
	}
}
