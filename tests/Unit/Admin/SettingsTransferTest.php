<?php
/**
 * Settings transfer tests.
 *
 * @package Aculect\AICompanion\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\SettingsTransfer;
use Aculect\AICompanion\Brand\BrandProfile;
use Aculect\AICompanion\Connectors\MCP\AccessLockdown;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\RoleConnectionEntryPoint;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\MCP\UserAccessControl;
use Aculect\AICompanion\Connectors\MCP\WordPressAbilitiesPolicy;
use Aculect\AICompanion\Diagnostics\LogSettings;
use PHPUnit\Framework\TestCase;

/**
 * Verifies sanitized settings import/export/reset behavior.
 */
final class SettingsTransferTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	public function test_export_payload_contains_safe_settings_only(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );
		( new ToolSafety() )->save_confirmation_groups( array( 'Content' ) );
		update_option(
			RoleAbilitiesPolicy::OPTION_ROLE_ABILITIES,
			array(
				'administrator' => array( 'content.update_item' ),
				'editor'        => array( 'content.get_item' ),
			),
			false
		);
		RoleConnectionEntryPoint::save( true, array( 'editor' ) );
		LogSettings::set_enabled( true );
		LogSettings::set_retention_days( 14 );
		( new BrandProfile() )->save(
			array(
				'site_name' => 'Exported Brand',
				'tone'      => 'Helpful',
			)
		);
		update_option( 'aculect_ai_companion_oauth_private_key', 'secret-private-key', false );
		update_option( 'aculect_ai_companion_oauth_encryption_key', 'secret-encryption-key', false );

		$transfer = new SettingsTransfer();
		$payload  = $transfer->export_payload();
		$json     = $transfer->export_json();

		self::assertSame( SettingsTransfer::SCHEMA, $payload['schema'] );
		self::assertSame( SettingsTransfer::SCHEMA_VERSION, $payload['schemaVersion'] );
		self::assertSame( array( 'content.get_item', 'content.update_item' ), $payload['settings']['enabledAbilities'] );
		self::assertSame( array( 'Content' ), $payload['settings']['confirmationGroups'] );
		self::assertSame( array( 'content.get_item' ), $payload['settings']['roleAbilityPolicies']['editor'] );
		self::assertArrayNotHasKey( 'administrator', $payload['settings']['roleAbilityPolicies'] );
		self::assertSame( true, $payload['settings']['roleConnections']['enabled'] );
		self::assertSame( array( 'editor' ), $payload['settings']['roleConnections']['allowedRoles'] );
		self::assertSame( true, $payload['settings']['diagnostics']['loggingEnabled'] );
		self::assertSame( 14, $payload['settings']['diagnostics']['retentionDays'] );
		self::assertSame( 'Exported Brand', $payload['settings']['brandProfile']['site_name'] );
		self::assertStringNotContainsString( 'secret-private-key', $json );
		self::assertStringNotContainsString( 'secret-encryption-key', $json );
	}

	public function test_import_payload_sanitizes_and_persists_supported_settings(): void {
		$payload = array(
			'schema'        => SettingsTransfer::SCHEMA,
			'schemaVersion' => SettingsTransfer::SCHEMA_VERSION,
			'settings'      => array(
				'enabledAbilities'    => array( 'content.get_item', 'unknown.tool' ),
				'enabledWpAbilities'  => array( 'wp/example', '<b>wp/html</b>' ),
				'confirmationGroups'  => array( 'Content', 'Unknown Group' ),
				'roleAbilityPolicies' => array(
					'administrator' => array( 'content.get_item' ),
					'editor'        => array( 'content.update_item', 'unknown.tool' ),
					'missing-role'  => array( 'content.get_item' ),
				),
				'roleConnections'     => array(
					'enabled'      => 'yes',
					'allowedRoles' => array( 'editor', 'missing-role' ),
				),
				'diagnostics'         => array(
					'loggingEnabled' => true,
					'retentionDays'  => 999,
				),
				'brandProfile'        => array(
					'site_name' => '<strong>Imported Brand</strong>',
					'tone'      => 'Concise',
				),
			),
		);

		self::assertTrue( ( new SettingsTransfer() )->import_payload( $payload ) );

		self::assertSame( array( 'content.get_item' ), ( new AbilitiesRegistry() )->enabled_ids() );
		self::assertSame( array( 'wp/example', 'wp/html' ), ( new WordPressAbilitiesPolicy() )->allowed_ids() );
		self::assertSame( array( 'Content' ), ( new ToolSafety() )->confirmation_groups() );
		self::assertSame(
			array( 'content.update_item' ),
			( new RoleAbilitiesPolicy() )->saved_policies( new AbilitiesRegistry() )['editor']
		);
		self::assertArrayNotHasKey(
			'administrator',
			( new RoleAbilitiesPolicy() )->saved_policies( new AbilitiesRegistry() )
		);
		self::assertTrue( RoleConnectionEntryPoint::is_enabled() );
		self::assertSame( array( 'editor' ), RoleConnectionEntryPoint::allowed_roles() );
		self::assertTrue( LogSettings::is_enabled() );
		self::assertSame( 365, LogSettings::retention_days() );
		self::assertSame( 'Imported Brand', ( new BrandProfile() )->saved()['site_name'] );
	}

	public function test_reset_restores_defaults_without_protocol_storage_cleanup(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item' ) );
		( new WordPressAbilitiesPolicy() )->save_allowed_ids( array( 'wp/example' ) );
		( new ToolSafety() )->save_confirmation_groups( array( 'Content' ) );
		( new RoleAbilitiesPolicy() )->save_role_policy( 'editor', array( 'content.get_item' ), $registry );
		RoleConnectionEntryPoint::save( true, array( 'editor' ) );
		LogSettings::set_enabled( true );
		( new BrandProfile() )->save( array( 'site_name' => 'Reset Brand' ) );
		AccessLockdown::set_paused( true );
		UserAccessControl::set_paused( 7, true );
		update_option( 'aculect_ai_companion_oauth_private_key', 'preserve-private-key', false );

		( new SettingsTransfer() )->reset();

		self::assertNull( get_option( AbilitiesRegistry::OPTION_ENABLED_ABILITIES, null ) );
		self::assertSame( array(), ( new WordPressAbilitiesPolicy() )->allowed_ids() );
		self::assertSame( array(), ( new ToolSafety() )->confirmation_groups() );
		self::assertSame( array(), ( new RoleAbilitiesPolicy() )->saved_policies( new AbilitiesRegistry() ) );
		self::assertFalse( RoleConnectionEntryPoint::is_enabled() );
		self::assertFalse( LogSettings::is_enabled() );
		self::assertNull( get_option( 'aculect_ai_companion_brand_profile', null ) );
		self::assertFalse( AccessLockdown::is_paused() );
		self::assertFalse( UserAccessControl::is_paused( 7 ) );
		self::assertSame( 'preserve-private-key', get_option( 'aculect_ai_companion_oauth_private_key' ) );
	}

	public function test_import_rejects_unknown_schema(): void {
		self::assertFalse(
			( new SettingsTransfer() )->import_payload(
				array(
					'schema'        => 'other-plugin',
					'schemaVersion' => SettingsTransfer::SCHEMA_VERSION,
					'settings'      => array(),
				)
			)
		);
	}
}
