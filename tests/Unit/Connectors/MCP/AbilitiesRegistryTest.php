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

		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$this->registry                               = new AbilitiesRegistry();
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
		self::assertNotContains( 'Brand', $groups );
		self::assertNotContains( 'Block Knowledge', $groups );

		$by_id = array_column( $definitions, null, 'id' );

		self::assertSame( 'read-only', $by_id['content.list_items']['riskLevel'] );
		self::assertFalse( $by_id['content.list_items']['changesSite'] );
		self::assertSame( 'write', $by_id['content.update_item']['riskLevel'] );
		self::assertTrue( $by_id['content.update_item']['changesSite'] );
		self::assertArrayNotHasKey( 'content_search.items', $by_id );
		self::assertArrayNotHasKey( 'content_search.chunks', $by_id );
		self::assertArrayNotHasKey( 'content_find.internal_links', $by_id );
		self::assertArrayNotHasKey( 'search', $by_id );
		self::assertArrayNotHasKey( 'fetch', $by_id );
		self::assertArrayNotHasKey( 'memory.list', $by_id );
		self::assertArrayHasKey( 'memory.save', $by_id );
	}

	public function test_requested_expansion_abilities_are_registered(): void {
		$definitions = $this->registry->definitions();

		foreach (
			array(
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
				'content_index.refresh_batch',
				'content_search.items',
				'content_search.chunks',
				'content_find.related',
				'content_find.internal_links',
				'memory.list',
				'memory.save',
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
		self::assertSame( 'search', $this->registry->tool_name( 'search' ) );
		self::assertSame( 'fetch', $this->registry->tool_name( 'fetch' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'search' ) );
		self::assertSame( array( 'content:read' ), $this->registry->required_scopes( 'fetch' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'search' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'fetch' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'content_search_chunks' ) );
		self::assertTrue( $this->registry->is_always_on_read_intelligence( 'memory.list' ) );
		self::assertFalse( $this->registry->is_always_on_read_intelligence( 'memory.save' ) );
		self::assertSame( array( 'content:draft' ), $this->registry->required_scopes( 'memory_save' ) );
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

		self::assertSame( 'abilities_api_unavailable', $result['error'] );
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
				'content_search.items',
				'memory.list',
				'memory.save',
				'<script>',
				array(),
			)
		);

		self::assertSame(
			array(
				'content.list_items',
				'content.create_item',
				'memory.save',
			),
			$this->registry->enabled_ids()
		);
	}
}
