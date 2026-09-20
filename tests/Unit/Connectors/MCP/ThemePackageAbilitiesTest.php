<?php
/**
 * Tests for confirmed theme package installation and updates.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ThemePackageAbilities;
use Aculect\AICompanion\Connectors\MCP\ThemePackagePolicy;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/theme-package-stubs.php';

/** Verifies confirmed theme package operations are bounded and fail closed. */
final class ThemePackageAbilitiesTest extends TestCase {

	private ThemePackageAbilities $abilities;

	protected function setUp(): void {
		parent::setUp();
		$this->abilities = new ThemePackageAbilities();

		$GLOBALS['aculect_ai_companion_test_denied_caps']           = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite']          = false;
		$GLOBALS['aculect_ai_companion_test_blog_id']               = 12;
		$GLOBALS['aculect_ai_companion_test_filesystem_method']     = 'direct';
		$GLOBALS['aculect_ai_companion_test_file_mod_allowed']      = true;
		$GLOBALS['aculect_ai_companion_test_stylesheet']            = 'twentytwentysix';
		$GLOBALS['aculect_ai_companion_test_hooks']                 = array(
			'actions' => array(),
			'filters' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_theme_upgrader_runs']   = 0;
		$GLOBALS['aculect_ai_companion_test_theme_upgrader_result'] = null;
		$GLOBALS['aculect_ai_companion_test_theme_cache_clears']    = 0;
		$GLOBALS['aculect_ai_companion_test_theme_package_headers'] = array(
			'Name'     => 'Aculect Sample',
			'Version'  => '1.0.0',
			'Template' => '',
		);
		$GLOBALS['aculect_ai_companion_test_theme_api']             = (object) array(
			'name'    => 'Aculect Sample',
			'slug'    => 'aculect-sample',
			'version' => '1.0.0',
		);
		$GLOBALS['aculect_ai_companion_test_theme_api_request']     = array();
		$GLOBALS['aculect_ai_companion_test_themes']                = array(
			'twentytwentysix' => array(
				'Name'       => 'Twenty Twenty-Six',
				'Version'    => '1.0.0',
				'Stylesheet' => 'twentytwentysix',
				'Template'   => 'twentytwentysix',
			),
		);
		$GLOBALS['aculect_ai_companion_test_site_options']          = array();
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_hooks']             = array(
			'actions' => array(),
			'filters' => array(),
		);
		$GLOBALS['aculect_ai_companion_test_site_options']      = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']       = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite']      = false;
		$GLOBALS['aculect_ai_companion_test_filesystem_method'] = 'direct';
		$GLOBALS['aculect_ai_companion_test_file_mod_allowed']  = true;
		parent::tearDown();
	}

	public function test_package_policy_accepts_only_exact_wordpress_org_theme_urls(): void {
		$policy   = new ThemePackagePolicy();
		$expected = 'https://downloads.wordpress.org/theme/aculect-sample.1.2.3.zip';

		self::assertSame( $expected, $policy->requested_package_url( $expected, 'aculect-sample', '1.2.3' ) );
		foreach (
			array(
				'http://downloads.wordpress.org/theme/aculect-sample.1.2.3.zip',
				'https://user@downloads.wordpress.org/theme/aculect-sample.1.2.3.zip',
				'https://downloads.wordpress.org:443/theme/aculect-sample.1.2.3.zip',
				'https://downloads.wordpress.org/theme/aculect-sample.1.2.3.zip?mirror=evil',
				'https://downloads.wordpress.org/theme/../plugin/aculect-sample.1.2.3.zip',
				'https://downloads.wordpress.org/theme/aculect-sample.1.2.3.zip#fragment',
			)
			as $url
		) {
			self::assertSame( 'invalid_package_url', $policy->requested_package_url( $url, 'aculect-sample', '1.2.3' )['error'] );
		}
		self::assertSame( 'invalid_theme_slug', $policy->requested_theme_slug( '../aculect-sample' )['error'] );
	}

	public function test_install_preview_accepts_omitted_parent_fields_and_never_writes(): void {
		$result = $this->abilities->install_theme(
			array(
				'slug'    => 'aculect-sample',
				'dry_run' => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( '1.0.0', $result['target']['version'] );
		self::assertSame( 'aculect-sample', $result[ ThemePackagePolicy::CONFIRMATION_BINDING_KEY ]['slug'] );
		self::assertSame( 'theme_information', $GLOBALS['aculect_ai_companion_test_theme_api_request']['action'] );
		self::assertSame( 'aculect-sample', $GLOBALS['aculect_ai_companion_test_theme_api_request']['args']['slug'] );
		self::assertSame( false, $GLOBALS['aculect_ai_companion_test_theme_api_request']['args']['fields']['versions'] );
		self::assertSame( 0, $GLOBALS['aculect_ai_companion_test_theme_upgrader_runs'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_hooks']['filters'] );
	}

	public function test_malformed_or_child_theme_metadata_is_rejected(): void {
		$GLOBALS['aculect_ai_companion_test_theme_api'] = (object) array(
			'name'     => 'Malformed',
			'slug'     => 'aculect-sample',
			'version'  => '1.0.0',
			'template' => array( 'unexpected' ),
		);
		$malformed                                      = $this->abilities->install_theme(
			array(
				'slug'    => 'aculect-sample',
				'dry_run' => true,
			)
		);
		self::assertSame( 'theme_parent_scope_unavailable', $malformed['error'] );

		$GLOBALS['aculect_ai_companion_test_theme_api'] = (object) array(
			'name'     => 'Child Theme',
			'slug'     => 'aculect-sample',
			'version'  => '1.0.0',
			'template' => 'parent-theme',
		);
		$child = $this->abilities->install_theme(
			array(
				'slug'    => 'aculect-sample',
				'dry_run' => true,
			)
		);
		self::assertSame( 'theme_parent_required', $child['error'] );
		self::assertSame( 0, $GLOBALS['aculect_ai_companion_test_theme_upgrader_runs'] );
	}

	public function test_capability_multisite_filemod_and_direct_filesystem_gates_block(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'install_themes' );
		$forbidden                                        = $this->abilities->install_theme(
			array(
				'slug'    => 'aculect-sample',
				'dry_run' => true,
			)
		);
		self::assertSame( 'forbidden', $forbidden['error'] );
		self::assertSame( 0, $GLOBALS['aculect_ai_companion_test_theme_upgrader_runs'] );

		$GLOBALS['aculect_ai_companion_test_denied_caps']  = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = true;
		$multisite = $this->abilities->install_theme(
			array(
				'slug'    => 'aculect-sample',
				'dry_run' => true,
			)
		);
		self::assertSame( 'theme_multisite_filesystem_scope', $multisite['error'] );

		$GLOBALS['aculect_ai_companion_test_is_multisite']     = false;
		$GLOBALS['aculect_ai_companion_test_file_mod_allowed'] = false;
		$filemods = $this->abilities->install_theme(
			array(
				'slug'    => 'aculect-sample',
				'dry_run' => true,
			)
		);
		self::assertSame( 'theme_file_modifications_disallowed', $filemods['error'] );

		$GLOBALS['aculect_ai_companion_test_file_mod_allowed']  = true;
		$GLOBALS['aculect_ai_companion_test_filesystem_method'] = 'ssh2';
		$filesystem = $this->abilities->install_theme(
			array(
				'slug'    => 'aculect-sample',
				'dry_run' => true,
			)
		);
		self::assertSame( 'theme_direct_filesystem_unavailable', $filesystem['error'] );
		self::assertSame( 0, $GLOBALS['aculect_ai_companion_test_theme_upgrader_runs'] );
	}

	public function test_install_binding_is_blog_bound_and_dry_run_never_executes(): void {
		$preview   = $this->abilities->install_theme(
			array(
				'slug'    => 'aculect-sample',
				'dry_run' => true,
			)
		);
		$binding   = $preview[ ThemePackagePolicy::CONFIRMATION_BINDING_KEY ];
		$arguments = array(
			'slug'                                       => 'aculect-sample',
			'dry_run'                                    => true,
			ThemePackagePolicy::CONFIRMATION_BINDING_KEY => $binding,
		);

		$repreview = $this->abilities->install_theme( $arguments );
		self::assertSame( 'preview', $repreview['status'] );
		self::assertSame( 0, $GLOBALS['aculect_ai_companion_test_theme_upgrader_runs'] );

		$GLOBALS['aculect_ai_companion_test_blog_id'] = 13;
		$stale                                        = $this->abilities->install_theme( $arguments );
		self::assertSame( 'invalid_confirmation_binding', $stale['error'] );
		self::assertSame( 0, $GLOBALS['aculect_ai_companion_test_theme_upgrader_runs'] );
	}

	public function test_install_and_update_use_exact_bound_packages_without_activation(): void {
		$install_preview = $this->abilities->install_theme(
			array(
				'slug'    => 'aculect-sample',
				'dry_run' => true,
			)
		);
		$install         = $this->abilities->install_theme(
			array(
				'slug' => 'aculect-sample',
				ThemePackagePolicy::CONFIRMATION_BINDING_KEY => $install_preview[ ThemePackagePolicy::CONFIRMATION_BINDING_KEY ],
			)
		);

		self::assertSame( 'installed', $install['status'] );
		self::assertSame( 'install', $install['operation'] );
		self::assertTrue( $install['verified'] );
		self::assertFalse( $install['theme']['active'] );
		self::assertSame( 'https://downloads.wordpress.org/theme/aculect-sample.1.0.0.zip', $GLOBALS['aculect_ai_companion_test_last_theme_upgrader_options']['package'] );
		self::assertSame( 'twentytwentysix', get_stylesheet() );

		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_themes'] = (object) array(
			'response' => array(
				'aculect-sample' => (object) array(
					'new_version' => '2.0.0',
					'package'     => 'https://downloads.wordpress.org/theme/aculect-sample.2.0.0.zip',
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_theme_package_headers']                         = array(
			'Name'     => 'Aculect Sample',
			'Version'  => '2.0.0',
			'Template' => '',
		);
		$update_preview = $this->abilities->update_theme(
			array(
				'stylesheet' => 'aculect-sample',
				'dry_run'    => true,
			)
		);
		$update         = $this->abilities->update_theme(
			array(
				'stylesheet' => 'aculect-sample',
				ThemePackagePolicy::CONFIRMATION_BINDING_KEY => $update_preview[ ThemePackagePolicy::CONFIRMATION_BINDING_KEY ],
			)
		);

		self::assertSame( 'updated', $update['status'] );
		self::assertSame( 'update', $update['operation'] );
		self::assertSame( '2.0.0', $update['theme']['version'] );
		self::assertSame( 'https://downloads.wordpress.org/theme/aculect-sample.2.0.0.zip', $GLOBALS['aculect_ai_companion_test_last_theme_upgrader_options']['package'] );
		self::assertSame( 'twentytwentysix', get_stylesheet() );
		self::assertSame( 2, $GLOBALS['aculect_ai_companion_test_theme_upgrader_runs'] );
		self::assertSame( 2, $GLOBALS['aculect_ai_companion_test_theme_cache_clears'] );
		self::assertSame( array(), $GLOBALS['aculect_ai_companion_test_hooks']['filters'] );
	}

	public function test_update_rejects_stale_version_and_noncanonical_cached_package(): void {
		$GLOBALS['aculect_ai_companion_test_themes']['aculect-sample']                      = array(
			'Name'       => 'Aculect Sample',
			'Version'    => '1.0.0',
			'Stylesheet' => 'aculect-sample',
			'Template'   => 'aculect-sample',
		);
		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_themes'] = (object) array(
			'response' => array(
				'aculect-sample' => (object) array(
					'new_version' => '2.0.0',
					'package'     => 'https://downloads.wordpress.org/theme/aculect-sample.2.0.0.zip',
				),
			),
		);
		$preview = $this->abilities->update_theme(
			array(
				'stylesheet' => 'aculect-sample',
				'dry_run'    => true,
			)
		);
		$binding = $preview[ ThemePackagePolicy::CONFIRMATION_BINDING_KEY ];
		$GLOBALS['aculect_ai_companion_test_themes']['aculect-sample']['Version'] = '1.1.0';
		$stale = $this->abilities->update_theme(
			array(
				'stylesheet' => 'aculect-sample',
				ThemePackagePolicy::CONFIRMATION_BINDING_KEY => $binding,
			)
		);
		self::assertSame( 'invalid_confirmation_binding', $stale['error'] );
		self::assertSame( 0, $GLOBALS['aculect_ai_companion_test_theme_upgrader_runs'] );

		$GLOBALS['aculect_ai_companion_test_themes']['aculect-sample']['Version'] = '1.0.0';
		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_themes']->response['aculect-sample']->package = 'https://evil.example/theme.zip';
		$invalid_package = $this->abilities->update_theme(
			array(
				'stylesheet' => 'aculect-sample',
				'dry_run'    => true,
			)
		);
		self::assertSame( 'invalid_package_url', $invalid_package['error'] );
	}

	public function test_failed_upgrader_result_is_terminal_and_hides_core_details(): void {
		$preview = $this->abilities->install_theme(
			array(
				'slug'    => 'aculect-sample',
				'dry_run' => true,
			)
		);
		$GLOBALS['aculect_ai_companion_test_theme_upgrader_result'] = new \WP_Error( 'secret_fs_error', 'private path /srv/site/wp-content/themes' );
		$result = $this->abilities->install_theme(
			array(
				'slug' => 'aculect-sample',
				ThemePackagePolicy::CONFIRMATION_BINDING_KEY => $preview[ ThemePackagePolicy::CONFIRMATION_BINDING_KEY ],
			)
		);

		self::assertSame( 'partial_write', $result['error'] );
		self::assertTrue( $result['terminal'] );
		$encoded = wp_json_encode( $result );
		self::assertIsString( $encoded );
		self::assertStringNotContainsString( 'secret_fs_error', $encoded );
		self::assertStringNotContainsString( '/srv/site', $encoded );
	}
}
