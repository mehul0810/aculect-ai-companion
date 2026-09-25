<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\FirstPartyAbilityModules;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\McpController;
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

	public function test_only_site_info_is_linked_across_every_paginated_tool_list_page(): void {
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
		self::assertCount( 1, $linked_tools );
		self::assertSame( McpAppsNegotiation::SITE_INFO_URI, $linked_tools[0]['_meta']['ui']['resourceUri'] );
		self::assertTrue( $linked_tools[0]['annotations']['readOnlyHint'] );
	}
}
