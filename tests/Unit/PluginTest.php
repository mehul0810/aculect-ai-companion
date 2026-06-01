<?php
/**
 * Plugin bootstrap tests.
 *
 * @package Aculect\AICompanion\Tests\Unit
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit;

use Aculect\AICompanion\Admin\SettingsPage;
use Aculect\AICompanion\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies central plugin hooks register routes required by the admin app.
 */
final class PluginTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_rest_routes'] = array();
	}

	public function test_register_routes_includes_settings_payload_endpoint(): void {
		Plugin::instance()->register_routes();

		$routes = array_values(
			array_filter(
				$GLOBALS['aculect_ai_companion_test_rest_routes'],
				static fn( array $route ): bool => 'aculect-ai-companion/v1' === $route['namespace']
					&& '/settings-payload' === $route['route']
			)
		);

		self::assertCount( 1, $routes );

		$route = $routes[0]['args'];

		self::assertSame( 'GET', $route['methods'] );
		self::assertIsArray( $route['callback'] );
		self::assertInstanceOf( SettingsPage::class, $route['callback'][0] );
		self::assertSame( 'rest_settings_payload', $route['callback'][1] );
		self::assertIsArray( $route['permission_callback'] );
		self::assertInstanceOf( SettingsPage::class, $route['permission_callback'][0] );
		self::assertSame( 'can_manage_settings', $route['permission_callback'][1] );
		self::assertSame( 'string', $route['args']['tab']['type'] );
		self::assertSame( 'sanitize_key', $route['args']['tab']['sanitize_callback'] );
	}

	public function test_root_authorize_route_is_detected_from_parse_request_query_vars(): void {
		$wp = (object) array(
			'query_vars' => array(
				'aculect_ai_companion_oauth_authorize' => '1',
			),
		);

		self::assertTrue(
			$this->invokePrivate(
				Plugin::instance(),
				'is_root_authorize_request',
				array( $wp )
			)
		);
	}

	public function test_root_authorize_route_ignores_unmatched_parse_request_query_vars(): void {
		$wp = (object) array(
			'query_vars' => array(
				'pagename' => 'oauth/authorize',
			),
		);

		self::assertFalse(
			$this->invokePrivate(
				Plugin::instance(),
				'is_root_authorize_request',
				array( $wp )
			)
		);
	}

	/**
	 * Invoke a private method for focused unit coverage without widening runtime API.
	 *
	 * @param object      $object    Object instance.
	 * @param string      $method    Method name.
	 * @param list<mixed> $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}
}
