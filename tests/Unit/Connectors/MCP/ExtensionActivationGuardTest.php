<?php
/**
 * Lifecycle activation and trusted-connection regressions.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionRequest;
use Aculect\AICompanion\Connectors\MCP\ExtensionActivationGuard;
use Aculect\AICompanion\Connectors\MCP\PluginLifecyclePackagePolicy;
use Aculect\AICompanion\Connectors\MCP\PluginLifecyclePackageManager;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Tests\Support\InMemoryExecutionClaimStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/site-workflow-stubs.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/theme-lifecycle-stubs.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/plugin-lifecycle-upgrader-stubs.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/plugin-lifecycle-stubs.php';

/**
 * Native theme validity and confirmation bypasses remain fail-closed.
 */
final class ExtensionActivationGuardTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['aculect_ai_companion_test_options']           = array();
		$GLOBALS['aculect_ai_companion_test_transients']        = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']       = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']   = 1;
		$GLOBALS['aculect_ai_companion_test_is_multisite']      = false;
		$GLOBALS['aculect_ai_companion_test_active_stylesheet'] = 'original';
		$GLOBALS['aculect_ai_companion_test_themes']            = array(
			'original' => array(
				'Name'       => 'Original',
				'Stylesheet' => 'original',
				'Version'    => '1.0',
			),
			'target'   => array(
				'Name'       => 'Target',
				'Stylesheet' => 'target',
				'Version'    => '1.0',
			),
		);
		$GLOBALS['aculect_ai_companion_test_users']             = array(
			1 => (object) array(
				'ID'         => 1,
				'roles'      => array( 'administrator' ),
				'user_login' => 'fixture',
			),
		);
	}

	public function test_theme_switch_requires_confirmation_even_with_trusted_write(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'theme_lifecycle.switch_theme' ) );
		$gateway = new AbilityExecutionGateway( $registry, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
		$result  = $gateway->execute(
			new AbilityExecutionRequest(
				array(
					'name'      => 'theme_lifecycle_switch_theme',
					'arguments' => array( 'stylesheet' => 'target' ),
				),
				array(
					'user_id'                  => 1,
					'client_id'                => 'fixture',
					'scopes'                   => array( 'content:read', 'content:draft' ),
					'profile'                  => 'full_access',
					'write_permission_enabled' => true,
				)
			)
		);
		self::assertSame( 'confirmation_required', $result->data['result']['status'] ?? '' );
		self::assertSame( 'original', $GLOBALS['aculect_ai_companion_test_active_stylesheet'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['aculect_ai_companion_test_themes'], $GLOBALS['aculect_ai_companion_test_active_stylesheet'] );
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = false;
	}

	public function test_invalid_disallowed_and_incompatible_themes_are_blocked(): void {
		$GLOBALS['aculect_ai_companion_test_themes']['target']['Invalid'] = true;
		self::assertSame( 'theme_unavailable', ExtensionActivationGuard::theme( 'target' )['error'] );
		unset( $GLOBALS['aculect_ai_companion_test_themes']['target']['Invalid'] );
		$GLOBALS['aculect_ai_companion_test_themes']['target']['Allowed'] = false;
		$GLOBALS['aculect_ai_companion_test_is_multisite']                = true;
		self::assertSame( 'theme_unavailable', ExtensionActivationGuard::theme( 'target' )['error'] );
		$GLOBALS['aculect_ai_companion_test_is_multisite']                    = false;
		$GLOBALS['aculect_ai_companion_test_themes']['target']['RequiresPHP'] = '99.0';
		self::assertSame( 'incompatible_theme', ExtensionActivationGuard::theme( 'target' )['error'] );
	}

	public function test_self_deactivation_is_protected(): void {
		self::assertSame( 'protected_plugin', ExtensionActivationGuard::plugin( plugin_basename( ACULECT_AI_COMPANION_PLUGIN_FILE ), 'deactivate' )['error'] );
	}

	public function test_plugin_packages_reject_query_credentials_and_fragments(): void {
		$policy = new PluginLifecyclePackagePolicy();
		foreach ( array( '?token=secret', '#fragment' ) as $suffix ) {
			$result = $policy->requested_package_url( 'https://downloads.wordpress.org/plugin/example.1.0.zip' . $suffix, 'example', '1.0' );
			self::assertIsArray( $result );
			self::assertSame( 'invalid_package_url', $result['error'] );
		}
	}

	public function test_plugin_packages_never_request_filesystem_credentials(): void {
		$manager = new PluginLifecyclePackageManager();
		try {
			$GLOBALS['aculect_ai_companion_test_filesystem_method'] = 'ftpext';
			$result = $manager->install( 'https://downloads.wordpress.org/plugin/example.1.0.zip' );
			self::assertInstanceOf( \WP_Error::class, $result );
			self::assertSame( 'filesystem_unavailable', $result->get_error_code() );
			$GLOBALS['aculect_ai_companion_test_filesystem_method'] = 'direct';
			$GLOBALS['aculect_ai_companion_test_file_mod_allowed']  = false;
			self::assertInstanceOf( \WP_Error::class, $manager->update( 'example/example.php', 'https://downloads.wordpress.org/plugin/example.1.0.zip' ) );
		} finally {
			unset( $GLOBALS['aculect_ai_companion_test_filesystem_method'], $GLOBALS['aculect_ai_companion_test_file_mod_allowed'] );
		}
	}

	public function test_plugin_package_urls_are_pinned_to_the_confirmed_version(): void {
		$policy = new PluginLifecyclePackagePolicy();
		$url    = 'https://downloads.wordpress.org/plugin/example.1.0.zip';
		self::assertSame( $url, $policy->requested_package_url( $url, 'example', '1.0' ) );
		foreach ( array( 'example.zip', 'example.2.0.zip', 'example.other.zip' ) as $file ) {
			self::assertSame( 'invalid_package_url', $policy->requested_package_url( 'https://downloads.wordpress.org/plugin/' . $file, 'example', '1.0' )['error'] );
		}
	}

	public function test_uncertain_plugin_package_result_cannot_repeat_with_the_same_confirmation(): void {
		$GLOBALS['aculect_ai_companion_test_plugins']               = array();
		$GLOBALS['aculect_ai_companion_test_plugin_api']            = (object) array(
			'name'          => 'Example',
			'version'       => '1.0',
			'download_link' => 'https://downloads.wordpress.org/plugin/example.1.0.zip',
		);
		$GLOBALS['aculect_ai_companion_test_plugin_install_result'] = new \WP_Error( 'core_failed', 'private-path' );
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'plugin_lifecycle.install_plugin' ) );
		$gateway = new AbilityExecutionGateway( $registry, null, null, new ToolSafety( new InMemoryExecutionClaimStore() ) );
		$auth    = array(
			'user_id'                  => 1,
			'client_id'                => 'fixture',
			'scopes'                   => array( 'content:read', 'content:draft' ),
			'profile'                  => 'full_access',
			'write_permission_enabled' => true,
		);
		$params  = array(
			'name'      => 'plugin_lifecycle_install_plugin',
			'arguments' => array( 'slug' => 'example' ),
		);
		try {
			$preview                                   = $gateway->execute( new AbilityExecutionRequest( $params, $auth ) );
			$params['arguments']['confirmation_token'] = $preview->data['result']['confirmation_token'];
			$failed                                    = $gateway->execute( new AbilityExecutionRequest( $params, $auth ) );
			self::assertSame( 'partial_write', $failed->data['result']['error'] );
			self::assertTrue( $failed->data['result']['terminal'] );
			$GLOBALS['aculect_ai_companion_test_last_plugin_package'] = '';
			$replay = $gateway->execute( new AbilityExecutionRequest( $params, $auth ) );
			self::assertSame( 'partial_write', $replay->data['result']['error'] );
			self::assertSame( '', $GLOBALS['aculect_ai_companion_test_last_plugin_package'] );
			self::assertStringNotContainsString( 'private-path', wp_json_encode( $failed->data ) );
		} finally {
			unset( $GLOBALS['aculect_ai_companion_test_plugins'], $GLOBALS['aculect_ai_companion_test_plugin_api'], $GLOBALS['aculect_ai_companion_test_plugin_install_result'], $GLOBALS['aculect_ai_companion_test_last_plugin_package'] );
		}
	}
}
