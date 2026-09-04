<?php
/**
 * Tests the MCP surface for taxonomy term operations.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\IntelligenceContext;
use Aculect\AICompanion\Connectors\MCP\IntelligenceRegistry;
use Aculect\AICompanion\Connectors\MCP\McpController;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/fixtures/mcp-request-stubs.php';

/**
 * Verifies taxonomy operations remain discoverable and client-safe.
 */
final class TaxonomyToolSurfaceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['aculect_ai_companion_test_current_user_id'] = 1;
		$GLOBALS['aculect_ai_companion_test_users'][1]        = (object) array(
			'ID'    => 1,
			'roles' => array( 'administrator' ),
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
	}

	public function test_taxonomy_operations_are_exposed_in_context_and_registry(): void {
		$site = ( new IntelligenceContext() )->site();

		self::assertSame( 'taxonomy_assign_terms', $site['operations']['content_groups']['assign_terms']['tool'] );
		self::assertSame( 'taxonomy_get_term', $site['operations']['content_groups']['get_term']['tool'] );
		self::assertSame( 'taxonomy_delete_term', $site['operations']['content_groups']['delete_term']['tool'] );
		self::assertTrue( ( new AbilitiesRegistry() )->is_known( 'taxonomy_assign_terms' ) );
		self::assertTrue( ( new AbilitiesRegistry() )->is_known( 'taxonomy_get_term' ) );
		self::assertTrue( ( new AbilitiesRegistry() )->is_known( 'taxonomy_delete_term' ) );
	}

	public function test_taxonomy_term_schemas_are_closed_and_require_identity(): void {
		$abilities     = new AbilitiesRegistry();
		$assign_schema = $abilities->input_schema( 'taxonomy_assign_terms' );
		$get_schema    = $abilities->input_schema( 'taxonomy_get_term' );
		$delete_schema = $abilities->input_schema( 'taxonomy_delete_term' );

		foreach ( array( $get_schema, $delete_schema ) as $schema ) {
			self::assertSame( 'object', $schema['type'] );
			self::assertFalse( $schema['additionalProperties'] );
			self::assertSame( array( 'taxonomy', 'term_id' ), $schema['required'] );
		}

		self::assertSame( 'object', $assign_schema['type'] );
		self::assertFalse( $assign_schema['additionalProperties'] );
		self::assertSame( array( 'post_id', 'taxonomy', 'terms' ), $assign_schema['required'] );
		self::assertSame( 'array', $assign_schema['properties']['terms']['type'] );
		self::assertSame( 100, $assign_schema['properties']['terms']['maxItems'] );
		self::assertSame( 'integer', $assign_schema['properties']['terms']['items']['type'] );
		self::assertArrayHasKey( 'expected_modified_gmt', $assign_schema['properties'] );
	}

	public function test_controller_manifest_contains_taxonomy_term_tools(): void {
		$manifest = ( new McpController() )->tool_manifest_for_user( 1 );
		$names    = array_column( $manifest['tools'], 'name' );

		self::assertContains( 'taxonomy_assign_terms', $names );
		self::assertContains( 'taxonomy_get_term', $names );
		self::assertContains( 'taxonomy_delete_term', $names );
	}
}
