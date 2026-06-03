<?php
/**
 * Tests for internal Aculect intelligence MCP tools.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\AbilitiesRegistry;
use Aculect\AICompanion\Connectors\MCP\IntelligenceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Verifies always-on intelligence tools stay separate from user-managed abilities.
 */
final class IntelligenceRegistryTest extends TestCase {

	private IntelligenceRegistry $registry;

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options'] = array(
			'blogname'        => 'Aculect Test Site',
			'blogdescription' => 'A test site for intelligence context.',
		);
		$this->registry                               = new IntelligenceRegistry();
		$this->register_test_blocks();
	}

	public function test_intelligence_tools_are_read_only_and_claude_safe(): void {
		$expected = array(
			'intelligence.site.get_context',
			'intelligence.content.get_context',
			'intelligence.developer.get_context',
			'intelligence.brand.get_context',
			'intelligence.blocks.list_available',
			'intelligence.blocks.get_info',
			'intelligence.patterns.list_available',
			'intelligence.patterns.get_info',
			'intelligence.content.validate_blocks',
		);

		foreach ( $expected as $id ) {
			$module    = $this->registry->module( $id );
			$tool_name = $this->registry->tool_name( $id );

			self::assertNotNull( $module );
			self::assertSame( 'Aculect Intelligence', $module->group() );
			self::assertSame( array( 'content:read' ), $module->required_scopes() );
			self::assertTrue( $module->is_read_only() );
			self::assertMatchesRegularExpression( '/^[a-zA-Z0-9_-]{1,64}$/', $tool_name );
			self::assertSame( $id, $this->registry->internal_id( $tool_name ) );
		}
	}

	public function test_intelligence_aliases_preserve_cached_client_calls(): void {
		self::assertSame( 'intelligence.brand.get_context', $this->registry->internal_id( 'brand_get_profile' ) );
		self::assertSame( 'intelligence.blocks.list_available', $this->registry->internal_id( 'blocks.list_available' ) );
		self::assertSame( 'intelligence.patterns.get_info', $this->registry->internal_id( 'patterns_get_info' ) );
		self::assertSame( 'intelligence.content.validate_blocks', $this->registry->internal_id( 'content_validate_blocks' ) );
	}

	public function test_intelligence_tools_are_not_user_managed_abilities(): void {
		$abilities = new AbilitiesRegistry();

		foreach ( array_keys( $this->registry->modules() ) as $id ) {
			self::assertFalse( $abilities->is_known( $id ) );
		}

		self::assertFalse( $abilities->is_known( 'brand_get_profile' ) );
		self::assertFalse( $abilities->is_known( 'blocks_list_available' ) );
	}

	public function test_intelligence_execution_returns_context_and_block_guardrails(): void {
		$site = $this->registry->execute( 'intelligence.site.get_context', array() );

		self::assertSame( 'site', $site['type'] );
		self::assertSame( 'Aculect Test Site', $site['site']['name'] );
		self::assertFalse( $site['connector']['managed_as_user_ability'] );
		self::assertContains( 'core/html', $site['guidance']['never_use'] );

		$brand = $this->registry->execute( 'brand_get_profile', array() );

		self::assertSame( 'brand', $brand['type'] );
		self::assertSame( 'Aculect Test Site', $brand['profile']['site']['name']['value'] );

		$validation = $this->registry->execute(
			'content_validate_blocks',
			array(
				'content' => '<!-- wp:html --><div>Raw</div><!-- /wp:html -->',
			)
		);

		self::assertFalse( $validation['valid'] );
		self::assertContains( 'core/html', $validation['content_guidance']['never_use'] );
	}

	private function register_test_blocks(): void {
		\WP_Block_Type_Registry::get_instance()->unregister_all();
		\WP_Block_Type_Registry::get_instance()->register(
			'core/html',
			array(
				'title'       => 'Custom HTML',
				'description' => 'Raw HTML block.',
				'category'    => 'widgets',
			)
		);
	}
}
