<?php
/**
 * Tests for MCP protocol responses that do not require a WordPress runtime.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use PHPUnit\Framework\TestCase;
use Aculect\AICompanion\Connectors\Helpers;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AbilityExecutionGateway;
use Aculect\AICompanion\Connectors\MCP\AccessLockdown;
use Aculect\AICompanion\Connectors\MCP\IntelligenceContext;
use Aculect\AICompanion\Connectors\MCP\IntelligenceRegistry;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpInputValidator;
use Aculect\AICompanion\Connectors\MCP\McpProtocolVersion;
use Aculect\AICompanion\Connectors\MCP\ToolSafety;
use Aculect\AICompanion\Connectors\MCP\UserAccessControl;
use Aculect\AICompanion\Connectors\MCP\ExecutionClaims\WordPressExecutionClaimStore;
use Aculect\AICompanion\Connectors\OAuth\ConnectionAccessLevel;
use Aculect\AICompanion\Tests\Support\InMemoryExecutionClaimStore;
use ReflectionMethod;
use ReflectionProperty;
use WP_REST_Request;

require_once dirname( __DIR__, 3 ) . '/fixtures/mcp-request-stubs.php';

/**
 * Verifies public MCP tool payloads remain compatible with assistant clients.
 */
final class McpControllerTest extends TestCase {

	public function test_default_controller_composes_the_production_execution_claim_store(): void {
		$controller = new McpController();
		$gateway    = $this->privatePropertyValue( $controller, 'execution_gateway' );
		self::assertInstanceOf( AbilityExecutionGateway::class, $gateway );

		$safety = $this->privatePropertyValue( $gateway, 'safety' );
		self::assertInstanceOf( ToolSafety::class, $safety );
		self::assertInstanceOf( WordPressExecutionClaimStore::class, $this->privatePropertyValue( $safety, 'claim_store' ) );
	}

