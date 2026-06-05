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
		self::assertSame( '', $operations['content']['get_item']['blocked_by'] );
		self::assertContains( 'content_get_item', $tool_names );

		self::assertFalse( $operations['content']['update']['available'] );
		self::assertSame( 'role_policy', $operations['content']['update']['blocked_by'] );
		self::assertNotContains( 'content_update_item', $tool_names );

		self::assertFalse( $operations['content']['list_items']['available'] );
		self::assertSame( 'global_disabled', $operations['content']['list_items']['blocked_by'] );
		self::assertNotContains( 'content_list_items', $tool_names );
		self::assertContains( 'content.update_item', $operations['policy']['blocked_by_role_ids'] );
		self::assertContains( 'content.list_items', $operations['policy']['blocked_by_global_ids'] );
	}

	public function test_operations_manifest_distinguishes_default_read_only_role_blocks(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );

		self::assertTrue( $operations['policy']['default_read_only_policy'] );
		self::assertFalse( $operations['policy']['explicit_role_policy'] );
		self::assertTrue( $operations['content']['get_item']['available'] );
		self::assertSame( '', $operations['content']['get_item']['blocked_by'] );
		self::assertFalse( $operations['content']['update']['available'] );
		self::assertSame( 'role_default_read_only', $operations['content']['update']['blocked_by'] );
	}

	/**
	 * Flatten operation entries from a structured operation manifest.
	 *
	 * @param array<string, mixed> $operations Structured operation manifest.
	 * @return list<array<string, mixed>>
	 */
	private function operation_entries( array $operations ): array {
		$entries = array();
		foreach ( array( 'content', 'content_groups', 'media', 'comments', 'actions' ) as $group ) {
			foreach ( (array) ( $operations[ $group ] ?? array() ) as $entry ) {
				if ( is_array( $entry ) ) {
					$entries[] = $entry;
				}
			}
		}

		return $entries;
	}
}
