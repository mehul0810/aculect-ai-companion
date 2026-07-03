<?php
/**
 * Tests for MCP ability registration and public tool-name mapping.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use PHPUnit\Framework\TestCase;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;

/**
 * Verifies the internal ability IDs remain decoupled from MCP-safe tool names.
 */
final class AbilitiesRegistryTest extends TestCase {

	private AbilitiesRegistry $registry;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']      = array();
		$GLOBALS['aculect_ai_companion_test_wp_abilities'] = array();
		$this->registry                                    = new AbilitiesRegistry();
	}

	public function test_public_tool_names_are_claude_safe_and_round_trip_to_internal_ids(): void {
		foreach ( $this->registry->definitions() as $internal_id => $definition ) {
			$tool_name = $this->registry->tool_name( (string) $internal_id );

			self::assertSame( (string) $internal_id, (string) $definition['id'] );
			self::assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/', $tool_name );
			self::assertStringNotContainsString( '.', $tool_name );
			self::assertStringNotContainsString( '/', $tool_name );
			self::assertSame( (string) $internal_id, $this->registry->internal_id( $tool_name ) );
		}
	}

	public function test_legacy_create_draft_aliases_map_to_create_item(): void {
		self::assertSame( 'content.create_item', $this->registry->internal_id( 'content.create_draft' ) );
		self::assertSame( 'content.create_item', $this->registry->internal_id( 'content_create_draft' ) );
	}

	public function test_public_definitions_include_enabled_status_and_tool_name(): void {
		$public_definitions = $this->registry->public_definitions();

		self::assertNotEmpty( $public_definitions );

		foreach ( $public_definitions as $definition ) {
			self::assertArrayHasKey( 'toolName', $definition );
			self::assertArrayHasKey( 'enabled', $definition );
			self::assertArrayHasKey( 'changesSite', $definition );
			self::assertArrayHasKey( 'riskLevel', $definition );
			self::assertTrue( $definition['enabled'] );
			self::assertIsString( $definition['toolName'] );
			self::assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/', $definition['toolName'] );
		}
	}

	public function test_core_default_definitions_are_default_active_and_not_user_configurable(): void {
		$core_defaults = $this->registry->core_default_public_definitions();
		$by_id         = array_column( $core_defaults, null, 'id' );

		self::assertNotEmpty( $core_defaults );
		self::assertArrayHasKey( 'workflow.route_request', $by_id );
		self::assertArrayHasKey( 'site_editor.get_context', $by_id );
		self::assertArrayHasKey( 'admin_menu.get_context', $by_id );
		self::assertArrayHasKey( 'search', $by_id );
		self::assertArrayNotHasKey( 'content.update_item', $by_id );
		self::assertTrue( $by_id['search']['enabled'] );
		self::assertTrue( $by_id['search']['coreDefault'] );
		self::assertFalse( $by_id['search']['configurable'] );
		self::assertSame( 'read-only', $by_id['search']['riskLevel'] );
		self::assertTrue( $this->registry->is_core_default( 'workflow_route_request' ) );
		self::assertTrue( $this->registry->is_core_default( 'site_editor.get_context' ) );
		self::assertFalse( $this->registry->is_core_default( 'memory.save' ) );

		$public_by_id = array_column( $this->registry->public_definitions(), null, 'id' );
		foreach ( array_keys( $by_id ) as $ability_id ) {
			self::assertArrayNotHasKey( $ability_id, $public_by_id );
		}

		$this->registry->save_enabled_ids( array( 'content.update_item' ) );

		self::assertSame( array( 'content.update_item' ), $this->registry->enabled_ids() );
		self::assertContains( 'search', $this->registry->policy_enabled_ids() );
		self::assertContains( 'content.update_item', $this->registry->policy_enabled_ids() );
		self::assertTrue( $this->registry->is_enabled( 'search' ) );
	}

	public function test_public_definitions_surface_practical_permission_groups_and_risk(): void {
		$definitions = $this->registry->public_definitions();
		$groups      = array_values(
			array_unique(
				array_map(
					static fn ( array $definition ): string => (string) $definition['group'],
					$definitions
				)
			)
		);

		self::assertContains( 'Content', $groups );
		self::assertContains( 'Content Groups', $groups );
		self::assertContains( 'Comments', $groups );
		self::assertContains( 'Media', $groups );
		self::assertContains( 'Site Information', $groups );
		self::assertContains( 'WordPress Actions', $groups );
		self::assertNotContains( 'Content Workflows', $groups );
		self::assertNotContains( 'SEO Workflows', $groups );
		self::assertNotContains( 'Site Workflows', $groups );
		self::assertNotContains( 'Site Editor Intelligence', $groups );
		self::assertNotContains( 'Admin Menu Intelligence', $groups );
		self::assertNotContains( 'Workflow Guides', $groups );
		self::assertNotContains( 'Brand', $groups );
		self::assertNotContains( 'Block Knowledge', $groups );

		$by_id = array_column( $definitions, null, 'id' );

		self::assertSame( 'read-only', $by_id['content.list_items']['riskLevel'] );
		self::assertFalse( $by_id['content.list_items']['changesSite'] );
		self::assertSame( 'write', $by_id['content.update_item']['riskLevel'] );
		self::assertTrue( $by_id['content.update_item']['changesSite'] );
		self::assertArrayNotHasKey( 'content_search.items', $by_id );
		self::assertArrayNotHasKey( 'content_search.chunks', $by_id );
		self::assertArrayNotHasKey( 'content_internal_link.policy', $by_id );
		self::assertArrayNotHasKey( 'content_find.internal_links', $by_id );
		self::assertArrayNotHasKey( 'content_audit.internal_links', $by_id );
		self::assertArrayNotHasKey( 'search', $by_id );
		self::assertArrayNotHasKey( 'fetch', $by_id );
		self::assertArrayNotHasKey( 'workflow_guides.list', $by_id );
		self::assertArrayNotHasKey( 'workflow_guides.get', $by_id );
		self::assertArrayNotHasKey( 'memory.list', $by_id );
		self::assertArrayNotHasKey( 'memory.save', $by_id );
		self::assertArrayNotHasKey( 'memory.bootstrap', $by_id );
	}

	public function test_requested_expansion_abilities_are_registered(): void {
		$definitions = $this->registry->definitions();

		foreach (
			array(
				'workflow.route_request',
				'workflow_session.start',
				'workflow_session.get',
				'workflow_session.update',
				'mcp_learning.inspect_activity',
				'search',
				'fetch',
				'wp_abilities.discover',
				'wp_abilities.get_info',
				'wp_abilities.run',
				'comments.list_items',
				'comments.get_item',
				'comments.create_item',
				'comments.update_item',
				'comments.bulk_update',
				'media.upload_item',
				'media.get_item',
				'media.update_item',
				'content_workflow.prepare_post',
				'content_workflow.create_draft',
				'content_workflow.update_post',
				'seo_workflow.update_rankmath',
				'site_workflow.audit',
				'site_editor.get_context',
				'site_editor.refresh_context',
				'site_editor.list_templates',
				'site_editor.get_template',
				'site_editor.list_template_parts',
				'site_editor.get_template_part',
				'admin_menu.get_context',
				'admin_menu.refresh_context',
				'admin_menu.list_pages',
				'admin_menu.get_navigation_target',
				'admin_menu.list_settings',
				'workflow_guides.list',
				'workflow_guides.get',
				'content_index.refresh_batch',
				'content_search.items',
				'content_search.chunks',
				'content_find.related',
				'content_internal_link.policy',
				'content_find.internal_links',
				'content_audit.internal_links',
				'memory.list',
				'memory.save',
				'memory.bootstrap',
				'content_batch.status',
				'site.get_info',
				'site.get_health',
				'site.list_plugins',
				'site.list_themes',
			) as $ability_id
		) {
			self::assertArrayHasKey( $ability_id, $definitions );
			self::assertTrue( $this->registry->is_known( $this->registry->tool_name( $ability_id ) ) );
		}

		self::assertSame( array( 'content:draft' ), $this->registry->required_scopes( 'wp_abilities.run' ) );
		self::assertSame( array( 'content:draft' ), $this->registry->required_scopes( 'media.upload_item' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'content_workflow.prepare_post' ) );
		self::assertSame( array( 'content:draft' ), $this->registry->required_scopes( 'content_workflow.create_draft' ) );
		self::assertTrue( $this->registry->is_derived_workflow( 'content_workflow_create_draft' ) );
		self::assertTrue( $this->registry->is_derived_workflow( 'seo_workflow.update_rankmath' ) );
		self::assertTrue( $this->registry->is_derived_workflow( 'site_workflow.audit' ) );
		self::assertFalse( $this->registry->is_derived_workflow( 'content.create_item' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'content_search_chunks' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'content_internal_link_policy' ) );
		self::assertSame( 'search', $this->registry->tool_name( 'search' ) );
		self::assertSame( 'fetch', $this->registry->tool_name( 'fetch' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'search' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'fetch' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'content_audit_internal_links' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'search' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'fetch' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'content_internal_link.policy' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'content_audit.internal_links' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'workflow_guides.list' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'workflow_guides_get' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'workflow_route_request' ) );
		self::assertSame( array( 'content:draft' ), $this->registry->required_scopes( 'workflow_session_start' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'workflow_session_get' ) );
		self::assertSame( array( 'content:draft' ), $this->registry->required_scopes( 'workflow_session_update' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'mcp_learning_inspect_activity' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'workflow_route_request' ) );
		self::assertTrue( $this->registry->is_always_on_write_intelligence( 'workflow_session_start' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'workflow_session_get' ) );
		self::assertTrue( $this->registry->is_always_on_write_intelligence( 'workflow_session_update' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'mcp_learning_inspect_activity' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'workflow_guides_list' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'workflow_guides.get' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'site_editor.get_context' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'site_editor.list_templates' ) );
		self::assertTrue( $this->registry->is_always_on_write_intelligence( 'site_editor.refresh_context' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'admin_menu.get_context' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'admin_menu.get_navigation_target' ) );
		self::assertTrue( $this->registry->is_always_on_write_intelligence( 'admin_menu.refresh_context' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'content_search_chunks' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'memory.list' ) );
		self::assertFalse( $this->registry->is_always_on_read_intelligence( 'memory.save' ) );
		self::assertTrue( $this->registry->is_always_on_write_intelligence( 'memory.save' ) );
		self::assertTrue( $this->registry->is_always_on_write_intelligence( 'memory.bootstrap' ) );
		self::assertSame( array( 'content:draft' ), $this->registry->required_scopes( 'memory_save' ) );
		self::assertSame( array( 'content:draft' ), $this->registry->required_scopes( 'memory_bootstrap' ) );
		self::assertSame( array( 'content.create_item' ), $this->registry->dependency_ids( 'content_workflow_create_draft' ) );
		self::assertSame( array( 'content.update_seo' ), $this->registry->dependency_ids( 'seo_workflow_update_rankmath' ) );
		self::assertSame( array( 'site.get_info', 'site.get_health' ), $this->registry->dependency_ids( 'site_workflow_audit' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'site.get_health' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'site.list_plugins' ) );
		self::assertArrayNotHasKey( 'brand.get_profile', $definitions );
		self::assertArrayNotHasKey( 'blocks.list_available', $definitions );
		self::assertArrayNotHasKey( 'patterns.get_info', $definitions );
		self::assertArrayNotHasKey( 'content.validate_blocks', $definitions );
	}

	public function test_registered_module_keeps_metadata_schema_and_handler_together(): void {
		$module     = $this->registry->module( 'wp_abilities.discover' );
		$definition = $this->registry->definitions()['wp_abilities.discover'] ?? array();

		self::assertNotNull( $module );
		self::assertSame( 'wp_abilities.discover', $module->id() );
		self::assertSame( $definition['title'], $module->title() );
		self::assertSame( $definition['description'], $module->description() );
		self::assertSame( array( 'content:read' ), $module->required_scopes() );
		self::assertTrue( $module->is_read_only() );
		self::assertSame( $module->input_schema(), $this->registry->input_schema( 'wp_abilities_discover' ) );
		self::assertArrayHasKey( 'search', $module->input_schema()['properties'] );

		$result = $module->execute( array( 'search' => 'content' ) );

		if ( isset( $result['error'] ) ) {
			self::assertSame( 'abilities_api_unavailable', $result['error'] );
			return;
		}

		self::assertSame( 0, $result['total'] );
		self::assertSame( array(), $result['items'] );
	}

	public function test_write_module_schema_includes_safety_controls(): void {
		$schema = $this->registry->input_schema( 'content.update_item' );

		self::assertArrayHasKey( 'dry_run', $schema['properties'] );
		self::assertArrayHasKey( 'confirmation_token', $schema['properties'] );
		self::assertArrayHasKey( 'title', $schema['properties'] );
		self::assertStringContainsString( 'never use the Custom HTML block', $schema['properties']['content']['description'] );
		self::assertSame( array( 'content:draft' ), $this->registry->required_scopes( 'content_update_item' ) );
		self::assertFalse( $this->registry->is_read_only( 'content_update_item' ) );
	}

	public function test_saving_enabled_ids_sanitizes_unknown_values_and_public_aliases(): void {
		$this->registry->save_enabled_ids(
			array(
				'content_list_items',
				'content.create_draft',
				'content_workflow.create_draft',
				'search',
				'fetch',
				'workflow_guides.list',
				'workflow.route_request',
				'workflow_session.start',
				'workflow_session.get',
				'workflow_session.update',
				'mcp_learning.inspect_activity',
				'content_search.items',
				'memory.list',
				'memory.save',
				'memory.bootstrap',
				'<script>',
				array(),
			)
		);

		self::assertSame(
			array(
				'content.list_items',
				'content.create_item',
			),
			$this->registry->enabled_ids()
		);
	}
}