	public function test_controller_protocol_constants_share_the_policy_authority(): void {
		self::assertSame( McpProtocolVersion::CURRENT, McpController::PROTOCOL_VERSION_CURRENT );
		self::assertSame( McpProtocolVersion::LEGACY, McpController::PROTOCOL_VERSION_LEGACY );
		self::assertSame(
			array( McpProtocolVersion::CURRENT, McpProtocolVersion::LEGACY ),
			McpController::SUPPORTED_PROTOCOL_VERSIONS
		);
	}

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_transients']       = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']      = array();
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']  = 1;
		$GLOBALS['aculect_ai_companion_test_users']            = array(
			1 => (object) array(
				'ID'           => 1,
				'roles'        => array( 'administrator' ),
				'display_name' => 'Ada Admin',
				'user_login'   => 'ada',
			),
			2 => (object) array(
				'ID'           => 2,
				'roles'        => array( 'subscriber' ),
				'display_name' => 'Sam Subscriber',
				'user_login'   => 'sam',
			),
			3 => (object) array(
				'ID'           => 3,
				'roles'        => array( 'editor' ),
				'display_name' => 'Ed Editor',
				'user_login'   => 'ed',
			),
		);
	}

	public function test_tools_list_exposes_safe_public_tool_names(): void {
		$result = $this->list_tools_manifest();

		self::assertIsArray( $result );
		self::assertArrayHasKey( 'tools', $result );
		self::assertIsArray( $result['tools'] );
		self::assertNotEmpty( $result['tools'] );

		$registry     = new AbilitiesRegistry();
		$intelligence = new IntelligenceRegistry();

		foreach ( $result['tools'] as $tool ) {
			self::assertIsArray( $tool );
			self::assertArrayHasKey( 'name', $tool );
			self::assertIsString( $tool['name'] );
			self::assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/', $tool['name'] );
			self::assertTrue( $registry->is_known( $tool['name'] ) || $intelligence->is_known( $tool['name'] ) );
		}

		$tools_by_name = array_column( $result['tools'], null, 'name' );
		self::assertFalse( $tools_by_name['intelligence_feedback_submit']['annotations']['readOnlyHint'] );
		self::assertFalse( $tools_by_name['plugin_incident_report']['annotations']['readOnlyHint'] );
		self::assertFalse( $tools_by_name['plugin_incident_report']['annotations']['openWorldHint'] );
		self::assertTrue( $tools_by_name['plugin_incident_list']['annotations']['readOnlyHint'] );
		self::assertArrayHasKey( 'dry_run', $tools_by_name['plugin_incident_report']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'confirmation_token', $tools_by_name['plugin_incident_report']['inputSchema']['properties'] );
	}

	public function test_handle_rpc_rejects_oversized_body_before_json_dispatch(): void {
		$response = ( new McpController() )->handle_rpc(
			new WP_REST_Request(
				array(),
				array( 'content-length' => '16000001' ),
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/list',
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			)
		);

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 413, $response->get_status() );
		self::assertSame( 'request_body_too_large', $response->get_data()['error']['data']['code'] ?? '' );
	}

	public function test_permission_check_rejects_oversized_auth_exempt_notification(): void {
		$request = new WP_REST_Request(
			array(),
			array( 'content-length' => '16000001' ),
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);

		$result = ( new McpController() )->check_mcp_permission( $request );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'request_body_too_large', $result->get_error_code() );
	}

	public function test_notification_requires_oauth_authentication(): void {
		$request = new WP_REST_Request(
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);

		$result = ( new McpController() )->check_mcp_permission( $request );

		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'rest_unauthorized', $result->get_error_code() );
	}

	public function test_initialized_notification_is_legacy_only(): void {
		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'user_id' => 1,
				'scopes'  => array( 'content:read' ),
			)
		);
		$legacy          = new WP_REST_Request(
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'method'  => 'notifications/initialized',
			),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);
		$legacy_response = $controller->handle_rpc( $legacy );
		self::assertInstanceOf( \WP_REST_Response::class, $legacy_response );
		self::assertSame( 202, $legacy_response->get_status() );

		$current          = $this->currentProtocolRequest( 'notifications/initialized', array() );
		$current_response = $controller->handle_rpc( $current );
		self::assertInstanceOf( \WP_REST_Response::class, $current_response );
		self::assertSame( 404, $current_response->get_status() );
		self::assertSame( -32601, $current_response->get_data()['error']['code'] ?? null );
	}

	public function test_origin_validation_uses_exact_origin_and_configured_proxy_origins(): void {
		$controller = new McpController();
		$canonical  = Helpers::origin_from_url( Helpers::mcp_resource() );

		self::assertNull( $this->transportError( $controller, array( 'origin' => $canonical ) ) );
		self::assertSame( 'invalid_mcp_origin', $this->transportError( $controller, array( 'origin' => 'https://foreign.example' ) )['code'] ?? '' );
		self::assertSame( 'invalid_mcp_origin', $this->transportError( $controller, array( 'origin' => 'null' ) )['code'] ?? '' );
		self::assertSame( 'invalid_mcp_origin', $this->transportError( $controller, array( 'origin' => str_replace( 'https://', 'http://', $canonical ) ) )['code'] ?? '' );

		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect-ai-companion/connectors/allowed_mcp_origins'] = static function ( array $origins ): array {
			$origins[] = 'https://approved.example:8443';
			return $origins;
		};

		self::assertNull( $this->transportError( new McpController(), array( 'origin' => 'https://approved.example:8443' ) ) );
		self::assertSame( 'invalid_mcp_origin', $this->transportError( new McpController(), array( 'origin' => 'https://approved.example' ) )['code'] ?? '' );

		$GLOBALS['aculect_ai_companion_test_filter_callbacks']['aculect-ai-companion/connectors/external_url'] = static fn(): string => 'https://proxy.example.test/connectors';
		self::assertNull( $this->transportError( new McpController(), array( 'origin' => 'https://proxy.example.test' ) ) );
		self::assertSame( 'invalid_mcp_origin', $this->transportError( new McpController(), array( 'origin' => 'https://example.com' ) )['code'] ?? '' );
	}

	public function test_current_stateless_discovery_requires_headers_and_request_metadata(): void {
		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'user_id' => 1,
				'scopes'  => array( 'content:read' ),
			)
		);
		$request = $this->currentProtocolRequest( 'server/discover', array() );

		$response = $controller->handle_rpc( $request );

		self::assertIsArray( $response );
		self::assertSame( McpController::SUPPORTED_PROTOCOL_VERSIONS, $response['result']['supportedVersions'] ?? array() );
		self::assertSame( 'complete', $response['result']['resultType'] ?? '' );
		self::assertSame( 'public', $response['result']['cacheScope'] ?? '' );
		self::assertSame( 3600000, $response['result']['ttlMs'] ?? 0 );
		self::assertSame( 'Aculect AI Companion MCP', $response['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? '' );

		$initialize = $controller->handle_rpc(
			$this->currentProtocolRequest(
				'initialize',
				array( 'protocolVersion' => McpController::PROTOCOL_VERSION_CURRENT )
			)
		);
		self::assertInstanceOf( \WP_REST_Response::class, $initialize );
		self::assertSame( 404, $initialize->get_status() );
		self::assertSame( -32601, $initialize->get_data()['error']['code'] ?? null );
		self::assertSame( 'Aculect AI Companion MCP', $initialize->get_data()['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? '' );
	}

	public function test_current_protocol_rejects_header_metadata_and_tool_name_mismatches(): void {
		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'user_id' => 1,
				'scopes'  => array( 'content:read' ),
			)
		);
		$missing_version = new WP_REST_Request(
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2026,
				'method'  => 'server/discover',
				'params'  => array(),
			),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);
		$response        = $controller->handle_rpc( $missing_version );
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 'missing_protocol_version', $response->get_data()['error']['data']['code'] ?? '' );

		$bad_meta = $this->currentProtocolRequest(
			'tools/list',
			array(),
			array(),
			McpController::PROTOCOL_VERSION_LEGACY
		);
		$response = $controller->handle_rpc( $bad_meta );
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( -32020, $response->get_data()['error']['code'] ?? null );
		self::assertSame( 'invalid_mcp_request_metadata', $response->get_data()['error']['data']['code'] ?? '' );

		$bad_name = $this->currentProtocolRequest(
			'tools/call',
			array(
				'name'      => 'search',
				'arguments' => array(),
			),
			array( 'mcp-name' => 'fetch' )
		);
		$response = $controller->handle_rpc( $bad_name );
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( -32020, $response->get_data()['error']['code'] ?? null );
		self::assertSame( 'invalid_mcp_name_header', $response->get_data()['error']['data']['code'] ?? '' );

		$malformed_capabilities = new WP_REST_Request(
			array(),
			array(
				'mcp-protocol-version' => McpController::PROTOCOL_VERSION_CURRENT,
				'mcp-method'           => 'tools/list',
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2026,
				'method'  => 'tools/list',
				'params'  => array(
					'_meta' => array(
						'io.modelcontextprotocol/protocolVersion'    => McpController::PROTOCOL_VERSION_CURRENT,
						'io.modelcontextprotocol/clientCapabilities' => array( 'not-an-object' ),
					),
				),
			),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);
		$response               = $controller->handle_rpc( $malformed_capabilities );
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 'invalid_mcp_request_metadata', $response->get_data()['error']['data']['code'] ?? '' );

		$resource_uri = 'https://example.com/wp-json/wp/v2/posts/10?context=edit';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Exercises the MCP mirrored-header sentinel contract.
		$encoded_name = '=?base64?' . base64_encode( $resource_uri ) . '?=';
		self::assertNull(
			$this->invokePrivate(
				new McpController(),
				'transport_error',
				array(
					$this->currentProtocolRequest(
						'resources/read',
						array( 'uri' => $resource_uri ),
						array( 'mcp-name' => $encoded_name )
					),
				)
			)
		);
	}

	public function test_current_protocol_requires_exact_mirrored_headers_and_accepts_canonical_base64(): void {
		$controller = new McpController();
		foreach (
			array(
				array(
					'mcp-protocol-version' => ' ' . McpController::PROTOCOL_VERSION_CURRENT . ' ',
					'invalid_mcp_protocol_version_header',
				),
				array(
					'mcp-method' => ' tools/list ',
					'invalid_mcp_method_header',
				),
			) as $case
		) {
			$expected = array_pop( $case );
			$error    = $this->transportErrorForRequest( $controller, $this->currentProtocolRequest( 'tools/list', array(), $case ) );
			self::assertSame( $expected, $error['code'] ?? '' );
		}

		$name_request = $this->currentProtocolRequest(
			'tools/call',
			array(
				'name'      => 'search',
				'arguments' => array(),
			),
			array( 'mcp-name' => ' search ' )
		);
		self::assertSame( 'invalid_mcp_name_header', $this->transportErrorForRequest( $controller, $name_request )['code'] ?? '' );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Exercises the mirrored-header sentinel contract.
		$version = '=?base64?' . base64_encode( McpController::PROTOCOL_VERSION_CURRENT ) . '?=';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Exercises the mirrored-header sentinel contract.
		$method = '=?base64?' . base64_encode( 'tools/list' ) . '?=';
		self::assertNull(
			$this->transportErrorForRequest(
				$controller,
				$this->currentProtocolRequest(
					'tools/list',
					array(),
					array(
						'mcp-protocol-version' => $version,
						'mcp-method'           => $method,
					)
				)
			)
		);

		$malformed = $this->currentProtocolRequest( 'tools/list', array(), array( 'mcp-method' => '=?base64?YQ?=' ) );
		self::assertSame( 'invalid_mcp_method_header', $this->transportErrorForRequest( $controller, $malformed )['code'] ?? '' );

		$coalesced = $this->currentProtocolRequest( 'tools/list', array(), array( 'mcp-method' => 'tools/list, tools/list' ) );
		self::assertSame( 'invalid_mcp_method_header', $this->transportErrorForRequest( $controller, $coalesced )['code'] ?? '' );

		$oversized = $this->currentProtocolRequest( 'tools/list', array(), array( 'mcp-method' => str_repeat( 'a', 4097 ) ) );
		self::assertSame( 'invalid_mcp_method_header', $this->transportErrorForRequest( $controller, $oversized )['code'] ?? '' );

		$unsafe_uri = "aculect://line\nbreak/✓";
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Exercises encoded UTF-8/control header data.
		$encoded_uri = '=?base64?' . base64_encode( $unsafe_uri ) . '?=';
		self::assertNull(
			$this->transportErrorForRequest(
				$controller,
				$this->currentProtocolRequest( 'resources/read', array( 'uri' => $unsafe_uri ), array( 'mcp-name' => $encoded_uri ) )
			)
		);

		$sentinel_name = '=?base64?YQ==?=';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The sentinel-looking body value must itself be encoded.
		$double_encoded = '=?base64?' . base64_encode( $sentinel_name ) . '?=';
		self::assertNull(
			$this->transportErrorForRequest(
				$controller,
				$this->currentProtocolRequest(
					'tools/call',
					array(
						'name'      => $sentinel_name,
						'arguments' => array(),
					),
					array( 'mcp-name' => $double_encoded )
				)
			)
		);
	}

	public function test_current_results_use_method_specific_cache_and_identity_metadata(): void {
		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'user_id'   => 1,
				'client_id' => 'current-result-client',
				'scopes'    => Helpers::supported_scopes(),
				'profile'   => 'full_access',
			)
		);

		$tools = $controller->handle_rpc( $this->currentProtocolRequest( 'tools/list', array() ) );
		self::assertIsArray( $tools );
		self::assertSame( 'private', $tools['result']['cacheScope'] ?? '' );
		self::assertSame( 0, $tools['result']['ttlMs'] ?? null );
		self::assertSame( 'Aculect AI Companion MCP', $tools['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? '' );

		$resources = $controller->handle_rpc( $this->currentProtocolRequest( 'resources/list', array() ) );
		self::assertIsArray( $resources );
		self::assertSame( 'public', $resources['result']['cacheScope'] ?? '' );
		self::assertSame( 3600000, $resources['result']['ttlMs'] ?? null );

		$read = $controller->handle_rpc(
			$this->currentProtocolRequest( 'resources/read', array( 'uri' => 'aculect://content/model' ) )
		);
		self::assertIsArray( $read );
		self::assertSame( 'private', $read['result']['cacheScope'] ?? '' );
		self::assertSame( 0, $read['result']['ttlMs'] ?? null );
	}

	public function test_current_schema_integration_fails_closed_while_legacy_remains_exact(): void {
		$controller = new McpController();
		$schema     = array(
			'type'       => 'object',
			'properties' => array( 'value' => array( 'type' => 'string' ) ),
		);

		$legacy = $this->invokePrivate( $controller, 'schema_for_protocol', array( $schema ) );
		self::assertSame( $schema, $legacy );

		$this->setPrivateProperty( $controller, 'request_protocol_version', McpController::PROTOCOL_VERSION_CURRENT );
		$current = $this->invokePrivate( $controller, 'schema_for_protocol', array( $schema ) );
		self::assertSame( '{"properties":{"value":{"type":"string"}},"type":"object"}', wp_json_encode( $current ) );

		$this->expectException( \UnexpectedValueException::class );
		$this->invokePrivate(
			$controller,
			'schema_for_protocol',
			array( array( '$ref' => 'https://example.com/external-schema.json' ) )
		);
	}

	public function test_current_unknown_tool_and_resource_use_json_rpc_invalid_params(): void {
		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'user_id'   => 1,
				'client_id' => 'current-error-client',
				'scopes'    => Helpers::supported_scopes(),
				'profile'   => 'full_access',
			)
		);

		$tool = $controller->handle_rpc(
			$this->currentProtocolRequest(
				'tools/call',
				array(
					'name'      => 'not_a_real_tool',
					'arguments' => array(),
				)
			)
		);
		self::assertIsArray( $tool );
		self::assertSame( -32602, $tool['error']['code'] ?? null );
		self::assertSame( 'unknown_tool', $tool['error']['data']['code'] ?? '' );
		self::assertArrayNotHasKey( 'result', $tool );

		$resource = $controller->handle_rpc(
			$this->currentProtocolRequest( 'resources/read', array( 'uri' => 'aculect://missing' ) )
		);
		self::assertIsArray( $resource );
		self::assertSame( -32602, $resource['error']['code'] ?? null );
		self::assertSame( 'resource_not_found', $resource['error']['data']['code'] ?? '' );
		self::assertArrayNotHasKey( 'result', $resource );
		self::assertSame( 'Aculect AI Companion MCP', $resource['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? '' );
	}

	public function test_tools_call_gateway_errors_have_legacy_and_current_response_goldens(): void {
		$auth = array(
			'user_id'   => 1,
			'client_id' => 'gateway-error-golden-client',
			'provider'  => 'chatgpt',
			'scopes'    => Helpers::supported_scopes(),
			'profile'   => 'full_access',
		);

		$legacy = new McpController();
		$this->setPrivateProperty( $legacy, 'request_auth', $auth );
		$legacy_unknown   = $legacy->handle_rpc(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'jsonrpc' => '2.0',
					'id'      => 803,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'not_a_real_tool',
						'arguments' => array(),
					),
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			)
		);
		$legacy_malformed = $legacy->handle_rpc(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'jsonrpc' => '2.0',
					'id'      => 804,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'plugin_incident_list',
						'arguments' => 'not-an-object',
					),
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			)
		);

		$current = new McpController();
		$this->setPrivateProperty( $current, 'request_auth', $auth );
		$current_unknown   = $current->handle_rpc(
			$this->currentProtocolRequest(
				'tools/call',
				array(
					'name'      => 'not_a_real_tool',
					'arguments' => array(),
				)
			)
		);
		$current_malformed = $current->handle_rpc(
			$this->currentProtocolRequest(
				'tools/call',
				array(
					'name'      => 'plugin_incident_list',
					'arguments' => 'not-an-object',
				)
			)
		);

		self::assertIsArray( $legacy_unknown );
		self::assertSame( 803, $legacy_unknown['id'] ?? null );
		self::assertTrue( $legacy_unknown['result']['isError'] ?? false );
		self::assertSame( 'Unknown tool.', $legacy_unknown['result']['content'][0]['text'] ?? '' );
		self::assertArrayNotHasKey( 'error', $legacy_unknown );
		self::assertArrayNotHasKey( 'io.modelcontextprotocol/serverInfo', $legacy_unknown['result']['_meta'] ?? array() );

		self::assertIsArray( $current_unknown );
		self::assertSame( -32602, $current_unknown['error']['code'] ?? null );
		self::assertSame( 'Invalid params', $current_unknown['error']['message'] ?? '' );
		self::assertSame( 'unknown_tool', $current_unknown['error']['data']['code'] ?? '' );
		self::assertArrayNotHasKey( 'result', $current_unknown );
		self::assertSame( 'Aculect AI Companion MCP', $current_unknown['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? '' );

		foreach ( array( $legacy_malformed, $current_malformed ) as $malformed ) {
			self::assertIsArray( $malformed );
			self::assertSame( -32602, $malformed['error']['code'] ?? null );
			self::assertSame( 'Invalid params', $malformed['error']['message'] ?? '' );
			self::assertSame( 'invalid_argument_type', $malformed['error']['data']['code'] ?? '' );
			self::assertSame( 'Tool arguments must be a JSON object.', $malformed['error']['data']['message'] ?? '' );
			self::assertArrayNotHasKey( 'result', $malformed );
		}

		self::assertArrayNotHasKey( 'io.modelcontextprotocol/serverInfo', $legacy_malformed['_meta'] ?? array() );
		self::assertSame( 'Aculect AI Companion MCP', $current_malformed['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? '' );
	}

	public function test_legacy_results_remain_free_of_current_protocol_metadata(): void {
		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'user_id' => 1,
				'scopes'  => Helpers::supported_scopes(),
			)
		);
		$request  = new WP_REST_Request(
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => 'resources/list',
				'params'  => array(),
			),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);
		$response = $controller->handle_rpc( $request );

		self::assertIsArray( $response );
		self::assertArrayNotHasKey( 'resultType', $response['result'] );
		self::assertArrayNotHasKey( 'ttlMs', $response['result'] );
		self::assertArrayNotHasKey( 'cacheScope', $response['result'] );
		self::assertArrayNotHasKey( '_meta', $response['result'] );
	}

	public function test_reused_controller_resets_protocol_state_between_requests(): void {
		$controller = new McpController();
		self::assertNull( $this->transportErrorForRequest( $controller, $this->currentProtocolRequest( 'tools/list', array() ) ) );

		$legacy = new WP_REST_Request( array(), array(), array( 'method' => 'tools/list' ), 'POST', '/aculect-ai-companion/v1/mcp' );
		self::assertNull( $this->transportErrorForRequest( $controller, $legacy ) );
		self::assertSame( McpController::PROTOCOL_VERSION_LEGACY, $this->privateProperty( $controller, 'request_protocol_version' ) );
	}

	public function test_early_body_rejections_cannot_inherit_or_lose_request_protocol_state(): void {
		$controller = new McpController();
		self::assertNull( $this->transportErrorForRequest( $controller, $this->currentProtocolRequest( 'tools/list', array() ) ) );

		$legacy_oversized = new WP_REST_Request(
			array(),
			array( 'content-length' => '16000001' ),
			array( 'method' => 'tools/list' ),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);
		$legacy_response  = $controller->handle_rpc( $legacy_oversized );
		self::assertInstanceOf( \WP_REST_Response::class, $legacy_response );
		self::assertArrayNotHasKey( '_meta', $legacy_response->get_data() );
		self::assertSame( McpController::PROTOCOL_VERSION_LEGACY, $this->privateProperty( $controller, 'request_protocol_version' ) );

		$current_oversized = new WP_REST_Request(
			array(),
			array(
				'content-length'       => '16000001',
				'mcp-protocol-version' => McpController::PROTOCOL_VERSION_CURRENT,
			),
			array( 'method' => 'tools/list' ),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);
		$current_response  = $controller->handle_rpc( $current_oversized );
		self::assertInstanceOf( \WP_REST_Response::class, $current_response );
		self::assertSame( 'Aculect AI Companion MCP', $current_response->get_data()['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? '' );
		self::assertSame( McpController::PROTOCOL_VERSION_CURRENT, $this->privateProperty( $controller, 'request_protocol_version' ) );

		$permission = $controller->check_mcp_permission( $legacy_oversized );
		self::assertInstanceOf( \WP_Error::class, $permission );
		self::assertSame( McpController::PROTOCOL_VERSION_LEGACY, $this->privateProperty( $controller, 'request_protocol_version' ) );
		$filtered = $controller->filter_mcp_auth_response(
			new \WP_REST_Response( array( 'code' => 'request_body_too_large' ), 413 ),
			null,
			$legacy_oversized
		);
		self::assertInstanceOf( \WP_REST_Response::class, $filtered );
		self::assertSame( McpController::PROTOCOL_VERSION_LEGACY, $filtered->header( 'MCP-Protocol-Version' ) );
	}

	public function test_current_get_response_uses_the_requested_protocol_header(): void {
		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'user_id' => 1,
				'scopes'  => array( 'content:read' ),
			)
		);
		$request = new WP_REST_Request(
			array(),
			array( 'mcp-protocol-version' => McpController::PROTOCOL_VERSION_CURRENT ),
			array(),
			'GET',
			'/aculect-ai-companion/v1/mcp'
		);
		self::assertNull( $this->transportErrorForRequest( $controller, $request ) );

		$response = $controller->describe( $request );
		$filtered = $controller->filter_mcp_auth_response( $response, null, $request );
		self::assertInstanceOf( \WP_REST_Response::class, $filtered );
		self::assertSame( 405, $filtered->get_status() );
		self::assertSame( McpController::PROTOCOL_VERSION_CURRENT, $filtered->header( 'MCP-Protocol-Version' ) );
	}

	public function test_unsupported_version_error_data_remains_valid_utf8(): void {
		$controller  = new McpController();
		$unsupported = str_repeat( 'a', 63 ) . '😀';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Exercises a multibyte boundary in the mirrored-header contract.
		$header  = '=?base64?' . base64_encode( $unsupported ) . '?=';
		$request = new WP_REST_Request(
			array(),
			array( 'mcp-protocol-version' => $header ),
			array( 'method' => 'tools/list' ),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);
		$error   = $this->transportErrorForRequest( $controller, $request );
		self::assertSame( 'unsupported_protocol_version', $error['code'] ?? '' );

		$data = $this->invokePrivate( $controller, 'transport_rpc_data', array( 'unsupported_protocol_version', $request ) );
		self::assertSame( '', $data['requested'] ?? null );
		self::assertIsString( wp_json_encode( $data ) );
	}

	public function test_legacy_initialize_negotiates_supported_version_and_rejects_unknown_version(): void {
		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'user_id' => 1,
				'scopes'  => array( 'content:read' ),
			)
		);
		$response = $controller->handle_rpc(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array( 'protocolVersion' => McpController::PROTOCOL_VERSION_LEGACY ),
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			)
		);

		self::assertIsArray( $response );
		self::assertSame( McpController::PROTOCOL_VERSION_LEGACY, $response['result']['protocolVersion'] ?? '' );

		$unknown  = new WP_REST_Request(
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'initialize',
				'params'  => array( 'protocolVersion' => '2099-01-01' ),
			),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);
		$response = $controller->handle_rpc( $unknown );
		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 400, $response->get_status() );
		self::assertSame( -32022, $response->get_data()['error']['code'] ?? null );
		self::assertSame( 'unsupported_protocol_version', $response->get_data()['error']['data']['code'] ?? '' );
		self::assertSame( '2099-01-01', $response->get_data()['error']['data']['requested'] ?? '' );
		self::assertSame( array( McpController::PROTOCOL_VERSION_LEGACY ), $response->get_data()['error']['data']['supported'] ?? array() );
	}

	public function test_current_unknown_method_returns_json_rpc_404(): void {
		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'user_id' => 1,
				'scopes'  => array( 'content:read' ),
			)
		);

		$response = $controller->handle_rpc( $this->currentProtocolRequest( 'unknown/method', array() ) );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 404, $response->get_status() );
		self::assertSame( -32601, $response->get_data()['error']['code'] ?? null );
	}

	public function test_stateless_get_returns_method_not_allowed(): void {
		$controller = new McpController();
		$this->setPrivateProperty( $controller, 'request_auth', array( 'user_id' => 1 ) );

		$response = $controller->describe( new WP_REST_Request() );

		self::assertInstanceOf( \WP_REST_Response::class, $response );
		self::assertSame( 405, $response->get_status() );
	}

	public function test_tool_call_rejects_oversized_schema_argument_before_execution(): void {
		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'user_id'   => 1,
				'client_id' => 'bounded-input-client',
				'provider'  => 'chatgpt',
				'scopes'    => array( 'content:read', 'content:draft' ),
				'profile'   => 'full_access',
			)
		);

		$response = $controller->handle_rpc(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'content_create_item',
						'arguments' => array(
							'title'   => 'Bounded content',
							'content' => str_repeat( 'x', 300001 ),
						),
					),
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			)
		);

		self::assertIsArray( $response );
		self::assertSame( -32602, $response['error']['code'] ?? null );
		self::assertSame( 'argument_string_too_large', $response['error']['data']['code'] ?? '' );
	}

	public function test_advertised_internal_link_aliases_pass_pre_execution_validation(): void {
		$tools_by_name = array_column( $this->list_tools_manifest()['tools'], null, 'name' );
		$validator     = new McpInputValidator();

		self::assertNull(
			$validator->arguments_error(
				array(
					'post_id' => 10,
					'items'   => array(
						array(
							'post_id'              => 20,
							'proposed_anchor_text' => 'Related guide',
							'reason'               => 'The target expands on this topic.',
						),
					),
				),
				$tools_by_name['content_internal_link_suggestions_create']['inputSchema'],
				'content_internal_link.suggestions_create'
			)
		);
		self::assertNull(
			$validator->arguments_error(
				array(
					'suggestion_id' => 'suggestion-1',
					'status'        => 'approved',
				),
				$tools_by_name['content_internal_link_suggestion_review']['inputSchema'],
				'content_internal_link.suggestion_review'
			)
		);
		self::assertNull(
			$validator->arguments_error(
				array( 'suggestion_id' => 'suggestion-1' ),
				$tools_by_name['content_internal_link_suggestion_apply']['inputSchema'],
				'content_internal_link.suggestion_apply'
			)
		);
	}

	public function test_claude_tools_list_uses_claude_safe_tool_names(): void {
		$result = $this->list_tools_manifest();
		$names  = array_column( $result['tools'], 'name' );

		foreach ( $names as $name ) {
			self::assertIsString( $name );
			self::assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/', $name );
			self::assertStringNotContainsString( '.', $name );
			self::assertStringNotContainsString( '/', $name );
		}
	}

	public function test_tools_list_filters_write_tools_by_granted_oauth_scopes(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item', 'content.update_item' ) );

		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'scopes' => array( 'content:read' ),
			)
		);

		$result        = $this->invokePrivate( $controller, 'list_tools' );
		$tools_by_name = array_column( $result['tools'], null, 'name' );

		self::assertArrayHasKey( 'content_get_item', $tools_by_name );
		self::assertArrayNotHasKey( 'content_update_item', $tools_by_name );
	}

	public function test_tools_list_cursor_pages_aggregate_to_scope_aware_manifest(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array_keys( $registry->configurable_definitions() ) );

		$controller = new McpController();
		$this->setPrivateProperty(
			$controller,
			'request_auth',
			array(
				'scopes' => array( 'content:read', 'content:draft' ),
			)
		);

		$first_page = $this->invokePrivate( $controller, 'list_tools' );

		self::assertLessThanOrEqual( McpController::tools_page_size(), count( $first_page['tools'] ) );
		self::assertArrayHasKey( 'nextCursor', $first_page );
		self::assertArrayHasKey( '_meta', $first_page );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $first_page['_meta']['aculect/toolListFingerprint'] );
		self::assertSame( ACULECT_AI_COMPANION_VERSION, $first_page['_meta']['aculect/toolListVersion'] );
		self::assertGreaterThan( McpController::tools_page_size(), $first_page['_meta']['aculect/totalTools'] );
		self::assertSame( McpController::tools_page_size(), $first_page['_meta']['aculect/pageSize'] );
		self::assertSame( 0, $first_page['_meta']['aculect/pageOffset'] );
		self::assertSame( McpController::tools_page_size(), $first_page['_meta']['aculect/pageToolCount'] );
		self::assertTrue( $first_page['_meta']['aculect/cursorValid'] );
		self::assertTrue( $first_page['_meta']['aculect/nextCursorVersioned'] );

		$manifest_names = array_column( $controller->tool_manifest_for_user( 1, array( 'content:read', 'content:draft' ) )['tools'], 'name' );
		$paged_names    = array_column( $this->list_tools_manifest( $controller )['tools'], 'name' );

		self::assertSame( $manifest_names, $paged_names );
		self::assertGreaterThan( McpController::tools_page_size(), count( $paged_names ) );
		self::assertSame( count( $paged_names ), count( array_unique( $paged_names ) ) );
	}

	public function test_tools_list_rejects_stale_versioned_cursor_after_policy_change(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array_keys( $registry->configurable_definitions() ) );

		$controller = new McpController();
		$scopes     = array( 'content:read', 'content:draft' );
		$first_page = $controller->tools_list_page_for_user( 1, $scopes );

		self::assertArrayHasKey( 'nextCursor', $first_page );

		$registry->save_enabled_ids( array( 'content.get_item' ) );
		$stale_cursor_page = $controller->tools_list_page_for_user( 1, $scopes, (string) $first_page['nextCursor'] );
		$fresh_first_page  = $controller->tools_list_page_for_user( 1, $scopes );

		self::assertFalse( $stale_cursor_page['_meta']['aculect/cursorValid'] );
		self::assertSame( 0, $stale_cursor_page['_meta']['aculect/pageOffset'] );
		self::assertSame(
			array_column( $fresh_first_page['tools'], 'name' ),
			array_column( $stale_cursor_page['tools'], 'name' )
		);
		self::assertSame(
			$fresh_first_page['_meta']['aculect/toolListFingerprint'],
			$stale_cursor_page['_meta']['aculect/toolListFingerprint']
		);
		self::assertNotSame(
			$first_page['_meta']['aculect/toolListFingerprint'],
			$stale_cursor_page['_meta']['aculect/toolListFingerprint']
		);
	}

	public function test_openai_chatgpt_codex_and_gemini_tool_descriptors_keep_mcp_security_contract(): void {
		$result           = $this->list_tools_manifest();
		$supported_scopes = Helpers::supported_scopes();

		foreach ( $result['tools'] as $tool ) {
			self::assertIsArray( $tool );
			foreach ( array( 'name', 'title', 'description', 'inputSchema', 'securitySchemes', '_meta', 'annotations' ) as $field ) {
				self::assertArrayHasKey( $field, $tool );
			}

			self::assertIsString( $tool['name'] );
			self::assertIsString( $tool['title'] );
			self::assertIsString( $tool['description'] );
			self::assertIsArray( $tool['inputSchema'] );
			self::assertSame( 'object', $tool['inputSchema']['type'] ?? null );
			self::assertArrayHasKey( 'properties', $tool['inputSchema'] );
			self::assertFalse( $tool['inputSchema']['additionalProperties'] ?? true, (string) $tool['name'] );

			self::assertIsArray( $tool['securitySchemes'] );
			self::assertNotEmpty( $tool['securitySchemes'] );
			self::assertIsArray( $tool['_meta'] );
			self::assertArrayHasKey( 'securitySchemes', $tool['_meta'] );
			self::assertSame( $tool['securitySchemes'], $tool['_meta']['securitySchemes'] );
			self::assertArrayHasKey( 'openai/toolInvocation/invoking', $tool['_meta'] );
			self::assertArrayHasKey( 'openai/toolInvocation/invoked', $tool['_meta'] );
			self::assertIsString( $tool['_meta']['openai/toolInvocation/invoking'] );
			self::assertIsString( $tool['_meta']['openai/toolInvocation/invoked'] );
			self::assertLessThanOrEqual( 64, strlen( $tool['_meta']['openai/toolInvocation/invoking'] ) );
			self::assertLessThanOrEqual( 64, strlen( $tool['_meta']['openai/toolInvocation/invoked'] ) );

			foreach ( $tool['securitySchemes'] as $scheme ) {
				self::assertIsArray( $scheme );
				self::assertSame( 'oauth2', $scheme['type'] ?? null );
				self::assertIsArray( $scheme['scopes'] ?? null );
				foreach ( $scheme['scopes'] as $scope ) {
					self::assertContains( $scope, $supported_scopes );
				}
			}

			self::assertIsArray( $tool['annotations'] );
			self::assertArrayHasKey( 'readOnlyHint', $tool['annotations'] );
			self::assertArrayHasKey( 'destructiveHint', $tool['annotations'] );
			self::assertArrayHasKey( 'idempotentHint', $tool['annotations'] );
			self::assertArrayHasKey( 'openWorldHint', $tool['annotations'] );
			self::assertIsBool( $tool['annotations']['readOnlyHint'] );
			self::assertIsBool( $tool['annotations']['destructiveHint'] );
			self::assertIsBool( $tool['annotations']['idempotentHint'] );
			self::assertIsBool( $tool['annotations']['openWorldHint'] );
		}

		$tools_by_name = array_column( $result['tools'], null, 'name' );
		self::assertTrue( $tools_by_name['media_delete_item']['annotations']['destructiveHint'] );
		self::assertTrue( $tools_by_name['content_create_item']['annotations']['openWorldHint'] );
		self::assertTrue( $tools_by_name['content_index_refresh_batch']['annotations']['idempotentHint'] );
		self::assertTrue( $tools_by_name['memory_bootstrap']['annotations']['idempotentHint'] );
	}

	public function test_initialize_payload_includes_chatgpt_workflow_instructions(): void {
		$result = $this->invokePrivate( new McpController(), 'initialize_payload' );

		self::assertSame( '2025-06-18', $result['protocolVersion'] );
		self::assertSame( 'Aculect AI Companion MCP', $result['serverInfo']['name'] );
		self::assertIsString( $result['instructions'] );
		self::assertStringContainsString( 'workflow_route_request', $result['instructions'] );
		self::assertStringContainsString( 'workflow_session_start', $result['instructions'] );
		self::assertStringContainsString( 'workflow_loop_run_next', $result['instructions'] );
		self::assertStringContainsString( 'workflow_loop_run_batch', $result['instructions'] );
		self::assertStringContainsString( 'resources/list', $result['instructions'] );
		self::assertStringContainsString( 'resources/read', $result['instructions'] );
		self::assertStringContainsString( 'intelligence_capabilities_get_directory', $result['instructions'] );
		self::assertStringContainsString( 'workflow_guides_list', $result['instructions'] );
		self::assertStringContainsString( 'workflow_guides_get', $result['instructions'] );
		self::assertStringContainsString( 'intelligence_site_get_context', $result['instructions'] );
		self::assertStringContainsString( 'intelligence_content_get_context', $result['instructions'] );
		self::assertStringContainsString( 'operations manifest', $result['instructions'] );
		self::assertStringContainsString( 'call search first', $result['instructions'] );
		self::assertStringContainsString( 'fetch with a returned ID', $result['instructions'] );
		self::assertStringContainsString( 'content_search_items', $result['instructions'] );
		self::assertStringContainsString( 'content_search_chunks', $result['instructions'] );
		self::assertStringContainsString( 'content_find_internal_links', $result['instructions'] );
		self::assertStringContainsString( 'content_internal_link_policy', $result['instructions'] );
		self::assertStringContainsString( 'content_audit_internal_links', $result['instructions'] );
		self::assertStringContainsString( 'confirmation_token', $result['instructions'] );
		self::assertStringContainsString( 'memory_list', $result['instructions'] );
		self::assertStringContainsString( 'site_workflow_audit', $result['instructions'] );
		self::assertStringContainsString( 'memory_save', $result['instructions'] );
		self::assertStringContainsString( 'memory_bootstrap', $result['instructions'] );
		self::assertStringContainsString( 'admin review', $result['instructions'] );
		self::assertStringContainsString( 'content_workflow_prepare_post', $result['instructions'] );
		self::assertStringContainsString( 'content_workflow_create_draft', $result['instructions'] );
		self::assertStringContainsString( 'navigation_get_context', $result['instructions'] );
		self::assertStringContainsString( 'navigation_list_items', $result['instructions'] );
		self::assertStringContainsString( 'intelligence_feedback_submit', $result['instructions'] );
		self::assertStringContainsString( 'plugin_incident_report', $result['instructions'] );
		self::assertStringContainsString( 'mcp_learning_inspect_activity', $result['instructions'] );
		self::assertStringContainsString( 'Never use raw Custom HTML blocks', $result['instructions'] );
		self::assertArrayHasKey( 'tools', $result['capabilities'] );
		self::assertFalse( $result['capabilities']['tools']['listChanged'] );
		self::assertArrayHasKey( 'resources', $result['capabilities'] );
		self::assertFalse( $result['capabilities']['resources']['listChanged'] );
	}
	public function test_intelligence_tools_advertise_output_schemas(): void {
		$result = $this->list_tools_manifest();

		foreach ( $result['tools'] as $tool ) {
			$name = (string) ( $tool['name'] ?? '' );
			if ( ! str_starts_with( $name, 'intelligence_' ) ) {
				continue;
			}

			self::assertArrayHasKey( 'outputSchema', $tool, $name );
			self::assertSame( 'object', $tool['outputSchema']['type'] ?? null, $name );
			self::assertIsArray( $tool['outputSchema']['properties'] ?? null, $name );
			self::assertTrue( $tool['outputSchema']['additionalProperties'] ?? false, $name );
		}

		$tools_by_name = array_column( $result['tools'], null, 'name' );
		self::assertSame( array( 'status' ), $tools_by_name['intelligence_feedback_submit']['outputSchema']['required'] );
		self::assertSame( array( 'status' ), $tools_by_name['plugin_incident_report']['outputSchema']['required'] );
		self::assertArrayHasKey( 'report_id', $tools_by_name['plugin_incident_report']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'correlation_id', $tools_by_name['plugin_incident_report']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'issue_url', $tools_by_name['plugin_incident_report']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'can_create_direct', $tools_by_name['plugin_incident_report']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'incident', $tools_by_name['plugin_incident_report']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'items', $tools_by_name['plugin_incident_list']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'regular_abilities', $tools_by_name['intelligence_capabilities_get_directory']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'learning_protocol', $tools_by_name['intelligence_site_get_context']['outputSchema']['properties'] );
	}

	public function test_operational_and_workflow_tools_advertise_output_schemas(): void {
		$result        = $this->list_tools_manifest();
		$tools_by_name = array_column( $result['tools'], null, 'name' );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['search'] );
		self::assertArrayHasKey( 'results', $tools_by_name['search']['outputSchema']['properties'] );
		self::assertSame( array( 'results' ), $tools_by_name['search']['outputSchema']['required'] );
		self::assertFalse( $tools_by_name['search']['outputSchema']['additionalProperties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['fetch'] );
		self::assertArrayHasKey( 'id', $tools_by_name['fetch']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'title', $tools_by_name['fetch']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'text', $tools_by_name['fetch']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'url', $tools_by_name['fetch']['outputSchema']['properties'] );
		self::assertFalse( $tools_by_name['fetch']['outputSchema']['additionalProperties'] );
		foreach ( array( 'content_create_item', 'content_update_item', 'content_update_block', 'content_update_seo', 'content_workflow_create_draft', 'seo_workflow_update_rankmath' ) as $name ) {
			self::assertArrayHasKey( 'outputSchema', $tools_by_name[ $name ], $name );
			self::assertArrayHasKey( 'status', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
			self::assertArrayHasKey( 'post_id', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
			self::assertArrayHasKey( 'next_actions', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
			self::assertArrayHasKey( 'confirmation_policy', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
			self::assertArrayHasKey( 'write_permission_enabled', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
		}
		foreach ( array( 'content_workflow_list', 'content_workflow_get', 'content_workflow_prepare', 'content_workflow_dry_run', 'content_workflow_execute', 'content_workflow_resume', 'content_workflow_cancel', 'content_workflow_status', 'content_workflow_result' ) as $name ) {
			self::assertArrayHasKey( 'outputSchema', $tools_by_name[ $name ], $name );
			self::assertArrayHasKey( 'status', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
			self::assertArrayHasKey( 'bounded', $tools_by_name[ $name ]['outputSchema']['properties'], $name );
		}
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['site_workflow_audit'] );
		self::assertArrayHasKey( 'findings', $tools_by_name['site_workflow_audit']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'summary', $tools_by_name['site_workflow_audit']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'operation_entries', $tools_by_name['site_workflow_audit']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_list_items'] );
		self::assertArrayHasKey( 'items', $tools_by_name['content_list_items']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'total', $tools_by_name['content_list_items']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'per_page', $tools_by_name['content_list_items']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_revisions_list'] );
		self::assertArrayHasKey( 'post_id', $tools_by_name['content_revisions_list']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'include_preview', $tools_by_name['content_revisions_list']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'items', $tools_by_name['content_revisions_list']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'has_more', $tools_by_name['content_revisions_list']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_autosaves_inspect'] );
		self::assertArrayHasKey( 'post_id', $tools_by_name['content_autosaves_inspect']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'has_autosave', $tools_by_name['content_autosaves_inspect']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'autosave', $tools_by_name['content_autosaves_inspect']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['users_current_access'] );
		self::assertArrayHasKey( 'user_id', $tools_by_name['users_current_access']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'privacy', $tools_by_name['users_current_access']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['users_roles_summary'] );
		self::assertArrayHasKey( 'items', $tools_by_name['users_roles_summary']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['navigation_list_menus'] );
		self::assertArrayHasKey( 'source_type', $tools_by_name['navigation_list_menus']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'items', $tools_by_name['navigation_list_menus']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['navigation_list_items'] );
		self::assertArrayHasKey( 'menu_id', $tools_by_name['navigation_list_items']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'navigation_id', $tools_by_name['navigation_list_items']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'items', $tools_by_name['navigation_list_items']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'summary', $tools_by_name['navigation_get_context']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['users_list_safe'] );
		self::assertArrayHasKey( 'per_page', $tools_by_name['users_list_safe']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_search_chunks'] );
		self::assertArrayHasKey( 'items', $tools_by_name['content_search_chunks']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'index', $tools_by_name['content_search_chunks']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'visible_total', $tools_by_name['content_search_chunks']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'filtered_by_access', $tools_by_name['content_search_chunks']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_audit_internal_links'] );
		self::assertArrayHasKey( 'items', $tools_by_name['content_audit_internal_links']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'index', $tools_by_name['content_audit_internal_links']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'health_summary', $tools_by_name['content_audit_internal_links']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'action_queue', $tools_by_name['content_audit_internal_links']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'filtered_by_access', $tools_by_name['content_audit_internal_links']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_internal_link_policy'] );
		self::assertArrayHasKey( 'policy', $tools_by_name['content_internal_link_policy']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_find_internal_links'] );
		self::assertArrayHasKey( 'quality_summary', $tools_by_name['content_find_internal_links']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_internal_link_suggestions_create'] );
		self::assertArrayHasKey( 'items', $tools_by_name['content_internal_link_suggestions_create']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'items', $tools_by_name['content_internal_link_suggestions_create']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_internal_link_suggestions_list'] );
		self::assertArrayHasKey( 'visible_total', $tools_by_name['content_internal_link_suggestions_list']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'status', $tools_by_name['content_internal_link_suggestions_list']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_internal_link_suggestion_review'] );
		self::assertArrayHasKey( 'suggestion', $tools_by_name['content_internal_link_suggestion_review']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'action', $tools_by_name['content_internal_link_suggestion_review']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_internal_link_suggestion_apply'] );
		self::assertArrayHasKey( 'diff', $tools_by_name['content_internal_link_suggestion_apply']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'dry_run', $tools_by_name['content_internal_link_suggestion_apply']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'max_word_count', $tools_by_name['content_search_items']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['content_index_refresh_batch'] );
		self::assertArrayHasKey( 'job', $tools_by_name['content_index_refresh_batch']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['memory_bootstrap'] );
		self::assertArrayHasKey( 'review_status', $tools_by_name['memory_bootstrap']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'skipped', $tools_by_name['memory_bootstrap']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'intelligence_context', $tools_by_name['content_workflow_prepare_post']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_guides_list'] );
		self::assertArrayHasKey( 'items', $tools_by_name['workflow_guides_list']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'max_guides', $tools_by_name['workflow_guides_list']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_guides_get'] );
		self::assertArrayHasKey( 'required_operations', $tools_by_name['workflow_guides_get']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'missing_required_operations', $tools_by_name['workflow_guides_get']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_route_request'] );
		self::assertArrayHasKey( 'next_tool', $tools_by_name['workflow_route_request']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'workflow_session_plan', $tools_by_name['workflow_route_request']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_session_start'] );
		self::assertArrayHasKey( 'workflow_session', $tools_by_name['workflow_session_start']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_loop_create'] );
		self::assertArrayHasKey( 'workflow_loop', $tools_by_name['workflow_loop_create']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'items', $tools_by_name['workflow_loop_create']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'max_word_count', $tools_by_name['workflow_loop_create']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_loop_run_next'] );
		self::assertArrayHasKey( 'active_item', $tools_by_name['workflow_loop_run_next']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'resume', $tools_by_name['workflow_loop_run_next']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_loop_run_batch'] );
		self::assertArrayHasKey( 'items_to_process', $tools_by_name['workflow_loop_run_batch']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'completed_items', $tools_by_name['workflow_loop_run_batch']['inputSchema']['properties'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_loop_pause'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['workflow_loop_cancel'] );
		self::assertArrayHasKey( 'outputSchema', $tools_by_name['mcp_learning_inspect_activity'] );
		self::assertArrayHasKey( 'insights', $tools_by_name['mcp_learning_inspect_activity']['outputSchema']['properties'] );
	}

	public function test_chatgpt_codex_claude_and_gemini_tools_prioritize_operational_tools_before_intelligence_tools(): void {
		$result = $this->list_tools_manifest();
		$names  = array_column( $result['tools'], 'name' );

		$critical_tools = array(
			'search',
			'fetch',
			'workflow_route_request',
			'workflow_session_start',
			'workflow_session_get',
			'workflow_session_update',
			'workflow_loop_create',
			'workflow_loop_get',
			'workflow_loop_run_next',
			'workflow_loop_run_batch',
			'workflow_loop_pause',
			'workflow_loop_cancel',
			'workflow_guides_list',
			'workflow_guides_get',
			'content_workflow_prepare_post',
			'content_workflow_create_draft',
			'content_workflow_update_post',
			'seo_workflow_update_rankmath',
			'site_workflow_audit',
			'navigation_get_context',
			'navigation_list_menus',
			'navigation_list_locations',
			'navigation_list_items',
			'content_index_refresh_batch',
			'content_search_items',
			'content_search_chunks',
			'content_find_related',
			'content_internal_link_policy',
			'content_find_internal_links',
			'content_audit_internal_links',
			'content_internal_link_suggestions_create',
			'content_internal_link_suggestions_list',
			'content_internal_link_suggestion_review',
			'content_internal_link_suggestion_apply',
			'memory_list',
			'memory_save',
			'memory_bootstrap',
			'mcp_learning_inspect_activity',
			'site_list_post_types',
			'content_list_items',
			'content_get_item',
			'content_create_item',
			'content_update_item',
			'content_update_seo',
			'taxonomy_list_taxonomies',
			'taxonomy_list_terms',
			'taxonomy_create_term',
			'taxonomy_update_term',
			'media_list_items',
			'media_get_item',
			'media_upload_item',
			'media_update_item',
		);

		foreach ( $critical_tools as $tool_name ) {
			self::assertContains( $tool_name, $names );
		}

		$first_intelligence_index = null;
		foreach ( $names as $index => $name ) {
			if ( is_string( $name ) && str_starts_with( $name, 'intelligence_' ) ) {
				$first_intelligence_index = $index;
				break;
			}
		}

		self::assertNotNull( $first_intelligence_index );
		foreach ( $critical_tools as $tool_name ) {
			$tool_index = array_search( $tool_name, $names, true );
			self::assertIsInt( $tool_index );
			self::assertLessThan( $first_intelligence_index, $tool_index );
		}
	}

	public function test_openai_chatgpt_codex_claude_and_gemini_input_schemas_use_client_safe_json_schema_subset(): void {
		$result = $this->list_tools_manifest();

		foreach ( $result['tools'] as $tool ) {
			self::assertIsArray( $tool );
			self::assertArrayHasKey( 'name', $tool );
			self::assertArrayHasKey( 'inputSchema', $tool );
			self::assertIsArray( $tool['inputSchema'] );
			self::assertFalse( $tool['inputSchema']['additionalProperties'] ?? true, (string) $tool['name'] . '.inputSchema must be closed at the top level' );

			$this->assertSchemaDoesNotContainCompositionKeywords( $tool['inputSchema'], (string) $tool['name'] . '.inputSchema' );
		}
	}

	public function test_intelligence_context_lists_operational_tool_names(): void {
		$site = ( new IntelligenceContext() )->site();

		self::assertSame( 'content_list_items', $site['operations']['content']['list_items']['tool'] );
		self::assertTrue( $site['operations']['content']['list_items']['available'] );
		self::assertSame( 'content_update_item', $site['operations']['content']['update']['tool'] );
		self::assertSame( 'content_update_seo', $site['operations']['content']['seo']['tool'] );
		self::assertSame( 'content_search_items', $site['operations']['intelligence_index']['search_items']['tool'] );
		self::assertSame( 'search', $site['operations']['intelligence_index']['canonical_search']['tool'] );
		self::assertSame( 'fetch', $site['operations']['intelligence_index']['canonical_fetch']['tool'] );
		self::assertSame( 'workflow_route_request', $site['operations']['workflows']['route_request']['tool'] );
		self::assertSame( 'workflow_guides_list', $site['operations']['workflow_guides']['list']['tool'] );
		self::assertSame( 'workflow_guides_get', $site['operations']['workflow_guides']['get']['tool'] );
		self::assertSame( 'workflow_session_start', $site['operations']['workflow_guides']['session_start']['tool'] );
		self::assertSame( 'workflow_session_get', $site['operations']['workflow_guides']['session_get']['tool'] );
		self::assertSame( 'workflow_session_update', $site['operations']['workflow_guides']['session_update']['tool'] );
		self::assertSame( 'navigation_get_context', $site['operations']['navigation']['get_context']['tool'] );
		self::assertSame( 'navigation_list_items', $site['operations']['navigation']['list_items']['tool'] );
		self::assertSame( 'content_find_internal_links', $site['operations']['intelligence_index']['internal_links']['tool'] );
		self::assertSame( 'content_audit_internal_links', $site['operations']['intelligence_index']['internal_link_audit']['tool'] );
		self::assertSame( 'memory_list', $site['operations']['intelligence_index']['memory_list']['tool'] );
		self::assertSame( 'memory_save', $site['operations']['intelligence_index']['memory_save']['tool'] );
		self::assertSame( 'memory_bootstrap', $site['operations']['intelligence_index']['memory_bootstrap']['tool'] );
		self::assertSame( 'mcp_learning_inspect_activity', $site['operations']['intelligence_index']['activity_learning']['tool'] );
		self::assertSame( 'media_upload_item', $site['operations']['media']['upload']['tool'] );
		self::assertSame( 'taxonomy_list_terms', $site['operations']['content_groups']['list_terms']['tool'] );
		self::assertSame( 'wp_abilities_run', $site['operations']['actions']['run']['tool'] );
	}

	public function test_input_schema_accepts_public_tool_name_aliases(): void {
		$dotted_schema = $this->schemaForTool( 'content.list_items' );
		$public_schema = $this->schemaForTool( 'content_list_items' );

		self::assertSame( $dotted_schema, $public_schema );
		self::assertSame( 'object', $public_schema['type'] );
		self::assertFalse( $public_schema['additionalProperties'] );
		self::assertArrayHasKey( 'post_type', $public_schema['properties'] );
		self::assertSame( 'array', $public_schema['properties']['status']['type'] );
		self::assertSame( 'string', $public_schema['properties']['status']['items']['type'] );
		self::assertSame( 1, $public_schema['properties']['page']['minimum'] );
		self::assertSame( 100, $public_schema['properties']['per_page']['maximum'] );
		self::assertArrayHasKey( 'context', $public_schema['properties'] );
		self::assertSame( array( 'compact', 'full' ), $public_schema['properties']['context']['enum'] );
	}

	public function test_expanded_tool_schemas_are_available(): void {
		$canonical_search_schema = $this->schemaForTool( 'search' );
		self::assertSame( 'object', $canonical_search_schema['type'] );
		self::assertSame( array( 'query' ), $canonical_search_schema['required'] );
		self::assertFalse( $canonical_search_schema['additionalProperties'] );

		$canonical_fetch_schema = $this->schemaForTool( 'fetch' );
		self::assertSame( 'object', $canonical_fetch_schema['type'] );
		self::assertSame( array( 'id' ), $canonical_fetch_schema['required'] );
		self::assertFalse( $canonical_fetch_schema['additionalProperties'] );

		$guide_list_schema = $this->schemaForTool( 'workflow_guides_list' );
		self::assertSame( 'object', $guide_list_schema['type'] );
		self::assertArrayHasKey( 'available_only', $guide_list_schema['properties'] );
		self::assertSame( array( 'summary', 'full' ), $guide_list_schema['properties']['detail']['enum'] );

		$guide_get_schema = $this->schemaForTool( 'workflow_guides_get' );
		self::assertSame( 'object', $guide_get_schema['type'] );
		self::assertSame( array( 'id' ), $guide_get_schema['required'] );

		$route_schema = $this->schemaForTool( 'workflow_route_request' );
		self::assertSame( 'object', $route_schema['type'] );
		self::assertFalse( $route_schema['additionalProperties'] );
		self::assertArrayHasKey( 'request', $route_schema['properties'] );
		self::assertArrayNotHasKey( 'required', $route_schema );

		$session_start_schema = $this->schemaForTool( 'workflow_session_start' );
		self::assertSame( 'object', $session_start_schema['type'] );
		self::assertArrayHasKey( 'workflow', $session_start_schema['properties'] );
		self::assertArrayHasKey( 'idempotency_key', $session_start_schema['properties'] );

		$session_get_schema = $this->schemaForTool( 'workflow_session_get' );
		self::assertArrayHasKey( 'workflow_session_id', $session_get_schema['properties'] );

		$learning_schema = $this->schemaForTool( 'mcp_learning_inspect_activity' );
		self::assertSame( 'object', $learning_schema['type'] );
		self::assertSame( array( 'error', 'success', 'all' ), $learning_schema['properties']['status']['enum'] );

		$media_schema = $this->schemaForTool( 'media_upload_item' );
		self::assertSame( 'object', $media_schema['type'] );
		self::assertFalse( $media_schema['additionalProperties'] );
		self::assertSame( array( 'url' ), $media_schema['required'] );
		self::assertSame( 'uri', $media_schema['properties']['url']['format'] );
		self::assertArrayHasKey( 'alt_text', $media_schema['properties'] );

		$media_get_schema = $this->schemaForTool( 'media_get_item' );
		self::assertSame( array( 'id' ), $media_get_schema['required'] );

		$media_update_schema = $this->schemaForTool( 'media_update_item' );
		self::assertSame( array( 'id' ), $media_update_schema['required'] );
		self::assertArrayHasKey( 'post_id', $media_update_schema['properties'] );

		$media_delete_schema = $this->schemaForTool( 'media_delete_item' );
		self::assertSame( array( 'id' ), $media_delete_schema['required'] );

		$media_rename_schema = $this->schemaForTool( 'media_rename_file' );
		self::assertSame( array( 'id', 'filename' ), $media_rename_schema['required'] );

		$create_schema = $this->schemaForTool( 'content_create_item' );
		self::assertArrayHasKey( 'featured_media', $create_schema['properties'] );

		$update_schema = $this->schemaForTool( 'content_update_item' );
		self::assertArrayHasKey( 'featured_media', $update_schema['properties'] );
		self::assertArrayHasKey( 'clear_featured_media', $update_schema['properties'] );

		$block_update_schema = $this->schemaForTool( 'content_update_block' );
		self::assertSame( array( 'id', 'locator' ), $block_update_schema['required'] );
		self::assertArrayHasKey( 'path', $block_update_schema['properties']['locator']['properties'] );
		self::assertArrayHasKey( 'text', $block_update_schema['properties'] );

		$seo_schema = $this->schemaForTool( 'content_update_seo' );
		self::assertSame( array( 'id' ), $seo_schema['required'] );
		self::assertArrayHasKey( 'meta_title', $seo_schema['properties'] );
		self::assertArrayHasKey( 'meta_description', $seo_schema['properties'] );
		self::assertArrayHasKey( 'focus_keywords', $seo_schema['properties'] );
		self::assertSame( 'array', $seo_schema['properties']['focus_keywords']['type'] );
		self::assertSame( 'string', $seo_schema['properties']['focus_keywords']['items']['type'] );
		self::assertSame( 10, $seo_schema['properties']['focus_keywords']['maxItems'] );

		$seo_read_schema = $this->schemaForTool( 'content_get_seo' );
		self::assertSame( array( 'id' ), $seo_read_schema['required'] );
		self::assertArrayHasKey( 'plugin', $seo_read_schema['properties'] );
		self::assertArrayHasKey( 'source', $seo_read_schema['properties'] );
		self::assertSame( array( 'auto', 'yoast', 'rank_math' ), $seo_read_schema['properties']['plugin']['enum'] );

		$comments_schema = $this->schemaForTool( 'comments_update_item' );
		self::assertSame( array( 'id' ), $comments_schema['required'] );
		self::assertArrayHasKey( 'status', $comments_schema['properties'] );

		$comments_list_schema = $this->schemaForTool( 'comments_list_items' );
		self::assertArrayHasKey( 'date_after', $comments_list_schema['properties'] );
		self::assertArrayHasKey( 'author_user_id', $comments_list_schema['properties'] );
		self::assertArrayHasKey( 'context', $comments_list_schema['properties'] );

		$comments_create_schema = $this->schemaForTool( 'comments_create_item' );
		self::assertArrayHasKey( 'parent_id', $comments_create_schema['properties'] );

		$comments_bulk_schema = $this->schemaForTool( 'comments_bulk_update' );
		self::assertSame( array( 'ids', 'status' ), $comments_bulk_schema['required'] );

		$abilities_schema = $this->schemaForTool( 'wp_abilities_run' );
		self::assertSame( array( 'id' ), $abilities_schema['required'] );
		self::assertArrayHasKey( 'arguments', $abilities_schema['properties'] );

		$health_schema = $this->schemaForTool( 'site_get_health' );
		self::assertSame( 'object', $health_schema['type'] );
		self::assertFalse( $health_schema['additionalProperties'] );
		self::assertInstanceOf( \stdClass::class, $health_schema['properties'] );

		$brand_schema = $this->schemaForTool( 'intelligence_brand_get_context' );
		self::assertSame( 'object', $brand_schema['type'] );
		self::assertFalse( $brand_schema['additionalProperties'] );
		self::assertInstanceOf( \stdClass::class, $brand_schema['properties'] );

		$feedback_schema = $this->schemaForTool( 'intelligence_feedback_submit' );
		self::assertSame( 'object', $feedback_schema['type'] );
		self::assertSame( array( 'domain', 'issue', 'suggested_update' ), $feedback_schema['required'] );
		self::assertSame( array( 'site', 'content', 'developer', 'brand' ), $feedback_schema['properties']['domain']['enum'] );

		$create_schema = $this->schemaForTool( 'content_create_item' );
		self::assertArrayHasKey( 'author', $create_schema['properties'] );
		self::assertArrayHasKey( 'taxonomies', $create_schema['properties'] );
		self::assertIsArray( $create_schema['properties']['taxonomies']['additionalProperties'] );
		self::assertSame( 'array', $create_schema['properties']['taxonomies']['additionalProperties']['type'] );
		self::assertSame( 'integer', $create_schema['properties']['taxonomies']['additionalProperties']['items']['type'] );
		self::assertArrayHasKey( 'date', $create_schema['properties'] );
		self::assertSame( 300000, $create_schema['properties']['content']['maxLength'] );
		self::assertSame( array( 'draft', 'future', 'pending', 'private', 'publish', 'trash' ), $create_schema['properties']['status']['enum'] );

		$update_schema = $this->schemaForTool( 'content_update_item' );
		self::assertArrayHasKey( 'author', $update_schema['properties'] );
		self::assertArrayHasKey( 'taxonomies', $update_schema['properties'] );
		self::assertArrayHasKey( 'date', $update_schema['properties'] );
		self::assertSame( array( 'draft', 'future', 'pending', 'private', 'publish', 'trash' ), $update_schema['properties']['status']['enum'] );

		$term_image_schema = $this->schemaForTool( 'taxonomy_set_term_image' );
		self::assertSame( array( 'taxonomy', 'term_id' ), $term_image_schema['required'] );
		self::assertArrayHasKey( 'image_id', $term_image_schema['properties'] );
		self::assertArrayHasKey( 'clear_image', $term_image_schema['properties'] );

		$workflow_prepare_schema = $this->schemaForTool( 'content_workflow_prepare_post' );
		self::assertSame( array( 'brief' ), $workflow_prepare_schema['required'] );
		self::assertArrayHasKey( 'desired_word_count', $workflow_prepare_schema['properties'] );
		self::assertSame( 3000, $workflow_prepare_schema['properties']['desired_word_count']['minimum'] );
		self::assertSame( 5000, $workflow_prepare_schema['properties']['desired_word_count']['maximum'] );
		self::assertArrayHasKey( 'content_mode', $workflow_prepare_schema['properties'] );
		self::assertArrayHasKey( 'layout_intent', $workflow_prepare_schema['properties'] );
		self::assertArrayHasKey( 'visual_reference_summary', $workflow_prepare_schema['properties'] );
		self::assertContains( 'visual_layout', $workflow_prepare_schema['properties']['content_mode']['enum'] );

		$workflow_create_schema = $this->schemaForTool( 'content_workflow_create_draft' );
		self::assertSame( array( 'title', 'content' ), $workflow_create_schema['required'] );
		self::assertArrayHasKey( 'meta_title', $workflow_create_schema['properties'] );
		self::assertArrayHasKey( 'dry_run', $workflow_create_schema['properties'] );
		self::assertSame( 'array', $workflow_create_schema['properties']['focus_keywords']['type'] );
		self::assertArrayHasKey( 'expected_block_families', $workflow_create_schema['properties'] );

		$workflow_update_schema = $this->schemaForTool( 'content_workflow_update_post' );
		self::assertSame( array( 'id' ), $workflow_update_schema['required'] );
		self::assertArrayHasKey( 'section_map', $workflow_update_schema['properties'] );
		self::assertArrayHasKey( 'status', $workflow_update_schema['properties'] );
		self::assertIsArray( $workflow_update_schema['properties']['section_map']['additionalProperties'] );
		self::assertSame( 'object', $workflow_update_schema['properties']['section_map']['additionalProperties']['type'] );
		self::assertSame( array( 'content' ), $workflow_update_schema['properties']['section_map']['additionalProperties']['required'] );
		self::assertFalse( $workflow_update_schema['properties']['section_map']['additionalProperties']['additionalProperties'] );

		$rankmath_workflow_schema = $this->schemaForTool( 'seo_workflow_update_rankmath' );
		self::assertSame( array( 'id' ), $rankmath_workflow_schema['required'] );
		self::assertArrayHasKey( 'focus_keywords', $rankmath_workflow_schema['properties'] );
		self::assertSame( 'array', $rankmath_workflow_schema['properties']['focus_keywords']['type'] );
	}

	public function test_write_tool_schemas_include_safety_controls(): void {
		$write_schema = $this->schemaForTool( 'content_update_item' );
		self::assertArrayHasKey( 'dry_run', $write_schema['properties'] );
		self::assertArrayHasKey( 'confirmation_token', $write_schema['properties'] );

		$read_schema = $this->schemaForTool( 'content_get_item' );
		self::assertArrayNotHasKey( 'dry_run', $read_schema['properties'] );
		self::assertArrayNotHasKey( 'confirmation_token', $read_schema['properties'] );
	}

	public function test_administrator_incident_report_requires_confirmation_and_replays_without_duplicate_storage(): void {
		$controller = $this->controller_with_claim_store();
		$auth       = array(
			'user_id'   => 1,
			'client_id' => 'incident-test-client',
			'provider'  => 'chatgpt',
			'scopes'    => array( 'content:read' ),
			'profile'   => 'full_access',
		);
		$args       = array(
			'title'   => 'Incident confirmation is required',
			'summary' => 'A report must be previewed before it is stored.',
		);

		$initial = $this->pluginIncidentToolCall( $controller, $args, 'plugin_incident_report', $auth );

		self::assertSame( 'confirmation_required', $initial['status'] );
		self::assertTrue( $initial['confirmation_required'] );
		self::assertSame( 'update', $initial['risk_level'] );
		self::assertSame( 'preview', $initial['preview']['status'] );
		self::assertTrue( $initial['preview']['dry_run'] );
		self::assertSame( array(), get_option( 'aculect_ai_companion_incident_reports', array() ) );

		$args['dry_run']            = true;
		$args['confirmation_token'] = $initial['confirmation_token'];
		$confirmed                  = $this->pluginIncidentToolCall( $controller, $args, 'plugin_incident_report', $auth );

		self::assertSame( 'stored_ready_for_client_submission', $confirmed['status'] );
		self::assertFalse( $confirmed['can_create_direct'] );
		self::assertSame( $initial['preview']['incident']['correlation_id'], $confirmed['correlation_id'] );
		self::assertSame( $initial['preview']['body'], $confirmed['body'] );
		self::assertSame( $initial['preview']['issue_url'], $confirmed['issue_url'] );
		self::assertCount( 1, get_option( 'aculect_ai_companion_incident_reports', array() ) );

		$replay = $this->pluginIncidentToolCall( $controller, $args, 'plugin_incident_report', $auth );

		self::assertSame( $confirmed['report_id'], $replay['report_id'] );
		self::assertTrue( $replay['replayed'] );
		self::assertCount( 1, get_option( 'aculect_ai_companion_incident_reports', array() ) );
	}

	public function test_administrator_incident_report_rejects_invalid_confirmation_token(): void {
		$controller = $this->controller_with_claim_store();
		$auth       = array(
			'user_id'   => 1,
			'client_id' => 'incident-invalid-confirmation-client',
			'provider'  => 'chatgpt',
			'scopes'    => array( 'content:read' ),
			'profile'   => 'full_access',
		);
		$result     = $this->pluginIncidentToolCall(
			$controller,
			array(
				'title'              => 'Invalid incident confirmation',
				'summary'            => 'An invalid confirmation token must not be treated as missing approval.',
				'confirmation_token' => 'invalid-token',
			),
			'plugin_incident_report',
			$auth
		);

		self::assertSame( 'blocked', $result['status'] );
		self::assertSame( 'invalid_confirmation_token', $result['error'] );
		self::assertTrue( $result['confirmation_required'] );
		self::assertSame( 'update', $result['risk_level'] );
		self::assertSame( array(), get_option( 'aculect_ai_companion_incident_reports', array() ) );
	}

	public function test_administrator_incident_report_uses_trusted_write_approval_without_confirmation_token(): void {
		$controller = new McpController();
		$auth       = array(
			'user_id'                  => 1,
			'client_id'                => 'incident-trusted-write-client',
			'provider'                 => 'chatgpt',
			'scopes'                   => array( 'content:read' ),
			'profile'                  => 'full_access',
			'access_level'             => ConnectionAccessLevel::WRITE,
			'write_permission_enabled' => true,
		);
		$result     = $this->pluginIncidentToolCall(
			$controller,
			array(
				'title'   => 'Trusted incident approval',
				'summary' => 'A trusted Write connection should use the standard direct-write approval path.',
			),
			'plugin_incident_report',
			$auth
		);

		self::assertSame( 'stored_ready_for_client_submission', $result['status'] );
		self::assertSame( 'trusted_connection_direct_write', $result['confirmation_policy'] );
		self::assertFalse( $result['confirmation_required'] );
		self::assertTrue( $result['write_permission_enabled'] );
		self::assertSame( ConnectionAccessLevel::WRITE, $result['access_level'] );
		self::assertArrayNotHasKey( 'confirmation_token', $result );
		self::assertCount( 1, get_option( 'aculect_ai_companion_incident_reports', array() ) );
	}

	public function test_administrator_discovers_incident_tools_and_lists_without_confirmation(): void {
		$controller = new McpController();
		$auth       = array(
			'user_id'   => 1,
			'client_id' => 'incident-list-test-client',
			'provider'  => 'chatgpt',
			'scopes'    => array( 'content:read' ),
			'profile'   => 'full_access',
		);

		$this->setPrivateProperty( $controller, 'request_auth', $auth );
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$tool_names = array_column( $this->list_tools_manifest( $controller )['tools'], 'name' );

		self::assertContains( 'plugin_incident_list', $tool_names );
		self::assertContains( 'plugin_incident_report', $tool_names );

		$result = $this->pluginIncidentToolCall( $controller, array(), 'plugin_incident_list', $auth );

		self::assertSame( 0, $result['total'] );
		self::assertArrayNotHasKey( 'confirmation_required', $result );
		self::assertArrayNotHasKey( 'confirmation_token', $result );
	}

	public function test_subscriber_cannot_discover_or_execute_incident_tools(): void {
		$this->assertNonAdminIncidentToolsFailClosed( 2, 'subscriber' );
	}

	public function test_editor_cannot_discover_or_execute_incident_tools(): void {
		$this->assertNonAdminIncidentToolsFailClosed( 3, 'editor' );
	}

	public function test_plugin_incident_tools_return_distinct_permission_policy_and_scope_errors(): void {
		$controller = new McpController();
		$auth       = array(
			'user_id'   => 1,
			'client_id' => 'incident-authorization-client',
			'provider'  => 'chatgpt',
			'scopes'    => array( 'content:read' ),
			'profile'   => 'full_access',
		);

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'manage_options' );
		$this->setPrivateProperty( $controller, 'request_auth', $auth );
		$tool_names = array_column( $this->list_tools_manifest( $controller )['tools'], 'name' );
		self::assertNotContains( 'plugin_incident_list', $tool_names );
		self::assertNotContains( 'plugin_incident_report', $tool_names );

		$permission = $this->pluginIncidentRpcResult( $controller, array(), 'plugin_incident_list', $auth );
		self::assertTrue( $permission['isError'] );
		self::assertSame( 'This ability is not available for the connected WordPress capabilities.', $permission['content'][0]['text'] );

		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();

		$policy_auth = array_merge( $auth, array( 'profile' => 'read_only_audit' ) );
		$policy      = $this->pluginIncidentRpcResult(
			$controller,
			array(
				'title'   => 'Profile guidance',
				'summary' => 'A read-only profile guides selection without hiding an otherwise authorized tool.',
			),
			'plugin_incident_report',
			$policy_auth
		);
		self::assertFalse( $policy['isError'] ?? false );
		self::assertSame( 'confirmation_required', $policy['structuredContent']['status'] ?? '' );

		$list = $this->pluginIncidentToolCall( $controller, array(), 'plugin_incident_list', $policy_auth );
		self::assertSame( 0, $list['total'] );

		$scope = $this->pluginIncidentRpcResult(
			$controller,
			array(
				'title'   => 'OAuth scope block',
				'summary' => 'A missing OAuth scope must be reported before approval is requested.',
			),
			'plugin_incident_report',
			array_merge( $auth, array( 'scopes' => array() ) )
		);
		self::assertTrue( $scope['isError'] );
		self::assertSame( 'Authorization required.', $scope['content'][0]['text'] );
		self::assertStringContainsString( 'insufficient_scope', $scope['_meta']['mcp/www_authenticate'][0] );
		self::assertStringContainsString( 'content:read', $scope['_meta']['mcp/www_authenticate'][0] );
	}

	/**
	 * Resolve a tool input schema from the module registry.
	 *
	 * @param string $tool Internal ID, legacy alias, or public tool name.
	 * @return array<string, mixed>
	 */
	private function schemaForTool( string $tool ): array {
		$intelligence = new IntelligenceRegistry();
		if ( $intelligence->is_known( $tool ) ) {
			return $intelligence->input_schema( $tool );
		}

		return ( new AbilitiesRegistry() )->input_schema( $tool );
	}

	/**
	 * Assert a schema avoids composition keywords that some MCP clients drop silently.
	 *
	 * @param array<string, mixed> $schema Schema fragment.
	 * @param string               $path   Debug path for assertion failures.
	 */
	private function assertSchemaDoesNotContainCompositionKeywords( array $schema, string $path ): void {
		foreach ( array( 'oneOf', 'anyOf', 'allOf' ) as $keyword ) {
			self::assertArrayNotHasKey( $keyword, $schema, $path . ' must not contain ' . $keyword );
		}

		foreach ( $schema as $key => $value ) {
			if ( is_array( $value ) ) {
				$this->assertSchemaDoesNotContainCompositionKeywords( $value, $path . '.' . (string) $key );
			}
		}
	}

	public function test_global_pause_blocks_tool_calls(): void {
		$gateway = new AbilityExecutionGateway();

		self::assertFalse( $gateway->is_access_paused() );

		AccessLockdown::set_paused( true );

		self::assertTrue( $gateway->is_access_paused() );
	}

	public function test_user_pause_blocks_only_matching_user_tool_calls(): void {
		$gateway = new AbilityExecutionGateway();

		UserAccessControl::set_paused( 7, true );

		self::assertTrue( $gateway->is_access_paused( 7 ) );
		self::assertFalse( $gateway->is_access_paused( 12 ) );
	}

	public function test_disabled_tools_are_not_listed_and_are_blocked_for_cached_clients(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.list_items' ) );

		$result = $this->list_tools_manifest();
		$names  = array_column( $result['tools'], 'name' );

		self::assertContains( 'content_list_items', $names );
		self::assertContains( 'intelligence_site_get_context', $names );
		self::assertContains( 'intelligence_brand_get_context', $names );
		self::assertContains( 'intelligence_blocks_list_available', $names );
		self::assertContains( 'intelligence_feedback_submit', $names );
		self::assertContains( 'plugin_incident_report', $names );
		self::assertContains( 'plugin_incident_list', $names );
		self::assertContains( 'memory_list', $names );
		self::assertContains( 'memory_save', $names );
		self::assertContains( 'memory_bootstrap', $names );
		self::assertContains( 'workflow_guides_list', $names );
		self::assertContains( 'workflow_route_request', $names );
		self::assertContains( 'workflow_session_start', $names );
		self::assertContains( 'workflow_session_get', $names );
		self::assertContains( 'workflow_session_update', $names );
		self::assertContains( 'search', $names );
		self::assertContains( 'fetch', $names );
		self::assertContains( 'content_search_items', $names );
		self::assertContains( 'content_search_chunks', $names );
		self::assertContains( 'content_find_related', $names );
		self::assertContains( 'content_internal_link_policy', $names );
		self::assertContains( 'content_find_internal_links', $names );
		self::assertContains( 'content_audit_internal_links', $names );
		self::assertContains( 'content_batch_status', $names );
		self::assertContains( 'mcp_learning_inspect_activity', $names );
		self::assertNotContains( 'content_workflow_create_draft', $names );
		self::assertNotContains( 'content_update_item', $names );
		self::assertNotContains( 'brand_get_profile', $names );
		self::assertNotContains( 'blocks_list_available', $names );
		$gateway = new AbilityExecutionGateway( $registry );
		foreach ( array( 'content.list_items', 'memory.list', 'memory.save', 'memory.bootstrap', 'workflow_guides.list', 'workflow.route_request', 'workflow_session.start', 'workflow_session.get', 'workflow_session.update', 'search', 'fetch', 'content_search.items', 'content_search.chunks', 'content_find.related', 'content_internal_link.policy', 'content_find.internal_links', 'content_audit.internal_links', 'content_batch.status', 'mcp_learning.inspect_activity' ) as $tool ) {
			self::assertSame( '', $this->invokePrivate( $gateway, 'tool_call_error', array( $tool ) ) );
		}
		self::assertSame( 'tool_disabled', $this->invokePrivate( $gateway, 'tool_call_error', array( 'content_workflow.create_draft' ) ) );
		self::assertSame( 'tool_disabled', $this->invokePrivate( $gateway, 'tool_call_error', array( 'content.update_item' ) ) );
		self::assertSame( 'unknown_tool', $this->invokePrivate( $gateway, 'tool_call_error', array( 'content.not_real' ) ) );
	}

	public function test_derived_workflow_tool_calls_require_enabled_dependencies(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array( 'content.get_item' ) );

		self::assertSame( 'tool_disabled', $this->invokePrivate( new AbilityExecutionGateway( $registry ), 'tool_call_error', array( 'content_workflow.create_draft', 1 ) ) );

		$registry->save_enabled_ids( array( 'content.create_item' ) );

		self::assertSame( '', $this->invokePrivate( new AbilityExecutionGateway( $registry ), 'tool_call_error', array( 'content_workflow.create_draft', 1 ) ) );
	}

	public function test_scope_checks_require_every_required_scope(): void {
		$gateway = new AbilityExecutionGateway();

		self::assertTrue( $this->invokePrivate( $gateway, 'has_scopes', array( array( 'content:read', 'content:draft' ), array( 'content:draft' ) ) ) );
		self::assertFalse( $this->invokePrivate( $gateway, 'has_scopes', array( array( 'content:read' ), array( 'content:draft' ) ) ) );
	}

	public function test_connection_write_permission_unblocks_only_write_tools(): void {
		$gateway = new AbilityExecutionGateway();

		self::assertTrue(
			$this->invokePrivate(
				$gateway,
				'write_permission_unblocks_tool',
				array(
					'content.update_item',
					array( 'write_permission_enabled' => true ),
					false,
				)
			)
		);
		self::assertTrue(
			$this->invokePrivate(
				$gateway,
				'write_permission_unblocks_tool',
				array(
					'content.update_item',
					array(
						'write_permission_enabled' => false,
						'access_level'             => ConnectionAccessLevel::FULL_WRITE,
					),
					false,
				)
			)
		);
		self::assertFalse(
			$this->invokePrivate(
				$gateway,
				'write_permission_unblocks_tool',
				array(
					'content.update_item',
					array( 'write_permission_enabled' => false ),
					false,
				)
			)
		);
		self::assertFalse(
			$this->invokePrivate(
				$gateway,
				'write_permission_unblocks_tool',
				array(
					'content.get_item',
					array( 'write_permission_enabled' => true ),
					false,
				)
			)
		);
		self::assertTrue(
			$this->invokePrivate(
				$gateway,
				'write_permission_unblocks_tool',
				array(
					'plugin.incident.report',
					array( 'write_permission_enabled' => true ),
					true,
				)
			)
		);
		self::assertFalse(
			$this->invokePrivate(
				$gateway,
				'write_permission_unblocks_tool',
				array(
					'plugin.incident.list',
					array( 'write_permission_enabled' => true ),
					true,
				)
			)
		);
	}

	public function test_write_permission_preview_removes_confirmation_metadata(): void {
		$result = $this->invokePrivate(
			new AbilityExecutionGateway(),
			'write_permission_preview_payload',
			array(
				array(
					'dry_run'                   => true,
					'confirmation_required'     => true,
					'confirmation_token'        => 'token',
					'confirmation_expires_in'   => 300,
					'confirmation_instructions' => 'Repeat with token.',
				),
			)
		);

		self::assertFalse( $result['confirmation_required'] );
		self::assertSame( 'trusted_connection_direct_write', $result['confirmation_policy'] );
		self::assertTrue( $result['write_permission_enabled'] );
		self::assertArrayNotHasKey( 'confirmation_token', $result );
		self::assertArrayNotHasKey( 'confirmation_expires_in', $result );
		self::assertArrayNotHasKey( 'confirmation_instructions', $result );
	}

	public function test_trusted_write_result_removes_confirmation_metadata(): void {
		$result = $this->invokePrivate(
			new AbilityExecutionGateway(),
			'trusted_write_result_payload',
			array(
				array(
					'status'                    => 'updated',
					'confirmation_required'     => true,
					'confirmation_token'        => 'token',
					'confirmation_expires_in'   => 300,
					'confirmation_instructions' => 'Repeat with token.',
				),
				array(
					'access_level' => ConnectionAccessLevel::EXECUTE,
				),
			)
		);

		self::assertSame( 'updated', $result['status'] );
		self::assertFalse( $result['confirmation_required'] );
		self::assertSame( 'trusted_connection_direct_write', $result['confirmation_policy'] );
		self::assertTrue( $result['write_permission_enabled'] );
		self::assertSame( ConnectionAccessLevel::WRITE, $result['access_level'] );
		self::assertArrayNotHasKey( 'confirmation_token', $result );
		self::assertArrayNotHasKey( 'confirmation_expires_in', $result );
		self::assertArrayNotHasKey( 'confirmation_instructions', $result );
	}

	public function test_auth_challenge_response_includes_mcp_www_authenticate_metadata(): void {
		$response = $this->invokePrivate(
			new McpController(),
			'auth_challenge_response',
			array( 1, 'content:draft', 403, 'insufficient_scope' )
		);

		self::assertSame( 403, $response->get_status() );
		self::assertStringContainsString( 'insufficient_scope', (string) $response->header( 'WWW-Authenticate' ) );

		$data = $response->get_data();
		self::assertTrue( $data['result']['isError'] );
		self::assertArrayHasKey( 'mcp/www_authenticate', $data['result']['_meta'] );
	}

	public function test_initial_auth_challenge_requests_all_supported_scopes(): void {
		$controller = new McpController();
		$scope      = $this->invokePrivate( $controller, 'initial_auth_scope' );

		self::assertSame( implode( ' ', Helpers::supported_scopes() ), $scope );
		self::assertSame( 'content:read content:draft offline_access', $scope );

		$response = $this->invokePrivate(
			$controller,
			'auth_challenge_response',
			array( 1, $scope, 401, 'invalid_token' )
		);
		$header   = (string) $response->header( 'WWW-Authenticate' );
		$data     = $response->get_data();

		self::assertStringContainsString( 'scope="content:read content:draft offline_access"', $header );
		self::assertStringContainsString( 'scope="content:read content:draft offline_access"', $data['result']['_meta']['mcp/www_authenticate'][0] );
	}

	public function test_tools_call_scope_denial_has_legacy_and_current_response_goldens(): void {
		$auth   = array(
			'user_id'   => 1,
			'client_id' => 'golden-scope-client',
			'provider'  => 'chatgpt',
			'scopes'    => array(),
			'profile'   => 'full_access',
		);
		$legacy = new McpController();
		$this->setPrivateProperty( $legacy, 'request_auth', $auth );
		$legacy_response = $legacy->handle_rpc(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'jsonrpc' => '2.0',
					'id'      => 801,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'plugin_incident_report',
						'arguments' => array(
							'title'   => 'Scope golden',
							'summary' => 'A scope challenge has a stable envelope.',
						),
					),
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			)
		);
		$current         = new McpController();
		$this->setPrivateProperty( $current, 'request_auth', $auth );
		$current_response = $current->handle_rpc(
			$this->currentProtocolRequest(
				'tools/call',
				array(
					'name'      => 'plugin_incident_report',
					'arguments' => array(
						'title'   => 'Scope golden',
						'summary' => 'A scope challenge has a stable envelope.',
					),
				)
			)
		);

		foreach ( array( $legacy_response, $current_response ) as $response ) {
			self::assertInstanceOf( \WP_REST_Response::class, $response );
			self::assertSame( 403, $response->get_status() );
			self::assertStringContainsString( 'insufficient_scope', (string) $response->header( 'WWW-Authenticate' ) );
			self::assertSame( 'Authorization required.', $response->get_data()['result']['content'][0]['text'] ?? '' );
			self::assertTrue( $response->get_data()['result']['isError'] ?? false );
		}

		self::assertSame( McpController::PROTOCOL_VERSION_LEGACY, $legacy_response->header( 'MCP-Protocol-Version' ) );
		self::assertSame( McpController::PROTOCOL_VERSION_CURRENT, $current_response->header( 'MCP-Protocol-Version' ) );
		self::assertArrayNotHasKey( 'io.modelcontextprotocol/serverInfo', $legacy_response->get_data()['result']['_meta'] ?? array() );
		self::assertArrayHasKey( 'io.modelcontextprotocol/serverInfo', $current_response->get_data()['result']['_meta'] ?? array() );
	}

	public function test_tools_call_normal_policy_denial_has_legacy_and_current_response_goldens(): void {
		$auth = array(
			'user_id'   => 1,
			'client_id' => 'golden-paused-client',
			'provider'  => 'chatgpt',
			'scopes'    => Helpers::supported_scopes(),
			'profile'   => 'full_access',
		);
		AccessLockdown::set_paused( true );

		try {
			$legacy = new McpController();
			$this->setPrivateProperty( $legacy, 'request_auth', $auth );
			$legacy_response = $legacy->handle_rpc(
				new WP_REST_Request(
					array(),
					array(),
					array(
						'jsonrpc' => '2.0',
						'id'      => 805,
						'method'  => 'tools/call',
						'params'  => array(
							'name'      => 'plugin_incident_report',
							'arguments' => array(
								'title'   => 'Paused golden',
								'summary' => 'A normal policy denial must retain the tool result envelope.',
							),
						),
					),
					'POST',
					'/aculect-ai-companion/v1/mcp'
				)
			);
			$current         = new McpController();
			$this->setPrivateProperty( $current, 'request_auth', $auth );
			$current_response = $current->handle_rpc(
				$this->currentProtocolRequest(
					'tools/call',
					array(
						'name'      => 'plugin_incident_report',
						'arguments' => array(
							'title'   => 'Paused golden',
							'summary' => 'A normal policy denial must retain the tool result envelope.',
						),
					)
				)
			);
		} finally {
			AccessLockdown::set_paused( false );
		}

		foreach ( array( $legacy_response, $current_response ) as $response ) {
			self::assertIsArray( $response );
			self::assertArrayNotHasKey( 'error', $response );
			self::assertTrue( $response['result']['isError'] ?? false );
			self::assertSame( 'AI access is paused in Aculect AI Companion settings.', $response['result']['content'][0]['text'] ?? '' );
			self::assertInstanceOf( \stdClass::class, $response['result']['structuredContent'] ?? null );
		}

		self::assertArrayNotHasKey( 'io.modelcontextprotocol/serverInfo', $legacy_response['result']['_meta'] ?? array() );
		self::assertSame(
			'Aculect AI Companion MCP',
			$current_response['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? ''
		);
	}

	public function test_tools_call_success_has_legacy_and_current_response_goldens(): void {
		$auth   = array(
			'user_id'   => 1,
			'client_id' => 'golden-success-client',
			'provider'  => 'chatgpt',
			'scopes'    => array( 'content:read' ),
			'profile'   => 'full_access',
		);
		$legacy = new McpController();
		$this->setPrivateProperty( $legacy, 'request_auth', $auth );
		$legacy_response = $legacy->handle_rpc(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'jsonrpc' => '2.0',
					'id'      => 802,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => 'plugin_incident_list',
						'arguments' => array(),
					),
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			)
		);
		$current         = new McpController();
		$this->setPrivateProperty( $current, 'request_auth', $auth );
		$current_response = $current->handle_rpc(
			$this->currentProtocolRequest(
				'tools/call',
				array(
					'name'      => 'plugin_incident_list',
					'arguments' => array(),
				)
			)
		);

		foreach ( array( $legacy_response, $current_response ) as $response ) {
			self::assertIsArray( $response );
			self::assertArrayNotHasKey( 'error', $response );
			self::assertSame(
				wp_json_encode( $response['result']['structuredContent'] ?? array() ),
				$response['result']['content'][0]['text'] ?? ''
			);
			self::assertArrayNotHasKey( 'isError', $response['result'] ?? array() );
		}

		self::assertArrayNotHasKey( 'io.modelcontextprotocol/serverInfo', $legacy_response['result']['_meta'] ?? array() );
		self::assertSame(
			'Aculect AI Companion MCP',
			$current_response['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'] ?? ''
		);
	}

	/**
	 * Return every tools/list page as one manifest for descriptor assertions.
	 *
	 * @param McpController|null $controller Controller instance.
	 * @return array{tools: list<array<string, mixed>>}
	 */
	private function list_tools_manifest( ?McpController $controller = null ): array {
		$controller ??= new McpController();
		$tools        = array();
		$cursor       = '';

		do {
			$page   = '' === $cursor
				? $this->invokePrivate( $controller, 'list_tools' )
				: $this->invokePrivate( $controller, 'list_tools', array( $cursor ) );
			$tools  = array_merge( $tools, (array) ( $page['tools'] ?? array() ) );
			$cursor = (string) ( $page['nextCursor'] ?? '' );
		} while ( '' !== $cursor );

		return array( 'tools' => $tools );
	}

	/**
	 * Build a controller with isolated authoritative claim storage.
	 */
	private function controller_with_claim_store(): McpController {
		return new McpController(
			new AbilityExecutionGateway(
				safety: new ToolSafety( new InMemoryExecutionClaimStore() )
			)
		);
	}

	/**
	 * Exercise one plugin incident tool through the public JSON-RPC request path.
	 *
	 * @param McpController        $controller Controller under test.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param string               $name      Public tool name.
	 * @param array<string, mixed> $auth      OAuth context.
	 * @return array<string, mixed>
	 */
	private function pluginIncidentToolCall( McpController $controller, array $arguments, string $name, array $auth ): array {
		$result = $this->pluginIncidentRpcResult( $controller, $arguments, $name, $auth );

		self::assertIsArray( $result['structuredContent'] ?? null );

		return $result['structuredContent'];
	}

	/**
	 * Return one plugin incident JSON-RPC result, including authorization errors.
	 *
	 * @param McpController        $controller Controller under test.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param string               $name      Public tool name.
	 * @param array<string, mixed> $auth      OAuth context.
	 * @return array<string, mixed>
	 */
	private function pluginIncidentRpcResult( McpController $controller, array $arguments, string $name, array $auth ): array {
		$this->setPrivateProperty( $controller, 'request_auth', $auth );
		$response = $controller->handle_rpc(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'jsonrpc' => '2.0',
					'id'      => 376,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => $name,
						'arguments' => $arguments,
					),
				),
				'POST',
				'/aculect-ai-companion/v1/mcp'
			)
		);

		if ( $response instanceof \WP_REST_Response ) {
			$response = $response->get_data();
		}

		self::assertIsArray( $response );
		self::assertIsArray( $response['result'] ?? null );

		return $response['result'];
	}

	/**
	 * Assert a non-admin role cannot discover or execute either incident tool.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $role    Role name for assertion context.
	 */
	private function assertNonAdminIncidentToolsFailClosed( int $user_id, string $role ): void {
		$controller = new McpController();
		$auth       = array(
			'user_id'   => $user_id,
			'client_id' => 'incident-' . $role . '-client',
			'provider'  => 'chatgpt',
			'scopes'    => array( 'content:read' ),
			'profile'   => 'full_access',
		);

		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array( 'manage_options' );
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = $user_id;
		$this->setPrivateProperty( $controller, 'request_auth', $auth );
		$tool_names = array_column( $this->list_tools_manifest( $controller )['tools'], 'name' );

		self::assertNotContains( 'plugin_incident_list', $tool_names, $role );
		self::assertNotContains( 'plugin_incident_report', $tool_names, $role );

		$list = $this->pluginIncidentRpcResult( $controller, array(), 'plugin_incident_list', $auth );
		self::assertTrue( $list['isError'], $role );
		self::assertSame( 'This ability is not available for the connected WordPress capabilities.', $list['content'][0]['text'], $role );

		$report = $this->pluginIncidentRpcResult(
			$controller,
			array(
				'title'   => 'Unauthorized incident report',
				'summary' => 'A non-admin connection must fail closed before approval handling.',
			),
			'plugin_incident_report',
			$auth
		);
		self::assertTrue( $report['isError'], $role );
		self::assertSame( 'This ability is not available for the connected WordPress capabilities.', $report['content'][0]['text'], $role );
		self::assertSame( array(), get_option( 'aculect_ai_companion_incident_reports', array() ), $role );
	}

	/**
	 * Build a 2026-07-28 stateless MCP request.
	 *
	 * @param string                $method           JSON-RPC method.
	 * @param array<string, mixed>  $params           Request parameters.
	 * @param array<string, string> $header_overrides Header overrides.
	 * @param string                $metadata_version Metadata protocol version.
	 */
	private function currentProtocolRequest( string $method, array $params, array $header_overrides = array(), string $metadata_version = McpController::PROTOCOL_VERSION_CURRENT ): WP_REST_Request {
		$params['_meta'] = array(
			'io.modelcontextprotocol/protocolVersion'    => $metadata_version,
			'io.modelcontextprotocol/clientCapabilities' => array(),
			'io.modelcontextprotocol/clientInfo'         => array(
				'name'    => 'Aculect test client',
				'version' => '1.0.0',
			),
		);
		$headers         = array(
			'mcp-protocol-version' => McpController::PROTOCOL_VERSION_CURRENT,
			'mcp-method'           => $method,
		);
		if ( in_array( $method, array( 'tools/call', 'prompts/get' ), true ) ) {
			$headers['mcp-name'] = (string) ( $params['name'] ?? '' );
		} elseif ( 'resources/read' === $method ) {
			$headers['mcp-name'] = (string) ( $params['uri'] ?? '' );
		}

		return new WP_REST_Request(
			array(),
			array_merge( $headers, $header_overrides ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2026,
				'method'  => $method,
				'params'  => $params,
			),
			'POST',
			'/aculect-ai-companion/v1/mcp'
		);
	}

	/**
	 * Return the private transport validator result for a request.
	 *
	 * @param McpController         $controller Controller under test.
	 * @param array<string, string> $headers    Request headers.
	 * @return array<string, mixed>|null
	 */
	private function transportError( McpController $controller, array $headers ): ?array {
		$result = $this->invokePrivate(
			$controller,
			'transport_error',
			array( new WP_REST_Request( array(), $headers, array(), 'GET', '/aculect-ai-companion/v1/mcp' ) )
		);

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Return the private transport validator result for an exact request.
	 *
	 * @param McpController   $controller Controller under test.
	 * @param WP_REST_Request $request   Exact request.
	 * @return array<string, mixed>|null
	 */
	private function transportErrorForRequest( McpController $controller, WP_REST_Request $request ): ?array {
		$result = $this->invokePrivate( $controller, 'transport_error', array( $request ) );

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Read a private property for focused state-isolation coverage.
	 *
	 * @param object $object Object instance.
	 * @param string $name   Property name.
	 */
	private function privateProperty( object $object, string $name ): mixed {
		$reflection = new ReflectionProperty( $object, $name );
		$reflection->setAccessible( true );

		return $reflection->getValue( $object );
	}

	/**
	 * Invoke a private method for focused unit coverage without widening runtime API.
	 *
	 * @param object $object    Object instance.
	 * @param string $method    Method name.
	 * @param array  $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}

	/**
	 * Set a private property for focused unit coverage without widening runtime API.
	 *
	 * @param object $object Object instance.
	 * @param string $name   Property name.
	 * @param mixed  $value  Property value.
	 */
	private function setPrivateProperty( object $object, string $name, mixed $value ): void {
		$reflection = new ReflectionProperty( $object, $name );
		$reflection->setAccessible( true );
		$reflection->setValue( $object, $value );
	}

	/**
	 * Read a private property for focused composition coverage.
	 *
	 * @param object $object Object instance.
	 * @param string $name   Property name.
	 */
	private function privatePropertyValue( object $object, string $name ): mixed {
		$reflection = new ReflectionProperty( $object, $name );
		$reflection->setAccessible( true );
		return $reflection->getValue( $object );
	}
}
