<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpProtocolVersion;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WP_REST_Request;

require_once dirname( __DIR__, 3 ) . '/fixtures/mcp-request-stubs.php';

/**
 * Verifies Skills extension negotiation and RPC wire results.
 */
final class McpSkillsControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_options']         = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_transients']      = array();
		$GLOBALS['aculect_ai_companion_test_users']           = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
		);
	}

	public function test_current_discovery_declares_skills_and_legacy_discovery_does_not(): void {
		$current = $this->dispatch( 'server/discover', array(), McpProtocolVersion::CURRENT );
		$legacy  = $this->dispatch( 'server/discover', array(), McpProtocolVersion::LEGACY );

		self::assertArrayHasKey( 'io.modelcontextprotocol/skills', $current['result']['capabilities']['extensions'] );
		self::assertInstanceOf( \stdClass::class, $current['result']['capabilities']['extensions']['io.modelcontextprotocol/skills'] );
		self::assertArrayHasKey( 'resources', $current['result']['capabilities'] );
		$legacy_extensions = $legacy['result']['capabilities']['extensions'] ?? array();
		self::assertArrayNotHasKey( 'io.modelcontextprotocol/skills', $legacy_extensions );
	}

	public function test_list_and_get_have_required_private_cache_envelope_and_get_unknown_is_invalid_params(): void {
		$list = $this->dispatch( 'skills/list', array(), McpProtocolVersion::CURRENT );

		self::assertSame( 'complete', $list['result']['resultType'] );
		self::assertSame( 0, $list['result']['ttlMs'] );
		self::assertSame( 'private', $list['result']['cacheScope'] );
		self::assertNotEmpty( $list['result']['skills'] );

		$uri = $list['result']['skills'][0]['uri'];
		$get = $this->dispatch( 'skills/get', array( 'uri' => $uri ), McpProtocolVersion::CURRENT );
		self::assertSame( 'complete', $get['result']['resultType'] );
		self::assertSame( 0, $get['result']['ttlMs'] );
		self::assertSame( 'private', $get['result']['cacheScope'] );
		self::assertSame( $uri, $get['result']['skill']['uri'] );

		$unknown = $this->dispatch( 'skills/get', array( 'uri' => 'skill://unknown/SKILL.md' ), McpProtocolVersion::CURRENT );
		self::assertSame( -32602, $unknown['error']['code'] );
	}

	public function test_resource_list_and_read_are_scoped_to_current_connection_and_protocol(): void {
		$list = $this->dispatch( 'resources/list', array(), McpProtocolVersion::CURRENT );
		$uris = array_column( $list['result']['resources'], 'uri' );
		self::assertNotEmpty( array_filter( $uris, static fn ( string $uri ): bool => str_starts_with( $uri, 'skill://' ) ) );

		$uri  = (string) current( array_filter( $uris, static fn ( string $uri ): bool => str_starts_with( $uri, 'skill://' ) ) );
		$read = $this->dispatch( 'resources/read', array( 'uri' => $uri ), McpProtocolVersion::CURRENT );
		self::assertSame( 'text/markdown', $read['result']['contents'][0]['mimeType'] );
		self::assertStringContainsString( 'name:', $read['result']['contents'][0]['text'] );
		self::assertSame( 'complete', $read['result']['resultType'] );
		self::assertSame( 0, $read['result']['ttlMs'] );
		self::assertSame( 'private', $read['result']['cacheScope'] );

		$unknown_read = $this->dispatch( 'resources/read', array( 'uri' => 'skill://unknown/SKILL.md' ), McpProtocolVersion::CURRENT );
		self::assertSame( -32602, $unknown_read['error']['code'] );

		$legacy      = $this->dispatch( 'resources/list', array(), McpProtocolVersion::LEGACY );
		$legacy_uris = array_column( $legacy['result']['resources'], 'uri' );
		self::assertSame( array(), array_values( array_filter( $legacy_uris, static fn ( string $item ): bool => str_starts_with( $item, 'skill://' ) ) ) );
	}

	public function test_malformed_cursor_uri_and_mirrored_skill_uri_are_rejected(): void {
		$cursor = $this->dispatch( 'skills/list', array( 'cursor' => array( 'bad' ) ), McpProtocolVersion::CURRENT );
		self::assertSame( -32602, $cursor['error']['code'] );
		$legacy_skills = $this->dispatch( 'skills/list', array(), McpProtocolVersion::LEGACY );
		self::assertSame( -32601, $legacy_skills['error']['code'] );

		$uri = $this->dispatch( 'skills/get', array( 'uri' => array( 'bad' ) ), McpProtocolVersion::CURRENT );
		self::assertInstanceOf( \WP_REST_Response::class, $uri );
		self::assertSame( 400, $uri->get_status() );

		$wrong_header = $this->dispatch(
			'skills/get',
			array( 'uri' => 'skill://wordpress-site-audit/SKILL.md' ),
			McpProtocolVersion::CURRENT,
			'aculect-name-mismatch'
		);
		self::assertSame( 400, $wrong_header->get_status() );
	}

	/**
	 * Dispatch one authenticated request through the MCP controller.
	 *
	 * @param string       $method       JSON-RPC method.
	 * @param array<mixed> $params       JSON-RPC params.
	 * @param string       $version      Protocol revision.
	 * @param string|null  $header_name  Optional mirrored name override.
	 * @return array<string, mixed>|\WP_REST_Response
	 */
	private function dispatch( string $method, array $params, string $version, ?string $header_name = null ): array|\WP_REST_Response {
		$params['_meta'] = array(
			'io.modelcontextprotocol/protocolVersion'    => $version,
			'io.modelcontextprotocol/clientCapabilities' => array(),
			'io.modelcontextprotocol/clientInfo'         => array(
				'name'    => 'Skills test client',
				'version' => '1.0.0',
			),
		);
		$headers         = array(
			'mcp-protocol-version' => $version,
			'mcp-method'           => $method,
		);
		if ( in_array( $method, array( 'resources/read', 'skills/get' ), true ) ) {
			$uri                 = $params['uri'] ?? '';
			$headers['mcp-name'] = $header_name ?? ( is_string( $uri ) ? $uri : '' );
		}

		$controller = new McpController();
		$property   = new ReflectionProperty( $controller, 'request_auth' );
		$property->setValue(
			$controller,
			array(
				'user_id' => 1,
				'scopes'  => array( 'content:read', 'content:draft' ),
				'profile' => 'standard',
			)
		);

		return $controller->handle_rpc(
			new WP_REST_Request(
				array(),
				$headers,
				array(
					'jsonrpc' => '2.0',
					'id'      => 4,
					'method'  => $method,
					'params'  => $params,
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			)
		);
	}
}
