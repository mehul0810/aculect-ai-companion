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

		$GLOBALS['aculect_ai_companion_test_options']             = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']         = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite']        = false;
		$GLOBALS['aculect_ai_companion_test_is_main_site']        = true;
		$GLOBALS['aculect_ai_companion_test_site']                = (object) array( 'deleted' => '0' );
		$GLOBALS['aculect_ai_companion_test_registered_settings'] = array(
			'blogname'                  => array(
				'group'        => 'general',
				'type'         => 'string',
				'description'  => 'Site title.',
				'show_in_rest' => true,
				'default'      => '',
			),
			'aculect_ai_companion_mode' => array(
				'group'        => 'aculect_ai_companion',
				'type'         => 'string',
				'description'  => 'AI Companion mode.',
				'show_in_rest' => false,
				'default'      => 'standard',
			),
		);

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate menu globals for the sparse-menu fallback test.
		$GLOBALS['menu'] = array(
			80 => array( 'Settings', 'manage_options', 'options-general.php', 'Settings' ),
		);
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolate submenu globals for the sparse-menu fallback test.
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

	public function test_list_pages_includes_core_submenus_when_dynamic_menu_is_sparse(): void {
		$GLOBALS['aculect_ai_companion_test_is_block_theme'] = false;
		$GLOBALS['aculect_ai_companion_test_theme_supports'] = array( 'menus' => true );

		$settings   = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'settings' ) );
		$tools      = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'tools' ) );
		$appearance = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'appearance' ) );
		$dashboard  = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'dashboard' ) );

		$setting_slugs    = array_column( $settings['items'], 'slug' );
		$tool_slugs       = array_column( $tools['items'], 'slug' );
		$appearance_slugs = array_column( $appearance['items'], 'slug' );
		$dashboard_slugs  = array_column( $dashboard['items'], 'slug' );

		self::assertContains( 'options-writing.php', $setting_slugs );
		self::assertContains( 'options-permalink.php', $setting_slugs );
		self::assertContains( 'import.php', $tool_slugs );
		self::assertContains( 'site-health.php', $tool_slugs );
		self::assertContains( 'nav-menus.php', $appearance_slugs );
		self::assertContains( 'theme-editor.php', $appearance_slugs );
		self::assertContains( 'update-core.php', $dashboard_slugs );
	}

	public function test_fallback_respects_block_theme_conditional_routes(): void {
		$GLOBALS['aculect_ai_companion_test_is_block_theme'] = true;
		$GLOBALS['aculect_ai_companion_test_theme_supports'] = array();

		$appearance       = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'appearance' ) );
		$tools            = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'tools' ) );
		$appearance_slugs = array_column( $appearance['items'], 'slug' );
		$tool_slugs       = array_column( $tools['items'], 'slug' );

		self::assertContains( 'site-editor.php', $appearance_slugs );
		self::assertNotContains( 'customize.php', $appearance_slugs );
		self::assertNotContains( 'nav-menus.php', $appearance_slugs );
		self::assertNotContains( 'theme-editor.php', $appearance_slugs );
		self::assertContains( 'theme-editor.php', $tool_slugs );
	}

	public function test_fallback_uses_wordpress_core_submenu_capabilities(): void {
		$GLOBALS['aculect_ai_companion_test_is_block_theme'] = false;
		$GLOBALS['aculect_ai_companion_test_theme_supports'] = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']    = array( 'update_core' );

		$tools        = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'tools' ) );
		$dashboard    = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'dashboard' ) );
		$tool_by_slug = array();
		foreach ( $tools['items'] as $item ) {
			$tool_by_slug[ (string) $item['slug'] ] = (string) $item['capability'];
		}

		$update_item = array_values(
			array_filter(
				$dashboard['items'],
				static fn ( array $item ): bool => 'update-core.php' === (string) ( $item['slug'] ?? '' )
			)
		);

		self::assertSame( 'edit_posts', $tool_by_slug['tools.php'] );
		self::assertSame( 'export_others_personal_data', $tool_by_slug['export-personal-data.php'] );
		self::assertSame( 'erase_others_personal_data', $tool_by_slug['erase-personal-data.php'] );
		self::assertCount( 1, $update_item );
		self::assertSame( 'update_plugins', $update_item[0]['capability'] );
	}

	public function test_fallback_respects_multisite_dashboard_routes(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = true;

		$dashboard = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'dashboard' ) );
		$slugs     = array_column( $dashboard['items'], 'slug' );

		self::assertContains( 'my-sites.php', $slugs );
		self::assertNotContains( 'update-core.php', $slugs );
	}

	public function test_main_multisite_does_not_expose_delete_site(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = true;
		$GLOBALS['aculect_ai_companion_test_is_main_site'] = true;

		$tools = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'tools' ) );
		$slugs = array_column( $tools['items'], 'slug' );

		self::assertNotContains( 'ms-delete-site.php', $slugs );
	}

	public function test_active_non_main_multisite_exposes_delete_site(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = true;
		$GLOBALS['aculect_ai_companion_test_is_main_site'] = false;
		$GLOBALS['aculect_ai_companion_test_site']         = (object) array( 'deleted' => '0' );

		$tools = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'tools' ) );
		$slugs = array_column( $tools['items'], 'slug' );

		self::assertContains( 'ms-delete-site.php', $slugs );
	}

	public function test_deleted_non_main_multisite_does_not_expose_delete_site(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = true;
		$GLOBALS['aculect_ai_companion_test_is_main_site'] = false;
		$GLOBALS['aculect_ai_companion_test_site']         = (object) array( 'deleted' => '1' );

		$tools = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'tools' ) );
		$slugs = array_column( $tools['items'], 'slug' );

		self::assertNotContains( 'ms-delete-site.php', $slugs );
	}

	public function test_dynamic_core_submenu_metadata_takes_precedence_over_fallback(): void {
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Override one submenu to verify live metadata precedence.
		$GLOBALS['submenu'] = array(
			'options-general.php' => array(
				10 => array( 'Writing', 'custom_writing_cap', 'options-writing.php', 'Writing' ),
			),
		);

		$result = ( new AdminMenuAbilities() )->list_pages( array( 'section' => 'settings' ) );
		$items  = array_values(
			array_filter(
				$result['items'],
				static fn ( array $item ): bool => 'options-writing.php' === (string) ( $item['slug'] ?? '' )
			)
		);

		self::assertCount( 1, $items );
		self::assertSame( 'custom_writing_cap', $items[0]['capability'] );
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
