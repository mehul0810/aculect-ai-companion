<?php
/**
 * MCP tool availability tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the intelligence operation map and MCP discovery share policy.
 */
final class McpToolAvailabilityTest extends TestCase {

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
	}

	public function test_available_operations_are_exposed_in_tools_list(): void {
		$availability = new McpToolAvailability();
		$operations   = $availability->operations_manifest_for_user( 7 );
		$tools        = ( new McpController() )->tool_manifest_for_current_user();
		$tool_names   = array_column( $tools['tools'], 'name' );

		foreach ( $this->operation_entries( $operations ) as $entry ) {
			if ( true === $entry['available'] ) {
				self::assertContains( $entry['tool'], $tool_names );
			}
		}
	}

	public function test_operations_manifest_explains_global_and_role_blocks(): void {
		$registry = new AbilitiesRegistry();
		$policy   = new RoleAbilitiesPolicy();

		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item', 'media.delete_item' ) );
		$policy->save_role_policy( 'editor', array( 'content.get_item', 'media.delete_item' ), $registry );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );
		$tools      = ( new McpController() )->tool_manifest_for_current_user();
		$tool_names = array_column( $tools['tools'], 'name' );

		self::assertTrue( $operations['content']['get_item']['available'] );
		self::assertArrayNotHasKey( 'blocked_by', $operations['content']['get_item'] );
		self::assertContains( 'content_get_item', $tool_names );

		self::assertFalse( $operations['content']['update']['available'] );
		self::assertSame( 'role_policy', $operations['content']['update']['blocked_by'] );
		self::assertNotContains( 'content_update_item', $tool_names );

		self::assertFalse( $operations['content']['list_items']['available'] );
		self::assertSame( 'global_disabled', $operations['content']['list_items']['blocked_by'] );
		self::assertNotContains( 'content_list_items', $tool_names );
	}

	public function test_operations_manifest_distinguishes_default_read_only_role_blocks(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );

		self::assertTrue( $operations['policy']['default_read_only_policy'] );
		self::assertFalse( $operations['policy']['explicit_role_policy'] );
		self::assertTrue( $operations['content']['get_item']['available'] );
		self::assertArrayNotHasKey( 'blocked_by', $operations['content']['get_item'] );
		self::assertFalse( $operations['content']['update']['available'] );
		self::assertSame( 'role_default_read_only', $operations['content']['update']['blocked_by'] );
	}

	public function test_workflow_operations_require_underlying_atomic_operations(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content_workflow.create_draft' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );
		$tools      = ( new McpController() )->tool_manifest_for_current_user();
		$tool_names = array_column( $tools['tools'], 'name' );

		self::assertArrayHasKey( 'workflows', $operations );
		self::assertArrayHasKey( 'intelligence_index', $operations );
		self::assertFalse( $operations['workflows']['create_draft']['available'] );
		self::assertSame( 'global_disabled:content.create_item', $operations['workflows']['create_draft']['blocked_by'] );
		self::assertNotContains( 'content_workflow_create_draft', $tool_names );
	}

	public function test_workflow_operations_are_available_when_self_and_dependencies_are_allowed(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content_workflow.create_draft', 'content.create_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );

		self::assertTrue( $operations['workflows']['create_draft']['available'] );
		self::assertArrayNotHasKey( 'blocked_by', $operations['workflows']['create_draft'] );
		self::assertSame( 'content_workflow_create_draft', $operations['workflows']['create_draft']['tool'] );
	}

	public function test_intelligence_index_operations_are_reported_with_read_and_write_policy(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'editor' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids(
			array(
				'content_search.items',
				'content_search.chunks',
				'content_find.internal_links',
				'memory.list',
				'memory.save',
			)
		);

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );

		self::assertTrue( $operations['intelligence_index']['search_items']['available'] );
		self::assertTrue( $operations['intelligence_index']['search_chunks']['available'] );
		self::assertTrue( $operations['intelligence_index']['internal_links']['available'] );
		self::assertTrue( $operations['intelligence_index']['memory_list']['available'] );
		self::assertFalse( $operations['intelligence_index']['memory_save']['available'] );
		self::assertSame( 'role_default_read_only', $operations['intelligence_index']['memory_save']['blocked_by'] );
	}

	/**
	 * Flatten operation entries from a structured operation manifest.
	 *
	 * @param array<string, mixed> $operations Structured operation manifest.
	 * @return list<array<string, mixed>>
	 */
	private function operation_entries( array $operations ): array {
		$entries = array();
		foreach ( array( 'content', 'workflows', 'intelligence_index', 'content_groups', 'media', 'comments', 'actions' ) as $group ) {
			foreach ( (array) ( $operations[ $group ] ?? array() ) as $entry ) {
				if ( is_array( $entry ) ) {
					$entries[] = $entry;
				}
			}
		}

		return $entries;
	}
}
