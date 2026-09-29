<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\FirstPartyAbilityModules;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\IntelligenceRegistry;
use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\McpController;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class McpAppsControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
		AbilitiesRegistry::reset_module_cache();
	}

	public function test_site_info_tool_links_the_view_only_for_negotiated_requests(): void {
		$modules    = ( new FirstPartyAbilityModules() )->all();
		$controller = new McpController();
		$method     = new \ReflectionMethod( McpController::class, 'tool_from_module' );
		$property   = new \ReflectionProperty( McpController::class, 'mcp_apps_ui_enabled' );

		$property->setValue( $controller, false );
		$without_ui = $method->invoke( $controller, $modules['site.get_info'] );
		self::assertArrayNotHasKey( 'ui', $without_ui['_meta'] );

		$property->setValue( $controller, true );
		$with_ui = $method->invoke( $controller, $modules['site.get_info'] );
		self::assertSame( McpAppsNegotiation::SITE_INFO_URI, $with_ui['_meta']['ui']['resourceUri'] );
		self::assertSame( array( 'model' ), $with_ui['_meta']['ui']['visibility'] );
	}

	public function test_only_allowlisted_app_resources_are_linked_across_tool_list_pages(): void {
		$controller = new McpController();
		$property   = new \ReflectionProperty( McpController::class, 'mcp_apps_ui_enabled' );
		$property->setValue( $controller, true );

		$cursor       = '';
		$pages        = 0;
		$linked_tools = array();
		do {
			$page = $controller->tools_list_page_for_user( 1, array( 'content:read' ), $cursor );
			++$pages;
			foreach ( $page['tools'] as $tool ) {
				if ( isset( $tool['_meta']['ui']['resourceUri'] ) ) {
					$linked_tools[] = $tool;
				}
			}
			$cursor = (string) ( $page['nextCursor'] ?? '' );
		} while ( '' !== $cursor && $pages < 20 );

		self::assertGreaterThan( 1, $pages );
		self::assertLessThan( 20, $pages );
		$uris = array_column( array_map( static fn ( array $tool ): array => $tool['_meta']['ui'], $linked_tools ), 'resourceUri' );
		self::assertContains( McpAppsNegotiation::SITE_INFO_URI, $uris );
		self::assertContains( McpAppsNegotiation::PATTERN_PICKER_URI, $uris );
		self::assertCount( 2, $uris );
		self::assertTrue( $linked_tools[0]['annotations']['readOnlyHint'] );
	}

	public function test_pattern_picker_resource_uri_is_attached_only_to_its_negotiated_tool(): void {
		$module     = ( new IntelligenceRegistry() )->module( 'intelligence.patterns.list_available' );
		$controller = new McpController();
		$method     = new \ReflectionMethod( McpController::class, 'tool_from_module' );
		$property   = new \ReflectionProperty( McpController::class, 'mcp_apps_ui_enabled' );

		self::assertNotNull( $module );
		$property->setValue( $controller, false );
		$without_ui = $method->invoke( $controller, $module );
		self::assertArrayNotHasKey( 'ui', $without_ui['_meta'] );

		$property->setValue( $controller, true );
		$with_ui = $method->invoke( $controller, $module );
		self::assertSame( McpAppsNegotiation::PATTERN_PICKER_URI, $with_ui['_meta']['ui']['resourceUri'] );
		self::assertSame( array( 'model' ), $with_ui['_meta']['ui']['visibility'] );

		$other = ( new FirstPartyAbilityModules() )->all()['site.get_settings'];
		self::assertArrayNotHasKey( 'ui', $method->invoke( $controller, $other )['_meta'] );
	}

	public function test_opted_in_resources_list_uses_private_zero_ttl_cache(): void {
		$controller = new McpController();
		$auth       = new \ReflectionProperty( McpController::class, 'request_auth' );
		$auth->setValue(
			$controller,
			array(
				'user_id'   => 1,
				'client_id' => 'current-result-client',
				'scopes'    => \Aculect\AICompanion\Connectors\Helpers::supported_scopes(),
				'profile'   => 'full_access',
			)
		);
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_apps_enabled'] = static fn (): bool => true;

		try {
			$response = $controller->handle_rpc(
				new WP_REST_Request(
					array(),
					array(
						'mcp-protocol-version' => McpController::PROTOCOL_VERSION_CURRENT,
						'mcp-method'           => 'resources/list',
					),
					array(
						'jsonrpc' => '2.0',
						'id'      => 2026,
						'method'  => 'resources/list',
						'params'  => array(
							'_meta' => array(
								'io.modelcontextprotocol/protocolVersion'    => McpController::PROTOCOL_VERSION_CURRENT,
								'io.modelcontextprotocol/clientCapabilities' => array(),
								'io.modelcontextprotocol/clientInfo'         => array(
									'name'    => 'Aculect test client',
									'version' => '1.0.0',
								),
							),
						),
					),
					'POST',
					'/aculect-ai-companion/v1/mcp'
				)
			);

			self::assertIsArray( $response );
			self::assertSame( 'private', $response['result']['cacheScope'] ?? '' );
			self::assertSame( 0, $response['result']['ttlMs'] ?? null );
		} finally {
			unset( $GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_apps_enabled'] );
		}
	}
}
