<?php
/**
 * Tests for user, role, and capability discovery.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use Aculect\AICompanion\Connectors\MCP\UserRoleCapabilitiesDiscovery;
use PHPUnit\Framework\TestCase;

/**
 * Verifies user-access discovery stays bounded, read-only, and privacy-safe.
 */
final class UserRoleCapabilitiesDiscoveryTest extends TestCase {

	private UserRoleCapabilitiesDiscovery $discovery;

	protected function setUp(): void {
		parent::setUp();

		$this->discovery = new UserRoleCapabilitiesDiscovery();

		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 7;
		$GLOBALS['aculect_ai_companion_test_roles']           = array(
			'administrator' => array(
				'name'         => 'Administrator',
				'capabilities' => array(
					'read'               => true,
					'edit_posts'         => true,
					'publish_posts'      => true,
					'upload_files'       => true,
					'moderate_comments'  => true,
					'manage_options'     => true,
					'edit_theme_options' => true,
					'activate_plugins'   => true,
					'switch_themes'      => true,
					'list_users'         => true,
					'promote_users'      => true,
					'create_users'       => true,
					'delete_users'       => true,
				),
			),
			'editor'        => array(
				'name'         => 'Editor',
				'capabilities' => array(
					'read'              => true,
					'edit_posts'        => true,
					'publish_posts'     => true,
					'upload_files'      => true,
					'moderate_comments' => true,
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			7  => (object) array(
				'ID'                    => 7,
				'roles'                 => array( 'editor' ),
				'display_name'          => 'Ed Editor',
				'user_login'            => 'ed',
				'user_email'            => 'ed@example.com',
				'user_pass'             => '$P$secret-hash',
				'application_passwords' => array( 'secret' ),
				'allcaps'               => array( 'manage_options' => true ),
				'caps'                  => array( 'editor' => true ),
			),
			21 => (object) array(
				'ID'             => 21,
				'roles'          => array( 'administrator' ),
				'display_name'   => 'Ada Admin',
				'user_email'     => 'ada@example.com',
				'session_tokens' => array( 'secret' ),
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_roles']           = array(
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor' ),
			'author'        => array( 'name' => 'Author' ),
		);
		$GLOBALS['aculect_ai_companion_test_users']           = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;

		parent::tearDown();
	}

	public function test_current_access_summary_returns_safe_current_user_booleans(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'upload_files', 'edit_theme_options', 'list_users', 'promote_users' );

		$result = $this->discovery->current_access_summary();

		self::assertSame( 7, $result['user_id'] );
		self::assertSame( array( 'editor' ), $result['roles'] );
		self::assertTrue( $result['capabilities']['read'] );
		self::assertFalse( $result['capabilities']['upload_files'] );
		self::assertFalse( $result['capabilities']['promote_users'] );
		self::assertContains( 'media_upload_unavailable', $result['blocked_unavailable'] );
		self::assertContains( 'role_summary_unavailable', $result['blocked_unavailable'] );
		self::assertTrue( $result['read_only'] );
		self::assertContains( 'email', $result['privacy']['excluded_fields'] );
		self::assertStringNotContainsString( 'ed@example.com', wp_json_encode( $result ) );
		self::assertStringNotContainsString( 'secret', wp_json_encode( $result ) );
		self::assertArrayNotHasKey( 'allcaps', $result );
	}

	public function test_roles_summary_requires_promote_users_and_returns_curated_categories(): void {
		$result = $this->discovery->roles_summary();

		self::assertSame( 2, $result['total'] );
		self::assertTrue( $result['bounded'] );
		self::assertSame( 'promote_users', $result['required_capability'] );

		$roles = array_column( $result['items'], null, 'slug' );
		self::assertSame( 'Administrator', $roles['administrator']['label'] );
		self::assertSame( 1, $roles['administrator']['user_count'] );
		self::assertTrue( $roles['administrator']['capability_categories']['site_admin']['can_manage_options'] );
		self::assertTrue( $roles['administrator']['capability_categories']['user_access']['can_promote_users'] );
		self::assertFalse( $roles['editor']['capability_categories']['site_admin']['can_manage_options'] );
		self::assertArrayNotHasKey( 'capabilities', $roles['administrator'] );
		self::assertStringNotContainsString( 'ed@example.com', wp_json_encode( $result ) );
		self::assertStringNotContainsString( '$P$secret-hash', wp_json_encode( $result ) );
	}

	public function test_roles_summary_denies_without_promote_users(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'promote_users' );

		$result = $this->discovery->roles_summary();

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_safe_user_list_is_capped_and_redacted(): void {
		$result = $this->discovery->list_safe_users(
			array(
				'per_page' => 500,
				'role'     => 'administrator',
			)
		);

		self::assertSame( 50, $result['per_page'] );
		self::assertSame( 'administrator', $result['role_filter'] );
		self::assertSame( 1, count( $result['items'] ) );
		self::assertSame( 21, $result['items'][0]['id'] );
		self::assertSame( 'Ada Admin', $result['items'][0]['display_name'] );
		self::assertSame( array( 'administrator' ), $result['items'][0]['roles'] );
		self::assertStringNotContainsString( 'ada@example.com', wp_json_encode( $result ) );
		self::assertStringNotContainsString( 'session_tokens', wp_json_encode( $result ) );
		self::assertStringNotContainsString( '$P$secret-hash', wp_json_encode( $result ) );
	}

	public function test_safe_user_list_denies_without_list_users(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'list_users' );

		$result = $this->discovery->list_safe_users( array() );

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_registry_and_operations_manifest_apply_default_and_capability_policy(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array() );

		self::assertTrue( $registry->is_core_default( 'users.current_access' ) );
		self::assertTrue( $registry->is_core_default( 'users.roles_summary' ) );
		self::assertFalse( $registry->is_core_default( 'users.list_safe' ) );
		self::assertFalse( $registry->is_configurable( 'users.current_access' ) );
		self::assertTrue( $registry->is_configurable( 'users.list_safe' ) );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'promote_users', 'list_users' );
		$operations                                       = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry, array( 'content:read' ) );

		self::assertTrue( $operations['user_access']['current_access']['available'] );
		self::assertTrue( $operations['user_access']['current_access']['core_default'] );
		self::assertFalse( $operations['user_access']['roles_summary']['available'] );
		self::assertSame( 'capability', $operations['user_access']['roles_summary']['blocked_by'] );
		self::assertFalse( $operations['user_access']['list_safe']['available'] );
		self::assertSame( 'global_disabled', $operations['user_access']['list_safe']['blocked_by'] );
	}
}
