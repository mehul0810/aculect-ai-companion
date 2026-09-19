<?php
/**
 * Tests for the admin-only MCP surface catalog.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\IntelligenceRegistry;
use Aculect\AICompanion\Connectors\MCP\McpSurfaceCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Verifies catalog completeness without changing authorization policy.
 */
final class McpSurfaceCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array();
	}

	public function test_catalog_contains_the_exact_registry_union_once(): void {
		$abilities    = new AbilitiesRegistry();
		$intelligence = new IntelligenceRegistry();
		$catalog      = ( new McpSurfaceCatalog() )->public_definitions();
		$actual_ids   = array_column( $catalog, 'id' );
		$expected_ids = array_merge( array_keys( $abilities->modules() ), array_keys( $intelligence->modules() ) );

		sort( $expected_ids );
		self::assertSame( $expected_ids, $actual_ids );
		self::assertSame( $actual_ids, array_values( array_unique( $actual_ids ) ) );
		self::assertCount( count( $catalog ), array_unique( array_column( $catalog, 'toolName' ) ) );
	}

	public function test_catalog_classifies_registry_surfaces_without_using_policy_names(): void {
		$catalog = array_column( ( new McpSurfaceCatalog() )->public_definitions(), null, 'id' );

		self::assertSame( 'intelligence', $catalog['intelligence.site.get_context']['surfaceType'] );
		self::assertFalse( $catalog['intelligence.site.get_context']['configurable'] );
		self::assertSame( 'context', $catalog['intelligence.site.get_context']['policyState'] );

		self::assertSame( 'workflow', $catalog['content_workflow.create_draft']['surfaceType'] );
		self::assertSame( 'composed', $catalog['content_workflow.create_draft']['policyState'] );

		self::assertSame( 'ability', $catalog['content.update_item']['surfaceType'] );
		self::assertTrue( $catalog['content.update_item']['configurable'] );
		self::assertSame( array( 'content:draft' ), $catalog['content.update_item']['requiredScopes'] );
		self::assertSame( 'ability', $catalog['taxonomy.assign_terms']['surfaceType'] );
		self::assertTrue( $catalog['taxonomy.assign_terms']['configurable'] );
		self::assertSame( 'taxonomy_assign_terms', $catalog['taxonomy.assign_terms']['toolName'] );
		self::assertSame( array( 'content:draft' ), $catalog['taxonomy.assign_terms']['requiredScopes'] );

		self::assertSame( 'ability', $catalog['search']['surfaceType'] );
		self::assertTrue( $catalog['search']['coreDefault'] );
		self::assertSame( 'core-default', $catalog['search']['policyState'] );
	}

	public function test_catalog_does_not_mutate_configurable_policy(): void {
		$registry = new AbilitiesRegistry();
		$before   = $registry->enabled_ids();

		( new McpSurfaceCatalog() )->public_definitions();

		self::assertSame( $before, $registry->enabled_ids() );
	}
}
