<?php
/**
 * Tests for internal Aculect intelligence MCP tools.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\IntelligenceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies always-on intelligence tools stay separate from user-managed abilities.
 */
final class IntelligenceRegistryTest extends TestCase {

	private IntelligenceRegistry $registry;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']         = array(
			'blogname'        => 'Aculect Test Site',
			'blogdescription' => 'A test site for intelligence context.',
		);
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_users']           = array();
		$this->registry                                       = new IntelligenceRegistry();
		$this->register_test_blocks();
	}

	public function test_intelligence_tools_are_claude_safe_and_scoped(): void {
		$read_only = array(
			'intelligence.capabilities.get_directory',
			'intelligence.site.get_context',
			'intelligence.content.get_context',
			'intelligence.developer.get_context',
			'intelligence.brand.get_context',
			'intelligence.blocks.list_available',
			'intelligence.blocks.get_info',
			'intelligence.patterns.list_available',
			'intelligence.patterns.get_info',
			'intelligence.content.validate_blocks',
		);

		foreach ( $read_only as $id ) {
			$module    = $this->registry->module( $id );
			$tool_name = $this->registry->tool_name( $id );

			self::assertNotNull( $module );
			self::assertSame( 'Aculect Intelligence', $module->group() );
			self::assertSame( array( 'content:read' ), $module->required_scopes() );
			self::assertTrue( $module->is_read_only() );
			self::assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/', $tool_name );
			self::assertSame( $id, $this->registry->internal_id( $tool_name ) );
		}

		$feedback = $this->registry->module( 'intelligence.feedback.submit' );
		self::assertNotNull( $feedback );
		self::assertFalse( $feedback->is_read_only() );
		self::assertSame( array( 'content:read' ), $feedback->required_scopes() );
		self::assertSame( 'intelligence.feedback.submit', $this->registry->internal_id( 'intelligence_feedback_submit' ) );
		self::assertSame( 'intelligence.capabilities.get_directory', $this->registry->internal_id( 'intelligence_capabilities_get_directory' ) );

		$incident_reporter = $this->registry->module( 'plugin.incident.report' );
		self::assertNotNull( $incident_reporter );
		self::assertSame( 'Aculect Intelligence', $incident_reporter->group() );
		self::assertSame( array( 'content:read' ), $incident_reporter->required_scopes() );
		self::assertFalse( $incident_reporter->is_read_only() );
		self::assertSame( 'plugin.incident.report', $this->registry->internal_id( 'plugin_incident_report' ) );
		self::assertSame( 'plugin.incident.report', $this->registry->internal_id( 'plugin_issue_report' ) );
		self::assertTrue( $this->registry->module( 'plugin.incident.list' )->is_read_only() );
	}

	public function test_intelligence_aliases_preserve_cached_client_calls(): void {
		self::assertSame( 'intelligence.brand.get_context', $this->registry->internal_id( 'brand_get_profile' ) );
		self::assertSame( 'intelligence.blocks.list_available', $this->registry->internal_id( 'blocks.list_available' ) );
		self::assertSame( 'intelligence.patterns.get_info', $this->registry->internal_id( 'patterns_get_info' ) );
		self::assertSame( 'intelligence.content.validate_blocks', $this->registry->internal_id( 'content_validate_blocks' ) );
	}

	public function test_block_validation_schema_bounds_serialized_content(): void {
		$schema = $this->registry->input_schema( 'intelligence.content.validate_blocks' );

		self::assertSame( 300000, $schema['properties']['content']['maxLength'] ?? null );
	}

	public function test_intelligence_tools_are_not_user_managed_abilities(): void {
		$abilities = new AbilitiesRegistry();

		foreach ( array_keys( $this->registry->modules() ) as $id ) {
			self::assertFalse( $abilities->is_known( $id ) );
		}

		self::assertFalse( $abilities->is_known( 'brand_get_profile' ) );
		self::assertFalse( $abilities->is_known( 'blocks_list_available' ) );
	}

	public function test_intelligence_execution_returns_context_and_block_guardrails(): void {
		$site = $this->registry->execute( 'intelligence.site.get_context', array() );

		self::assertSame( 'site', $site['type'] );
		self::assertSame( 'Aculect Test Site', $site['site']['name'] );
		self::assertFalse( $site['connector']['managed_as_user_ability'] );
		self::assertContains( 'core/html', $site['guidance']['never_use'] );
		self::assertSame( 'intelligence_feedback_submit', $site['learning_protocol']['feedback_tool'] );
		self::assertTrue( $site['learning_protocol']['admin_review_required'] );

		$brand = $this->registry->execute( 'brand_get_profile', array() );

		self::assertSame( 'brand', $brand['type'] );
		self::assertSame( 'Aculect Test Site', $brand['profile']['site']['name']['value'] );

		$validation = $this->registry->execute(
			'content_validate_blocks',
			array(
				'content' => '<!-- wp:html --><div>Raw</div><!-- /wp:html -->',
			)
		);

		self::assertFalse( $validation['valid'] );
		self::assertContains( 'core/html', $validation['content_guidance']['never_use'] );
	}

	public function test_feedback_tool_queues_review_suggestion_with_source_context(): void {
		$result = $this->registry->execute(
			'intelligence_feedback_submit',
			array(
				'domain'           => 'brand',
				'issue'            => 'Generated copy missed the saved tone.',
				'suggested_update' => 'Prefer the saved brand tone before inference.',
				'confidence'       => 'high',
			),
			array(
				'provider'    => 'chatgpt',
				'client_id'   => 'client-1',
				'client_name' => 'ChatGPT',
				'user_id'     => 3,
			)
		);

		self::assertSame( 'queued', $result['status'] );
		self::assertSame( 'brand', $result['suggestion']['domain'] );
		self::assertSame( 'chatgpt', $result['suggestion']['source']['provider'] );
		self::assertFalse( $result['review_status']['updates_memory'] );
	}

	public function test_plugin_incident_report_stores_local_report_and_prepares_public_github_issue_draft(): void {
		$result = $this->registry->execute(
			'plugin_incident_report',
			array(
				'title'              => 'ChatGPT cannot complete content workflow',
				'summary'            => 'The assistant failed after block validation and did not know how to recover.',
				'correlation_id'     => 'mcp-call-123',
				'client_name'        => 'ChatGPT',
				'workflow'           => 'Long-form draft creation',
				'tool_name'          => 'content_workflow_create_draft',
				'category'           => 'compatibility',
				'severity'           => 'high',
				'activity_id'        => 77,
				'steps_to_reproduce' => array(
					'Connect the WordPress MCP server.',
					'Ask the assistant to create a long-form draft.',
					'Observe the failed recovery path.',
				),
				'recovery_attempts'  => array(
					'Called the workflow guide and retried block validation.',
				),
				'actual_behavior'    => 'The assistant stopped instead of using the workflow guide.',
				'evidence'           => 'No secrets here.',
			),
			array(
				'provider'    => 'chatgpt',
				'client_name' => 'ChatGPT',
			)
		);

		self::assertSame( 'stored_ready_for_client_submission', $result['status'] );
		self::assertFalse( $result['can_create_direct'] );
		self::assertNotEmpty( $result['report_id'] );
		self::assertSame( 'mcp-call-123', $result['correlation_id'] );
		self::assertSame( 'mehul0810/aculect-ai-companion', $result['repository'] );
		self::assertSame( 'ChatGPT cannot complete content workflow', $result['title'] );
		self::assertStringStartsWith( 'https://github.com/mehul0810/aculect-ai-companion/issues/new?', $result['issue_url'] );
		self::assertStringContainsString( '## Summary', $result['body'] );
		self::assertStringContainsString( '- Correlation ID: mcp-call-123', $result['body'] );
		self::assertStringContainsString( '- Client: ChatGPT', $result['body'] );
		self::assertStringContainsString( '- Tool: content_workflow_create_draft', $result['body'] );
		self::assertStringContainsString( '- Category: compatibility', $result['body'] );
		self::assertStringContainsString( '- Severity: high', $result['body'] );
		self::assertStringContainsString( '## Recovery attempted', $result['body'] );
		self::assertStringContainsString( 'Generated by the Aculect AI Companion MCP incident reporter', $result['body'] );
		self::assertStringNotContainsString( 'client_secret', (string) wp_json_encode( $result ) );
		self::assertStringContainsString( 'GitHub connector', $result['next_actions'][0] );
		self::assertSame( 'compatibility', $result['incident']['category'] );
		self::assertSame( 'high', $result['incident']['severity'] );

		$list = $this->registry->execute( 'plugin_incident_list', array() );
		self::assertSame( 1, $list['total'] );
		self::assertSame( $result['report_id'], $list['items'][0]['id'] );
		self::assertArrayNotHasKey( 'body', $list['items'][0]['github'] );
	}

	public function test_plugin_incident_report_dry_run_prepares_sanitized_draft_without_storage(): void {
		$result = $this->registry->execute(
			'plugin_incident_report',
			array(
				'title'   => 'Preview an incident report',
				'summary' => 'The preview must not create a local incident report.',
				'dry_run' => true,
			),
			array(
				'provider'    => 'chatgpt',
				'client_name' => 'ChatGPT',
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertTrue( $result['dry_run'] );
		self::assertStringContainsString( '## Summary', $result['body'] );
		self::assertArrayNotHasKey( 'id', $result['incident'] );
		self::assertStringStartsWith( 'air_', $result['incident']['correlation_id'] );
		self::assertSame( array(), get_option( 'aculect_ai_companion_incident_reports', array() ) );
	}

	public function test_capability_directory_summarizes_abilities_workflows_and_intelligence(): void {
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
		$GLOBALS['aculect_ai_companion_test_users'][7]        = (object) array(
			'roles' => array( 'editor' ),
		);

		$summary = $this->registry->execute( 'intelligence_capabilities_get_directory', array() );

		self::assertSame( 'capability_directory', $summary['type'] );
		self::assertSame( 'summary', $summary['detail'] );
		self::assertGreaterThan( 0, $summary['summary']['available_regular_tools'] );
		self::assertGreaterThan( 0, $summary['summary']['blocked_regular_tools'] );
		self::assertSame( 'Content', $summary['regular_abilities'][0]['label'] );
		self::assertSame( 'Guided Workflows', $summary['workflows']['label'] );
		self::assertSame( 'Workflow Guides', $summary['workflow_guides']['label'] );
		self::assertContains( 'workflow_guides_list', $summary['workflow_guides']['available_tools'] );
		self::assertGreaterThan( 0, $summary['summary']['available_guide_tools'] );
		self::assertContains( 'intelligence_site_get_context', array_column( $summary['intelligence']['context_tools'], 'tool' ) );
		self::assertContains( 'intelligence_content_validate_blocks', array_column( $summary['intelligence']['knowledge_tools'], 'tool' ) );
		self::assertContains( 'plugin_incident_report', array_column( $summary['intelligence']['knowledge_tools'], 'tool' ) );
		self::assertContains( 'plugin_incident_list', array_column( $summary['intelligence']['knowledge_tools'], 'tool' ) );
		self::assertArrayHasKey( 'role_default_read_only', $summary['blocked_capabilities']['counts_by_reason'] );
		self::assertStringContainsString( 'admin review', $summary['intelligence']['learning']['write_policy'] );
		self::assertSame( 'plugin_incident_report', $summary['intelligence']['learning']['plugin_incident_tool'] );
		self::assertSame( 'plugin_incident_list', $summary['intelligence']['learning']['incident_tools']['list'] );
		self::assertFalse( $summary['safety']['secrets_included'] );
		self::assertStringNotContainsString( 'client_secret', (string) wp_json_encode( $summary ) );

		$full = $this->registry->execute( 'intelligence_capabilities_get_directory', array( 'detail' => 'full' ) );

		self::assertSame( 'full', $full['detail'] );
		self::assertArrayHasKey( 'entries', $full['regular_abilities'][0] );
		self::assertArrayHasKey( 'required_scopes', $full['regular_abilities'][0]['entries'][0] );
	}

	private function register_test_blocks(): void {
		\WP_Block_Type_Registry::get_instance()->unregister_all();
		\WP_Block_Type_Registry::get_instance()->register(
			'core/html',
			array(
				'title'       => 'Custom HTML',
				'description' => 'Raw HTML block.',
				'category'    => 'widgets',
			)
		);
	}
}
