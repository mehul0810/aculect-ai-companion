<?php
/**
 * Tests for MCP protocol responses that do not require a WordPress runtime.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use PHPUnit\Framework\TestCase;
use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\AccessLockdown;
use Aculect\AICompanion\Connectors\MCP\McpController;
use ReflectionMethod;

/**
 * Verifies public MCP tool payloads remain compatible with assistant clients.
 */
final class McpControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
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
		self::assertArrayHasKey('context', $public_schema['properties']);
		self::assertSame(array('compact', 'full'), $public_schema['properties']['context']['enum']);
	}

	public function test_expanded_tool_schemas_are_available(): void {
		$controller = new McpController();

		$media_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'media_upload_item' ) );
		self::assertSame('object', $media_schema['type']);
		self::assertSame(array('url'), $media_schema['required']);
		self::assertArrayHasKey('alt_text', $media_schema['properties']);

		$media_get_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'media_get_item' ) );
		self::assertSame(array('id'), $media_get_schema['required']);

		$media_update_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'media_update_item' ) );
		self::assertSame(array('id'), $media_update_schema['required']);
		self::assertArrayHasKey('post_id', $media_update_schema['properties']);

		$media_delete_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'media_delete_item' ) );
		self::assertSame(array('id'), $media_delete_schema['required']);

		$media_rename_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'media_rename_file' ) );
		self::assertSame(array('id', 'filename'), $media_rename_schema['required']);

		$create_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'content_create_item' ) );
		self::assertArrayHasKey('featured_media', $create_schema['properties']);

		$update_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'content_update_item' ) );
		self::assertArrayHasKey('featured_media', $update_schema['properties']);
		self::assertArrayHasKey('clear_featured_media', $update_schema['properties']);

		$seo_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'content_update_seo' ) );
		self::assertSame(array('id'), $seo_schema['required']);
		self::assertArrayHasKey('meta_title', $seo_schema['properties']);
		self::assertArrayHasKey('meta_description', $seo_schema['properties']);
		self::assertArrayHasKey('focus_keywords', $seo_schema['properties']);

		$comments_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'comments_update_item' ) );
		self::assertSame(array('id'), $comments_schema['required']);
		self::assertArrayHasKey('status', $comments_schema['properties']);

		$comments_list_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'comments_list_items' ) );
		self::assertArrayHasKey('date_after', $comments_list_schema['properties']);
		self::assertArrayHasKey('author_user_id', $comments_list_schema['properties']);
		self::assertArrayHasKey('context', $comments_list_schema['properties']);

		$comments_create_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'comments_create_item' ) );
		self::assertArrayHasKey('parent_id', $comments_create_schema['properties']);

		$comments_bulk_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'comments_bulk_update' ) );
		self::assertSame(array('ids', 'status'), $comments_bulk_schema['required']);

		$abilities_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'wp_abilities_run' ) );
		self::assertSame(array('id'), $abilities_schema['required']);
		self::assertArrayHasKey('arguments', $abilities_schema['properties']);

		$health_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'site_get_health' ) );
		self::assertSame('object', $health_schema['type']);
		self::assertInstanceOf(\stdClass::class, $health_schema['properties']);

		$create_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'content_create_item' ) );
		self::assertArrayHasKey('author', $create_schema['properties']);
		self::assertArrayHasKey('taxonomies', $create_schema['properties']);

		$update_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'content_update_item' ) );
		self::assertArrayHasKey('author', $update_schema['properties']);
		self::assertArrayHasKey('taxonomies', $update_schema['properties']);

		$term_image_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'taxonomy_set_term_image' ) );
		self::assertSame(array('taxonomy', 'term_id'), $term_image_schema['required']);
		self::assertArrayHasKey('image_id', $term_image_schema['properties']);
		self::assertArrayHasKey('clear_image', $term_image_schema['properties']);
	}

	public function test_write_tool_schemas_include_safety_controls(): void {
		$controller = new McpController();

		$write_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'content_update_item' ) );
		self::assertArrayHasKey( 'dry_run', $write_schema['properties'] );
		self::assertArrayHasKey( 'confirmation_token', $write_schema['properties'] );

		$read_schema = $this->invokePrivate( $controller, 'input_schema_for_tool', array( 'content_get_item' ) );
		self::assertArrayNotHasKey( 'dry_run', $read_schema['properties'] );
		self::assertArrayNotHasKey( 'confirmation_token', $read_schema['properties'] );
	}

	public function test_global_pause_blocks_tool_calls(): void {
		$controller = new McpController();

		self::assertFalse($this->invokePrivate($controller, 'is_access_paused'));

		AccessLockdown::set_paused(true);

		self::assertTrue($this->invokePrivate($controller, 'is_access_paused'));
	}

	public function test_disabled_tools_are_not_listed_and_are_blocked_for_cached_clients(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids(array('content.list_items'));

		$result = $this->invokePrivate(new McpController(), 'list_tools');
		$names  = array_column($result['tools'], 'name');

		self::assertContains('content_list_items', $names);
		self::assertNotContains('content_update_item', $names);
		self::assertSame('', $this->invokePrivate(new McpController(), 'tool_call_error', array('content.list_items', $registry)));
		self::assertSame('tool_disabled', $this->invokePrivate(new McpController(), 'tool_call_error', array('content.update_item', $registry)));
		self::assertSame('unknown_tool', $this->invokePrivate(new McpController(), 'tool_call_error', array('content.not_real', $registry)));
	}

	public function test_scope_checks_require_every_required_scope(): void {
		$controller = new McpController();

		self::assertTrue($this->invokePrivate($controller, 'has_scopes', array(array('content:read', 'content:draft'), array('content:draft'))));
		self::assertFalse($this->invokePrivate($controller, 'has_scopes', array(array('content:read'), array('content:draft'))));
	}

	public function test_auth_challenge_response_includes_mcp_www_authenticate_metadata(): void {
		$response = $this->invokePrivate(
			new McpController(),
			'auth_challenge_response',
			array(1, 'content:draft', 403, 'insufficient_scope')
		);

		self::assertSame(403, $response->get_status());
		self::assertStringContainsString('insufficient_scope', (string) $response->header('WWW-Authenticate'));

		$data = $response->get_data();
		self::assertTrue($data['result']['isError']);
		self::assertArrayHasKey('mcp/www_authenticate', $data['result']['_meta']);
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
