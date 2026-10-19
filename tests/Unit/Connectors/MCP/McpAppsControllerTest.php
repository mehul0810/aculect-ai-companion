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

	private mixed $previous_capability_callback;
	private mixed $previous_filter_callbacks;

	protected function setUp(): void {
		parent::setUp();

		$this->previous_capability_callback = $GLOBALS['aculect_ai_companion_test_capability_callback'] ?? null;
		$this->previous_filter_callbacks    = $GLOBALS['aculect_ai_companion_test_filter_callbacks'] ?? null;
		unset( $GLOBALS['aculect_ai_companion_test_capability_callback'] );
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']  = 1;
		$GLOBALS['aculect_ai_companion_test_users']            = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
		AbilitiesRegistry::reset_module_cache();
	}

	protected function tearDown(): void {
		foreach ( array(
			'aculect_ai_companion_test_capability_callback' => $this->previous_capability_callback,
			'aculect_ai_companion_test_filter_callbacks' => $this->previous_filter_callbacks,
		) as $name => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $name ] );
			} else {
				$GLOBALS[ $name ] = $value;
			}
		}
		parent::tearDown();
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
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn (): bool => true;
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
		self::assertContains( McpAppsNegotiation::IMAGE_UPLOAD_URI, $uris );
		self::assertEquals(
			array(
				McpAppsNegotiation::SITE_INFO_URI      => 1,
				McpAppsNegotiation::PATTERN_PICKER_URI => 1,
				McpAppsNegotiation::IMAGE_UPLOAD_URI   => 1,
				McpAppsNegotiation::MEDIA_PICKER_URI   => 2,
				McpAppsNegotiation::CONTENT_PICKER_URI => 3,
			),
			array_count_values( $uris )
		);
		foreach ( $linked_tools as $tool ) {
			self::assertTrue( $tool['annotations']['readOnlyHint'] );
			self::assertSame( array( 'model' ), $tool['_meta']['ui']['visibility'] );
		}
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

	public function test_upload_handoff_tool_is_discoverable_without_apps_and_links_resource_only_when_negotiated(): void {
		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn ( string $capability ): bool => 'upload_files' === $capability;
		$module = ( new FirstPartyAbilityModules() )->all()['media.open_upload_handoff'];
		self::assertNotNull( $module );
		$controller = new McpController();
		$method     = new \ReflectionMethod( McpController::class, 'tool_from_module' );
		$property   = new \ReflectionProperty( McpController::class, 'mcp_apps_ui_enabled' );

		$property->setValue( $controller, false );
		$plain = $method->invoke( $controller, $module );
		self::assertArrayNotHasKey( 'ui', $plain['_meta'] );
		self::assertTrue( $plain['annotations']['readOnlyHint'] );

		$property->setValue( $controller, true );
		$app = $method->invoke( $controller, $module );
		self::assertSame( McpAppsNegotiation::IMAGE_UPLOAD_URI, $app['_meta']['ui']['resourceUri'] );
		self::assertSame( array( 'model' ), $app['_meta']['ui']['visibility'] );

		$tools = ( new McpController() )->tool_manifest_for_user( 1, array( 'content:read' ) )['tools'];
		self::assertContains( 'media_open_upload_handoff', array_column( $tools, 'name' ) );

		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn (): bool => false;
		$blocked_tools = ( new McpController() )->tool_manifest_for_user( 1, array( 'content:read' ) )['tools'];
		self::assertNotContains( 'media_open_upload_handoff', array_column( $blocked_tools, 'name' ) );
	}

	public function test_tools_call_returns_text_and_structured_human_handoff_and_rejects_bytes_or_missing_scope(): void {
		$GLOBALS['aculect_ai_companion_test_capability_callback']                                       = static fn ( string $capability ): bool => 'upload_files' === $capability;
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_apps_enabled'] = static fn (): bool => true;
		$call = static function ( array $arguments, array $scopes ): array {
			$controller = new McpController();
			( new \ReflectionProperty( McpController::class, 'request_auth' ) )->setValue(
				$controller,
				array(
					'user_id'   => 1,
					'client_id' => 'upload-handoff-test',
					'scopes'    => $scopes,
					'profile'   => 'full_access',
				)
			);
			$request  = new WP_REST_Request(
				array(
					'mcp-protocol-version' => McpController::PROTOCOL_VERSION_CURRENT,
					'mcp-method'           => 'tools/call',
				),
				array(),
				array(
					'jsonrpc' => '2.0',
					'id'      => 'upload-handoff-test',
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'media_open_upload_handoff',
						'arguments' => $arguments,
					),
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			);
			$response = $controller->handle_rpc( $request );
			return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
		};

		$success = $call( array(), array( 'content:read' ) );
		self::assertSame( 'https://example.com/wp-admin/admin.php?page=aculect-mcp-image-upload', $success['result']['structuredContent']['url'] ?? '' );
		self::assertStringContainsString( 'attachment ID', $success['result']['content'][0]['text'] ?? '' );
		self::assertArrayNotHasKey( 'error', $success );

		$bytes = $call( array( 'image_bytes' => 'not accepted' ), array( 'content:read' ) );
		self::assertSame( 'unexpected_argument', $bytes['error']['data']['code'] ?? '' );

		$scope = $call( array(), array() );
		self::assertTrue( $scope['result']['isError'] ?? false );
		self::assertStringContainsString( 'scope="content:read"', $scope['result']['_meta']['mcp/www_authenticate'][0] ?? '' );

		$GLOBALS['aculect_ai_companion_test_capability_callback'] = static fn (): bool => false;
		$denied = $call( array(), array( 'content:read' ) );
		self::assertTrue( $denied['result']['isError'] ?? false );
		self::assertStringContainsString( 'not available for the connected WordPress capabilities', $denied['result']['content'][0]['text'] ?? '' );
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
