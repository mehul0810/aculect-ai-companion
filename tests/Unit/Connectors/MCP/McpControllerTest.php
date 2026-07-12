<?php
/**
 * Tests for MCP protocol responses that do not require a WordPress runtime.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use PHPUnit\Framework\TestCase;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AccessLockdown;
use Aculect\AICompanion\Connectors\MCP\IntelligenceContext;
use Aculect\AICompanion\Connectors\MCP\IntelligenceRegistry;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\UserAccessControl;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Verifies public MCP tool payloads remain compatible with assistant clients.
 */
final class McpControllerTest extends TestCase {

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

	public function test_tools_list_exposes_safe_public_tool_names(): void {
		$result = $this->list_tools_manifest();

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'tools', $result );
		self::assertIsArray( $result['tools'] );
		self::assertNotEmpty( $result['tools'] );

		$registry     = new AbilitiesRegistry();
		$intelligence = new IntelligenceRegistry();

		foreach ( $result['tools'] as $tool ) {
			self::assertIsArray( $tool );
			self::assertArrayHasKey( 'name', $tool );
			self::assertIsString( $tool['name'] );
			self::assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/', $tool['name'] );
			self::assertTrue( $registry->is_known( $tool['name'] ) || $intelligence->is_known( $tool['name'] ) );
		}

