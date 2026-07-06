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
use Aculect\AICompanion\Connectors\MCP\McpToolProfiles;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesRegistrar;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/wordpress-abilities-stubs.php';

/**
 * Verifies the intelligence operation map and MCP discovery share policy.
 */
final class McpToolAvailabilityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']  = 7;
		$GLOBALS['aculect_ai_companion_test_denied_caps']      = array();
		$GLOBALS['aculect_ai_companion_test_wp_abilities']     = array();
		$GLOBALS['aculect_ai_companion_test_users']            = array(
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
			21 => (object) array(
				'ID'           => 21,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
	}

	public function test_profile_resolution_prefers_connection_override_then_role_and_global_defaults(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'editor' );

		$profiles = new McpToolProfiles();

		update_option(
			McpToolProfiles::OPTION_ROLE_DEFAULT_PROFILES,
			array(
				'editor' => McpToolProfiles::PROFILE_SITE_MANAGEMENT,
			),
			false
		);
		update_option( McpToolProfiles::OPTION_GLOBAL_DEFAULT_PROFILE, McpToolProfiles::PROFILE_CONTENT_MANAGEMENT, false );

		$connection = $profiles->resolve_for_user(
			7,
			null,
			array(
				'connection_profile' => McpToolProfiles::PROFILE_READ_ONLY_AUDIT,
			)
		);
		$role       = $profiles->resolve_for_user( 7 );
		$global     = $profiles->resolve_for_user( 13 );

		self::assertSame( McpToolProfiles::PROFILE_READ_ONLY_AUDIT, $connection['id'] );
		self::assertSame( 'connection_override', $connection['source'] );
		self::assertContains( 'Theme Lifecycle', $connection['profile']['included_groups'] );
		self::assertSame( McpToolProfiles::PROFILE_SITE_MANAGEMENT, $role['id'] );
		self::assertSame( 'role_default', $role['source'] );
		self::assertContains( 'Theme Lifecycle', $role['profile']['included_groups'] );
		self::assertSame( McpToolProfiles::PROFILE_CONTENT_MANAGEMENT, $global['id'] );
		self::assertSame( 'global_default', $global['source'] );
	}

	public function test_profile_resolution_uses_safe_fallback_when_configured_sources_are_unknown(): void {
		$profiles = new McpToolProfiles();

		update_option( McpToolProfiles::OPTION_GLOBAL_DEFAULT_PROFILE, 'unknown-profile', false );

		$resolved = $profiles->resolve_for_user(
			7,
			null,
			array(
				'connection_profile'   => 'also-unknown',
				'role_default_profile' => 'not-a-profile',
			)
		);

		self::assertSame( McpToolProfiles::PROFILE_READ_ONLY_AUDIT, $resolved['id'] );
		self::assertSame( 'safe_fallback', $resolved['source'] );
		self::assertTrue( $resolved['fallback'] );
	}

	public function test_read_only_audit_profile_exposes_no_write_capable_tools(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array_keys( $registry->configurable_definitions() ) );

		$modules = ( new McpToolAvailability() )->tool_modules_for_user(
			7,
			$registry,
			null,
			array( 'content:read', 'content:draft' ),
			array( 'connection_profile' => McpToolProfiles::PROFILE_READ_ONLY_AUDIT )
		);

		self::assertNotEmpty( $modules );
		foreach ( $modules as $module ) {
			self::assertTrue( $module->is_read_only(), $module->id() . ' should be hidden by the read-only audit profile.' );
		}

		self::assertArrayNotHasKey( 'content.update_item', $modules );
		self::assertArrayNotHasKey( 'memory.save', $modules );
	}

	public function test_profile_hidden_tools_are_reported_separately_from_existing_blockers(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item', 'media.delete_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user(
			7,
			$registry,
			array( 'content:read', 'content:draft' ),
			array( 'connection_profile' => McpToolProfiles::PROFILE_READ_ONLY_AUDIT )
		);

		self::assertSame( McpToolProfiles::PROFILE_READ_ONLY_AUDIT, $operations['policy']['profile_id'] );
		self::assertSame( 'connection_override', $operations['policy']['profile_source'] );
		self::assertTrue( $operations['content']['get_item']['available'] );
		self::assertArrayNotHasKey( 'blocked_by', $operations['content']['get_item'] );
		self::assertFalse( $operations['content']['update']['available'] );
		self::assertSame( 'hidden_by_profile', $operations['content']['update']['blocked_by'] );
		self::assertContains( 'content.update_item', $operations['policy']['hidden_by_profile_ids'] );

		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user(
			7,
			$registry,
			array( 'content:read', 'content:draft' ),
			array( 'connection_profile' => McpToolProfiles::PROFILE_READ_ONLY_AUDIT )
		);

		self::assertFalse( $operations['media']['trash']['available'] );
		self::assertSame( 'global_disabled', $operations['media']['trash']['blocked_by'] );
	}

	public function test_custom_profile_filter_can_only_narrow_known_groups(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_tool_profiles'] = static function ( array $profiles ): array {
			$profiles['custom_content_read'] = array(
				'id'                => 'custom_content_read',
				'label'             => 'Custom content read',
				'description'       => 'Read-only content group profile.',
				'included_groups'   => array( 'Content', 'Not A Real Group' ),
				'hidden_groups'     => array( 'Media' ),
				'read_only_default' => true,
				'risk_level'        => 'read-only',
				'tool_ids'          => array( 'content.update_item' ),
			);

			return $profiles;
		};

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item', 'media.list_items' ) );

		$resolved = ( new McpToolProfiles() )->resolve_for_user(
			7,
			$registry,
			array( 'connection_profile' => 'custom_content_read' )
		);
		$modules  = ( new McpToolAvailability() )->tool_modules_for_user(
			7,
			$registry,
			null,
			array( 'content:read', 'content:draft' ),
			array( 'connection_profile' => 'custom_content_read' )
		);

		self::assertSame( array( 'Content' ), $resolved['profile']['included_groups'] );
		self::assertSame( array( 'Media' ), $resolved['profile']['hidden_groups'] );
		self::assertArrayHasKey( 'content.get_item', $modules );
		self::assertArrayNotHasKey( 'content.update_item', $modules );
		self::assertArrayNotHasKey( 'media.list_items', $modules );
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
		RoleAbilitiesPolicy::set_editing_enabled( true );
		$policy->save_role_policy( 'editor', array( 'content.get_item', 'media.delete_item' ), $registry );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );
		$tools      = ( new McpController() )->tool_manifest_for_current_user();
		$tool_names = array_column( $tools['tools'], 'name' );

		self::assertTrue( $operations['content']['get_item']['available'] );
		self::assertArrayNotHasKey( 'blocked_by', $operations['content']['get_item'] );
		self::assertArrayHasKey( 'wordpress_ability', $operations['content']['get_item'] );
		self::assertFalse( $operations['content']['get_item']['wordpress_ability']['mirrored'] );
		self::assertSame( 'not_applicable', $operations['content']['get_item']['wordpress_ability']['registration_status'] );
		self::assertSame( 'not_mirrored', $operations['content']['get_item']['wordpress_ability']['status'] );
		self::assertSame(
			array(
				'mcp'                 => true,
				'wordpress_abilities' => false,
				'summary'             => 'mcp_only',
				'wordpress_status'    => 'not_mirrored',
			),
			$operations['content']['get_item']['availability_channels']
		);
		self::assertContains( 'content_get_item', $tool_names );

		self::assertFalse( $operations['content']['update']['available'] );
		self::assertSame( 'role_policy', $operations['content']['update']['blocked_by'] );
		self::assertSame( 'neither', $operations['content']['update']['availability_channels']['summary'] );
		self::assertNotContains( 'content_update_item', $tool_names );

		self::assertFalse( $operations['content']['list_items']['available'] );
		self::assertSame( 'global_disabled', $operations['content']['list_items']['blocked_by'] );
		self::assertSame( 'neither', $operations['content']['list_items']['availability_channels']['summary'] );
		self::assertNotContains( 'content_list_items', $tool_names );
	}

	public function test_operations_manifest_keeps_existing_entry_fields_when_channel_metadata_is_added(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item' ) );

		$entry = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry )['content']['get_item'];

		foreach ( array( 'tool', 'available', 'required_scopes', 'read_only', 'core_default', 'configurable', 'wordpress_ability' ) as $legacy_key ) {
			self::assertArrayHasKey( $legacy_key, $entry );
		}

		self::assertArrayHasKey( 'availability_channels', $entry );
		self::assertSame( 'content_get_item', $entry['tool'] );
		self::assertTrue( $entry['available'] );
		self::assertArrayNotHasKey( 'blocked_by', $entry );
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

	public function test_revision_and_autosave_discovery_are_default_active_read_tools(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.update_item' ) );

		$modules = ( new McpToolAvailability() )->tool_modules_for_user( 7, $registry, null, array( 'content:read' ) );

		self::assertArrayHasKey( 'navigation.get_context', $modules );
		self::assertArrayHasKey( 'navigation.list_items', $modules );
		self::assertArrayHasKey( 'content_revisions.list', $modules );
		self::assertArrayHasKey( 'content_autosaves.inspect', $modules );
		self::assertTrue( $registry->is_core_default( 'navigation_get_context' ) );
		self::assertTrue( $registry->is_core_default( 'content_revisions_list' ) );
		self::assertTrue( $registry->is_core_default( 'content_autosaves.inspect' ) );
		self::assertTrue( $modules['content_revisions.list']->is_read_only() );
		self::assertSame( array( 'content:read' ), $modules['content_autosaves.inspect']->required_scopes() );
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

	public function test_admin_inherits_user_enabled_workflows_when_dependencies_are_allowed(): void {
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 21;

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.create_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 21, $registry, array( 'content:read', 'content:draft' ) );
		$tools      = ( new McpController() )->tool_manifest_for_current_user();
		$tool_names = array_column( $tools['tools'], 'name' );

		self::assertArrayHasKey( 'workflows', $operations );
		self::assertTrue( $operations['workflows']['create_draft']['available'] );
		self::assertArrayNotHasKey( 'blocked_by', $operations['workflows']['create_draft'] );
		self::assertTrue( $operations['workflows']['create_draft']['derived'] );
		self::assertSame( array( 'content.create_item' ), $operations['workflows']['create_draft']['dependency_ids'] );
		self::assertSame( 'derived_from_allowed_dependencies', $operations['workflows']['create_draft']['availability_model'] );
		self::assertContains( 'content_workflow_create_draft', $tool_names );
	}

	public function test_workflow_operations_are_blocked_when_atomic_operations_are_globally_disabled(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );
		$tools      = ( new McpController() )->tool_manifest_for_current_user();
		$tool_names = array_column( $tools['tools'], 'name' );

		self::assertFalse( $operations['content']['create']['available'] );
		self::assertSame( 'global_disabled', $operations['content']['create']['blocked_by'] );
		self::assertFalse( $operations['workflows']['create_draft']['available'] );
		self::assertSame( 'global_disabled:content.create_item', $operations['workflows']['create_draft']['blocked_by'] );
		self::assertNotContains( 'content_workflow_create_draft', $tool_names );
	}

	public function test_non_admin_workflows_keep_existing_role_policy_behavior(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'editor' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.create_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );

		self::assertFalse( $operations['content']['create']['available'] );
		self::assertSame( 'role_default_read_only', $operations['content']['create']['blocked_by'] );
		self::assertFalse( $operations['workflows']['create_draft']['available'] );
		self::assertSame( 'role_default_read_only:content.create_item', $operations['workflows']['create_draft']['blocked_by'] );
		self::assertArrayNotHasKey( 'content_workflow.create_draft', ( new McpToolAvailability() )->tool_modules_for_user( 7, $registry ) );

		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );
		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry, array( 'content:read' ) );

		self::assertFalse( $operations['workflows']['create_draft']['available'] );
		self::assertSame( 'oauth_scope', $operations['workflows']['create_draft']['blocked_by'] );
		self::assertSame( array( 'content:draft' ), $operations['workflows']['create_draft']['missing_scopes'] );
	}

	public function test_workflow_operations_respect_explicit_non_admin_role_enablement(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'editor' );

		$registry = new AbilitiesRegistry();
		$policy   = new RoleAbilitiesPolicy();

		$registry->save_enabled_ids( array( 'content.create_item' ) );
		RoleAbilitiesPolicy::set_editing_enabled( true );
		$policy->save_role_policy( 'editor', array( 'content.create_item' ), $registry );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry, array( 'content:read', 'content:draft' ) );
		$modules    = ( new McpToolAvailability() )->tool_modules_for_user( 7, $registry, null, array( 'content:read', 'content:draft' ) );

		self::assertTrue( $operations['content']['create']['available'] );
		self::assertTrue( $operations['workflows']['create_draft']['available'] );
		self::assertArrayHasKey( 'content_workflow.create_draft', $modules );
	}

	public function test_workflow_operations_respect_static_capability_dependency_blocks(): void {
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 21;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array( 'manage_options' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'site.get_info', 'site.get_health' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 21, $registry, array( 'content:read' ) );
		$modules    = ( new McpToolAvailability() )->tool_modules_for_user( 21, $registry, null, array( 'content:read' ) );

		self::assertTrue( $operations['site_information']['get_info']['available'] );
		self::assertFalse( $operations['site_information']['get_health']['available'] );
		self::assertSame( 'capability', $operations['site_information']['get_health']['blocked_by'] );
		self::assertFalse( $operations['workflows']['site_audit']['available'] );
		self::assertSame( 'capability:site.get_health', $operations['workflows']['site_audit']['blocked_by'] );
		self::assertArrayNotHasKey( 'site_workflow.audit', $modules );
	}

	public function test_plugin_lifecycle_operations_report_availability_and_capability_blocks(): void {
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 21;

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'plugin_lifecycle.list_plugins', 'plugin_lifecycle.get_plugin', 'plugin_lifecycle.activate_plugin', 'plugin_lifecycle.deactivate_plugin' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 21, $registry, array( 'content:read' ) );
		$write_ops  = ( new McpToolAvailability() )->operations_manifest_for_user( 21, $registry, array( 'content:draft' ) );
		$tools      = ( new McpController() )->tool_manifest_for_current_user();
		$tool_names = array_column( $tools['tools'], 'name' );

		self::assertTrue( $operations['plugin_lifecycle']['list_plugins']['available'] );
		self::assertSame( 'plugin_lifecycle_list_plugins', $operations['plugin_lifecycle']['list_plugins']['tool'] );
		self::assertSame( array( 'content:read' ), $operations['plugin_lifecycle']['list_plugins']['required_scopes'] );
		self::assertTrue( $operations['plugin_lifecycle']['list_plugins']['read_only'] );
		self::assertContains( 'plugin_lifecycle_list_plugins', $tool_names );
		self::assertTrue( $operations['plugin_lifecycle']['get_plugin']['available'] );
		self::assertTrue( $write_ops['plugin_lifecycle']['activate_plugin']['available'] );
		self::assertSame( array( 'content:draft' ), $write_ops['plugin_lifecycle']['activate_plugin']['required_scopes'] );
		self::assertFalse( $write_ops['plugin_lifecycle']['activate_plugin']['read_only'] );
		self::assertTrue( $write_ops['plugin_lifecycle']['deactivate_plugin']['available'] );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'activate_plugins' );

		$blocked = ( new McpToolAvailability() )->operations_manifest_for_user( 21, $registry, array( 'content:draft' ) );

		self::assertFalse( $blocked['plugin_lifecycle']['list_plugins']['available'] );
		self::assertSame( 'capability', $blocked['plugin_lifecycle']['list_plugins']['blocked_by'] );
		self::assertFalse( $blocked['plugin_lifecycle']['get_plugin']['available'] );
		self::assertSame( 'capability', $blocked['plugin_lifecycle']['get_plugin']['blocked_by'] );
		self::assertFalse( $blocked['plugin_lifecycle']['activate_plugin']['available'] );
		self::assertSame( 'capability', $blocked['plugin_lifecycle']['activate_plugin']['blocked_by'] );
		self::assertFalse( $blocked['plugin_lifecycle']['deactivate_plugin']['available'] );
		self::assertSame( 'capability', $blocked['plugin_lifecycle']['deactivate_plugin']['blocked_by'] );
	}

	public function test_theme_lifecycle_operations_report_availability_and_capability_blocks(): void {
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 21;

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'theme_lifecycle.list_themes', 'theme_lifecycle.get_theme' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 21, $registry, array( 'content:read' ) );
		$tools      = ( new McpController() )->tool_manifest_for_current_user();
		$tool_names = array_column( $tools['tools'], 'name' );

		self::assertTrue( $operations['theme_lifecycle']['list_themes']['available'] );
		self::assertSame( 'theme_lifecycle_list_themes', $operations['theme_lifecycle']['list_themes']['tool'] );
		self::assertSame( array( 'content:read' ), $operations['theme_lifecycle']['list_themes']['required_scopes'] );
		self::assertTrue( $operations['theme_lifecycle']['list_themes']['read_only'] );
		self::assertContains( 'theme_lifecycle_list_themes', $tool_names );
		self::assertTrue( $operations['theme_lifecycle']['get_theme']['available'] );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'switch_themes' );

		$blocked = ( new McpToolAvailability() )->operations_manifest_for_user( 21, $registry, array( 'content:read' ) );

		self::assertFalse( $blocked['theme_lifecycle']['list_themes']['available'] );
		self::assertSame( 'capability', $blocked['theme_lifecycle']['list_themes']['blocked_by'] );
		self::assertFalse( $blocked['theme_lifecycle']['get_theme']['available'] );
		self::assertSame( 'capability', $blocked['theme_lifecycle']['get_theme']['blocked_by'] );
	}

	public function test_wordpress_ability_diagnostics_report_capability_blocks(): void {
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 21;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array( 'manage_options' );

		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 21, null, array( 'content:read' ) );

		self::assertFalse( $operations['admin_menu']['get_context']['available'] );
		self::assertSame( 'capability', $operations['admin_menu']['get_context']['blocked_by'] );
		self::assertSame( 'capability_blocked', $operations['admin_menu']['get_context']['wordpress_ability']['status'] );
		self::assertFalse( $operations['admin_menu']['get_context']['wordpress_ability']['capable'] );
		self::assertSame( 'registered', $operations['admin_menu']['get_context']['wordpress_ability']['registration_status'] );
		self::assertSame( 'valid', $operations['admin_menu']['get_context']['wordpress_ability']['schema_status'] );
		self::assertSame( 'allowed', $operations['admin_menu']['get_context']['wordpress_ability']['policy_status'] );
		self::assertSame( 'blocked', $operations['admin_menu']['get_context']['wordpress_ability']['permission_status'] );
		self::assertSame( 'neither', $operations['admin_menu']['get_context']['availability_channels']['summary'] );
	}

	public function test_registered_wordpress_ability_operations_report_both_availability_channels(): void {
		( new WordPressAbilitiesRegistrar() )->register_abilities();

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, null, array( 'content:read' ) );

		self::assertTrue( $operations['intelligence_index']['search_items']['available'] );
		self::assertSame( 'available', $operations['intelligence_index']['search_items']['wordpress_ability']['status'] );
		self::assertSame(
			array(
				'mcp'                 => true,
				'wordpress_abilities' => true,
				'summary'             => 'both',
				'wordpress_status'    => 'available',
			),
			$operations['intelligence_index']['search_items']['availability_channels']
		);
	}

	public function test_intelligence_index_operations_are_reported_with_read_and_write_policy(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'editor' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids(
			array(
				'content_search.items',
				'content_search.chunks',
				'content_internal_link.policy',
				'content_find.internal_links',
				'content_audit.internal_links',
				'memory.list',
				'memory.save',
				'memory.bootstrap',
			)
		);

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );

		self::assertTrue( $operations['intelligence_index']['search_items']['available'] );
		self::assertTrue( $operations['intelligence_index']['search_items']['wordpress_ability']['mirrored'] );
			self::assertContains(
				$operations['intelligence_index']['search_items']['wordpress_ability']['status'],
				array( 'abilities_api_unavailable', 'missing_registration', 'available' )
			);
		self::assertContains(
			$operations['intelligence_index']['search_items']['availability_channels']['summary'],
			array( 'both', 'mcp_only' )
		);
		self::assertTrue( $operations['intelligence_index']['search_chunks']['available'] );
		self::assertTrue( $operations['intelligence_index']['canonical_search']['available'] );
		self::assertTrue( $operations['intelligence_index']['canonical_fetch']['available'] );
		self::assertTrue( $operations['workflow_guides']['list']['available'] );
		self::assertTrue( $operations['workflow_guides']['get']['available'] );
		self::assertTrue( $operations['workflow_guides']['list']['always_on'] );
		self::assertTrue( $operations['workflow_guides']['session_start']['available'] );
		self::assertTrue( $operations['workflow_guides']['session_start']['always_on'] );
		self::assertSame( 'always_on_write_intelligence', $operations['workflow_guides']['session_start']['availability_model'] );
		self::assertTrue( $operations['workflow_guides']['session_get']['available'] );
		self::assertTrue( $operations['workflow_guides']['session_update']['available'] );
		self::assertTrue( $operations['intelligence_index']['internal_link_policy']['available'] );
		self::assertTrue( $operations['intelligence_index']['internal_links']['available'] );
		self::assertTrue( $operations['intelligence_index']['internal_link_audit']['available'] );
		self::assertTrue( $operations['intelligence_index']['memory_list']['available'] );
		self::assertTrue( $operations['intelligence_index']['memory_save']['available'] );
		self::assertTrue( $operations['intelligence_index']['memory_save']['always_on'] );
		self::assertSame( 'always_on_write_intelligence', $operations['intelligence_index']['memory_save']['availability_model'] );
		self::assertTrue( $operations['intelligence_index']['memory_bootstrap']['available'] );
		self::assertTrue( $operations['intelligence_index']['memory_bootstrap']['always_on'] );
		self::assertSame( 'always_on_write_intelligence', $operations['intelligence_index']['memory_bootstrap']['availability_model'] );
		self::assertTrue( $operations['intelligence_index']['activity_learning']['available'] );
		self::assertTrue( $operations['intelligence_index']['activity_learning']['always_on'] );
	}

	public function test_read_only_intelligence_retrieval_is_available_by_default(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'editor' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry );
		$modules    = ( new McpToolAvailability() )->tool_modules_for_user( 7, $registry );

		self::assertTrue( $operations['intelligence_index']['search_items']['available'] );
		self::assertTrue( $operations['intelligence_index']['search_chunks']['available'] );
		self::assertTrue( $operations['intelligence_index']['canonical_search']['available'] );
		self::assertTrue( $operations['intelligence_index']['canonical_fetch']['available'] );
		self::assertTrue( $operations['workflow_guides']['list']['available'] );
		self::assertTrue( $operations['workflow_guides']['get']['available'] );
		self::assertTrue( $operations['intelligence_index']['find_related']['available'] );
		self::assertTrue( $operations['intelligence_index']['internal_link_policy']['available'] );
		self::assertTrue( $operations['intelligence_index']['internal_links']['available'] );
		self::assertTrue( $operations['intelligence_index']['internal_link_audit']['available'] );
		self::assertTrue( $operations['intelligence_index']['memory_list']['available'] );
		self::assertTrue( $operations['intelligence_index']['memory_save']['available'] );
		self::assertTrue( $operations['intelligence_index']['memory_bootstrap']['available'] );
		self::assertTrue( $operations['intelligence_index']['batch_status']['available'] );
		self::assertTrue( $operations['intelligence_index']['search_items']['always_on'] );
		self::assertTrue( $operations['intelligence_index']['search_items']['core_default'] );
		self::assertFalse( $operations['intelligence_index']['search_items']['configurable'] );
		self::assertTrue( $operations['intelligence_index']['internal_link_audit']['always_on'] );
		self::assertTrue( $operations['intelligence_index']['internal_link_policy']['always_on'] );
		self::assertTrue( $operations['intelligence_index']['canonical_search']['always_on'] );
		self::assertTrue( $operations['intelligence_index']['memory_save']['always_on'] );
		self::assertTrue( $operations['site_editor']['get_context']['available'] );
		self::assertTrue( $operations['site_editor']['get_context']['always_on'] );
		self::assertTrue( $operations['site_editor']['get_context']['core_default'] );
		self::assertFalse( $operations['site_editor']['get_context']['configurable'] );
		self::assertSame( 'core_default_read', $operations['site_editor']['get_context']['availability_model'] );
		self::assertTrue( $operations['site_editor']['refresh_context']['available'] );
		self::assertTrue( $operations['site_editor']['refresh_context']['always_on'] );
		self::assertSame( 'always_on_write_intelligence', $operations['site_editor']['refresh_context']['availability_model'] );
		self::assertTrue( $operations['admin_menu']['get_context']['available'] );
		self::assertTrue( $operations['admin_menu']['get_context']['always_on'] );
		self::assertTrue( $operations['admin_menu']['list_settings']['available'] );
		self::assertTrue( $operations['navigation']['get_context']['available'] );
		self::assertTrue( $operations['navigation']['list_items']['available'] );
		self::assertTrue( $operations['navigation']['get_context']['always_on'] );
		self::assertSame( 'core_default_read', $operations['navigation']['get_context']['availability_model'] );
		self::assertSame( 'search', $operations['intelligence_index']['canonical_search']['tool'] );
		self::assertSame( 'fetch', $operations['intelligence_index']['canonical_fetch']['tool'] );
		self::assertSame( 'core_default_read', $operations['intelligence_index']['search_items']['availability_model'] );
		self::assertSame( 'core_default_read', $operations['intelligence_index']['internal_link_policy']['availability_model'] );
		self::assertSame( 'core_default_read', $operations['intelligence_index']['internal_link_audit']['availability_model'] );
		self::assertSame( 'always_on_write_intelligence', $operations['intelligence_index']['memory_save']['availability_model'] );
		self::assertArrayHasKey( 'search', $modules );
		self::assertArrayHasKey( 'fetch', $modules );
		self::assertSame( 'core_default_read', $operations['workflow_guides']['list']['availability_model'] );
		self::assertArrayHasKey( 'workflow_guides.list', $modules );
		self::assertArrayHasKey( 'workflow_guides.get', $modules );
		self::assertArrayHasKey( 'site_editor.get_context', $modules );
		self::assertArrayHasKey( 'site_editor.refresh_context', $modules );
		self::assertArrayHasKey( 'admin_menu.get_context', $modules );
		self::assertArrayHasKey( 'admin_menu.list_settings', $modules );
		self::assertArrayHasKey( 'navigation.get_context', $modules );
		self::assertArrayHasKey( 'navigation.list_items', $modules );
		self::assertArrayHasKey( 'content_search.items', $modules );
		self::assertArrayHasKey( 'content_internal_link.policy', $modules );
		self::assertArrayHasKey( 'content_audit.internal_links', $modules );
		self::assertArrayHasKey( 'memory.list', $modules );
		self::assertArrayHasKey( 'memory.save', $modules );
		self::assertArrayHasKey( 'memory.bootstrap', $modules );
	}

	public function test_read_only_intelligence_retrieval_respects_oauth_scope_blocks(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry, array() );
		$modules    = ( new McpToolAvailability() )->tool_modules_for_user( 7, $registry, null, array() );

		self::assertFalse( $operations['intelligence_index']['search_items']['available'] );
		self::assertFalse( $operations['intelligence_index']['internal_link_policy']['available'] );
		self::assertFalse( $operations['intelligence_index']['internal_link_audit']['available'] );
		self::assertFalse( $operations['intelligence_index']['canonical_search']['available'] );
		self::assertFalse( $operations['intelligence_index']['canonical_fetch']['available'] );
		self::assertFalse( $operations['intelligence_index']['memory_save']['available'] );
		self::assertFalse( $operations['intelligence_index']['memory_bootstrap']['available'] );
		self::assertFalse( $operations['site_editor']['get_context']['available'] );
		self::assertFalse( $operations['site_editor']['refresh_context']['available'] );
		self::assertFalse( $operations['admin_menu']['get_context']['available'] );
		self::assertFalse( $operations['admin_menu']['list_settings']['available'] );
		self::assertFalse( $operations['navigation']['get_context']['available'] );
		self::assertFalse( $operations['navigation']['list_items']['available'] );
		self::assertSame( 'oauth_scope', $operations['intelligence_index']['search_items']['blocked_by'] );
		self::assertSame( 'oauth_scope', $operations['intelligence_index']['internal_link_policy']['blocked_by'] );
		self::assertSame( 'oauth_scope', $operations['intelligence_index']['internal_link_audit']['blocked_by'] );
		self::assertSame( 'oauth_scope', $operations['intelligence_index']['canonical_search']['blocked_by'] );
		self::assertSame( 'oauth_scope', $operations['intelligence_index']['memory_save']['blocked_by'] );
		self::assertSame( 'oauth_scope', $operations['intelligence_index']['memory_bootstrap']['blocked_by'] );
		self::assertSame( 'oauth_scope', $operations['site_editor']['get_context']['blocked_by'] );
		self::assertSame( 'oauth_scope', $operations['admin_menu']['get_context']['blocked_by'] );
		self::assertSame( 'oauth_scope', $operations['navigation']['get_context']['blocked_by'] );
		self::assertSame( array( 'content:read' ), $operations['intelligence_index']['search_items']['missing_scopes'] );
		self::assertSame( array( 'content:read' ), $operations['intelligence_index']['internal_link_policy']['missing_scopes'] );
		self::assertSame( array( 'content:read' ), $operations['intelligence_index']['internal_link_audit']['missing_scopes'] );
		self::assertSame( array( 'content:read' ), $operations['intelligence_index']['canonical_fetch']['missing_scopes'] );
		self::assertSame( array( 'content:draft' ), $operations['intelligence_index']['memory_save']['missing_scopes'] );
		self::assertArrayNotHasKey( 'search', $modules );
		self::assertArrayNotHasKey( 'content_internal_link.policy', $modules );
		self::assertArrayNotHasKey( 'content_audit.internal_links', $modules );
		self::assertArrayNotHasKey( 'fetch', $modules );
		self::assertFalse( $operations['workflow_guides']['list']['available'] );
		self::assertFalse( $operations['workflow_guides']['get']['available'] );
		self::assertSame( 'oauth_scope', $operations['workflow_guides']['list']['blocked_by'] );
		self::assertSame( array( 'content:read' ), $operations['workflow_guides']['get']['missing_scopes'] );
		self::assertArrayNotHasKey( 'workflow_guides.list', $modules );
		self::assertArrayNotHasKey( 'workflow_guides.get', $modules );
		self::assertArrayNotHasKey( 'site_editor.get_context', $modules );
		self::assertArrayNotHasKey( 'admin_menu.get_context', $modules );
		self::assertArrayNotHasKey( 'navigation.get_context', $modules );
		self::assertArrayNotHasKey( 'content_search.items', $modules );
		self::assertArrayNotHasKey( 'memory.save', $modules );
		self::assertArrayNotHasKey( 'memory.bootstrap', $modules );
	}

	public function test_snapshot_refresh_intelligence_requires_write_scope(): void {
		$GLOBALS['aculect_ai_companion_test_users'][7]->roles = array( 'administrator' );

		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 7, $registry, array( 'content:read' ) );
		$modules    = ( new McpToolAvailability() )->tool_modules_for_user( 7, $registry, null, array( 'content:read' ) );

		self::assertTrue( $operations['site_editor']['get_context']['available'] );
		self::assertFalse( $operations['site_editor']['refresh_context']['available'] );
		self::assertSame( 'oauth_scope', $operations['site_editor']['refresh_context']['blocked_by'] );
		self::assertSame( array( 'content:draft' ), $operations['site_editor']['refresh_context']['missing_scopes'] );
		self::assertTrue( $operations['admin_menu']['get_context']['available'] );
		self::assertFalse( $operations['admin_menu']['refresh_context']['available'] );
		self::assertSame( 'oauth_scope', $operations['admin_menu']['refresh_context']['blocked_by'] );
		self::assertSame( array( 'content:draft' ), $operations['admin_menu']['refresh_context']['missing_scopes'] );
		self::assertArrayHasKey( 'site_editor.get_context', $modules );
		self::assertArrayNotHasKey( 'site_editor.refresh_context', $modules );
		self::assertArrayHasKey( 'admin_menu.get_context', $modules );
		self::assertArrayNotHasKey( 'admin_menu.refresh_context', $modules );
	}

	/**
	 * Flatten operation entries from a structured operation manifest.
	 *
	 * @param array<string, mixed> $operations Structured operation manifest.
	 * @return list<array<string, mixed>>
	 */
	private function operation_entries( array $operations ): array {
		$entries = array();
		foreach ( array( 'site_information', 'plugin_lifecycle', 'theme_lifecycle', 'content', 'workflows', 'site_editor', 'admin_menu', 'navigation', 'workflow_guides', 'intelligence_index', 'content_groups', 'media', 'comments', 'actions' ) as $group ) {
			foreach ( (array) ( $operations[ $group ] ?? array() ) as $entry ) {
				if ( is_array( $entry ) ) {
					$entries[] = $entry;
				}
			}
		}

		return $entries;
	}
}
