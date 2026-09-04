<?php
/**
 * Tests for Aculect Memory authorization across MCP discovery and execution.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\McpController;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use WP_REST_Request;

require_once dirname( __DIR__, 3 ) . '/fixtures/mcp-request-stubs.php';

/**
 * Verifies Aculect Memory writes retain a WordPress capability boundary.
 */
final class McpMemoryAuthorizationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_transients']       = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']      = array( 'manage_options' );
		$GLOBALS['aculect_ai_companion_test_filter_callbacks'] = array();
		$GLOBALS['aculect_ai_companion_test_current_user_id']  = 2;
		$GLOBALS['aculect_ai_companion_test_users']            = array(
			2 => (object) array(
				'ID'           => 2,
				'roles'        => array( 'subscriber' ),
				'display_name' => 'Sam Subscriber',
				'user_login'   => 'sam',
			),
		);
	}

	public function test_memory_writes_require_management_capability_across_discovery_and_execution(): void {
		$controller = new McpController();
		$scopes     = array( 'content:read', 'content:draft' );
		$auth       = array(
			'user_id'   => 2,
			'client_id' => 'memory-capability-client',
			'provider'  => 'chatgpt',
			'scopes'    => $scopes,
			'profile'   => 'full_access',
		);

		$tool_names = $this->all_tool_names( $controller, $scopes );
		self::assertNotContains( 'memory_save', $tool_names );
		self::assertNotContains( 'memory_bootstrap', $tool_names );

		$this->setPrivateProperty( $controller, 'request_auth', $auth );
		foreach ( array( 'memory_save', 'memory_bootstrap' ) as $tool_name ) {
			$result = $this->call_tool( $controller, $tool_name );

			self::assertTrue( $result['isError'] );
			self::assertSame( 'This ability is not available for the connected WordPress capabilities.', $result['content'][0]['text'] );
		}
	}

	/**
	 * Return all tool names from the paginated public discovery surface.
	 *
	 * @param McpController $controller Controller under test.
	 * @param string[]      $scopes     Granted OAuth scopes.
	 * @return string[]
	 */
	private function all_tool_names( McpController $controller, array $scopes ): array {
		$names  = array();
		$cursor = '';

		do {
			$page   = $controller->tools_list_page_for_user( 2, $scopes, $cursor, array( 'profile' => 'full_access' ) );
			$names  = array_merge( $names, array_column( $page['tools'], 'name' ) );
			$cursor = (string) ( $page['nextCursor'] ?? '' );
		} while ( '' !== $cursor );

		return $names;
	}

	/**
	 * Call one MCP tool through the public JSON-RPC path.
	 *
	 * @param McpController $controller Controller under test.
	 * @param string        $name       Public MCP tool name.
	 * @return array<string, mixed>
	 */
	private function call_tool( McpController $controller, string $name ): array {
		$response = $controller->handle_rpc(
			new WP_REST_Request(
				array(),
				array(),
				array(
					'jsonrpc' => '2.0',
					'id'      => 892,
					'method'  => 'tools/call',
					'params'  => array(
						'name'      => $name,
						'arguments' => array( 'dry_run' => true ),
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

	private function setPrivateProperty( object $object, string $name, mixed $value ): void {
		$property = new ReflectionProperty( $object, $name );
		$property->setAccessible( true );
		$property->setValue( $object, $value );
	}
}
