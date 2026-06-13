<?php
/**
 * Tests for OAuth discovery metadata.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\OAuth
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\OAuth;

use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\OAuth\DiscoveryController;
use Aculect\AICompanion\Connectors\OAuth\TokenEndpointAuthMethod;
use PHPUnit\Framework\TestCase;

/**
 * Verifies connector clients discover the same scope contract.
 */
final class DiscoveryControllerTest extends TestCase {

	public function test_discovery_metadata_advertises_all_supported_scopes(): void {
		$controller = new DiscoveryController();
		$expected   = Helpers::supported_scopes();

		$resource_metadata = $controller->protected_resource_metadata()->get_data();
		$auth_metadata     = $controller->authorization_server_metadata()->get_data();

		self::assertSame( array( 'content:read', 'content:draft' ), $expected );
		self::assertSame( $expected, $resource_metadata['scopes_supported'] );
		self::assertSame( $expected, $auth_metadata['scopes_supported'] );
		self::assertSame( Helpers::registration_endpoint(), $auth_metadata['registration_endpoint'] );
		self::assertSame( array( 'S256' ), $auth_metadata['code_challenge_methods_supported'] );
		self::assertSame( TokenEndpointAuthMethod::supported(), $resource_metadata['token_endpoint_auth_methods_supported'] );
		self::assertSame( TokenEndpointAuthMethod::supported(), $auth_metadata['token_endpoint_auth_methods_supported'] );
	}
}