		$tools_by_name = array_column( $result['tools'], null, 'name' );
		self::assertFalse( $tools_by_name['intelligence_feedback_submit']['annotations']['readOnlyHint'] );
		self::assertFalse( $tools_by_name['plugin_incident_report']['annotations']['readOnlyHint'] );
		self::assertTrue( $tools_by_name['plugin_incident_report']['annotations']['openWorldHint'] );
		self::assertTrue( $tools_by_name['plugin_incident_list']['annotations']['readOnlyHint'] );
	}

	public function test_claude_tools_list_uses_claude_safe_tool_names(): void {
		$result = $this->list_tools_manifest();
		$names  = array_column( $result['tools'], 'name' );

		foreach ( $names as $name ) {
			self::assertIsString( $name );
			self::assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/', $name );
			self::assertStringNotContainsString( '.', $name );
			self::assertStringNotContainsString( '/', $name );
		}
	}

	public function test_tools_list_filters_write_tools_by_granted_oauth_scopes(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );

		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'scopes' => array( 'content:read' ),
			)
		);

		$result        = $this->invokePrivate( $controller, 'list_tools' );
		$tools_by_name = array_column( $result['tools'], null, 'name' );

		self::assertArrayHasKey( 'content_get_item', $tools_by_name );
		self::assertArrayNotHasKey( 'content_update_item', $tools_by_name );
	}

	public function test_tools_list_cursor_pages_aggregate_to_scope_aware_manifest(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array_keys( $registry->configurable_definitions() ) );

		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'scopes' => array( 'content:read', 'content:draft' ),
			)
		);

		$first_page = $this->invokePrivate( $controller, 'list_tools' );

		self::assertLessThanOrEqual( McpController::tools_page_size(), count( $first_page['tools'] ) );
		self::assertArrayHasKey( 'nextCursor', $first_page );

		$manifest_names = array_column( $controller->tool_manifest_for_user( 1, array( 'content:read', 'content:draft' ) )['tools'], 'name' );
		$paged_names    = array_column( $this->list_tools_manifest( $controller )['tools'], 'name' );

		self::assertSame( $manifest_names, $paged_names );
		self::assertGreaterThan( McpController::tools_page_size(), count( $paged_names ) );
		self::assertSame( count( $paged_names ), count( array_unique( $paged_names ) ) );
	}

	public function test_openai_chatgpt_codex_and_gemini_tool_descriptors_keep_mcp_security_contract(): void {
		$result           = $this->list_tools_manifest();
		$supported_scopes = Helpers::supported_scopes();

		foreach ( $result['tools'] as $tool ) {
			self::assertIsArray( $tool );
			foreach ( array( 'name', 'title', 'description', 'inputSchema', 'securitySchemes', '_meta', 'annotations' ) as $field ) {
				self::assertArrayHasKey( $field, $tool );
			}

			self::assertIsString( $tool['name'] );
			self::assertIsString( $tool['title'] );
			self::assertIsString( $tool['description'] );
			self::assertIsArray( $tool['inputSchema'] );
			self::assertSame( 'object', $tool['inputSchema']['type'] ?? null );
			self::assertArrayHasKey( 'properties', $tool['inputSchema'] );
			self::assertFalse( $tool['inputSchema']['additionalProperties'] ?? true, (string) $tool['name'] );

			self::assertIsArray( $tool['securitySchemes'] );
			self::assertNotEmpty( $tool['securitySchemes'] );
			self::assertIsArray( $tool['_meta'] );
			self::assertArrayHasKey( 'securitySchemes', $tool['_meta'] );
			self::assertSame( $tool['securitySchemes'], $tool['_meta']['securitySchemes'] );
			self::assertArrayHasKey( 'openai/toolInvocation/invoking', $tool['_meta'] );
			self::assertArrayHasKey( 'openai/toolInvocation/invoked', $tool['_meta'] );
			self::assertIsString( $tool['_meta']['openai/toolInvocation/invoking'] );
			self::assertIsString( $tool['_meta']['openai/toolInvocation/invoked'] );
			self::assertLessThanOrEqual( 64, strlen( $tool['_meta']['openai/toolInvocation/invoking'] ) );
			self::assertLessThanOrEqual( 64, strlen( $tool['_meta']['openai/toolInvocation/invoked'] ) );

			foreach ( $tool['securitySchemes'] as $scheme ) {
				self::assertIsArray( $scheme );
				self::assertSame( 'oauth2', $scheme['type'] ?? null );
				self::assertIsArray( $scheme['scopes'] ?? null );
				foreach ( $scheme['scopes'] as $scope ) {
					self::assertContains( $scope, $supported_scopes );
				}
			}

			self::assertIsArray( $tool['annotations'] );
			self::assertArrayHasKey( 'readOnlyHint', $tool['annotations'] );
			self::assertArrayHasKey( 'destructiveHint', $tool['annotations'] );
			self::assertArrayHasKey( 'idempotentHint', $tool['annotations'] );
			self::assertArrayHasKey( 'openWorldHint', $tool['annotations'] );
			self::assertIsBool( $tool['annotations']['readOnlyHint'] );
			self::assertIsBool( $tool['annotations']['destructiveHint'] );
			self::assertIsBool( $tool['annotations']['idempotentHint'] );
			self::assertIsBool( $tool['annotations']['openWorldHint'] );
		}

		$tools_by_name = array_column( $result['tools'], null, 'name' );
		self::assertTrue( $tools_by_name['media_delete_item']['annotations']['destructiveHint'] );
		self::assertTrue( $tools_by_name['content_create_item']['annotations']['openWorldHint'] );
		self::assertTrue( $tools_by_name['content_index_refresh_batch']['annotations']['idempotentHint'] );
		self::assertTrue( $tools_by_name['memory_bootstrap']['annotations']['idempotentHint'] );
	}

	public function test_initialize_payload_includes_chatgpt_workflow_instructions(): void {
		$result = $this->invokePrivate( new McpController(), 'initialize_payload' );

		self::assertSame( '2025-06-18', $result['protocolVersion'] );
		self::assertSame( 'Aculect AI Companion MCP', $result['serverInfo']['name'] );
		self::assertIsString( $result['instructions'] );
		self::assertStringContainsString( 'workflow_route_request', $result['instructions'] );
		self::assertStringContainsString( 'workflow_session_start', $result['instructions'] );
		self::assertStringContainsString( 'workflow_loop_run_next', $result['instructions'] );
		self::assertStringContainsString( 'workflow_loop_run_batch', $result['instructions'] );
		self::assertStringContainsString( 'resources/list', $result['instructions'] );
		self::assertStringContainsString( 'resources/read', $result['instructions'] );
		self::assertStringContainsString( 'intelligence_capabilities_get_directory', $result['instructions'] );
		self::assertStringContainsString( 'workflow_guides_list', $result['instructions'] );
		self::assertStringContainsString( 'workflow_guides_get', $result['instructions'] );
		self::assertStringContainsString( 'intelligence_site_get_context', $result['instructions'] );
		self::assertStringContainsString( 'intelligence_content_get_context', $result['instructions'] );
		self::assertStringContainsString( 'operations manifest', $result['instructions'] );
		self::assertStringContainsString( 'call search first', $result['instructions'] );
		self::assertStringContainsString( 'fetch with a returned ID', $result['instructions'] );
		self::assertStringContainsString( 'content_search_items', $result['instructions'] );
		self::assertStringContainsString( 'content_search_chunks', $result['instructions'] );
		self::assertStringContainsString( 'content_find_internal_links', $result['instructions'] );
		self::assertStringContainsString( 'memory_list', $result['instructions'] );
		self::assertStringContainsString( 'site_workflow_audit', $result['instructions'] );
		self::assertStringContainsString( 'memory_save', $result['instructions'] );
		self::assertStringContainsString( 'memory_bootstrap', $result['instructions'] );
		self::assertStringContainsString( 'admin review', $result['instructions'] );
		self::assertStringContainsString( 'content_workflow_prepare_post', $result['instructions'] );
		self::assertStringContainsString( 'content_workflow_create_draft', $result['instructions'] );
		self::assertStringContainsString( 'intelligence_feedback_submit', $result['instructions'] );
		self::assertStringContainsString( 'plugin_incident_report', $result['instructions'] );
		self::assertStringContainsString( 'mcp_learning_inspect_activity', $result['instructions'] );
		self::assertStringContainsString( 'Never use raw Custom HTML blocks', $result['instructions'] );
		self::assertArrayHasKey( 'tools', $result['capabilities'] );
		self::assertTrue( $result['capabilities']['tools']['listChanged'] );
		self::assertArrayHasKey( 'resources', $result['capabilities'] );
		self::assertTrue( $result['capabilities']['resources']['listChanged'] );
	}

	public function test_intelligence_tools_advertise_output_schemas(): void {
		$result = $this->list_tools_manifest();

		foreach ( $result['tools'] as $tool ) {
			$name = (string) ( $tool['name'] ?? '' );
			if ( ! str_starts_with( $name, 'intelligence_' ) ) {
				continue;
			}

			self::assertArrayHasKey( 'outputSchema', $tool, $name );
			self::assertSame( 'object', $tool['outputSchema']['type'] ?? null, $name );
			self::assertIsArray( $tool['outputSchema']['properties'] ?? null, $name );
			self::assertTrue( $tool['outputSchema']['additionalProperties'] ?? false, $name );
		}

		$tools_by_name = array_column( $result['tools'], null, 'name' );
		self::assertSame( array( 'status' ), $tools_by_name['intelligence_feedback_submit']['outputSchema']['required'] );
		self::assertSame( array( 'status' ), $tools_by_name['plugin_incident_report']['outputSchema']['required'] );
		self::assertArrayHasKey( 'report_id', $tools_by_name['plugin_incident_report']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'correlation_id', $tools_by_name['plugin_incident_report']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'issue_url', $tools_by_name['plugin_incident_report']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'can_create_direct', $tools_by_name['plugin_incident_report']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'incident', $tools_by_name['plugin_incident_report']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'items', $tools_by_name['plugin_incident_list']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'regular_abilities', $tools_by_name['intelligence_capabilities_get_directory']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'learning_protocol', $tools_by_name['intelligence_site_get_context']['outputSchema']['properties'] );
	}

	public function test_operational_and_workflow_tools_advertise_output_schemas(): void {
		$result        = $this->list_tools_manifest();
		$tools_by_name = array_column( $result['tools'], null, 'name' );

		self::assertArrayHasKey( 'outputSchema', $tools_by_name['search'] );
		self::assertArrayHasKey( 'results', $tools_by_name['search']['outputSchema']['properties'] );
		self::assertSame( array( 'results' ), $tools_by_name['search']['outputSchema']['required'] );
		self::assertFalse( $tools_by_name['search']['outputSchema']['additionalProperties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['fetch'] );
		self::assertArrayHasKey( 'id', $tools_by_name['fetch']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'title', $tools_by_name['fetch']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'text', $tools_by_name['fetch']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'url', $tools_by_name['fetch']['outputSchema']['properties'] );
		self::assertFalse( $tools_by_name['fetch']['outputSchema']['additionalProperties'] );

		foreach ( array( 'content_create_item', 'content_update_item', 'content_update_seo', 'content_workflow_create_draft', 'seo_workflow_update_rankmath' ) as $name ) {
			self::assertArrayHasKey( 'outputSchema', $tools_by_name[ $name ], $name );
			self::assertArrayHasKey( 'status', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
			self::assertArrayHasKey( 'post_id', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
			self::assertArrayHasKey( 'next_actions', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
			self::assertArrayHasKey( 'confirmation_policy', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
			self::assertArrayHasKey( 'write_permission_enabled', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
		}

		self::assertArrayHasKey( 'outputSchema', $tools_by_name['site_workflow_audit'] );
		self::assertArrayHasKey( 'findings', $tools_by_name['site_workflow_audit']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'summary', $tools_by_name['site_workflow_audit']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'operation_entries', $tools_by_name['site_workflow_audit']['outputSchema']['properties'] );

		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_list_items'] );
		self::assertArrayHasKey( 'items', $tools_by_name['content_list_items']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'total', $tools_by_name['content_list_items']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'per_page', $tools_by_name['content_list_items']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_search_chunks'] );
		self::assertArrayHasKey( 'items', $tools_by_name['content_search_chunks']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'index', $tools_by_name['content_search_chunks']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'visible_total', $tools_by_name['content_search_chunks']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'filtered_by_access', $tools_by_name['content_search_chunks']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_audit_internal_links'] );
		self::assertArrayHasKey( 'items', $tools_by_name['content_audit_internal_links']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'index', $tools_by_name['content_audit_internal_links']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'filtered_by_access', $tools_by_name['content_audit_internal_links']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'max_word_count', $tools_by_name['content_search_items']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_index_refresh_batch'] );
		self::assertArrayHasKey( 'job', $tools_by_name['content_index_refresh_batch']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['memory_bootstrap'] );
		self::assertArrayHasKey( 'review_status', $tools_by_name['memory_bootstrap']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'skipped', $tools_by_name['memory_bootstrap']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'intelligence_context', $tools_by_name['content_workflow_prepare_post']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_guides_list'] );
		self::assertArrayHasKey( 'items', $tools_by_name['workflow_guides_list']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'max_guides', $tools_by_name['workflow_guides_list']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_guides_get'] );
		self::assertArrayHasKey( 'required_operations', $tools_by_name['workflow_guides_get']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'missing_required_operations', $tools_by_name['workflow_guides_get']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_route_request'] );
		self::assertArrayHasKey( 'next_tool', $tools_by_name['workflow_route_request']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'workflow_session_plan', $tools_by_name['workflow_route_request']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_session_start'] );
		self::assertArrayHasKey( 'workflow_session', $tools_by_name['workflow_session_start']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_loop_create'] );
		self::assertArrayHasKey( 'workflow_loop', $tools_by_name['workflow_loop_create']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'items', $tools_by_name['workflow_loop_create']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'max_word_count', $tools_by_name['workflow_loop_create']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_loop_run_next'] );
		self::assertArrayHasKey( 'active_item', $tools_by_name['workflow_loop_run_next']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'resume', $tools_by_name['workflow_loop_run_next']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_loop_run_batch'] );
		self::assertArrayHasKey( 'items_to_process', $tools_by_name['workflow_loop_run_batch']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'completed_items', $tools_by_name['workflow_loop_run_batch']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_loop_pause'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_loop_cancel'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['mcp_learning_inspect_activity'] );
		self::assertArrayHasKey( 'insights', $tools_by_name['mcp_learning_inspect_activity']['outputSchema']['properties'] );
	}

	public function test_chatgpt_codex_claude_and_gemini_tools_prioritize_operational_tools_before_intelligence_tools(): void {
		$result = $this->list_tools_manifest();
		$names  = array_column( $result['tools'], 'name' );

		$critical_tools = array(
			'search',
			'fetch',
			'workflow_route_request',
			'workflow_session_start',
			'workflow_session_get',
			'workflow_session_update',
			'workflow_loop_create',
			'workflow_loop_get',
			'workflow_loop_run_next',
			'workflow_loop_run_batch',
			'workflow_loop_pause',
			'workflow_loop_cancel',
			'workflow_guides_list',
			'workflow_guides_get',
			'content_workflow_prepare_post',
			'content_workflow_create_draft',
			'content_workflow_update_post',
			'seo_workflow_update_rankmath',
			'site_workflow_audit',
			'content_index_refresh_batch',
			'content_search_items',
			'content_search_chunks',
			'content_find_related',
			'content_find_internal_links',
			'content_audit_internal_links',
			'memory_list',
			'memory_save',
			'memory_bootstrap',
			'mcp_learning_inspect_activity',
			'site_list_post_types',
			'content_list_items',
			'content_get_item',
			'content_create_item',
			'content_update_item',
			'content_update_seo',
			'taxonomy_list_taxonomies',
			'taxonomy_list_terms',
			'taxonomy_create_term',
			'taxonomy_update_term',
			'media_list_items',
			'media_get_item',
			'media_upload_item',
			'media_update_item',
		);

		foreach ( $critical_tools as $tool_name ) {
			self::assertContains( $tool_name, $names );
		}

		$first_intelligence_index = null;
		foreach ( $names as $index => $name ) {
			if ( is_string( $name ) && str_starts_with( $name, 'intelligence_' ) ) {
				$first_intelligence_index = $index;
				break;
			}
		}

		self::assertNotNull( $first_intelligence_index );
		foreach ( $critical_tools as $tool_name ) {
			$tool_index = array_search( $tool_name, $names, true );
			self::assertIsInt( $tool_index );
			self::assertLessThan( $first_intelligence_index, $tool_index );
		}
	}

	public function test_openai_chatgpt_codex_claude_and_gemini_input_schemas_use_client_safe_json_schema_subset(): void {
		$result = $this->list_tools_manifest();

		foreach ( $result['tools'] as $tool ) {
			self::assertIsArray( $tool );
			self::assertArrayHasKey( 'name', $tool );
			self::assertArrayHasKey( 'inputSchema', $tool );
			self::assertIsArray( $tool['inputSchema'] );
			self::assertFalse( $tool['inputSchema']['additionalProperties'] ?? true, (string) $tool['name'] . '.inputSchema must be closed at the top level' );

			$this->assertSchemaDoesNotContainCompositionKeywords( $tool['inputSchema'], (string) $tool['name'] . '.inputSchema' );
		}
	}

	public function test_intelligence_context_lists_operational_tool_names(): void {
		$site = ( new IntelligenceContext() )->site();

		self::assertSame( 'content_list_items', $site['operations']['content']['list_items']['tool'] );
		self::assertTrue( $site['operations']['content']['list_items']['available'] );
		self::assertSame( 'content_update_item', $site['operations']['content']['update']['tool'] );
		self::assertSame( 'content_update_seo', $site['operations']['content']['seo']['tool'] );
		self::assertSame( 'content_search_items', $site['operations']['intelligence_index']['search_items']['tool'] );
		self::assertSame( 'search', $site['operations']['intelligence_index']['canonical_search']['tool'] );
		self::assertSame( 'fetch', $site['operations']['intelligence_index']['canonical_fetch']['tool'] );
		self::assertSame( 'workflow_route_request', $site['operations']['workflows']['route_request']['tool'] );
		self::assertSame( 'workflow_guides_list', $site['operations']['workflow_guides']['list']['tool'] );
		self::assertSame( 'workflow_guides_get', $site['operations']['workflow_guides']['get']['tool'] );
		self::assertSame( 'workflow_session_start', $site['operations']['workflow_guides']['session_start']['tool'] );
		self::assertSame( 'workflow_session_get', $site['operations']['workflow_guides']['session_get']['tool'] );
		self::assertSame( 'workflow_session_update', $site['operations']['workflow_guides']['session_update']['tool'] );
		self::assertSame( 'content_find_internal_links', $site['operations']['intelligence_index']['internal_links']['tool'] );
		self::assertSame( 'content_audit_internal_links', $site['operations']['intelligence_index']['internal_link_audit']['tool'] );
		self::assertSame( 'memory_list', $site['operations']['intelligence_index']['memory_list']['tool'] );
		self::assertSame( 'memory_save', $site['operations']['intelligence_index']['memory_save']['tool'] );
		self::assertSame( 'memory_bootstrap', $site['operations']['intelligence_index']['memory_bootstrap']['tool'] );
		self::assertSame( 'mcp_learning_inspect_activity', $site['operations']['intelligence_index']['activity_learning']['tool'] );
		self::assertSame( 'media_upload_item', $site['operations']['media']['upload']['tool'] );
		self::assertSame( 'taxonomy_list_terms', $site['operations']['content_groups']['list_terms']['tool'] );
		self::assertSame( 'wp_abilities_run', $site['operations']['actions']['run']['tool'] );
	}

	public function test_input_schema_accepts_public_tool_name_aliases(): void {
		$dotted_schema = $this->schemaForTool( 'content.list_items' );
		$public_schema = $this->schemaForTool( 'content_list_items' );

		self::assertSame( $dotted_schema, $public_schema );
		self::assertSame( 'object', $public_schema['type'] );
		self::assertFalse( $public_schema['additionalProperties'] );
		self::assertArrayHasKey( 'post_type', $public_schema['properties'] );
		self::assertSame( 'array', $public_schema['properties']['status']['type'] );
		self::assertSame( 'string', $public_schema['properties']['status']['items']['type'] );
		self::assertSame( 1, $public_schema['properties']['page']['minimum'] );
		self::assertSame( 100, $public_schema['properties']['per_page']['maximum'] );
		self::assertArrayHasKey( 'context', $public_schema['properties'] );
		self::assertSame( array( 'compact', 'full' ), $public_schema['properties']['context']['enum'] );
	}

	public function test_expanded_tool_schemas_are_available(): void {
		$canonical_search_schema = $this->schemaForTool( 'search' );
		self::assertSame( 'object', $canonical_search_schema['type'] );
		self::assertSame( array( 'query' ), $canonical_search_schema['required'] );
		self::assertFalse( $canonical_search_schema['additionalProperties'] );

		$canonical_fetch_schema = $this->schemaForTool( 'fetch' );
		self::assertSame( 'object', $canonical_fetch_schema['type'] );
		self::assertSame( array( 'id' ), $canonical_fetch_schema['required'] );
		self::assertFalse( $canonical_fetch_schema['additionalProperties'] );

		$guide_list_schema = $this->schemaForTool( 'workflow_guides_list' );
		self::assertSame( 'object', $guide_list_schema['type'] );
		self::assertArrayHasKey( 'available_only', $guide_list_schema['properties'] );
		self::assertSame( array( 'summary', 'full' ), $guide_list_schema['properties']['detail']['enum'] );

		$guide_get_schema = $this->schemaForTool( 'workflow_guides_get' );
		self::assertSame( 'object', $guide_get_schema['type'] );
		self::assertSame( array( 'id' ), $guide_get_schema['required'] );

		$route_schema = $this->schemaForTool( 'workflow_route_request' );
		self::assertSame( 'object', $route_schema['type'] );
		self::assertFalse( $route_schema['additionalProperties'] );
		self::assertArrayHasKey( 'request', $route_schema['properties'] );
		self::assertArrayNotHasKey( 'required', $route_schema );

		$session_start_schema = $this->schemaForTool( 'workflow_session_start' );
		self::assertSame( 'object', $session_start_schema['type'] );
		self::assertArrayHasKey( 'workflow', $session_start_schema['properties'] );
		self::assertArrayHasKey( 'idempotency_key', $session_start_schema['properties'] );

		$session_get_schema = $this->schemaForTool( 'workflow_session_get' );
		self::assertArrayHasKey( 'workflow_session_id', $session_get_schema['properties'] );

		$learning_schema = $this->schemaForTool( 'mcp_learning_inspect_activity' );
		self::assertSame( 'object', $learning_schema['type'] );
		self::assertSame( array( 'error', 'success', 'all' ), $learning_schema['properties']['status']['enum'] );

		$media_schema = $this->schemaForTool( 'media_upload_item' );
		self::assertSame( 'object', $media_schema['type'] );
		self::assertFalse( $media_schema['additionalProperties'] );
		self::assertSame( array( 'url' ), $media_schema['required'] );
		self::assertSame( 'uri', $media_schema['properties']['url']['format'] );
		self::assertArrayHasKey( 'alt_text', $media_schema['properties'] );

		$media_get_schema = $this->schemaForTool( 'media_get_item' );
		self::assertSame( array( 'id' ), $media_get_schema['required'] );

		$media_update_schema = $this->schemaForTool( 'media_update_item' );
		self::assertSame( array( 'id' ), $media_update_schema['required'] );
		self::assertArrayHasKey( 'post_id', $media_update_schema['properties'] );

		$media_delete_schema = $this->schemaForTool( 'media_delete_item' );
		self::assertSame( array( 'id' ), $media_delete_schema['required'] );

		$media_rename_schema = $this->schemaForTool( 'media_rename_file' );
		self::assertSame( array( 'id', 'filename' ), $media_rename_schema['required'] );

		$create_schema = $this->schemaForTool( 'content_create_item' );
		self::assertArrayHasKey( 'featured_media', $create_schema['properties'] );

		$update_schema = $this->schemaForTool( 'content_update_item' );
		self::assertArrayHasKey( 'featured_media', $update_schema['properties'] );
		self::assertArrayHasKey( 'clear_featured_media', $update_schema['properties'] );

		$seo_schema = $this->schemaForTool( 'content_update_seo' );
		self::assertSame( array( 'id' ), $seo_schema['required'] );
		self::assertArrayHasKey( 'meta_title', $seo_schema['properties'] );
		self::assertArrayHasKey( 'meta_description', $seo_schema['properties'] );
		self::assertArrayHasKey( 'focus_keywords', $seo_schema['properties'] );
		self::assertSame( 'array', $seo_schema['properties']['focus_keywords']['type'] );
		self::assertSame( 'string', $seo_schema['properties']['focus_keywords']['items']['type'] );
		self::assertSame( 10, $seo_schema['properties']['focus_keywords']['maxItems'] );

		$seo_read_schema = $this->schemaForTool( 'content_get_seo' );
		self::assertSame( array( 'id' ), $seo_read_schema['required'] );
		self::assertArrayHasKey( 'plugin', $seo_read_schema['properties'] );
		self::assertArrayHasKey( 'source', $seo_read_schema['properties'] );
		self::assertSame( array( 'auto', 'yoast', 'rank_math' ), $seo_read_schema['properties']['plugin']['enum'] );

		$comments_schema = $this->schemaForTool( 'comments_update_item' );
		self::assertSame( array( 'id' ), $comments_schema['required'] );
		self::assertArrayHasKey( 'status', $comments_schema['properties'] );

		$comments_list_schema = $this->schemaForTool( 'comments_list_items' );
		self::assertArrayHasKey( 'date_after', $comments_list_schema['properties'] );
		self::assertArrayHasKey( 'author_user_id', $comments_list_schema['properties'] );
		self::assertArrayHasKey( 'context', $comments_list_schema['properties'] );

		$comments_create_schema = $this->schemaForTool( 'comments_create_item' );
		self::assertArrayHasKey( 'parent_id', $comments_create_schema['properties'] );

		$comments_bulk_schema = $this->schemaForTool( 'comments_bulk_update' );
		self::assertSame( array( 'ids', 'status' ), $comments_bulk_schema['required'] );

		$abilities_schema = $this->schemaForTool( 'wp_abilities_run' );
		self::assertSame( array( 'id' ), $abilities_schema['required'] );
		self::assertArrayHasKey( 'arguments', $abilities_schema['properties'] );

		$health_schema = $this->schemaForTool( 'site_get_health' );
		self::assertSame( 'object', $health_schema['type'] );
		self::assertFalse( $health_schema['additionalProperties'] );
		self::assertInstanceOf( \stdClass::class, $health_schema['properties'] );

		$brand_schema = $this->schemaForTool( 'intelligence_brand_get_context' );
		self::assertSame( 'object', $brand_schema['type'] );
		self::assertFalse( $brand_schema['additionalProperties'] );
		self::assertInstanceOf( \stdClass::class, $brand_schema['properties'] );

		$feedback_schema = $this->schemaForTool( 'intelligence_feedback_submit' );
		self::assertSame( 'object', $feedback_schema['type'] );
		self::assertSame( array( 'domain', 'issue', 'suggested_update' ), $feedback_schema['required'] );
		self::assertSame( array( 'site', 'content', 'developer', 'brand' ), $feedback_schema['properties']['domain']['enum'] );

		$create_schema = $this->schemaForTool( 'content_create_item' );
		self::assertArrayHasKey( 'author', $create_schema['properties'] );
		self::assertArrayHasKey( 'taxonomies', $create_schema['properties'] );
		self::assertIsArray( $create_schema['properties']['taxonomies']['additionalProperties'] );
		self::assertSame( 'array', $create_schema['properties']['taxonomies']['additionalProperties']['type'] );
		self::assertSame( 'integer', $create_schema['properties']['taxonomies']['additionalProperties']['items']['type'] );
		self::assertArrayHasKey( 'date', $create_schema['properties'] );
		self::assertSame( 300000, $create_schema['properties']['content']['maxLength'] );
		self::assertSame( array( 'draft', 'future', 'pending', 'private', 'publish', 'trash' ), $create_schema['properties']['status']['enum'] );

		$update_schema = $this->schemaForTool( 'content_update_item' );
		self::assertArrayHasKey( 'author', $update_schema['properties'] );
		self::assertArrayHasKey( 'taxonomies', $update_schema['properties'] );
		self::assertArrayHasKey( 'date', $update_schema['properties'] );
		self::assertSame( array( 'draft', 'future', 'pending', 'private', 'publish', 'trash' ), $update_schema['properties']['status']['enum'] );

		$term_image_schema = $this->schemaForTool( 'taxonomy_set_term_image' );
		self::assertSame( array( 'taxonomy', 'term_id' ), $term_image_schema['required'] );
		self::assertArrayHasKey( 'image_id', $term_image_schema['properties'] );
		self::assertArrayHasKey( 'clear_image', $term_image_schema['properties'] );

		$workflow_prepare_schema = $this->schemaForTool( 'content_workflow_prepare_post' );
		self::assertSame( array( 'brief' ), $workflow_prepare_schema['required'] );
		self::assertArrayHasKey( 'desired_word_count', $workflow_prepare_schema['properties'] );
		self::assertSame( 3000, $workflow_prepare_schema['properties']['desired_word_count']['minimum'] );
		self::assertSame( 5000, $workflow_prepare_schema['properties']['desired_word_count']['maximum'] );
		self::assertArrayHasKey( 'content_mode', $workflow_prepare_schema['properties'] );
		self::assertArrayHasKey( 'layout_intent', $workflow_prepare_schema['properties'] );
		self::assertArrayHasKey( 'visual_reference_summary', $workflow_prepare_schema['properties'] );
		self::assertContains( 'visual_layout', $workflow_prepare_schema['properties']['content_mode']['enum'] );

		$workflow_create_schema = $this->schemaForTool( 'content_workflow_create_draft' );
		self::assertSame( array( 'title', 'content' ), $workflow_create_schema['required'] );
		self::assertArrayHasKey( 'meta_title', $workflow_create_schema['properties'] );
		self::assertArrayHasKey( 'dry_run', $workflow_create_schema['properties'] );
		self::assertSame( 'array', $workflow_create_schema['properties']['focus_keywords']['type'] );
		self::assertArrayHasKey( 'expected_block_families', $workflow_create_schema['properties'] );

		$workflow_update_schema = $this->schemaForTool( 'content_workflow_update_post' );
		self::assertSame( array( 'id' ), $workflow_update_schema['required'] );
		self::assertArrayHasKey( 'section_map', $workflow_update_schema['properties'] );
		self::assertArrayHasKey( 'status', $workflow_update_schema['properties'] );
		self::assertIsArray( $workflow_update_schema['properties']['section_map']['additionalProperties'] );
		self::assertSame( 'object', $workflow_update_schema['properties']['section_map']['additionalProperties']['type'] );
		self::assertSame( array( 'content' ), $workflow_update_schema['properties']['section_map']['additionalProperties']['required'] );
		self::assertFalse( $workflow_update_schema['properties']['section_map']['additionalProperties']['additionalProperties'] );

		$rankmath_workflow_schema = $this->schemaForTool( 'seo_workflow_update_rankmath' );
		self::assertSame( array( 'id' ), $rankmath_workflow_schema['required'] );
		self::assertArrayHasKey( 'focus_keywords', $rankmath_workflow_schema['properties'] );
		self::assertSame( 'array', $rankmath_workflow_schema['properties']['focus_keywords']['type'] );
	}

	public function test_write_tool_schemas_include_safety_controls(): void {
		$write_schema = $this->schemaForTool( 'content_update_item' );
		self::assertArrayHasKey( 'dry_run', $write_schema['properties'] );
		self::assertArrayHasKey( 'confirmation_token', $write_schema['properties'] );

		$read_schema = $this->schemaForTool( 'content_get_item' );
		self::assertArrayNotHasKey( 'dry_run', $read_schema['properties'] );
		self::assertArrayNotHasKey( 'confirmation_token', $read_schema['properties'] );
	}

	/**
	 * Resolve a tool input schema from the module registry.
	 *
	 * @param string $tool Internal ID, legacy alias, or public tool name.
	 * @return array<string, mixed>
	 */
	private function schemaForTool( string $tool ): array {
		$intelligence = new IntelligenceRegistry();
		if ( $intelligence->is_known( $tool ) ) {
			return $intelligence->input_schema( $tool );
		}

		return ( new AbilitiesRegistry() )->input_schema( $tool );
	}

	/**
	 * Assert a schema avoids composition keywords that some MCP clients drop silently.
	 *
	 * @param array<string, mixed> $schema Schema fragment.
	 * @param string               $path   Debug path for assertion failures.
	 */
	private function assertSchemaDoesNotContainCompositionKeywords( array $schema, string $path ): void {
		foreach ( array( 'oneOf', 'anyOf', 'allOf' ) as $keyword ) {
			self::assertArrayNotHasKey( $keyword, $schema, $path . ' must not contain ' . $keyword );
		}

		foreach ( $schema as $key => $value ) {
			if ( is_array( $value ) ) {
				$this->assertSchemaDoesNotContainCompositionKeywords( $value, $path . '.' . (string) $key );
			}
		}
	}

	public function test_global_pause_blocks_tool_calls(): void {
		$controller = new McpController();

		self::assertFalse( $this->invokePrivate( $controller, 'is_access_paused' ) );

		AccessLockdown::set_paused( true );

		self::assertTrue( $this->invokePrivate( $controller, 'is_access_paused' ) );
	}

	public function test_user_pause_blocks_only_matching_user_tool_calls(): void {
		$controller = new McpController();

		UserAccessControl::set_paused( 7, true );

		self::assertTrue( $this->invokePrivate( $controller, 'is_access_paused', array( 7 ) ) );
		self::assertFalse( $this->invokePrivate( $controller, 'is_access_paused', array( 12 ) ) );
	}

	public function test_disabled_tools_are_not_listed_and_are_blocked_for_cached_clients(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.list_items' ) );

		$result = $this->list_tools_manifest();
		$names  = array_column( $result['tools'], 'name' );

		self::assertContains( 'content_list_items', $names );
		self::assertContains( 'intelligence_site_get_context', $names );
		self::assertContains( 'intelligence_brand_get_context', $names );
		self::assertContains( 'intelligence_blocks_list_available', $names );
		self::assertContains( 'intelligence_feedback_submit', $names );
		self::assertContains( 'plugin_incident_report', $names );
		self::assertContains( 'plugin_incident_list', $names );
		self::assertContains( 'memory_list', $names );
		self::assertContains( 'memory_save', $names );
		self::assertContains( 'memory_bootstrap', $names );
		self::assertContains( 'workflow_guides_list', $names );
		self::assertContains( 'workflow_route_request', $names );
		self::assertContains( 'workflow_session_start', $names );
		self::assertContains( 'workflow_session_get', $names );
		self::assertContains( 'workflow_session_update', $names );
		self::assertContains( 'search', $names );
		self::assertContains( 'fetch', $names );
		self::assertContains( 'content_search_items', $names );
		self::assertContains( 'content_search_chunks', $names );
		self::assertContains( 'content_find_related', $names );
		self::assertContains( 'content_find_internal_links', $names );
		self::assertContains( 'content_audit_internal_links', $names );
		self::assertContains( 'content_batch_status', $names );
		self::assertContains( 'mcp_learning_inspect_activity', $names );
		self::assertNotContains( 'content_workflow_create_draft', $names );
		self::assertNotContains( 'content_update_item', $names );
		self::assertNotContains( 'brand_get_profile', $names );
		self::assertNotContains( 'blocks_list_available', $names );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content.list_items', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'memory.list', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'memory.save', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'memory.bootstrap', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'workflow_guides.list', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'workflow.route_request', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'workflow_session.start', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'workflow_session.get', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'workflow_session.update', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'search', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'fetch', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content_search.items', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content_search.chunks', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content_find.related', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content_find.internal_links', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content_audit.internal_links', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content_batch.status', $registry ) ) );
		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'mcp_learning.inspect_activity', $registry ) ) );
		self::assertSame( 'tool_disabled', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content_workflow.create_draft', $registry ) ) );
		self::assertSame( 'tool_disabled', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content.update_item', $registry ) ) );
		self::assertSame( 'unknown_tool', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content.not_real', $registry ) ) );
	}

	public function test_derived_workflow_tool_calls_require_enabled_dependencies(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item' ) );

		self::assertSame( 'tool_disabled', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content_workflow.create_draft', $registry, 1 ) ) );

		$registry->save_enabled_ids( array( 'content.create_item' ) );

		self::assertSame( '', $this->invokePrivate( new McpController(), 'tool_call_error', array( 'content_workflow.create_draft', $registry, 1 ) ) );
	}

	public function test_scope_checks_require_every_required_scope(): void {
		$controller = new McpController();

		self::assertTrue( $this->invokePrivate( $controller, 'has_scopes', array( array( 'content:read', 'content:draft' ), array( 'content:draft' ) ) ) );
		self::assertFalse( $this->invokePrivate( $controller, 'has_scopes', array( array( 'content:read' ), array( 'content:draft' ) ) ) );
	}

	public function test_connection_write_permission_unblocks_only_write_tools(): void {
		$controller = new McpController();
		$registry   = new AbilitiesRegistry();

		self::assertTrue(
			$this->invokePrivate(
				$controller,
				'write_permission_unblocks_tool',
				array(
					'content.update_item',
					$registry,
					array( 'write_permission_enabled' => true ),
				)
			)
		);
		self::assertTrue(
			$this->invokePrivate(
				$controller,
				'write_permission_unblocks_tool',
				array(
					'content.update_item',
					$registry,
					array(
						'write_permission_enabled' => false,
						'access_level'             => ConnectionAccessLevel::FULL_WRITE,
					),
				)
			)
		);
		self::assertFalse(
			$this->invokePrivate(
				$controller,
				'write_permission_unblocks_tool',
				array(
					'content.update_item',
					$registry,
					array( 'write_permission_enabled' => false ),
				)
			)
		);
		self::assertFalse(
			$this->invokePrivate(
				$controller,
				'write_permission_unblocks_tool',
				array(
					'content.get_item',
					$registry,
					array( 'write_permission_enabled' => true ),
				)
			)
		);
	}

	public function test_write_permission_preview_removes_confirmation_metadata(): void {
		$result = $this->invokePrivate(
			new McpController(),
			'write_permission_preview_payload',
			array(
				array(
					'dry_run'                   => true,
					'confirmation_required'     => true,
					'confirmation_token'        => 'token',
					'confirmation_expires_in'   => 300,
					'confirmation_instructions' => 'Repeat with token.',
				),
			)
		);

		self::assertFalse( $result['confirmation_required'] );
		self::assertSame( 'trusted_connection_direct_write', $result['confirmation_policy'] );
		self::assertTrue( $result['write_permission_enabled'] );
		self::assertArrayNotHasKey( 'confirmation_token', $result );
		self::assertArrayNotHasKey( 'confirmation_expires_in', $result );
		self::assertArrayNotHasKey( 'confirmation_instructions', $result );
	}

	public function test_trusted_write_result_removes_confirmation_metadata(): void {
		$result = $this->invokePrivate(
			new McpController(),
			'trusted_write_result_payload',
			array(
				array(
					'status'                    => 'updated',
					'confirmation_required'     => true,
					'confirmation_token'        => 'token',
					'confirmation_expires_in'   => 300,
					'confirmation_instructions' => 'Repeat with token.',
				),
				array(
					'access_level' => ConnectionAccessLevel::EXECUTE,
				),
			)
		);

		self::assertSame( 'updated', $result['status'] );
		self::assertFalse( $result['confirmation_required'] );
		self::assertSame( 'trusted_connection_direct_write', $result['confirmation_policy'] );
		self::assertTrue( $result['write_permission_enabled'] );
		self::assertSame( ConnectionAccessLevel::WRITE, $result['access_level'] );
		self::assertArrayNotHasKey( 'confirmation_token', $result );
		self::assertArrayNotHasKey( 'confirmation_expires_in', $result );
		self::assertArrayNotHasKey( 'confirmation_instructions', $result );
	}

	public function test_auth_challenge_response_includes_mcp_www_authenticate_metadata(): void {
		$response = $this->invokePrivate(
			new McpController(),
			'auth_challenge_response',
			array( 1, 'content:draft', 403, 'insufficient_scope' )
		);

		self::assertSame( 403, $response->get_status() );
		self::assertStringContainsString( 'insufficient_scope', (string) $response->header( 'WWW-Authenticate' ) );

		$data = $response->get_data();
		self::assertTrue( $data['result']['isError'] );
		self::assertArrayHasKey( 'mcp/www_authenticate', $data['result']['_meta'] );
	}

	public function test_initial_auth_challenge_requests_all_supported_scopes(): void {
		$controller = new McpController();
		$scope      = $this->invokePrivate( $controller, 'initial_auth_scope' );

		self::assertSame( implode( ' ', Helpers::supported_scopes() ), $scope );
		self::assertSame( 'content:read content:draft', $scope );

		$response = $this->invokePrivate(
			$controller,
			'auth_challenge_response',
			array( 1, $scope, 401, 'invalid_token' )
		);
		$header   = (string) $response->header( 'WWW-Authenticate' );
		$data     = $response->get_data();

		self::assertStringContainsString( 'scope="content:read content:draft"', $header );
		self::assertStringContainsString( 'scope="content:read content:draft"', $data['result']['_meta']['mcp/www_authenticate'][0] );
	}

	/**
	 * Return every tools/list page as one manifest for descriptor assertions.
	 *
	 * @param McpController|null $controller Controller instance.
	 * @return array{tools: list<array<string, mixed>>}
	 */
	private function list_tools_manifest( ?McpController $controller = null ): array {
		$controller ??= new McpController();
		$tools        = array();
		$cursor       = '';

		do {
			$page   = '' === $cursor
				? $this->invokePrivate( $controller, 'list_tools' )
				: $this->invokePrivate( $controller, 'list_tools', array( $cursor ) );
			$tools  = array_merge( $tools, (array) ( $page['tools'] ?? array() ) );
			$cursor = (string) ( $page['nextCursor'] ?? '' );
		} while ( '' !== $cursor );

		return array( 'tools' => $tools );
	}

	/**
	 * Invoke a private method for focused unit coverage without widening runtime API.
	 *
	 * @param object $object    Object instance.
	 * @param string $method    Method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}

	/**
	 * Set a private property for focused unit coverage without widening runtime API.
	 *
	 * @param object $object Object instance.
	 * @param string $name   Property name.
	 * @param mixed  $value  Property value.
	 */
	private function setPrivateProperty( object $object, string $name, mixed $value ): void {
		$reflection = new ReflectionProperty( $object, $name );
		$reflection->setAccessible( true );
		$reflection->setValue( $object, $value );
	}
}
