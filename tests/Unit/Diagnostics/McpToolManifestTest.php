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
use Aculect\AICompanion\Diagnostics\McpToolManifest;
use PHPUnit\Framework\TestCase;

/**
 * Verifies MCP manifest exports distinguish server exposure from client discovery.
 */
final class McpToolManifestTest extends TestCase {

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

	public function test_export_includes_exact_tools_list_payload_and_role_policy_context(): void {
		$registry = new AbilitiesRegistry();
		$policy   = new RoleAbilitiesPolicy();

		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item', 'media.delete_item' ) );
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
				'access_level'             => 'selective_write',
				'write_permission_enabled' => true,
			)
		);

		$names = array_column( $export['tools_list_payload']['tools'], 'name' );

		self::assertContains( 'content_get_item', $names );
		self::assertContains( 'media_delete_item', $names );
		self::assertNotContains( 'content_update_item', $names );
		self::assertContains( 'intelligence_site_get_context', $names );
		self::assertSame( 7, $export['ability_policy']['user_id'] );
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
		self::assertSame( 'tools/list', $export['json_rpc_method'] );
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
}
