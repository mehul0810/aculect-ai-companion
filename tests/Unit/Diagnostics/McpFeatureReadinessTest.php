<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Diagnostics;

use Aculect\AICompanion\Diagnostics\CloudflareReadiness;
use Aculect\AICompanion\Diagnostics\McpFeatureReadiness;
use Aculect\AICompanion\Settings\McpAppsSettings;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that configuration and public probes do not imply client acceptance.
 */
final class McpFeatureReadinessTest extends TestCase {

	public function test_saved_and_effective_preference_are_distinct_from_client_observations(): void {
		$previous = McpAppsSettings::saved_enabled();
		$filters  = $GLOBALS['aculect_ai_companion_test_filter_callbacks'] ?? array();
		McpAppsSettings::set_enabled( true );
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'][ McpAppsSettings::OPTION_ENABLED ] = static fn (): bool => false;
		try {
			$result = ( new McpFeatureReadiness() )->item();
			self::assertTrue( $result['evidence']['appsSavedEnabled'] );
			self::assertFalse( $result['evidence']['appsEffectiveEnabled'] );
			self::assertFalse( $result['evidence']['clientNegotiationVerified'] );
			self::assertFalse( $result['evidence']['clientRenderingVerified'] );
			self::assertSame( 'warning', $result['status'] );
		} finally {
			$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = $filters;
			McpAppsSettings::set_enabled( $previous );
		}
	}

	public function test_successful_public_probes_and_missing_headers_do_not_pass_connector_gate(): void {
		$result = ( new CloudflareReadiness() )->item( array( 'status' => 200 ), array( 'status' => 401 ), null );
		self::assertSame( 'warning', $result['status'] );
		self::assertFalse( $result['evidence']['cloudflareDetected'] );
		self::assertFalse( $result['evidence']['connectorReachabilityVerified'] );
	}

	public function test_challenge_is_external_block_without_exporting_response_data(): void {
		$result = ( new CloudflareReadiness() )->item(
			array(
				'status'              => 200,
				'edge_challenge'      => true,
				'cloudflare_detected' => true,
				'body'                => 'private challenge',
				'cf-ray'              => 'private ray',
			)
		);
		self::assertSame( 'externally_blocked', $result['status'] );
		self::assertTrue( $result['evidence']['cloudflareDetected'] );
		self::assertTrue( $result['evidence']['endpointBlocked'] );
		self::assertFalse( $result['evidence']['connectorReachabilityVerified'] );
		self::assertStringNotContainsString( 'private', (string) wp_json_encode( $result ) );
	}
}
