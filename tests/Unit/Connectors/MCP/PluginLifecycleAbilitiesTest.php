<?php
/**
 * Tests for read-only MCP plugin lifecycle inventory abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\PluginLifecycleAbilities;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/plugin-lifecycle-stubs.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/plugin-lifecycle-upgrader-stubs.php';

/**
 * Verifies plugin lifecycle inventory stays bounded, safe, and read-only.
 */
final class PluginLifecycleAbilitiesTest extends TestCase {

	private PluginLifecycleAbilities $abilities;

	protected function setUp(): void {
		parent::setUp();

		$this->abilities = new PluginLifecycleAbilities();

		$GLOBALS['aculect_ai_companion_test_current_user_id']          = 7;
		$GLOBALS['aculect_ai_companion_test_denied_caps']              = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite']             = true;
		$GLOBALS['aculect_ai_companion_test_blog_id']                  = 12;
		$GLOBALS['aculect_ai_companion_test_network_admin']            = true;
		$GLOBALS['aculect_ai_companion_test_recovery_mode']            = true;
		$GLOBALS['aculect_ai_companion_test_active_plugins']           = array( 'acme/acme.php' );
		$GLOBALS['aculect_ai_companion_test_network_active_plugins']   = array( 'network-tool/network-tool.php' );
		$GLOBALS['aculect_ai_companion_test_paused_plugins']           = array( 'paused-plugin/paused-plugin.php' );
		$GLOBALS['aculect_ai_companion_test_activate_plugin_errors']   = array();
		$GLOBALS['aculect_ai_companion_test_activation_noop']           = false;
		$GLOBALS['aculect_ai_companion_test_deactivation_noop']         = false;
		$GLOBALS['aculect_ai_companion_test_last_plugin_activation']   = '';
		$GLOBALS['aculect_ai_companion_test_last_plugin_deactivation'] = array();
		$GLOBALS['aculect_ai_companion_test_plugin_api']               = (object) array(
			'name'          => 'Classic Editor',
			'version'       => '1.6.0',
			'download_link' => 'https://downloads.wordpress.org/plugin/classic-editor.zip',
		);
		$GLOBALS['aculect_ai_companion_test_plugin_install_result']    = true;
		$GLOBALS['aculect_ai_companion_test_plugin_update_result']     = true;
		$GLOBALS['aculect_ai_companion_test_plugin_to_install']        = array(
			'file'    => 'classic-editor/classic-editor.php',
			'headers' => array(
				'Name'        => 'Classic Editor',
				'Version'     => '1.6.0',
				'Description' => 'Restores the classic editor.',
				'Author'      => 'WordPress Contributors',
			),
		);
		$GLOBALS['aculect_ai_companion_test_plugin_update_versions']   = array( 'acme/acme.php' => '2.0.0' );
		$GLOBALS['aculect_ai_companion_test_last_plugin_package']      = '';
		$GLOBALS['aculect_ai_companion_test_last_plugin_upgrade']      = '';
		$GLOBALS['aculect_ai_companion_test_last_plugin_upgrade_package'] = '';
		$GLOBALS['aculect_ai_companion_test_options']                  = array(
			'active_plugins' => array( 'acme/acme.php' ),
		);
		$GLOBALS['aculect_ai_companion_test_site_options']             = array(
			'active_sitewide_plugins'        => array(
				'network-tool/network-tool.php' => time(),
			),
			'_site_transient_update_plugins' => (object) array(
				'last_checked' => time() - HOUR_IN_SECONDS,
				'response'     => array(
					'acme/acme.php' => (object) array(
						'new_version'  => '2.0.0',
						'tested'       => '6.8',
						'requires'     => '6.7',
						'requires_php' => '8.2',
						'package'      => 'https://downloads.wordpress.org/plugin/acme.2.0.0.zip',
					),
				),
			),
		);
		$GLOBALS['aculect_ai_companion_test_plugins']                  = array(
			'acme/acme.php'                   => array(
				'Name'        => 'Acme <strong>Builder</strong>',
				'Version'     => '1.0.0',
				'Description' => '<p>Builds pages safely.</p><script>alert(1)</script>',
				'Author'      => '<a href="https://example.test">Acme Inc.</a>',
			),
			'network-tool/network-tool.php'   => array(
				'Name'        => 'Network Tool',
				'Version'     => '3.4.5',
				'Description' => 'Network helper.',
				'Author'      => 'Aculect',
			),
			'paused-plugin/paused-plugin.php' => array(
				'Name'        => 'Paused Plugin',
				'Version'     => '0.9.0',
				'Description' => 'Paused by recovery mode.',
				'Author'      => 'Aculect',
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_plugins']                  = array();
		$GLOBALS['aculect_ai_companion_test_active_plugins']           = array();
		$GLOBALS['aculect_ai_companion_test_network_active_plugins']   = array();
		$GLOBALS['aculect_ai_companion_test_paused_plugins']           = array();
		$GLOBALS['aculect_ai_companion_test_site_options']             = array();
		$GLOBALS['aculect_ai_companion_test_is_multisite']             = false;
		$GLOBALS['aculect_ai_companion_test_network_admin']            = false;
		$GLOBALS['aculect_ai_companion_test_recovery_mode']            = false;
		$GLOBALS['aculect_ai_companion_test_denied_caps']              = array();
		$GLOBALS['aculect_ai_companion_test_activate_plugin_errors']   = array();
		$GLOBALS['aculect_ai_companion_test_activation_noop']           = false;
		$GLOBALS['aculect_ai_companion_test_deactivation_noop']         = false;
		$GLOBALS['aculect_ai_companion_test_last_plugin_activation']   = '';
		$GLOBALS['aculect_ai_companion_test_last_plugin_deactivation'] = array();
		$GLOBALS['aculect_ai_companion_test_plugin_api']               = null;
		$GLOBALS['aculect_ai_companion_test_plugin_install_result']    = true;
		$GLOBALS['aculect_ai_companion_test_plugin_update_result']     = true;
		$GLOBALS['aculect_ai_companion_test_plugin_to_install']        = null;
		$GLOBALS['aculect_ai_companion_test_plugin_update_versions']   = array();
		$GLOBALS['aculect_ai_companion_test_last_plugin_package']      = '';
		$GLOBALS['aculect_ai_companion_test_last_plugin_upgrade']      = '';
		$GLOBALS['aculect_ai_companion_test_last_plugin_upgrade_package'] = '';

		parent::tearDown();
	}

	public function test_list_plugins_returns_lifecycle_status_without_raw_payloads(): void {
		$result = $this->abilities->list_plugins( array( 'per_page' => 100 ) );
		$items  = array_column( $result['items'], null, 'plugin' );

		self::assertSame( 3, $result['total'] );
		self::assertTrue( $result['safety']['read_only'] );
		self::assertTrue( $result['safety']['install_implemented'] );
		self::assertTrue( $result['safety']['update_implemented'] );
		self::assertTrue( $result['safety']['activate_implemented'] );
		self::assertTrue( $result['safety']['deactivate_implemented'] );
		self::assertFalse( $result['filters']['forced_update_checks'] );
		self::assertFalse( $result['filters']['raw_update_payloads_included'] );
		self::assertTrue( $result['context']['multisite'] );
		self::assertSame( 12, $result['context']['blog_id'] );
		self::assertTrue( $result['context']['network_admin'] );
		self::assertTrue( $result['capabilities']['can_manage_plugins'] );
		self::assertSame( array(), $result['capability_blockers'] );

		self::assertSame( 'Acme Builder', $items['acme/acme.php']['name'] );
		self::assertSame( 'Builds pages safely.', $items['acme/acme.php']['description'] );
		self::assertSame( 'Acme Inc.', $items['acme/acme.php']['author'] );
		self::assertSame( 'active', $items['acme/acme.php']['status'] );
		self::assertTrue( $items['acme/acme.php']['active'] );
		self::assertTrue( $items['acme/acme.php']['site_active'] );
		self::assertFalse( $items['acme/acme.php']['network_active'] );
		self::assertTrue( $items['acme/acme.php']['update']['available'] );
		self::assertSame( '2.0.0', $items['acme/acme.php']['update']['new_version'] );
		self::assertFalse( $items['acme/acme.php']['update']['package_url_included'] );
		self::assertFalse( $items['acme/acme.php']['update']['raw_update_payloads_included'] );
		self::assertArrayNotHasKey( 'package', $items['acme/acme.php']['update'] );

		self::assertSame( 'network_active', $items['network-tool/network-tool.php']['status'] );
		self::assertTrue( $items['network-tool/network-tool.php']['active'] );
		self::assertTrue( $items['network-tool/network-tool.php']['network_active'] );
		self::assertFalse( $items['network-tool/network-tool.php']['update']['available'] );

		self::assertTrue( $items['paused-plugin/paused-plugin.php']['recovery']['recovery_mode_active'] );
		self::assertTrue( $items['paused-plugin/paused-plugin.php']['recovery']['paused'] );
		self::assertFalse( $items['paused-plugin/paused-plugin.php']['recovery']['error_details_included'] );
		self::assertFalse( $items['paused-plugin/paused-plugin.php']['recovery']['raw_stack_trace_included'] );

		$encoded = wp_json_encode( $result );
		self::assertIsString( $encoded );
		self::assertStringNotContainsString( 'private.zip', $encoded );
		self::assertStringNotContainsString( 'wp-content/plugins', $encoded );
	}

	public function test_get_plugin_returns_one_installed_plugin(): void {
		$result = $this->abilities->get_plugin( array( 'plugin' => 'acme/acme.php' ) );

		self::assertSame( 'acme/acme.php', $result['plugin']['plugin'] );
		self::assertSame( 'acme', $result['plugin']['slug'] );
		self::assertSame( '2.0.0', $result['plugin']['update']['new_version'] );
		self::assertTrue( $result['safety']['read_only'] );
		self::assertTrue( $result['safety']['activate_implemented'] );
		self::assertTrue( $result['safety']['deactivate_implemented'] );
	}

	public function test_get_plugin_rejects_invalid_or_missing_plugin_basename(): void {
		$invalid = $this->abilities->get_plugin( array( 'plugin' => '../wp-config.php' ) );
		$missing = $this->abilities->get_plugin( array( 'plugin' => 'missing/missing.php' ) );

		self::assertSame( 'invalid_plugin', $invalid['error'] );
		self::assertSame( 'plugin_not_found', $missing['error'] );
	}

	public function test_install_plugin_returns_confirmation_preview_for_valid_wordpress_org_slug(): void {
		$result = $this->abilities->install_plugin(
			array(
				'slug'    => 'classic-editor',
				'dry_run' => true,
			)
		);

		self::assertTrue( $result['dry_run'] );
		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'plugin_lifecycle.install_plugin', $result['action'] );
		self::assertSame( 'classic-editor', $result['target']['id'] );
		self::assertTrue( $result['confirmation_required'] );
		self::assertSame( 'system', $result['risk_level'] );
		self::assertArrayHasKey( PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY, $result );
		unset( $result[ PluginLifecycleAbilities::CONFIRMATION_BINDING_KEY ] );
		self::assertStringNotContainsString( 'classic-editor.zip', wp_json_encode( $result ) );
	}

	public function test_install_plugin_rejects_invalid_or_existing_slugs(): void {
		$invalid = $this->abilities->install_plugin( array( 'slug' => '../classic-editor' ) );
		$exists  = $this->abilities->install_plugin( array( 'slug' => 'acme' ) );

		self::assertSame( 'invalid_plugin_slug', $invalid['error'] );
		self::assertSame( 'plugin_already_installed', $exists['error'] );
	}

	public function test_install_plugin_uses_core_upgrader_and_returns_bounded_result(): void {
		$result = $this->abilities->install_plugin( array( 'slug' => 'classic-editor' ) );

		self::assertSame( 'installed', $result['status'] );
		self::assertTrue( $result['changed'] );
		self::assertTrue( $result['verified'] );
		self::assertSame( 'classic-editor/classic-editor.php', $result['plugin']['plugin'] );
		self::assertSame( 'https://downloads.wordpress.org/plugin/classic-editor.zip', $GLOBALS['aculect_ai_companion_test_last_plugin_package'] );
		self::assertTrue( $result['safety']['filesystem_writes'] );
		self::assertTrue( $result['safety']['install_implemented'] );
	}

	public function test_install_plugin_reports_core_information_and_upgrader_failures_without_raw_messages(): void {
		$GLOBALS['aculect_ai_companion_test_plugin_api'] = new \WP_Error( 'api_failed', '/var/www/private/plugin.zip' );
		$information_failure                             = $this->abilities->install_plugin( array( 'slug' => 'classic-editor' ) );

		$GLOBALS['aculect_ai_companion_test_plugin_api']            = (object) array(
			'name'          => 'Classic Editor',
			'version'       => '1.6.0',
			'download_link' => 'https://downloads.wordpress.org/plugin/classic-editor.zip',
		);
		$GLOBALS['aculect_ai_companion_test_plugin_install_result'] = new \WP_Error( 'folder_exists', '/var/www/private/plugin' );
		$upgrader_failure = $this->abilities->install_plugin( array( 'slug' => 'classic-editor' ) );

		self::assertSame( 'plugin_install_information_failed', $information_failure['error'] );
		self::assertSame( 'api_failed', $information_failure['failure_code'] );
		self::assertStringNotContainsString( '/var/www/private', wp_json_encode( $information_failure ) );
		self::assertSame( 'plugin_install_failed', $upgrader_failure['error'] );
		self::assertSame( 'folder_exists', $upgrader_failure['failure_code'] );
		self::assertStringNotContainsString( '/var/www/private', wp_json_encode( $upgrader_failure ) );
	}

	public function test_install_plugin_rejects_non_wordpress_package_sources(): void {
		$GLOBALS['aculect_ai_companion_test_plugin_api']->download_link = 'https://example.com/classic-editor.zip';

		$result = $this->abilities->install_plugin( array( 'slug' => 'classic-editor' ) );

		self::assertSame( 'invalid_package_url', $result['error'] );
		self::assertStringNotContainsString( 'example.com', wp_json_encode( $result ) );
	}

	public function test_install_plugin_rejects_wordpress_package_authority_overrides(): void {
		$GLOBALS['aculect_ai_companion_test_plugin_api']->download_link = 'https://downloads.wordpress.org:8443/plugin/classic-editor.zip';

		$result = $this->abilities->install_plugin( array( 'slug' => 'classic-editor' ) );

		self::assertSame( 'invalid_package_url', $result['error'] );
	}

	public function test_install_plugin_blocks_incompatible_wordpress_requirements_before_preview(): void {
		$GLOBALS['aculect_ai_companion_test_plugin_api']->requires = '9.0';

		$result = $this->abilities->install_plugin( array( 'slug' => 'classic-editor', 'dry_run' => true ) );

		self::assertSame( 'plugin_requires_wordpress', $result['error'] );
	}

	public function test_update_plugin_returns_unavailable_without_cached_package(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = false;
		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_plugins'] = (object) array( 'response' => array() );

		$result = $this->abilities->update_plugin( array( 'plugin' => 'acme/acme.php' ) );

		self::assertSame( 'update_unavailable', $result['error'] );
	}

	public function test_update_plugin_rejects_missing_or_private_cached_package(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = false;
		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_plugins'] = (object) array(
			'response' => array(
				'acme/acme.php' => (object) array( 'new_version' => '2.0.0' ),
			),
		);
		$missing = $this->abilities->update_plugin( array( 'plugin' => 'acme/acme.php' ) );

		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_plugins'] = (object) array(
			'response' => array(
				'acme/acme.php' => (object) array(
					'new_version' => '2.0.0',
					'package'     => 'http://127.0.0.1/plugin.zip',
				),
			),
		);
		$private = $this->abilities->update_plugin( array( 'plugin' => 'acme/acme.php' ) );

