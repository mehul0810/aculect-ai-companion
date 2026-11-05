<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Diagnostics\WebMcpReadiness;
use PHPUnit\Framework\TestCase;

/**
 * Checks that WebMCP diagnostics distinguish configuration, delivery and execution proof.
 */
final class WebMcpReadinessTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		unset( $GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_webmcp_enabled'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_webmcp_enabled'] );
		parent::tearDown();
	}

	public function test_disabled_feature_is_distinguished_from_missing_delivery(): void {
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_webmcp_enabled'] = static fn(): bool => false;
		$item = ( new WebMcpReadiness() )->item( array() );

		self::assertSame( 'not_applicable', $item['status'] );
		self::assertFalse( $item['evidence']['featureEnabled'] );
		self::assertFalse( $item['evidence']['publicScriptObserved'] );
		self::assertFalse( $item['evidence']['nativeBrowserRegistrationVerified'] );
	}

	public function test_homepage_script_observation_never_claims_browser_registration(): void {
		$asset = ACULECT_AI_COMPANION_PLUGIN_URL . 'assets/js/webmcp.js?ver=0.9.0';
		$item  = ( new WebMcpReadiness() )->item(
			array(
				'status'       => 200,
				'content_type' => 'text/html; charset=UTF-8',
				'body'         => sprintf( '<html><%s src="%s"></%s></html>', 'script', esc_url( $asset ), 'script' ),
			)
		);

		self::assertTrue( $item['evidence']['featureEnabled'] );
		self::assertTrue( $item['evidence']['assetPresentLocally'] );
		self::assertFalse( $item['evidence']['nativeBrowserRegistrationVerified'] );
		self::assertSame( 'warning', $item['status'] );
	}

	public function test_wrong_origin_or_inert_script_is_not_public_delivery_proof(): void {
		$tag  = 'script';
		$item = ( new WebMcpReadiness() )->item(
			array(
				'status'       => 200,
				'content_type' => 'text/html',
				'body'         => sprintf( '<html><%1$s src="https://evil.invalid/assets/js/webmcp.js"></%1$s><%1$s type="text/plain" src="%2$sassets/js/webmcp.js"></%1$s></html>', $tag, ACULECT_AI_COMPANION_PLUGIN_URL ),
			)
		);

		self::assertFalse( $item['evidence']['publicScriptObserved'] );
		self::assertFalse( $item['evidence']['nativeBrowserRegistrationVerified'] );
	}

	public function test_edge_challenge_is_reported_as_externally_blocked(): void {
		$item = ( new WebMcpReadiness() )->item(
			array(
				'status'         => 403,
				'edge_challenge' => true,
			)
		);

		self::assertSame( 'externally_blocked', $item['status'] );
		self::assertFalse( $item['evidence']['publicScriptObserved'] );
	}
}
