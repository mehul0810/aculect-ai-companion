<?php
/**
 * Tests for Admin Menu intelligence MCP abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AdminMenuAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies admin menu intelligence avoids raw option exposure.
 */
final class AdminMenuAbilitiesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		$GLOBALS['aculect_ai_companion_test_registered_settings'] = array(
			'blogname' => array(
				'group'       => 'general',
				'type'        => 'string',
				'description' => 'Site title.',
				'show_in_rest' => true,
				'default'     => '',
			),
			'aculect_ai_companion_mode' => array(
				'group'       => 'aculect_ai_companion',
				'type'        => 'string',
				'description' => 'AI Companion mode.',
				'show_in_rest' => false,
				'default'     => 'standard',
			),
		);

		$GLOBALS['menu'] = array(
			80 => array( 'Settings', 'manage_options', 'options-general.php', 'Settings' ),
		);
		$GLOBALS['submenu'] = array(
			'options-general.php' => array(
				10 => array( 'AI Companion', 'manage_options', 'options-general.php?page=aculect-ai-companion', 'AI Companion' ),
			),
		);
	}

	public function test_context_lists_admin_surfaces_without_option_values(): void {
		$result = ( new AdminMenuAbilities() )->get_context();

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'admin_menu', $result['type'] );
		self::assertSame( 'Admin-level WordPress changes only; no filesystem or arbitrary wp_options writes.', $result['admin']['change_model'] );
		self::assertFalse( $result['settings']['values_included'] );
		self::assertFalse( $result['settings']['raw_options_included'] );
		self::assertNotEmpty( $result['menu']['items'] );
		self::assertNotEmpty( $result['memory_candidates'] );
	}

	public function test_list_settings_returns_metadata_only(): void {
		$result = ( new AdminMenuAbilities() )->list_settings(
			array(
				'group' => 'aculect_ai_companion',
			)
		);

		self::assertSame( 1, $result['total'] );
		self::assertSame( 'aculect_ai_companion_mode', $result['items'][0]['name'] );
		self::assertFalse( $result['items'][0]['value_included'] );
		self::assertArrayNotHasKey( 'value', $result['items'][0] );
		self::assertFalse( $result['raw_options_included'] );
	}

	public function test_navigation_target_finds_core_settings_page(): void {
		$result = ( new AdminMenuAbilities() )->get_navigation_target(
			array(
				'query' => 'permalink settings',
			)
		);

		self::assertSame( 'ready', $result['status'] );
		self::assertSame( 'options-permalink.php', $result['target']['slug'] );
		self::assertStringContainsString( 'options-permalink.php', $result['target']['url'] );
	}

	public function test_refresh_context_stores_plugin_owned_snapshot(): void {
		$result = ( new AdminMenuAbilities() )->refresh_context();
		$stored = get_option( AdminMenuAbilities::OPTION_SNAPSHOT, array() );

		self::assertSame( 'success', $result['status'] );
		self::assertIsArray( $stored );
		self::assertNotEmpty( $stored['fingerprint'] );
		self::assertSame( $stored['fingerprint'], $result['snapshot']['fingerprint'] );
	}

	public function test_context_requires_manage_options_capability(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );

		$result = ( new AdminMenuAbilities() )->get_context();

		self::assertSame( 'forbidden', $result['error'] );
	}
}
