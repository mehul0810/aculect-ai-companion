<?php
/**
 * Tests for MCP tool manifest diagnostics.
 *
 * @package Aculect\AICompanion\Tests\Unit\Diagnostics
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesRegistrar;
use Aculect\AICompanion\Diagnostics\McpToolManifest;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/fixtures/wordpress-abilities-stubs.php';

/**
 * Verifies MCP manifest exports distinguish server exposure from client discovery.
 */
final class McpToolManifestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']               = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']       = 7;
		$GLOBALS['aculect_ai_companion_test_users']                 = array(
			7 => (object) array(
				'ID'           => 7,
				'roles'        => array( 'editor' ),
				'display_name' => 'Ed Editor',
				'user_login'   => 'ed',
			),
		);
		$GLOBALS['aculect_ai_companion_test_wp_abilities']          = array();
		$GLOBALS['aculect_ai_companion_test_wp_ability_categories'] = array();
	}

	public function test_export_includes_exact_tools_list_payload_and_role_policy_context(): void {
		$registry = new AbilitiesRegistry();
		$policy   = new RoleAbilitiesPolicy();

		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item', 'media.delete_item' ) );
		RoleAbilitiesPolicy::set_editing_enabled( true );
		$policy->save_role_policy( 'editor', array( 'content.get_item', 'media.delete_item' ), $registry );

		$export = ( new McpToolManifest() )->export_for_current_user(
			array(
				'id'                       => 12,
				'provider'                 => 'claude',
				'client_name'              => 'Claude',
				'user_id'                  => 7,
				'user_roles'               => array( 'Editor' ),
				'scopes'                   => array( 'content:read', 'content:draft' ),
				'resource'                 => 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
				'status'                   => 'active',
				'access_level'             => 'write',
				'write_permission_enabled' => true,
			)
		);

		$names = array_column( $export['tools_list_payload']['tools'], 'name' );

		self::assertContains( 'content_get_item', $names );
		self::assertContains( 'media_delete_item', $names );
		self::assertNotContains( 'content_update_item', $names );
		self::assertContains( 'intelligence_site_get_context', $names );
		self::assertSame( 7, $export['ability_policy']['user_id'] );
		self::assertArrayHasKey( 'operations_manifest', $export );
		self::assertArrayHasKey( 'wordpress_ability', $export['operations_manifest']['content']['get_item'] );
		self::assertArrayHasKey( 'wordpress_abilities', $export );
		self::assertTrue( $export['wordpress_abilities']['api_available'] );
		self::assertSame( array( 'editor' ), $export['ability_policy']['user_roles'] );
		self::assertTrue( $export['ability_policy']['explicit_role_policy'] );
		self::assertContains( 'content.update_item', $export['ability_policy']['blocked_by_role_ids'] );
		self::assertSame( 'claude', $export['session']['provider'] );
		self::assertIsArray( $export['initialize_payload'] );
		self::assertIsArray( $export['metadata'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $export['metadata']['fingerprint'] );
		self::assertSame( 'sha256:canonical-json:mcp-metadata-v1', $export['metadata']['fingerprint_algorithm'] );
		self::assertContains( 'initialize.instructions', $export['metadata']['covers'] );
		self::assertContains( 'tools.inputSchema', $export['metadata']['covers'] );
		self::assertSame( $export['metadata']['fingerprint'], $export['summary']['metadata_fingerprint'] );
		self::assertArrayHasKey( 'chatgpt_app', $export['metadata']['refresh_guidance'] );
		self::assertArrayHasKey( 'gemini_cli', $export['metadata']['refresh_guidance'] );
		self::assertSame( 'cursor', $export['tools_list_pagination']['mode'] );
		self::assertSame( 'aggregated_all_pages', $export['tools_list_pagination']['export_shape'] );
		self::assertSame( count( $export['tools_list_payload']['tools'] ), $export['tools_list_pagination']['total_tools'] );
		self::assertSame( 'tools/list', $export['json_rpc_method'] );
	}

	public function test_export_uses_active_session_oauth_scopes_for_tool_payload_and_diagnostics(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );

		$export = ( new McpToolManifest() )->export_for_current_user(
			array(
				'id'                       => 44,
				'provider'                 => 'chatgpt',
				'client_name'              => 'ChatGPT',
				'user_id'                  => 7,
				'user_roles'               => array( 'administrator' ),
				'scopes'                   => array( 'content:read' ),
				'resource'                 => 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
				'status'                   => 'active',
				'access_level'             => 'read',
				'write_permission_enabled' => false,
			)
		);

		$names = array_column( $export['tools_list_payload']['tools'], 'name' );

		self::assertContains( 'content_get_item', $names );
		self::assertNotContains( 'content_update_item', $names );
		self::assertTrue( $export['ability_policy']['scope_aware'] );
		self::assertSame( array( 'content:read' ), $export['ability_policy']['granted_scopes'] );
		self::assertSame( 'read_only_audit', $export['profile']['id'] );
		self::assertSame( 'inferred_from_scopes', $export['profile']['source'] );
		self::assertContains( 'content_get_item', $export['profile']['visible_tools'] );
		self::assertContains( 'content_update_item', $export['profile']['hidden_tools'] );
		self::assertSame( 'oauth_scope', $this->hiddenProfileReason( $export, 'content_update_item' )['reason'] );
		self::assertSame( array( 'content:draft' ), $this->hiddenProfileReason( $export, 'content_update_item' )['missing_scopes'] );
		self::assertFalse( $export['operations_manifest']['content']['update']['available'] );
		self::assertSame( 'oauth_scope', $export['operations_manifest']['content']['update']['blocked_by'] );
		self::assertSame( array( 'content:draft' ), $export['operations_manifest']['content']['update']['missing_scopes'] );
		self::assertSame( count( $names ), $export['summary']['tool_count'] );
		self::assertSame( count( $names ), $export['tools_list_pagination']['total_tools'] );
	}

	public function test_export_includes_explicit_profile_metadata_without_changing_tool_schema(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$export     = ( new McpToolManifest() )->export_for_current_user(
			array(
				'id'           => 55,
				'provider'     => 'codex',
				'client_name'  => 'Codex',
				'user_id'      => 7,
				'user_roles'   => array( 'administrator' ),
				'profile'      => 'site_management',
				'scopes'       => array( 'content:read', 'content:draft' ),
				'resource'     => 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
				'status'       => 'active',
				'access_level' => 'read',
			)
		);
		$tool_names = array_column( $export['tools_list_payload']['tools'], 'name' );

		self::assertSame( 'site_management', $export['profile']['id'] );
		self::assertSame( 'session', $export['profile']['source'] );
		self::assertSame( array( 'content:read', 'content:draft' ), $export['profile']['granted_scopes'] );
		self::assertSame( $tool_names, $export['profile']['visible_tools'] );
		self::assertSame( 'site_management', $export['session']['profile'] );
		self::assertSame( array(), $export['profile']['hidden_by_profile'] );
		self::assertContains( 'site_workflow_audit', $export['profile']['required_tools'] );
		self::assertContains( 'navigation_get_context', $export['profile']['required_tools'] );

		foreach ( $export['tools_list_payload']['tools'] as $tool ) {
			self::assertArrayNotHasKey( 'profile', $tool );
			self::assertArrayNotHasKey( 'hidden_by_profile', $tool );
		}
	}

	public function test_export_reports_registered_first_party_wordpress_abilities(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$export = ( new McpToolManifest() )->export_for_current_user();

		self::assertGreaterThan( 0, $export['wordpress_abilities']['registered_first_party_count'] );
		self::assertSame( array(), $export['wordpress_abilities']['missing_first_party_names'] );
		self::assertTrue( $export['wordpress_abilities']['schema_valid'] );
		self::assertSame( 'available', $export['operations_manifest']['intelligence_index']['search_items']['wordpress_ability']['status'] );
		self::assertSame( 'aculect-ai-companion/content-search-items', $export['operations_manifest']['intelligence_index']['search_items']['wordpress_ability']['name'] );
		self::assertSame( 'both', $export['operations_manifest']['intelligence_index']['search_items']['availability_channels']['summary'] );
	}

	public function test_metadata_fingerprint_changes_when_tools_or_instructions_change(): void {
		$manifest   = new McpToolManifest();
		$initialize = array(
			'instructions' => 'Use the current workflow.',
		);
		$payload    = array(
			'tools' => array(
				array(
					'name'        => 'content_get_item',
					'description' => 'Get one item.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'id' => array( 'type' => 'integer' ),
						),
					),
					'annotations' => array( 'readOnlyHint' => true ),
				),
			),
		);

		$baseline = $manifest->metadata_fingerprint( $payload, $initialize );

		$changed_tool = $payload;
		$changed_tool['tools'][0]['inputSchema']['properties']['context'] = array( 'type' => 'string' );

		$changed_initialize                 = $initialize;
		$changed_initialize['instructions'] = 'Use the updated workflow.';

		self::assertNotSame( $baseline, $manifest->metadata_fingerprint( $changed_tool, $initialize ) );
		self::assertNotSame( $baseline, $manifest->metadata_fingerprint( $payload, $changed_initialize ) );
		self::assertSame(
			$baseline,
			$manifest->metadata_fingerprint(
				array(
					'tools' => array(
						array(
							'annotations' => array( 'readOnlyHint' => true ),
							'inputSchema' => array(
								'properties' => array(
									'id' => array( 'type' => 'integer' ),
								),
								'type'       => 'object',
							),
							'description' => 'Get one item.',
							'name'        => 'content_get_item',
						),
					),
				),
				$initialize
			)
		);
	}

	public function test_summary_flags_duplicate_and_invalid_tool_names(): void {
		$summary = ( new McpToolManifest() )->summary(
			array(
				'tools' => array(
					array(
						'name'        => 'content_get_item',
						'annotations' => array( 'readOnlyHint' => true ),
					),
					array(
						'name'        => 'content_get_item',
						'annotations' => array( 'readOnlyHint' => false ),
					),
					array(
						'name'        => 'content.get.item',
						'annotations' => array( 'readOnlyHint' => true ),
					),
				),
			)
		);

		self::assertSame( 3, $summary['tool_count'] );
		self::assertSame( array( 'content_get_item' ), $summary['duplicate_tool_names'] );
		self::assertSame( array( 'content.get.item' ), $summary['invalid_tool_names'] );
		self::assertSame( 2, $summary['read_only_tool_count'] );
		self::assertSame( 1, $summary['write_tool_count'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $summary['metadata_fingerprint'] );
	}

	/**
	 * Return one hidden profile reason from an export.
	 *
	 * @param array<string, mixed> $export    Manifest export.
	 * @param string               $tool_name Public tool name.
	 * @return array<string, mixed>
	 */
	private function hiddenProfileReason( array $export, string $tool_name ): array {
		foreach ( (array) ( $export['profile']['hidden_by_profile'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && (string) ( $entry['tool'] ?? '' ) === $tool_name ) {
				return $entry;
			}
		}

		self::fail( sprintf( 'Missing hidden profile reason for %s.', $tool_name ) );
	}
}
