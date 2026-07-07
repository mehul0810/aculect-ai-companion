<?php
/**
 * Tests for MCP theme lifecycle inventory and switch abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ThemeLifecycleAbilities;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/theme-lifecycle-stubs.php';

/**
 * Verifies theme lifecycle inventory stays bounded and switching stays gated.
 */
final class ThemeLifecycleAbilitiesTest extends TestCase {

	private ThemeLifecycleAbilities $abilities;

	protected function setUp(): void {
		parent::setUp();

		$this->abilities = new ThemeLifecycleAbilities();

		$GLOBALS['aculect_ai_companion_test_current_user_id']   = 7;
		$GLOBALS['aculect_ai_companion_test_denied_caps']       = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite']      = true;
		$GLOBALS['aculect_ai_companion_test_blog_id']           = 12;
		$GLOBALS['aculect_ai_companion_test_network_admin']     = true;
		$GLOBALS['aculect_ai_companion_test_active_stylesheet'] = 'child-theme';
		$GLOBALS['aculect_ai_companion_test_site_options']      = array(
			'_site_transient_update_themes' => (object) array(
				'last_checked' => time() - HOUR_IN_SECONDS,
				'response'     => array(
					'parent-theme' => (object) array(
						'new_version'  => '2.0.0',
						'tested'       => '6.8',
						'requires'     => '6.7',
						'requires_php' => '8.2',
						'package'      => 'https://downloads.example.test/theme.zip',
					),
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_switched_themes']   = array();
		$GLOBALS['aculect_ai_companion_test_themes']            = array(
			'child-theme'  => array(
				'Name'             => 'Child <strong>Theme</strong>',
				'Version'          => '1.2.3',
				'Description'      => '<p>Child experience.</p><script>alert(1)</script>',
				'Author'           => '<a href="https://example.test">Aculect</a>',
				'Stylesheet'       => 'child-theme',
				'Template'         => 'parent-theme',
				'ParentStylesheet' => 'parent-theme',
				'IsBlockTheme'     => false,
				'Files'            => array(
					'theme.json' => 'theme.json',
				),
			),
			'parent-theme' => array(
				'Name'         => 'Parent Theme',
				'Version'      => '1.0.0',
				'Description'  => 'Parent experience.',
				'Author'       => 'Aculect',
				'Stylesheet'   => 'parent-theme',
				'Template'     => 'parent-theme',
				'IsBlockTheme' => false,
				'Files'        => array(),
			),
			'block-theme'  => array(
				'Name'         => 'Block Theme',
				'Version'      => '3.4.5',
				'Description'  => 'Site editor ready.',
				'Author'       => 'Aculect',
				'Stylesheet'   => 'block-theme',
				'Template'     => 'block-theme',
				'IsBlockTheme' => true,
				'Files'        => array(
					'theme.json'           => 'theme.json',
					'templates/index.html' => 'templates/index.html',
				),
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_themes']          = array();
		$GLOBALS['aculect_ai_companion_test_site_options']    = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite']    = false;
		$GLOBALS['aculect_ai_companion_test_network_admin']   = false;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_switched_themes'] = array();

		parent::tearDown();
	}

	public function test_list_themes_returns_lifecycle_status_without_raw_payloads(): void {
		$result = $this->abilities->list_themes( array( 'per_page' => 100 ) );
		$items  = array_column( $result['items'], null, 'stylesheet' );

		self::assertSame( 3, $result['total'] );
		self::assertTrue( $result['safety']['read_only'] );
		self::assertFalse( $result['safety']['install_implemented'] );
		self::assertFalse( $result['safety']['update_implemented'] );
		self::assertTrue( $result['safety']['switch_implemented'] );
		self::assertFalse( $result['safety']['deactivate_implemented'] );
		self::assertFalse( $result['safety']['deactivate_supported'] );
		self::assertSame( array( 'deactivate' ), $result['safety']['unsupported_operations'] );
		self::assertFalse( $result['filters']['forced_update_checks'] );
		self::assertFalse( $result['filters']['raw_update_payloads_included'] );
		self::assertTrue( $result['context']['multisite'] );
		self::assertSame( 12, $result['context']['blog_id'] );
		self::assertTrue( $result['context']['network_admin'] );
		self::assertSame( 'child-theme', $result['context']['active_stylesheet'] );
		self::assertTrue( $result['capabilities']['can_manage_themes'] );
		self::assertSame( array(), $result['capability_blockers'] );

		self::assertSame( 'Child Theme', $items['child-theme']['name'] );
		self::assertSame( 'Child experience.', $items['child-theme']['description'] );
		self::assertSame( 'Aculect', $items['child-theme']['author'] );
		self::assertTrue( $items['child-theme']['active'] );
		self::assertSame( 'active', $items['child-theme']['status'] );
		self::assertSame( 'child', $items['child-theme']['relationship']['role'] );
		self::assertTrue( $items['child-theme']['relationship']['is_child'] );
		self::assertSame( 'parent-theme', $items['child-theme']['relationship']['parent_stylesheet'] );
		self::assertSame( 'Parent Theme', $items['child-theme']['relationship']['parent_name'] );
		self::assertSame( 'hybrid', $items['child-theme']['presentation']['classification'] );
		self::assertTrue( $items['child-theme']['presentation']['is_hybrid'] );

		self::assertSame( 'parent', $items['parent-theme']['relationship']['role'] );
		self::assertTrue( $items['parent-theme']['relationship']['is_parent'] );
		self::assertSame( array( 'child-theme' ), $items['parent-theme']['relationship']['child_stylesheets'] );
		self::assertTrue( $items['parent-theme']['update']['available'] );
		self::assertSame( '2.0.0', $items['parent-theme']['update']['new_version'] );
		self::assertFalse( $items['parent-theme']['update']['package_url_included'] );
		self::assertFalse( $items['parent-theme']['update']['raw_update_payloads_included'] );
		self::assertArrayNotHasKey( 'package', $items['parent-theme']['update'] );
		self::assertSame( 'classic', $items['parent-theme']['presentation']['classification'] );

		self::assertSame( 'block', $items['block-theme']['presentation']['classification'] );
		self::assertTrue( $items['block-theme']['presentation']['is_block'] );

		$encoded = wp_json_encode( $result );
		self::assertIsString( $encoded );
		self::assertStringNotContainsString( 'theme.zip', $encoded );
		self::assertStringNotContainsString( 'wp-content/themes', $encoded );
	}

	public function test_get_theme_returns_one_installed_theme(): void {
		$result = $this->abilities->get_theme( array( 'stylesheet' => 'child-theme' ) );

		self::assertSame( 'child-theme', $result['theme']['stylesheet'] );
		self::assertSame( 'parent-theme', $result['theme']['relationship']['parent_stylesheet'] );
		self::assertSame( 'hybrid', $result['theme']['presentation']['classification'] );
		self::assertTrue( $result['safety']['read_only'] );
	}

	public function test_get_theme_rejects_invalid_or_missing_stylesheet(): void {
		$invalid = $this->abilities->get_theme( array( 'stylesheet' => '../wp-content/themes' ) );
		$missing = $this->abilities->get_theme( array( 'stylesheet' => 'missing-theme' ) );

		self::assertSame( 'invalid_theme', $invalid['error'] );
		self::assertSame( 'theme_not_found', $missing['error'] );
	}

	public function test_switch_theme_dry_run_returns_preview_without_mutation(): void {
		$result = $this->abilities->switch_theme(
			array(
				'stylesheet' => 'block-theme',
				'dry_run'    => true,
			)
		);

		self::assertTrue( $result['dry_run'] );
		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'theme_lifecycle.switch_theme', $result['action'] );
		self::assertSame( 'system', $result['risk_level'] );
		self::assertSame( 'block-theme', $result['target']['id'] );
		self::assertSame( 'theme', $result['target']['type'] );
		$diff_fields = array_column( $result['diff']['fields'], null, 'field' );
		self::assertSame( 'child-theme', $diff_fields['active_stylesheet']['before']['value'] );
		self::assertSame( 'block-theme', $diff_fields['active_stylesheet']['after']['value'] );
		self::assertTrue( $result['confirmation_required'] );
		self::assertSame( 'child-theme', $GLOBALS['aculect_ai_companion_test_active_stylesheet'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_switched_themes'] );
	}

	public function test_switch_theme_changes_active_theme_with_rollback_metadata(): void {
		$result = $this->abilities->switch_theme( array( 'stylesheet' => 'block-theme' ) );

		self::assertSame( 'switched', $result['status'] );
		self::assertSame( 'switch_theme', $result['operation'] );
		self::assertTrue( $result['changed'] );
		self::assertSame( 'block-theme', $result['theme']['stylesheet'] );
		self::assertTrue( $result['theme']['active'] );
		self::assertSame( 'block-theme', $result['context']['active_stylesheet'] );
		self::assertSame( 'switch_theme', $result['rollback']['operation'] );
		self::assertSame( 'child-theme', $result['rollback']['stylesheet'] );
		self::assertFalse( $result['safety']['read_only'] );
		self::assertTrue( $result['safety']['switch_implemented'] );
		self::assertTrue( $result['safety']['option_writes'] );
		self::assertFalse( $result['safety']['filesystem_writes'] );
		self::assertFalse( $result['confirmation_required'] );
		self::assertSame( array( 'block-theme' ), $GLOBALS['aculect_ai_companion_test_switched_themes'] );
	}

	public function test_switch_theme_reports_noop_when_theme_already_active(): void {
		$result = $this->abilities->switch_theme( array( 'stylesheet' => 'child-theme' ) );

		self::assertSame( 'already_active', $result['status'] );
		self::assertFalse( $result['changed'] );
		self::assertSame( 'child-theme', $result['theme']['stylesheet'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_switched_themes'] );
	}

	public function test_switch_theme_rejects_invalid_missing_and_forbidden_requests(): void {
		$invalid = $this->abilities->switch_theme( array( 'stylesheet' => '../wp-content/themes' ) );
		$missing = $this->abilities->switch_theme( array( 'stylesheet' => 'missing-theme' ) );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'switch_themes' );
		$forbidden                                        = $this->abilities->switch_theme( array( 'stylesheet' => 'block-theme' ) );

		self::assertSame( 'invalid_theme', $invalid['error'] );
		self::assertSame( 'theme_not_found', $missing['error'] );
		self::assertSame( 'forbidden', $forbidden['error'] );
	}

	public function test_list_themes_supports_safe_status_filters(): void {
		$updates = $this->abilities->list_themes( array( 'status' => 'update_available' ) );
		$parents = $this->abilities->list_themes( array( 'status' => 'parent' ) );
		$hybrid  = $this->abilities->list_themes( array( 'status' => 'hybrid' ) );

		self::assertSame( 1, $updates['total'] );
		self::assertSame( 'parent-theme', $updates['items'][0]['stylesheet'] );
		self::assertSame( 1, $parents['total'] );
		self::assertSame( 'parent-theme', $parents['items'][0]['stylesheet'] );
		self::assertSame( 1, $hybrid['total'] );
		self::assertSame( 'child-theme', $hybrid['items'][0]['stylesheet'] );
	}

	public function test_list_themes_reports_capability_denial(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'switch_themes' );

		$result = $this->abilities->list_themes( array() );

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_capability_blockers_report_missing_lifecycle_capabilities(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'install_themes', 'update_themes', 'delete_themes', 'manage_network_themes' );

		$result = $this->abilities->list_themes( array() );

		self::assertFalse( $result['capabilities']['can_install_themes'] );
		self::assertFalse( $result['capabilities']['can_update_themes'] );
		self::assertFalse( $result['capabilities']['can_delete_themes'] );
		self::assertSame( 'install_themes', $result['capability_blockers']['install']['capability'] );
		self::assertSame( 'update_themes', $result['capability_blockers']['update']['capability'] );
		self::assertSame( 'delete_themes', $result['capability_blockers']['delete']['capability'] );
		self::assertSame( 'manage_network_themes', $result['capability_blockers']['network_enable']['capability'] );
	}
}
