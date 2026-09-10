<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\ChecksumFileInspector;
use Aculect\AICompanion\Connectors\MCP\McpInputValidator;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies advertised schemas, permission discovery and mandatory write safety.
 */
final class SiteOperationsToolSurfaceTest extends TestCase {

	private const WRITES = array( 'navigation.update_item', 'content_fields.update_field', 'maintenance.clean_post_cache', 'maintenance.flush_rewrite_rules' );
	private const READS  = array( 'navigation.read_item', 'content_fields.list_fields', 'content_fields.read_field', 'site.inspect_rendered_page', 'integrity.check_core', 'integrity.check_plugin' );

	protected function setUp(): void {
		$GLOBALS['aculect_ai_companion_test_options']             = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
		$GLOBALS['aculect_ai_companion_test_current_user_id']     = 1;
		$GLOBALS['aculect_ai_companion_test_users']               = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Test Admin',
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = null;
	}

	public function test_new_modules_have_closed_schemas_and_stable_tool_names(): void {
		$registry = new AbilitiesRegistry();
		foreach ( array_merge( self::READS, self::WRITES ) as $id ) {
			$module = $registry->modules()[ $id ];
			self::assertSame( $id, $module->id() );
			self::assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/D', $registry->tool_name( $id ) );
			self::assertFalse( $module->input_schema()['additionalProperties'] );
			self::assertSame( in_array( $id, self::READS, true ), $module->is_read_only() );
			self::assertSame( array( in_array( $id, self::READS, true ) ? 'content:read' : 'content:draft' ), $module->required_scopes() );
			if ( ! $module->is_read_only() ) {
				foreach ( array( 'dry_run', 'confirmation_token', 'idempotency_key' ) as $control ) {
					self::assertArrayHasKey( $control, $module->input_schema()['properties'] );
				}
			}
		}
	}

	public function test_all_new_writes_require_confirmation_even_for_trusted_connections(): void {
		$safety  = new ToolSafety();
		$gateway = new AbilityExecutionGateway();
		$policy  = new ReflectionMethod( $gateway, 'write_permission_unblocks_tool' );
		foreach ( self::WRITES as $id ) {
			self::assertSame( 'system', $safety->risk_level( $id, array() ) );
			self::assertTrue( $safety->requires_confirmation( $id, array() ) );
			foreach ( array( array( 'access_level' => 'write' ), array( 'write_permission_enabled' => true ) ) as $auth ) {
				self::assertFalse( $policy->invoke( $gateway, $id, $auth, false ) );
			}
		}
		self::assertTrue( $policy->invoke( $gateway, 'content.update_item', array( 'write_permission_enabled' => true ), false ) );
	}

	public function test_privileged_tools_are_hidden_without_their_capabilities_or_scope(): void {
		$registry     = new AbilitiesRegistry();
		$availability = new McpToolAvailability();
		$all          = $availability->tool_modules_for_user( 1, $registry, null, array( 'content:read', 'content:draft' ) );
		foreach ( array_merge( self::READS, self::WRITES ) as $id ) {
			self::assertArrayHasKey( $id, $all );
		}
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_theme_options', 'manage_options', 'update_core', 'update_plugins' );
		$limited = $availability->tool_modules_for_user( 1, $registry, null, array( 'content:read', 'content:draft' ) );
		foreach ( array( 'navigation.read_item', 'navigation.update_item', 'maintenance.clean_post_cache', 'maintenance.flush_rewrite_rules', 'integrity.check_core', 'integrity.check_plugin' ) as $id ) {
			self::assertArrayNotHasKey( $id, $limited );
		}
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		$read = $availability->tool_modules_for_user( 1, $registry, null, array( 'content:read' ) );
		foreach ( self::WRITES as $id ) {
			self::assertArrayNotHasKey( $id, $read );
		}
	}

	public function test_field_schema_accepts_scalar_types_but_rejects_objects_and_missing_state(): void {
		$schema    = ( new AbilitiesRegistry() )->modules()['content_fields.update_field']->input_schema();
		$validator = new McpInputValidator();
		$args      = array(
			'post_id'        => 123,
			'key'            => 'rating',
			'expected_state' => str_repeat( 'a', 64 ),
		);
		foreach ( array( 'text', 1, 1.5, true, false ) as $value ) {
			self::assertNull( $validator->arguments_error( $args + array( 'value' => $value ), $schema ) );
		}
		foreach ( array( null, array(), new \stdClass(), str_repeat( 'x', 4001 ) ) as $value ) {
			self::assertNotNull( $validator->arguments_error( $args + array( 'value' => $value ), $schema ) );
		}
		unset( $args['expected_state'] );
		self::assertNotNull( $validator->arguments_error( $args + array( 'value' => 1 ), $schema ) );
	}

	public function test_checksum_paths_accept_real_manifest_assets_without_path_escape(): void {
		foreach ( array( '_inc/img/akismet-refresh-logo@2x.png', '.htaccess', 'assets/icon (dark)+2.svg', 'languages/café.json' ) as $path ) {
			self::assertTrue( ChecksumFileInspector::valid_path( $path ), $path );
		}
		foreach ( array( '', '/etc/passwd', '../outside', 'assets/../outside', 'php://input', 'C:\\secret', "assets/\x00file", 'assets//file', './file' ) as $path ) {
			self::assertFalse( ChecksumFileInspector::valid_path( $path ) );
		}
	}
}
