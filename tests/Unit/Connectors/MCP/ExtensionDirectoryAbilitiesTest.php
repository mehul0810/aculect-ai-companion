<?php
/**
 * Bounded public directory contracts.
 *
 * @package Aculect\AICompanion\Tests
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\ExtensionDirectoryAbilities;
use Aculect\AICompanion\Connectors\MCP\ExtensionLifecyclePolicy;
use Aculect\AICompanion\Connectors\MCP\Modules\ExtensionLifecycleAbilityModules;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/extension-upload-stubs.php';
require_once dirname( __DIR__, 3 ) . '/fixtures/plugin-lifecycle-upgrader-stubs.php';

/**
 * Remote data never becomes an executable package or a secret-bearing result.
 */
final class ExtensionDirectoryAbilitiesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		unset( $GLOBALS['aculect_extension_test_https'], $GLOBALS['aculect_ai_companion_test_file_mod_allowed'] );
	}

	public function test_search_projects_only_safe_bounded_fields(): void {
		$service = new ExtensionDirectoryAbilities(
			static function ( string $kind, array $args ): object {
				self::assertSame( 'plugin', $kind );
				self::assertSame( 2, $args['per_page'] );
				return (object) array(
					'plugins' => array(
						(object) array(
							'slug'          => 'example',
							'name'          => '<b>Example</b>',
							'version'       => '1.0',
							'download_link' => 'https://private.test/?token=secret',
							'secret'        => 'secret',
						),
						(object) array(
							'slug' => '../../bad',
							'name' => 'Invalid',
						),
						(object) array( 'slug' => 'outside-limit' ),
					),
				);
			}
		);
		$result  = $service->search(
			'plugin',
			array(
				'search'   => 'example',
				'per_page' => 2,
			)
		);
		self::assertCount( 1, $result['items'] );
		self::assertSame( 'Example', $result['items'][0]['name'] );
		self::assertSame( 'https://wordpress.org/plugins/example/', $result['items'][0]['url'] );
		self::assertStringNotContainsString( 'secret', (string) wp_json_encode( $result ) );
		self::assertSame( 'untrusted_directory_metadata', $result['content_trust'] );
	}

	public function test_malformed_search_never_calls_directory(): void {
		$service = new ExtensionDirectoryAbilities(
			static function (): never {
				self::fail( 'Unexpected remote query.' );
			}
		);
		foreach ( array( null, array(), new \stdClass(), '', str_repeat( 'x', 121 ) ) as $query ) {
			self::assertSame( 'invalid_search', $service->search( 'theme', array( 'search' => $query ) )['error'] );
		}
		foreach ( array( 0, 101, '1', array() ) as $page ) {
			self::assertSame(
				'invalid_search',
				$service->search(
					'theme',
					array(
						'search' => 'test',
						'page'   => $page,
					)
				)['error']
			);
		}
	}

	public function test_directory_failures_are_fixed_and_do_not_leak(): void {
		foreach ( array( null, false, 'secret', array(), new \WP_Error( 'secret', 'secret' ) ) as $response ) {
			$result = ( new ExtensionDirectoryAbilities( static fn (): mixed => $response ) )->search( 'theme', array( 'search' => 'test' ) );
			self::assertSame( 'directory_unavailable', $result['error'] );
			self::assertStringNotContainsString( 'secret', (string) wp_json_encode( $result ) );
		}
		$result = ( new ExtensionDirectoryAbilities( static fn (): never => throw new \RuntimeException( 'secret' ) ) )->search( 'theme', array( 'search' => 'test' ) );
		self::assertSame( 'directory_unavailable', $result['error'] );
	}

	public function test_directory_and_upload_require_their_capabilities(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'install_plugins', 'upload_themes' );
		$service = new ExtensionDirectoryAbilities();
		self::assertSame( 'forbidden', $service->search( 'plugin', array( 'search' => 'test' ) )['error'] );
		self::assertSame( 'forbidden', $service->upload( 'theme' )['error'] );
		self::assertSame( 'forbidden', $service->upload( 'invalid' )['error'] );
	}

	public function test_new_modules_and_lifecycle_safety_are_closed(): void {
		$modules = ( new ExtensionLifecycleAbilityModules() )->all();
		self::assertCount( 8, $modules );
		foreach ( $modules as $module ) {
			self::assertNotNull( ExtensionLifecyclePolicy::capabilities( $module->id() ) );
			if ( ! $module->is_read_only() ) {
				self::assertTrue( ExtensionLifecyclePolicy::requires_binding( $module->id() ) );
				self::assertTrue( ExtensionLifecyclePolicy::requires_confirmation( $module->id() ) );
				self::assertTrue( ( new ToolSafety() )->requires_confirmation( $module->id(), array() ) );
			}
		}
		self::assertTrue( ExtensionLifecyclePolicy::requires_confirmation( 'theme_lifecycle.switch_theme' ) );
		self::assertSame( 'destructive', ( new ToolSafety() )->risk_level( 'plugin_lifecycle.delete_plugin', array() ) );
	}

	public function test_upload_is_manual_and_requires_https_single_site_and_file_modifications(): void {
		$service = new ExtensionDirectoryAbilities();
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = false;
		$GLOBALS['aculect_extension_test_https']           = true;
		foreach ( array( 'plugin', 'theme' ) as $kind ) {
			$result = $service->upload( $kind );
			self::assertSame( 'manual_action_required', $result['status'] );
			self::assertFalse( $result['changed'] );
			self::assertStringContainsString( $kind . '-install.php', $result['admin_url'] );
		}
		$GLOBALS['aculect_extension_test_https'] = false;
		self::assertSame( 'upload_unavailable', $service->upload( 'plugin' )['error'] );
		$GLOBALS['aculect_extension_test_https']           = true;
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = true;
		self::assertSame( 'upload_unavailable', $service->upload( 'plugin' )['error'] );
		$GLOBALS['aculect_ai_companion_test_is_multisite']     = false;
		$GLOBALS['aculect_ai_companion_test_file_mod_allowed'] = false;
		self::assertSame( 'upload_unavailable', $service->upload( 'theme' )['error'] );
	}
}
