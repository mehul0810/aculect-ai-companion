<?php
/**
 * Provider profile fixture tests for MCP discovery diagnostics.
 *
 * @package Aculect\AICompanion\Tests\Unit\Diagnostics
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Diagnostics\McpToolManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verifies local provider/profile fixtures expose required workflow tools.
 */
final class McpProviderProfileFixturesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 21;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			21 => (object) array(
				'ID'           => 21,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);

		AbilitiesRegistry::reset_module_cache();
	}

	/**
	 * Verify one provider/profile fixture.
	 *
	 * @param string $provider       Provider slug.
	 * @param string $profile        Profile slug.
	 * @param array  $scopes         Granted OAuth scopes.
	 * @param array  $required_tools Required public MCP tool names.
	 * @phpstan-param list<string> $scopes
	 * @phpstan-param list<string> $required_tools
	 */
	#[DataProvider( 'providerProfileFixtures' )]
	public function test_provider_profile_fixtures_expose_required_workflow_tools( string $provider, string $profile, array $scopes, array $required_tools ): void {
		$export     = ( new McpToolManifest() )->export_for_current_user(
			array(
				'id'           => 100,
				'provider'     => $provider,
				'client_name'  => ucfirst( str_replace( '_', ' ', $provider ) ),
				'user_id'      => 21,
				'user_roles'   => array( 'administrator' ),
				'profile'      => $profile,
				'scopes'       => $scopes,
				'resource'     => 'https://example.com/wp-json/aculect-ai-companion/v1/mcp',
				'status'       => 'active',
				'access_level' => 'read',
			)
		);
		$tool_names = array_column( $export['tools_list_payload']['tools'], 'name' );

		self::assertSame( $profile, $export['profile']['id'] );
		self::assertSame( $tool_names, $export['profile']['visible_tools'] );
		self::assertSame( array_values( $tool_names ), $tool_names, 'Tool ordering should remain stable and list-based.' );
		self::assertSame( count( array_unique( $tool_names ) ), count( $tool_names ), 'Tool names should remain unique.' );

		foreach ( $required_tools as $tool_name ) {
			self::assertContains( $tool_name, $tool_names, sprintf( '%s profile %s should expose %s.', $provider, $profile, $tool_name ) );
		}
	}

	/**
	 * Return provider/profile parity fixtures.
	 *
	 * @return array<string, array{provider:string,profile:string,scopes:list<string>,required_tools:list<string>}>
	 */
	public static function providerProfileFixtures(): array {
		$providers = array( 'chatgpt', 'openai_api', 'claude', 'codex' );
		$profiles  = array(
			'read_only_audit'    => array(
				'scopes'         => array( 'content:read' ),
				'required_tools' => array( 'workflow_route_request', 'core_schema_discover', 'site_workflow_audit', 'workflow_guides_list', 'workflow_guides_get' ),
			),
			'content_management' => array(
				'scopes'         => array( 'content:read', 'content:draft' ),
				'required_tools' => array( 'workflow_route_request', 'content_workflow_prepare_post', 'content_workflow_create_draft', 'content_workflow_update_post', 'workflow_session_start' ),
			),
			'site_management'    => array(
				'scopes'         => array( 'content:read', 'content:draft' ),
				'required_tools' => array( 'workflow_route_request', 'site_workflow_audit', 'site_editor_get_context', 'admin_menu_get_context', 'core_schema_discover' ),
			),
		);
		$fixtures  = array();

		foreach ( $providers as $provider ) {
			foreach ( $profiles as $profile => $definition ) {
				$fixtures[ $provider . ':' . $profile ] = array(
					'provider'       => $provider,
					'profile'        => $profile,
					'scopes'         => $definition['scopes'],
					'required_tools' => $definition['required_tools'],
				);
			}
		}

		return $fixtures;
	}
}
