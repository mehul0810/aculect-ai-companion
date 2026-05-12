<?php
/**
 * Tests for MCP protocol responses that do not require a WordPress runtime.
 *
 * @package Quark\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Quark\Tests\Unit\Connectors\MCP;

use PHPUnit\Framework\TestCase;
use Quark\Connectors\MCP\AbilitiesRegistry;
use Quark\Connectors\MCP\McpController;
use ReflectionMethod;

/**
 * Verifies public MCP tool payloads remain compatible with assistant clients.
 */
final class McpControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['quark_test_options'] = array();
	}

	public function test_tools_list_exposes_safe_public_tool_names(): void {
		$result = $this->invokePrivate(new McpController(), 'list_tools');

		self::assertIsArray($result);
		self::assertArrayHasKey('tools', $result);
		self::assertIsArray($result['tools']);
		self::assertNotEmpty($result['tools']);

		$registry = new AbilitiesRegistry();

		foreach ( $result['tools'] as $tool ) {
			self::assertIsArray($tool);
			self::assertArrayHasKey('name', $tool);
			self::assertIsString($tool['name']);
			self::assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $tool['name']);
			self::assertTrue($registry->is_known($tool['name']));
		}
	}

	public function test_input_schema_accepts_public_tool_name_aliases(): void {
		$controller = new McpController();

		$dotted_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'content.list_items' ) );
		$public_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'content_list_items' ) );

		self::assertSame($dotted_schema, $public_schema);
		self::assertSame('object', $public_schema['type']);
		self::assertArrayHasKey('post_type', $public_schema['properties']);
	}

	public function test_expanded_tool_schemas_are_available(): void {
		$controller = new McpController();

		$media_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'media_upload_item' ) );
		self::assertSame('object', $media_schema['type']);
		self::assertSame(array('url'), $media_schema['required']);
		self::assertArrayHasKey('alt_text', $media_schema['properties']);

		$comments_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'comments_update_item' ) );
		self::assertSame(array('id'), $comments_schema['required']);
		self::assertArrayHasKey('status', $comments_schema['properties']);

		$abilities_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'wp_abilities_run' ) );
		self::assertSame(array('id'), $abilities_schema['required']);
		self::assertArrayHasKey('arguments', $abilities_schema['properties']);
	}

	/**
	 * Invoke a private method for focused unit coverage without widening runtime API.
	 *
	 * @param object       $object    Object instance.
	 * @param string       $method    Method name.
	 * @param list<mixed>  $arguments Method arguments.
	 * @return mixed
	 */
	private function invokePrivate( object $object, string $method, array $arguments = array() ): mixed {
		$reflection = new ReflectionMethod( $object, $method );

		return $reflection->invokeArgs( $object, $arguments );
	}
}
