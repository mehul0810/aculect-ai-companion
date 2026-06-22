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
use ReflectionMethod;

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
		self::assertArrayHasKey( 'support_safety', $export );
		self::assertTrue( $export['support_safety']['support_safe_by_default'] );
		self::assertFalse( $export['support_safety']['secret_values_included'] );
		self::assertFalse( $export['support_safety']['raw_request_bodies_included'] );
		self::assertFalse( $export['support_safety']['full_content_bodies_included'] );
		self::assertSame( 'tools/list', $export['json_rpc_method'] );
	}

	public function test_final_export_safety_pass_redacts_sensitive_future_payload_fields(): void {
		$manifest = new McpToolManifest();
		$redacted = 0;
		$payload  = array(
			'access_token'       => 'access-secret',
			'refresh_token'      => 'refresh-secret',
			'authorization_code' => 'auth-code-secret',
			'encryption_key'     => 'key-secret',
			'_wpnonce'           => 'nonce-secret',
			'request_body'       => '{"client_secret":"hidden"}',
			'post_content'       => '<!-- wp:paragraph --><p>Private draft.</p><!-- /wp:paragraph -->',
			'nested'             => array(
				'client_secret'   => 'client-secret',
				'body'            => 'raw request body',
				'securitySchemes' => array(
					array(
						'type'   => 'oauth2',
						'scopes' => array( 'content:read' ),
					),
				),
			),
		);
		$args     = array( $payload, '', 0, &$redacted );

		$result = $this->invokePrivate(
			$manifest,
			'sanitize_export_value',
			$args
		);
		$json   = wp_json_encode( $result );

		self::assertIsArray( $result );
		self::assertSame( '[redacted]', $result['access_token'] );
		self::assertSame( '[redacted]', $result['refresh_token'] );
		self::assertSame( '[redacted]', $result['authorization_code'] );
		self::assertSame( '[redacted]', $result['encryption_key'] );
		self::assertSame( '[redacted]', $result['_wpnonce'] );
		self::assertSame( '[redacted]', $result['request_body'] );
		self::assertSame( '[redacted]', $result['post_content'] );
		self::assertSame( '[redacted]', $result['nested']['client_secret'] );
		self::assertSame( '[redacted]', $result['nested']['body'] );
		self::assertSame( 'oauth2', $result['nested']['securitySchemes'][0]['type'] );
		self::assertGreaterThanOrEqual( 9, $redacted );
		self::assertIsString( $json );
		self::assertStringNotContainsString( 'access-secret', $json );
		self::assertStringNotContainsString( 'refresh-secret', $json );
		self::assertStringNotContainsString( 'auth-code-secret', $json );
		self::assertStringNotContainsString( 'client-secret', $json );
		self::assertStringNotContainsString( 'Private draft', $json );
	}

	public function test_export_reports_registered_first_party_wordpress_abilities(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$export = ( new McpToolManifest() )->export_for_current_user();

		self::assertGreaterThan( 0, $export['wordpress_abilities']['registered_first_party_count'] );
		self::assertSame( array(), $export['wordpress_abilities']['missing_first_party_names'] );
		self::assertTrue( $export['wordpress_abilities']['schema_valid'] );
		self::assertSame( 'available', $export['operations_manifest']['intelligence_index']['search_items']['wordpress_ability']['status'] );
		self::assertSame( 'aculect-ai-companion/content-search-items', $export['operations_manifest']['intelligence_index']['search_items']['wordpress_ability']['name'] );
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
	 * Invoke a private method for focused regression coverage.
	 *
	 * @param object       $object    Object instance.
	 * @param string       $method    Method name.
	 * @param array<mixed> $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}
}
