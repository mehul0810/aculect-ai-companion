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

		self::assertSame( array( 'content:read', 'content:draft', 'offline_access' ), $expected );
		self::assertSame( $expected, $resource_metadata['scopes_supported'] );
		self::assertSame( $expected, $auth_metadata['scopes_supported'] );
		self::assertSame( Helpers::authorization_endpoint(), $auth_metadata['authorization_endpoint'] );
		self::assertSame( 'https://example.com/aculect-ai-companion/oauth/authorize', $auth_metadata['authorization_endpoint'] );
		self::assertStringNotContainsString( '/wp-json/', $auth_metadata['authorization_endpoint'] );
		self::assertSame( Helpers::registration_endpoint(), $auth_metadata['registration_endpoint'] );
		self::assertSame( array( 'S256' ), $auth_metadata['code_challenge_methods_supported'] );
		self::assertSame( TokenEndpointAuthMethod::supported(), $resource_metadata['token_endpoint_auth_methods_supported'] );
		self::assertSame( TokenEndpointAuthMethod::supported(), $auth_metadata['token_endpoint_auth_methods_supported'] );
		self::assertFalse( $auth_metadata['client_id_metadata_document_supported'] );
		self::assertTrue( $auth_metadata['authorization_response_iss_parameter_supported'] );
		self::assertSame( Helpers::issuer(), $auth_metadata['issuer'] );
		self::assertSame( array( Helpers::issuer() ), $resource_metadata['authorization_servers'] );
		self::assertSame( 'public, max-age=300', $controller->protected_resource_metadata()->header( 'Cache-Control' ) );
		self::assertSame( 'public, max-age=300', $controller->authorization_server_metadata()->header( 'Cache-Control' ) );
	}

	public function test_path_specific_protected_resource_metadata_matches_the_mcp_challenge(): void {
		$resource      = Helpers::mcp_resource();
		$resource_path = Helpers::resource_path( $resource );
		$metadata_url  = Helpers::protected_resource_metadata_url( $resource );
		$response      = ( new DiscoveryController() )->protected_resource_metadata( $resource_path );

		self::assertSame( 'https://example.com/.well-known/oauth-protected-resource/wp-json/aculect-ai-companion/v1/mcp', $metadata_url );
		self::assertSame( 200, $response->get_status() );
		self::assertSame( $resource, $response->get_data()['resource'] );
		self::assertSame( array( Helpers::authorization_server_issuer() ), $response->get_data()['authorization_servers'] );
	}

	public function test_reverse_proxy_external_root_is_the_sole_canonical_issuer(): void {
		$previous_callbacks = $GLOBALS['aculect_ai_companion_test_filter_callbacks'] ?? array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect-ai-companion/connectors/external_url'] =
			static fn(): string => 'https://public.example/proxied-site/';

		try {
			$controller = new DiscoveryController();
			$auth       = $controller->authorization_server_metadata()->get_data();
			$resource   = $controller->protected_resource_metadata()->get_data();

			self::assertSame( 'https://public.example/proxied-site', Helpers::issuer() );
			self::assertSame( Helpers::issuer(), $auth['issuer'] );
			self::assertSame( array( Helpers::issuer() ), $resource['authorization_servers'] );
			self::assertStringStartsWith( Helpers::issuer(), $auth['authorization_endpoint'] );
			self::assertStringStartsWith( Helpers::issuer(), $auth['token_endpoint'] );
		} finally {
			$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = $previous_callbacks;
		}
	}

	public function test_root_well_known_urls_resolve_only_the_matching_active_multisite_blog(): void {
		require_once dirname( __DIR__, 3 ) . '/fixtures/oauth-multisite-discovery-stubs.php';
		$keys               = array(
			'aculect_ai_companion_test_is_multisite',
			'aculect_ai_companion_test_blog_id',
			'aculect_ai_companion_test_home_url',
			'aculect_ai_companion_test_options',
			'aculect_ai_companion_test_site_options',
			'aculect_oauth_discovery_site_lookup',
			'aculect_oauth_discovery_second_active',
			'aculect_oauth_discovery_second_network',
			'aculect_oauth_discovery_site_flags',
			'aculect_oauth_discovery_saved_context',
		);
		$previous           = array_intersect_key( $GLOBALS, array_flip( $keys ) );
		$had_http_host      = array_key_exists( 'HTTP_HOST', $_SERVER );
		$previous_http_host = $_SERVER['HTTP_HOST'] ?? null;
		try {
			$_SERVER['HTTP_HOST']                              = 'example.com';
			$GLOBALS['aculect_ai_companion_test_is_multisite'] = true;
			$GLOBALS['aculect_ai_companion_test_blog_id']      = 1;
			$GLOBALS['aculect_ai_companion_test_home_url']     = 'https://example.com';
			$GLOBALS['aculect_ai_companion_test_options']      = array( 'active_plugins' => array( plugin_basename( ACULECT_AI_COMPANION_PLUGIN_FILE ) ) );
			$GLOBALS['aculect_ai_companion_test_site_options'] = array();
			$GLOBALS['aculect_oauth_discovery_site_lookup']    = array();
			$GLOBALS['aculect_oauth_discovery_second_active']  = true;
			$GLOBALS['aculect_oauth_discovery_second_network'] = 1;
			$GLOBALS['aculect_oauth_discovery_site_flags']     = array();

			$controller = new DiscoveryController();
			$resource   = $controller->well_known_metadata_response( Helpers::PROTECTED_RESOURCE_METADATA, '/second/wp-json/aculect-ai-companion/v1/mcp' );
			$issuer     = $controller->well_known_metadata_response( Helpers::AUTHORIZATION_METADATA, '/second' );
			self::assertSame( 200, $resource->get_status() );
			self::assertSame( 'https://example.com/second/wp-json/aculect-ai-companion/v1/mcp', $resource->get_data()['resource'] );
			self::assertSame( array( 'https://example.com/second' ), $resource->get_data()['authorization_servers'] );
			self::assertSame( 200, $issuer->get_status() );
			self::assertSame( 'https://example.com/second', $issuer->get_data()['issuer'] );
			self::assertSame( 1, get_current_blog_id() );
			self::assertSame( 'https://example.com', Helpers::issuer() );

			$wrong_resource = $controller->well_known_metadata_response( Helpers::PROTECTED_RESOURCE_METADATA, '/second/wp-json/not-the-mcp-resource' );
			self::assertSame( 404, $wrong_resource->get_status() );
			self::assertSame( 'invalid_target', $wrong_resource->get_data()['error'] );
			self::assertSame( 404, $controller->well_known_metadata_response( Helpers::AUTHORIZATION_METADATA, '/unknown' )->get_status() );
			$_SERVER['HTTP_HOST'] = 'mapped.example';
			self::assertSame( 404, $controller->well_known_metadata_response( Helpers::PROTECTED_RESOURCE_METADATA, '/second/wp-json/aculect-ai-companion/v1/mcp' )->get_status() );
			self::assertSame( 404, $controller->well_known_metadata_response( Helpers::AUTHORIZATION_METADATA, '/second' )->get_status() );
			$_SERVER['HTTP_HOST'] = 'example.com';

			foreach ( array( array( 'deleted' => 1 ), array( 'deleted' => 2 ), array( 'archived' => 1 ), array( 'spam' => 1 ) ) as $flags ) {
				$GLOBALS['aculect_oauth_discovery_site_flags'] = $flags;
				self::assertSame( 404, $controller->well_known_metadata_response( Helpers::PROTECTED_RESOURCE_METADATA, '/second/wp-json/aculect-ai-companion/v1/mcp' )->get_status() );
				self::assertSame( 404, $controller->well_known_metadata_response( Helpers::AUTHORIZATION_METADATA, '/second' )->get_status() );
				self::assertSame( 1, get_current_blog_id() );
			}
			$GLOBALS['aculect_oauth_discovery_site_flags'] = array();

			$GLOBALS['aculect_oauth_discovery_second_active'] = false;
			self::assertSame( 404, $controller->well_known_metadata_response( Helpers::PROTECTED_RESOURCE_METADATA, '/second/wp-json/aculect-ai-companion/v1/mcp' )->get_status() );
			$GLOBALS['aculect_oauth_discovery_second_active']  = true;
			$GLOBALS['aculect_oauth_discovery_second_network'] = 2;
			self::assertSame( 404, $controller->well_known_metadata_response( Helpers::PROTECTED_RESOURCE_METADATA, '/second/wp-json/aculect-ai-companion/v1/mcp' )->get_status() );
			self::assertSame( 1, get_current_blog_id() );
		} finally {
			foreach ( $keys as $key ) {
				if ( array_key_exists( $key, $previous ) ) {
					$GLOBALS[ $key ] = $previous[ $key ];
				} else {
					unset( $GLOBALS[ $key ] );
				}
			}
			if ( $had_http_host ) {
				$_SERVER['HTTP_HOST'] = $previous_http_host;
			} else {
				unset( $_SERVER['HTTP_HOST'] );
			}
		}
	}
}
