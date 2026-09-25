<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpAppsNegotiation;
use Aculect\AICompanion\Connectors\MCP\McpController;
use PHPUnit\Framework\TestCase;

final class McpAppsNegotiationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_transients']       = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['aculect_ai_companion_test_transients']       = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
		parent::tearDown();
	}

	public function test_feature_is_off_by_default_even_when_client_advertises_it(): void {
		$request      = $this->initialize_request( true );
		$capabilities = array(
			'tools'     => array( 'listChanged' => false ),
			'resources' => array( 'listChanged' => false ),
		);

		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'initialize', $request, '2025-11-25', $this->auth() ) );
		self::assertSame( $capabilities, McpAppsNegotiation::initialize_capabilities( $capabilities, true ) );
	}

	public function test_initialize_negotiates_and_remembers_supported_legacy_client(): void {
		$this->enable_feature();
		$auth         = $this->auth();
		$capabilities = array(
			'tools'     => array( 'listChanged' => false ),
			'resources' => array( 'listChanged' => false ),
		);

		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'initialize', $this->initialize_request( true ), '2025-11-25', $auth ) );
		self::assertSame(
			array(
				'tools'      => array( 'listChanged' => false ),
				'resources'  => array( 'listChanged' => false ),
				'extensions' => array( McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => array( McpAppsNegotiation::MIME_TYPE ) ) ),
			),
			McpAppsNegotiation::initialize_capabilities( $capabilities, true )
		);
		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'tools/list', array( 'method' => 'tools/list' ), '2025-11-25', $auth ) );
	}

	public function test_initialize_without_supported_mime_type_does_not_enable_ui(): void {
		$this->enable_feature();
		$auth = $this->auth();

		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'initialize', $this->initialize_request( false ), '2025-11-25', $auth ) );
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth ) );
	}

	public function test_malformed_and_partial_client_capabilities_fail_closed(): void {
		$this->enable_feature();
		$auth  = $this->auth();
		$cases = array(
			array(),
			array( 'capabilities' => 'ui' ),
			array( 'capabilities' => array( 'extensions' => 'ui' ) ),
			array( 'capabilities' => array( 'extensions' => array( McpAppsNegotiation::EXTENSION => true ) ) ),
			array( 'capabilities' => array( 'extensions' => array( McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => 'text/html;profile=mcp-app' ) ) ) ),
			array( 'capabilities' => array( 'extensions' => array( McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => array( 'text/html' ) ) ) ) ),
		);

		foreach ( $cases as $params ) {
			self::assertFalse( McpAppsNegotiation::enabled_for_request( 'initialize', array( 'params' => $params ), '2025-11-25', $auth ) );
			self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $auth ) );
		}
	}

	public function test_legacy_capability_does_not_cross_access_token_sessions_for_the_same_client(): void {
		$this->enable_feature();
		$first              = $this->auth();
		$second             = $first;
		$second['token_id'] = 'second-token';

		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'initialize', $this->initialize_request( true ), '2025-11-25', $first ) );
		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $first ) );
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), '2025-11-25', $second ) );
	}

	public function test_stateless_requests_require_the_exact_per_request_capability(): void {
		$this->enable_feature();
		$request = array(
			'params' => array(
				'_meta' => array(
					'io.modelcontextprotocol/clientCapabilities' => array(
						'extensions' => array(
							McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => array( McpAppsNegotiation::MIME_TYPE ) ),
						),
					),
				),
			),
		);

		self::assertTrue( McpAppsNegotiation::enabled_for_request( 'tools/list', $request, McpController::PROTOCOL_VERSION_CURRENT, $this->auth() ) );
		$request['params']['_meta']['io.modelcontextprotocol/clientCapabilities']['extensions'][ McpAppsNegotiation::EXTENSION ]['mimeTypes'] = array( 'text/html' );
		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', $request, McpController::PROTOCOL_VERSION_CURRENT, $this->auth() ) );
	}

	public function test_stateless_requests_do_not_fall_back_to_legacy_cached_support(): void {
		$this->enable_feature();
		$auth = $this->auth();
		McpAppsNegotiation::enabled_for_request( 'initialize', $this->initialize_request( true ), '2025-11-25', $auth );

		self::assertFalse( McpAppsNegotiation::enabled_for_request( 'tools/list', array(), McpController::PROTOCOL_VERSION_CURRENT, $auth ) );
	}

	public function test_discovery_only_advertises_extension_when_opted_in(): void {
		$disabled = McpAppsNegotiation::discovery_payload( array( '2026-07-28' ), 'Instructions.' );
		self::assertArrayNotHasKey( 'extensions', $disabled['capabilities'] );

		$this->enable_feature();
		$enabled = McpAppsNegotiation::discovery_payload( array( '2026-07-28' ), 'Instructions.' );
		self::assertArrayHasKey( McpAppsNegotiation::EXTENSION, $enabled['capabilities']['extensions'] );
	}

	public function test_only_site_info_tool_gets_read_only_app_metadata(): void {
		$this->enable_feature();
		$metadata = McpAppsNegotiation::tool_metadata( 'site.get_info', array(), 'Reading…', 'Read.', true );

		self::assertSame( McpAppsNegotiation::SITE_INFO_URI, $metadata['ui']['resourceUri'] );
		self::assertSame( array( 'model' ), $metadata['ui']['visibility'] );
		$non_ui_metadata = McpAppsNegotiation::tool_metadata( 'site.get_settings', array( array( 'type' => 'oauth2' ) ), 'Reading…', 'Read.', true );
		self::assertArrayNotHasKey( 'ui', $non_ui_metadata );
		self::assertSame( array( array( 'type' => 'oauth2' ) ), $non_ui_metadata['securitySchemes'] );
		self::assertArrayNotHasKey( 'ui', McpAppsNegotiation::tool_metadata( 'site.get_info', array(), 'Reading…', 'Read.', false ) );
	}

	/**
	 * Configure the site-level feature opt-in used by the test.
	 */
	private function enable_feature(): void {
		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect_ai_companion_mcp_apps_enabled'] = static fn (): bool => true;
	}

	/**
	 * Build a legacy MCP initialize request with the optional UI extension.
	 *
	 * @param bool $supported Whether to advertise the UI MIME type.
	 * @return array<string, mixed>
	 */
	private function initialize_request( bool $supported ): array {
		return array(
			'params' => array(
				'capabilities' => array(
					'extensions' => $supported
						? array( McpAppsNegotiation::EXTENSION => array( 'mimeTypes' => array( McpAppsNegotiation::MIME_TYPE ) ) )
						: array(),
				),
			),
		);
	}

	/**
	 * Return a test OAuth client context.
	 *
	 * @return array<string, mixed>
	 */
	private function auth(): array {
		return array(
			'client_id' => 'test-mcp-apps-client',
			'token_id'  => 'first-token',
		);
	}
}
