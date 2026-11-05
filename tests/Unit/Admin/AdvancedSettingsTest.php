<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\AdvancedSettings;
use Aculect\AICompanion\Admin\SettingsPage;
use Aculect\AICompanion\Admin\SettingsTransfer;
use Aculect\AICompanion\Connectors\MCP\RoleAbilitiesPolicy;
use Aculect\AICompanion\Connectors\MCP\RoleConnectionEntryPoint;
use Aculect\AICompanion\Diagnostics\LogSettings;
use Aculect\AICompanion\Settings\McpAppsSettings;
use PHPUnit\Framework\TestCase;

final class AdvancedSettingsTest extends TestCase {

	private array $previous_options;

	protected function setUp(): void {
		$this->previous_options = $GLOBALS['aculect_ai_companion_test_options'];
		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_options'] = $this->previous_options;
	}

	public function test_advanced_form_saves_opt_in_with_existing_settings(): void {
		AdvancedSettings::save(
			array(
				'mcp_apps_enabled'           => '1',
				'diagnostic_logging_enabled' => '1',
				'role_connections_enabled'   => '1',
				'role_abilities_enabled'     => '1',
				'role_connection_roles'      => array( 'editor' ),
			)
		);
		self::assertTrue( McpAppsSettings::saved_enabled() );
		self::assertTrue( LogSettings::is_enabled() );
		self::assertTrue( RoleConnectionEntryPoint::is_enabled() );
		self::assertSame( array( 'editor' ), RoleConnectionEntryPoint::allowed_roles() );
		self::assertTrue( RoleAbilitiesPolicy::is_editing_enabled() );

		AdvancedSettings::save( array() );
		self::assertTrue( McpAppsSettings::saved_enabled(), 'Old forms preserve the new setting.' );
		AdvancedSettings::save( array( 'mcp_apps_enabled' => '0' ) );
		self::assertFalse( McpAppsSettings::saved_enabled() );
		AdvancedSettings::save( array( 'mcp_apps_enabled' => array( '1' ) ) );
		self::assertFalse( McpAppsSettings::saved_enabled() );
	}

	public function test_advanced_action_requires_manage_options_before_writing(): void {
		$previous_post = $_POST;
		$previous_caps = $GLOBALS['aculect_ai_companion_test_denied_caps'] ?? array();
		$_POST = array( 'mcp_apps_enabled' => '1' );
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		try {
			( new SettingsPage() )->handle_save_advanced();
			self::fail( 'Unauthorized settings write was accepted.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'Insufficient permissions.', $error->getMessage() );
			self::assertFalse( McpAppsSettings::saved_enabled() );
		} finally {
			$_POST = $previous_post;
			$GLOBALS['aculect_ai_companion_test_denied_caps'] = $previous_caps;
		}
	}

	public function test_transfer_round_trip_reset_and_old_exports(): void {
		$transfer = new SettingsTransfer();
		McpAppsSettings::set_enabled( true );
		$payload = $transfer->export_payload();
		self::assertTrue( $payload['settings']['mcpAppsEnabled'] );
		$transfer->reset();
		self::assertFalse( McpAppsSettings::saved_enabled() );
		self::assertTrue( $transfer->import_payload( $payload ) );
		self::assertTrue( McpAppsSettings::saved_enabled() );

		unset( $payload['settings']['mcpAppsEnabled'] );
		self::assertTrue( $transfer->import_payload( $payload ) );
		self::assertTrue( McpAppsSettings::saved_enabled() );
		$payload['settings']['mcpAppsEnabled'] = false;
		self::assertTrue( $transfer->import_payload( $payload ) );
		self::assertFalse( McpAppsSettings::saved_enabled() );
		$payload['settings']['mcpAppsEnabled'] = array( 'true' );
		self::assertTrue( $transfer->import_payload( $payload ) );
		self::assertFalse( McpAppsSettings::saved_enabled() );
	}
}
