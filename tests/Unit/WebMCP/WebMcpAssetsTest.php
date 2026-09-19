<?php
/**
 * Tests for public WebMCP asset registration.
 *
 * @package Aculect\AICompanion\Tests\Unit\WebMCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\WebMCP;

use Aculect\AICompanion\WebMCP\WebMcpAssets;
use Aculect\AICompanion\WebMCP\WebMcpRequestPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies the WebMCP bridge remains a frontend progressive enhancement.
 */
final class WebMcpAssetsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_hooks']['actions'] = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']  = 0;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['aculect_ai_companion_test_current_user_id'] );

		parent::tearDown();
	}

	public function test_register_adds_only_the_frontend_enqueue_hook(): void {
		( new WebMcpAssets() )->register();

		$actions = $GLOBALS['aculect_ai_companion_test_hooks']['actions'];

		self::assertCount( 1, $actions );
		self::assertSame( 'wp_enqueue_scripts', $actions[0]['hook_name'] );
		self::assertSame( 'enqueue', $actions[0]['callback'][1] );
	}

	public function test_site_filter_can_disable_the_experimental_bridge(): void {
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_webmcp_enabled'] = static fn (): bool => false;
		$method = new ReflectionMethod( WebMcpAssets::class, 'should_enqueue' );

		self::assertFalse( $method->invoke( new WebMcpAssets() ) );
	}

	public function test_request_policy_denies_member_preview_and_private_contexts(): void {
		$policy  = new WebMcpRequestPolicy();
		$context = array(
			'admin'           => false,
			'json'            => false,
			'feed'            => false,
			'password'        => false,
			'logged_in'       => false,
			'preview'         => false,
			'singular_status' => 'publish',
		);

		self::assertTrue( $policy->allows( $context ) );
		self::assertFalse( $policy->allows( array_merge( $context, array( 'logged_in' => true ) ) ) );
		self::assertFalse( $policy->allows( array_merge( $context, array( 'preview' => true ) ) ) );
		self::assertFalse( $policy->allows( array_merge( $context, array( 'singular_status' => 'private' ) ) ) );
	}
}