		self::assertSame( 'update_unavailable', $missing['error'] );
		self::assertSame( 'invalid_package_url', $private['error'] );
		self::assertSame( '', $GLOBALS['aculect_ai_companion_test_last_plugin_upgrade'] );
	}

	public function test_update_plugin_blocks_incompatible_php_requirements_before_preview(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = false;
		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_plugins']->response['acme/acme.php']->requires_php = '99.0';

		$result = $this->abilities->update_plugin( array( 'plugin' => 'acme/acme.php', 'dry_run' => true ) );

		self::assertSame( 'plugin_requires_php', $result['error'] );
	}

	public function test_update_plugin_rejects_public_non_wordpress_package_sources(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = false;
		$GLOBALS['aculect_ai_companion_test_site_options']['_site_transient_update_plugins']->response['acme/acme.php']->package = 'https://example.com/acme.2.0.0.zip';

		$result = $this->abilities->update_plugin( array( 'plugin' => 'acme/acme.php' ) );

		self::assertSame( 'invalid_package_url', $result['error'] );
	}

	public function test_update_plugin_returns_preview_and_uses_core_upgrader(): void {
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = false;
		$preview = $this->abilities->update_plugin(
			array(
				'plugin'  => 'acme/acme.php',
				'dry_run' => true,
			)
		);
		$result  = $this->abilities->update_plugin( array( 'plugin' => 'acme/acme.php' ) );

		self::assertSame( 'plugin_lifecycle.update_plugin', $preview['action'] );
		self::assertTrue( $preview['confirmation_required'] );
		self::assertSame( '2.0.0', $preview['target']['version'] );
		self::assertSame( 'updated', $result['status'] );
		self::assertTrue( $result['verified'] );
		self::assertSame( 'acme/acme.php', $GLOBALS['aculect_ai_companion_test_last_plugin_upgrade'] );
		self::assertSame( 'https://downloads.wordpress.org/plugin/acme.2.0.0.zip', $GLOBALS['aculect_ai_companion_test_last_plugin_upgrade_package'] );
		self::assertSame( '2.0.0', $result['plugin']['version'] );
		self::assertTrue( $result['safety']['filesystem_writes'] );
	}

	public function test_install_and_update_require_exact_version_postconditions(): void {
		$GLOBALS['aculect_ai_companion_test_plugin_to_install']['headers']['Version'] = '1.5.0';
		$install = $this->abilities->install_plugin( array( 'slug' => 'classic-editor' ) );

		$GLOBALS['aculect_ai_companion_test_is_multisite'] = false;
		$GLOBALS['aculect_ai_companion_test_plugin_update_versions']['acme/acme.php'] = '1.5.0';
		$update = $this->abilities->update_plugin( array( 'plugin' => 'acme/acme.php' ) );

		self::assertSame( 'plugin_install_postcondition_failed', $install['error'] );
		self::assertSame( 'plugin_update_postcondition_failed', $update['error'] );
	}

	public function test_update_plugin_rejects_site_active_plugins_on_multisite(): void {
		$result = $this->abilities->update_plugin( array( 'plugin' => 'acme/acme.php' ) );

		self::assertSame( 'multisite_update_scope', $result['error'] );
		self::assertStringNotContainsString( '2.0.0', wp_json_encode( $result ) );
	}

	public function test_install_and_update_plugin_check_capabilities(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'install_plugins', 'update_plugins' );

		$install = $this->abilities->install_plugin( array( 'slug' => 'classic-editor' ) );
		$update  = $this->abilities->update_plugin( array( 'plugin' => 'acme/acme.php' ) );

		self::assertSame( 'forbidden', $install['error'] );
		self::assertSame( 'forbidden', $update['error'] );
	}

	public function test_list_plugins_supports_safe_status_filters(): void {
		$updates = $this->abilities->list_plugins( array( 'status' => 'update_available' ) );
		$paused  = $this->abilities->list_plugins( array( 'status' => 'paused' ) );

		self::assertSame( 1, $updates['total'] );
		self::assertSame( 'acme/acme.php', $updates['items'][0]['plugin'] );
		self::assertSame( 1, $paused['total'] );
		self::assertSame( 'paused-plugin/paused-plugin.php', $paused['items'][0]['plugin'] );
	}

	public function test_list_plugins_reports_capability_denial(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'activate_plugins' );

		$result = $this->abilities->list_plugins( array() );

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_capability_blockers_report_missing_lifecycle_capabilities(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'install_plugins', 'update_plugins', 'manage_network_plugins' );

		$result = $this->abilities->list_plugins( array() );

		self::assertFalse( $result['capabilities']['can_install_plugins'] );
		self::assertFalse( $result['capabilities']['can_update_plugins'] );
		self::assertTrue( $result['capabilities']['can_activate_plugins'] );
		self::assertSame( 'install_plugins', $result['capability_blockers']['install']['capability'] );
		self::assertSame( 'update_plugins', $result['capability_blockers']['update']['capability'] );
		self::assertSame( 'manage_network_plugins', $result['capability_blockers']['network_activate']['capability'] );
	}

	public function test_activate_plugin_returns_preview_for_dry_run(): void {
		$result = $this->abilities->activate_plugin(
			array(
				'plugin'  => 'paused-plugin/paused-plugin.php',
				'dry_run' => true,
			)
		);

		self::assertTrue( $result['dry_run'] );
		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'plugin_lifecycle.activate_plugin', $result['action'] );
		self::assertTrue( $result['confirmation_required'] );
		self::assertSame( 'Paused Plugin', $result['target']['name'] );
	}

	public function test_activate_plugin_activates_installed_plugin(): void {
		$result = $this->abilities->activate_plugin( array( 'plugin' => 'paused-plugin/paused-plugin.php' ) );

		self::assertSame( 'activated', $result['status'] );
		self::assertTrue( $result['changed'] );
		self::assertTrue( $result['plugin']['active'] );
		self::assertTrue( in_array( 'paused-plugin/paused-plugin.php', $GLOBALS['aculect_ai_companion_test_active_plugins'], true ) );
		self::assertSame( 'paused-plugin/paused-plugin.php', $GLOBALS['aculect_ai_companion_test_last_plugin_activation'] );
		self::assertFalse( $result['safety']['read_only'] );
	}

	public function test_activation_and_deactivation_require_exact_state_postconditions(): void {
		$GLOBALS['aculect_ai_companion_test_activation_noop'] = true;
		$activate = $this->abilities->activate_plugin( array( 'plugin' => 'paused-plugin/paused-plugin.php' ) );

		$GLOBALS['aculect_ai_companion_test_activation_noop']   = false;
		$GLOBALS['aculect_ai_companion_test_deactivation_noop'] = true;
		$deactivate = $this->abilities->deactivate_plugin( array( 'plugin' => 'acme/acme.php' ) );

		self::assertSame( 'plugin_activate_postcondition_failed', $activate['error'] );
		self::assertSame( 'plugin_deactivate_postcondition_failed', $deactivate['error'] );
	}

	public function test_activate_plugin_rejects_invalid_or_missing_plugins(): void {
		$invalid = $this->abilities->activate_plugin( array( 'plugin' => '../wp-config.php' ) );
		$missing = $this->abilities->activate_plugin( array( 'plugin' => 'missing/missing.php' ) );

		self::assertSame( 'invalid_plugin', $invalid['error'] );
		self::assertSame( 'plugin_not_found', $missing['error'] );
	}

	public function test_deactivate_plugin_deactivates_site_active_plugin(): void {
		$result = $this->abilities->deactivate_plugin( array( 'plugin' => 'acme/acme.php' ) );

		self::assertSame( 'deactivated', $result['status'] );
		self::assertTrue( $result['changed'] );
		self::assertFalse( $result['plugin']['site_active'] );
		self::assertFalse( in_array( 'acme/acme.php', $GLOBALS['aculect_ai_companion_test_active_plugins'], true ) );
		self::assertSame( array( 'acme/acme.php' ), $GLOBALS['aculect_ai_companion_test_last_plugin_deactivation'] );
	}

	public function test_deactivate_plugin_blocks_network_active_plugins_from_site_scope(): void {
		$result = $this->abilities->deactivate_plugin( array( 'plugin' => 'network-tool/network-tool.php' ) );

		self::assertSame( 'network_active_plugin', $result['error'] );
	}
}
