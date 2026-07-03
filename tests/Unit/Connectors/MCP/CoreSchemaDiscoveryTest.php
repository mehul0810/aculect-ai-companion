<?php
/**
 * Tests for WordPress REST/core schema discovery.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\CoreSchemaDiscovery;
use Aculect\AICompanion\Connectors\MCP\McpController;
use Aculect\AICompanion\Connectors\MCP\McpToolAvailability;
use PHPUnit\Framework\TestCase;

/**
 * Verifies bounded read-only WordPress schema discovery.
 */
final class CoreSchemaDiscoveryTest extends TestCase {

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
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_post_types']      = array(
			'post'    => new \WP_Post_Type( 'post', array( 'label' => 'Posts', 'rest_base' => 'posts' ) ),
			'book'    => new \WP_Post_Type(
				'book',
				array(
					'label'     => 'Books',
					'rest_base' => 'books',
					'cap'       => array(
						'edit_posts'    => 'edit_books',
						'create_posts'  => 'edit_books',
						'publish_posts' => 'publish_books',
						'delete_posts'  => 'delete_books',
					),
				)
			),
			'private' => new \WP_Post_Type( 'private', array( 'public' => false, 'show_ui' => false, 'show_in_rest' => false ) ),
		);
		$GLOBALS['aculect_ai_companion_test_taxonomies']      = array(
			'category'   => new \WP_Taxonomy( 'category', array( 'label' => 'Categories', 'hierarchical' => true, 'rest_base' => 'categories', 'object_type' => array( 'post', 'book' ) ) ),
			'book_genre' => new \WP_Taxonomy( 'book_genre', array( 'label' => 'Book Genres', 'rest_base' => 'book-genres', 'object_type' => array( 'book' ) ) ),
		);
		$GLOBALS['aculect_ai_companion_test_post_statuses']   = array(
			'publish' => (object) array( 'name' => 'publish', 'label' => 'Published', 'public' => true, 'private' => false, 'protected' => false, 'internal' => false, 'show_in_admin_all_list' => true ),
			'private' => (object) array( 'name' => 'private', 'label' => 'Private', 'public' => false, 'private' => true, 'protected' => false, 'internal' => false, 'show_in_admin_all_list' => true ),
		);
		$GLOBALS['aculect_ai_companion_test_post_type_supports'] = array(
			'post' => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author', 'revisions' ),
			'book' => array( 'title', 'editor', 'thumbnail' ),
		);
		$GLOBALS['aculect_ai_companion_test_rest_routes']     = array(
			'/wp/v2/posts'                             => array(
				array(
					'methods' => array( 'GET' => true, 'POST' => true ),
					'args'    => array( 'search' => array(), 'status' => array() ),
				),
			),
			'/wp/v2/posts/(?P<parent>[\d]+)/revisions' => array( array( 'methods' => 'GET' ) ),
			'/wp/v2/posts/(?P<parent>[\d]+)/autosaves' => array( array( 'methods' => 'GET, POST' ) ),
			'/wp/v2/books'                             => array( array( 'methods' => array( 'GET' => true ) ) ),
			'/wp/v2/taxonomies'                        => array( array( 'methods' => 'GET' ) ),
			'/wp/v2/templates'                         => array( array( 'methods' => 'GET' ) ),
			'/aculect-ai-companion/v1/private'         => array( array( 'methods' => 'GET' ) ),
		);
	}

	public function test_manifest_returns_compact_core_schema_without_private_route_callbacks(): void {
		$result = ( new CoreSchemaDiscovery() )->manifest();

		self::assertSame( '2026-07-03', $result['schema_version'] );
		self::assertTrue( $result['rest']['available'] );
		self::assertContains( 'wp/v2', $result['rest']['namespaces'] );
		self::assertNotContains( '/aculect-ai-companion/v1/private', array_column( $result['rest']['routes'], 'route' ) );
		self::assertArrayNotHasKey( 'callback', $result['rest']['routes'][0] );

		$post_types = array_column( $result['post_types'], null, 'name' );
		self::assertArrayHasKey( 'post', $post_types );
		self::assertArrayHasKey( 'book', $post_types );
		self::assertArrayNotHasKey( 'private', $post_types );
		self::assertSame( 'books', $post_types['book']['rest_base'] );
		self::assertSame( array( 'category', 'book_genre' ), $post_types['book']['taxonomies'] );

		$taxonomies = array_column( $result['taxonomies'], null, 'name' );
		self::assertArrayHasKey( 'book_genre', $taxonomies );
		self::assertSame( array( 'book' ), $taxonomies['book_genre']['object_types'] );
		self::assertTrue( $result['features']['revisions']['post']['supports'] );
		self::assertTrue( $result['features']['revisions']['post']['rest_route'] );
		self::assertTrue( $result['features']['site_editor']['template_routes'] );
	}

	public function test_manifest_filters_capability_hints_without_exposing_private_data(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'edit_books', 'publish_books', 'delete_books', 'manage_categories' );

		$result     = ( new CoreSchemaDiscovery() )->manifest();
		$post_types = array_column( $result['post_types'], null, 'name' );
		$taxonomies = array_column( $result['taxonomies'], null, 'name' );

		self::assertFalse( $post_types['book']['capabilities']['can_edit'] );
		self::assertFalse( $post_types['book']['capabilities']['can_create'] );
		self::assertFalse( $post_types['book']['capabilities']['can_publish'] );
		self::assertFalse( $post_types['book']['editable_fields']['content'] );
		self::assertFalse( $taxonomies['book_genre']['capabilities']['can_manage'] );
		self::assertArrayNotHasKey( 'options', $result );
		self::assertArrayNotHasKey( 'nonces', $result );
	}

	public function test_manifest_reports_missing_core_route_surfaces(): void {
		$GLOBALS['aculect_ai_companion_test_rest_routes'] = array();

		$result      = ( new CoreSchemaDiscovery() )->manifest();
		$diagnostics = array_column( $result['diagnostics'], null, 'id' );

		self::assertSame( array(), $result['rest']['routes'] );
		self::assertArrayHasKey( 'no_core_rest_routes', $diagnostics );
		self::assertArrayHasKey( 'site_editor_routes_unavailable', $diagnostics );
		self::assertFalse( $result['features']['site_editor']['template_routes'] );
	}

	public function test_manifest_requires_basic_read_capability(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'read' );

		$result = ( new CoreSchemaDiscovery() )->manifest();

		self::assertSame( 'forbidden', $result['error'] );
	}

	public function test_core_schema_discovery_is_default_active_and_descriptor_is_read_only(): void {
		$registry = new AbilitiesRegistry();
		$registry->save_enabled_ids( array() );

		$core_defaults = array_column( $registry->core_default_public_definitions(), null, 'id' );
		self::assertArrayHasKey( 'core_schema.discover', $core_defaults );
		self::assertTrue( $registry->is_core_default( 'core_schema_discover' ) );
		self::assertFalse( $registry->is_configurable( 'core_schema.discover' ) );

		$operations = ( new McpToolAvailability() )->operations_manifest_for_user( 1, $registry, array( 'content:read' ) );
		self::assertTrue( $operations['content']['discover_core_schema']['available'] );
		self::assertTrue( $operations['content']['discover_core_schema']['core_default'] );
		self::assertSame( 'core_schema_discover', $operations['content']['discover_core_schema']['tool'] );

		$tools = array_column( ( new McpController() )->tool_manifest_for_current_user()['tools'], null, 'name' );
		self::assertArrayHasKey( 'core_schema_discover', $tools );
		self::assertTrue( $tools['core_schema_discover']['annotations']['readOnlyHint'] );
		self::assertSame( array( 'content:read' ), $tools['core_schema_discover']['securitySchemes'][0]['scopes'] );
		self::assertArrayHasKey( 'post_types', $tools['core_schema_discover']['outputSchema']['properties'] );
		self::assertArrayHasKey( 'rest', $tools['core_schema_discover']['outputSchema']['properties'] );
	}
}
