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
			7  => (object) array(
				'ID'           => 7,
				'roles'        => array( 'editor' ),
				'display_name' => 'Ed Editor',
				'user_login'   => 'ed',
			),
			13 => (object) array(
				'ID'           => 13,
				'roles'        => array(),
				'display_name' => 'No Role',
				'user_login'   => 'norole',
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
		self::assertSame( 'default_read_only', $operations['policy']['user_policy_state'] );
		self::assertFalse( $operations['policy']['explicit_role_policy'] );
		self::assertTrue( $operations['content']['get_item']['available'] );
		self::assertArrayNotHasKey( 'blocked_by', $operations['content']['get_item'] );
		self::assertFalse( $operations['content']['update']['available'] );
		self::assertSame( 'role_default_read_only', $operations['content']['update']['blocked_by'] );
	}

	public function test_operations_manifest_distinguishes_oauth_scope_blocks(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry, array( 'content:read' ) );

		self::assertTrue( $operations['policy']['scope_aware'] );
		self::assertSame( array( 'content:read' ), $operations['policy']['granted_scopes'] );
		self::assertTrue( $operations['content']['get_item']['available'] );
		self::assertArrayNotHasKey( 'blocked_by', $operations['content']['get_item'] );
		self::assertSame( array( 'content:read' ), $operations['content']['get_item']['required_scopes'] );
		self::assertTrue( $operations['content']['get_item']['read_only'] );
		self::assertFalse( $operations['content']['update']['available'] );
		self::assertSame( 'oauth_scope', $operations['content']['update']['blocked_by'] );
		self::assertSame( array( 'content:draft' ), $operations['content']['update']['required_scopes'] );
		self::assertSame( array( 'content:draft' ), $operations['content']['update']['missing_scopes'] );
		self::assertFalse( $operations['content']['update']['read_only'] );
	}

	public function test_tool_modules_for_user_filters_by_granted_oauth_scopes(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );

		$modules = ( new McpToolAvailability() )->tool_modules_for_user( 7, $registry, null, array( 'content:read' ) );

		self::assertArrayHasKey( 'content.get_item', $modules );
		self::assertArrayNotHasKey( 'content.update_item', $modules );
	}

	public function test_operations_manifest_identifies_missing_user_blocks(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 99, $registry );

		self::assertSame( 'missing_user', $operations['policy']['user_policy_state'] );
		self::assertTrue( $operations['policy']['missing_user'] );
		self::assertFalse( $operations['policy']['missing_role'] );
		self::assertTrue( $operations['content']['get_item']['available'] );
		self::assertFalse( $operations['content']['update']['available'] );
		self::assertSame( 'missing_user', $operations['content']['update']['blocked_by'] );
	}

	public function test_operations_manifest_identifies_roleless_user_blocks(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 13, $registry );

		self::assertSame( 'missing_role', $operations['policy']['user_policy_state'] );
		self::assertFalse( $operations['policy']['missing_user'] );
		self::assertTrue( $operations['policy']['missing_role'] );
		self::assertTrue( $operations['content']['get_item']['available'] );
		self::assertFalse( $operations['content']['update']['available'] );
		self::assertSame( 'missing_role', $operations['content']['update']['blocked_by'] );
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
		self::assertTrue( $operations['workflows']['create_draft']['derived'] );
		self::assertSame( array( 'content.create_item' ), $operations['workflows']['create_draft']['dependency_ids'] );
		self::assertSame( 'derived_from_dependencies', $operations['workflows']['create_draft']['availability_model'] );
		self::assertNotContains( 'content_workflow_create_draft', $tool_names );
	}

	public function test_workflow_operations_are_derived_from_allowed_dependencies(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.create_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );
		$modules    = ( new McpToolAvailability() )->tool_modules_for_user( 7, $registry );

		self::assertTrue( $operations['workflows']['create_draft']['available'] );
		self::assertArrayNotHasKey( 'blocked_by', $operations['workflows']['create_draft'] );
		self::assertSame( 'content_workflow_create_draft', $operations['workflows']['create_draft']['tool'] );
		self::assertTrue( $operations['workflows']['create_draft']['derived'] );
		self::assertSame( array( 'content_create_item' ), $operations['workflows']['create_draft']['dependency_tools'] );
		self::assertArrayHasKey( 'content_workflow.create_draft', $modules );
	}

	public function test_derived_workflow_operations_respect_role_and_oauth_dependency_blocks(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'editor' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.create_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );

		self::assertFalse( $operations['workflows']['create_draft']['available'] );
		self::assertSame( 'role_default_read_only:content.create_item', $operations['workflows']['create_draft']['blocked_by'] );

		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );
		$operations                                           = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry, array( 'content:read' ) );

		self::assertFalse( $operations['workflows']['create_draft']['available'] );
		self::assertSame( 'oauth_scope', $operations['workflows']['create_draft']['blocked_by'] );
		self::assertSame( array( 'content:draft' ), $operations['workflows']['create_draft']['missing_scopes'] );
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
		foreach ( array( 'site_information', 'content', 'workflows', 'intelligence_index', 'content_groups', 'media', 'comments', 'actions' ) as $group ) {
			foreach ( (array) ( $operations[ $group ] ?? array() ) as $entry ) {
				if ( is_array( $entry ) ) {
					$entries[] = $entry;
				}
			}
		}

		return $entries;
	}
}
