<?php
/**
 * Tests for deterministic MCP initialize and tools/list discovery.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use Aculect\AICompanion\Diagnostics\McpToolManifest;
use PHPUnit\Framework\TestCase;

/**
 * Verifies repeated MCP discovery stays stable for one authenticated context.
 */
final class McpDiscoveryDeterminismTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			7  => (object) array(
				'ID'           => 7,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
			12 => (object) array(
				'ID'           => 12,
				'roles'        => array( 'editor' ),
				'display_name' => 'Ed Editor',
				'user_login'   => 'ed',
			),
		);

		AbilitiesRegistry::reset_module_cache();
	}

	public function test_repeated_initialize_and_tools_list_pages_are_deterministic(): void {
		$controller = new McpController();
		$manifest   = new McpToolManifest();

		$first_initialize  = $controller->initialize_payload_for_diagnostics();
		$second_initialize = $controller->initialize_payload_for_diagnostics();
		$first_discovery   = $this->collect_all_tools_pages( $controller, 7, null );
		$second_discovery  = $this->collect_all_tools_pages( $controller, 7, null );
		$tool_names        = array_column( $first_discovery['tools'], 'name' );
		$summary           = $manifest->summary( array( 'tools' => $first_discovery['tools'] ), $first_initialize );

		self::assertSame( $first_initialize, $second_initialize );
		self::assertEquals( $first_discovery, $second_discovery );
		self::assertSame(
			$manifest->metadata_fingerprint( array( 'tools' => $first_discovery['tools'] ), $first_initialize ),
			$manifest->metadata_fingerprint( array( 'tools' => $second_discovery['tools'] ), $second_initialize )
		);
		self::assertGreaterThan( McpController::tools_page_size(), count( $first_discovery['tools'] ) );
		self::assertNotEmpty( $first_discovery['cursors'] );
		self::assertCount( count( array_unique( $tool_names ) ), $tool_names );
		self::assertSame( array(), $summary['duplicate_tool_names'] );
		self::assertSame( array(), $summary['invalid_tool_names'] );
		self::assertSame( count( $tool_names ), $summary['tool_count'] );
	}

	public function test_filtered_diagnostics_are_consistent_for_same_scopes_and_role_policy(): void {
		$registry     = new AbilitiesRegistry();
		$availability = new McpToolAvailability();
		$scopes       = array( 'content:read' );

		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );

		$first_policy      = $availability->ability_policy_for_user( 7, $registry, $scopes );
		$second_policy     = $availability->ability_policy_for_user( 7, $registry, $scopes );
		$first_operations  = $availability->operations_manifest_for_user( 7, $registry, $scopes );
		$second_operations = $availability->operations_manifest_for_user( 7, $registry, $scopes );

		self::assertSame( $first_policy, $second_policy );
		self::assertSame( $first_operations, $second_operations );
		self::assertTrue( $first_policy['scope_aware'] );
		self::assertSame( array( 'content:read' ), $first_policy['granted_scopes'] );
		self::assertContains( 'content.get_item', $first_policy['exposed_ability_ids'] );
		self::assertNotContains( 'content.update_item', $first_policy['exposed_ability_ids'] );
		self::assertSame( 'oauth_scope', $first_operations['content']['update']['blocked_by'] );
		self::assertSame( array( 'content:draft' ), $first_operations['content']['update']['missing_scopes'] );
	}

	public function test_settings_and_role_policy_changes_invalidate_discovery_without_stale_counts(): void {
		$controller = new McpController();
		$manifest   = new McpToolManifest();
		$registry   = new AbilitiesRegistry();
		$policy     = new RoleAbilitiesPolicy();
		$scopes     = array( 'content:read', 'content:draft' );

		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 12;
		RoleAbilitiesPolicy::set_editing_enabled( true );
		$registry->save_enabled_ids( array( 'content.get_item' ) );
		$policy->save_role_policy( 'editor', array( 'content.get_item' ), $registry );

		$before = $this->collect_all_tools_pages( $controller, 12, $scopes );

		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );
		$policy->save_role_policy( 'editor', array( 'content.get_item', 'content.update_item' ), $registry );

		$after        = $this->collect_all_tools_pages( $controller, 12, $scopes );
		$before_names = array_column( $before['tools'], 'name' );
		$after_names  = array_column( $after['tools'], 'name' );

		self::assertNotSame( $before['tools'], $after['tools'] );
		self::assertLessThan( count( $after['tools'] ), count( $before['tools'] ) );
		self::assertNotContains( 'content_update_item', $before_names );
		self::assertContains( 'content_update_item', $after_names );
		self::assertNotSame(
			$manifest->metadata_fingerprint( array( 'tools' => $before['tools'] ), $controller->initialize_payload_for_diagnostics() ),
			$manifest->metadata_fingerprint( array( 'tools' => $after['tools'] ), $controller->initialize_payload_for_diagnostics() )
		);
		self::assertEquals( $after, $this->collect_all_tools_pages( $controller, 12, $scopes ) );
	}

	/**
	 * Collect every paginated tools/list page for one deterministic auth context.
	 *
	 * @param McpController     $controller MCP controller.
	 * @param int               $user_id    WordPress user ID.
	 * @param array<mixed>|null $scopes     Granted OAuth scopes.
	 * @return array{tools: list<array<string, mixed>>, pages: int, cursors: list<string>}
	 */
	private function collect_all_tools_pages( McpController $controller, int $user_id, ?array $scopes ): array {
		$cursor  = '';
		$tools   = array();
		$cursors = array();
		$pages   = 0;

		do {
			$page = $controller->tools_list_page_for_user( $user_id, $scopes, $cursor );
			++$pages;

			$tools  = array_merge( $tools, $page['tools'] );
			$cursor = isset( $page['nextCursor'] ) ? (string) $page['nextCursor'] : '';
			if ( '' !== $cursor ) {
				$cursors[] = $cursor;
			}
		} while ( '' !== $cursor && $pages < 20 );

		self::assertLessThan( 20, $pages );

		return array(
			'tools'   => $tools,
			'pages'   => $pages,
			'cursors' => $cursors,
		);
	}
}
