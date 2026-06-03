<?php
/**
 * Tests for block and pattern knowledge MCP abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\BlockKnowledgeAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies block metadata, pattern metadata, and Custom HTML guardrails.
 */
final class BlockKnowledgeAbilitiesTest extends TestCase {

	private BlockKnowledgeAbilities $abilities;

	protected function setUp(): void {
		parent::setUp();

		$this->abilities = new BlockKnowledgeAbilities();
		$this->registerTestBlocks();
		$this->registerTestPatterns();
	}

	public function test_list_blocks_returns_bounded_guidance_and_filters_by_search(): void {
		$result = $this->abilities->list_blocks(
			array(
				'search'   => 'body copy',
				'context'  => 'full',
				'per_page' => 10,
			)
		);

		self::assertSame( 1, $result['total'] );
		self::assertSame( 'core/paragraph', $result['items'][0]['name'] );
		self::assertSame( array( 'content' ), $result['items'][0]['attributes'] );
		self::assertContains( 'align', $result['items'][0]['supports'] );
		self::assertSame( array( 'core/html' ), $result['content_guidance']['never_use'] );
		self::assertStringContainsString( 'Never use the Custom HTML block', $result['content_guidance']['custom_html_rule'] );
	}

	public function test_custom_html_block_is_registered_but_never_allowed_for_generation(): void {
		$result = $this->abilities->get_block_info( array( 'name' => 'core/html' ) );

		self::assertSame( 'core/html', $result['name'] );
		self::assertFalse( $result['allowed_for_generation'] );
		self::assertStringContainsString( 'Never use the Custom HTML block', $result['guidance'] );
		self::assertStringContainsString( 'Never use the Custom HTML block', $result['content_guidance']['custom_html_rule'] );
	}

	public function test_list_patterns_returns_usage_metadata_without_full_content_by_default(): void {
		$result = $this->abilities->list_patterns(
			array(
				'category' => 'hero',
				'context'  => 'full',
			)
		);

		self::assertSame( 1, $result['total'] );
		self::assertSame( 'theme/hero', $result['items'][0]['name'] );
		self::assertSame( array( 'hero' ), $result['items'][0]['categories'] );
		self::assertSame( array( 'core/cover' ), $result['items'][0]['block_types'] );
		self::assertArrayHasKey( 'content_preview', $result['items'][0] );
		self::assertArrayNotHasKey( 'content', $result['items'][0] );
		self::assertTrue( $result['items'][0]['allowed_for_generation'] );
	}

	public function test_get_pattern_info_can_include_bounded_content_and_flags_custom_html_patterns(): void {
		$result = $this->abilities->get_pattern_info(
			array(
				'name'            => 'theme/hero',
				'include_content' => true,
			)
		);

		self::assertSame( 'theme/hero', $result['name'] );
		self::assertStringContainsString( '<!-- wp:cover', $result['content'] );
		self::assertFalse( $result['content_truncated'] );

		$html_pattern = $this->abilities->get_pattern_info( array( 'name' => 'theme/raw-html' ) );

		self::assertFalse( $html_pattern['allowed_for_generation'] );
		self::assertStringContainsString( 'Custom HTML block', $html_pattern['guidance'] );
	}

	public function test_validate_block_content_warns_when_custom_html_is_present(): void {
		$result = $this->abilities->validate_block_content(
			array(
				'content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --><!-- wp:html --><div>Raw</div><!-- /wp:html -->',
			)
		);

		$blocks = array_column( $result['blocks'], null, 'name' );

		self::assertFalse( $result['valid'] );
		self::assertTrue( $blocks['core/paragraph']['registered'] );
		self::assertTrue( $blocks['core/paragraph']['allowed_for_generation'] );
		self::assertTrue( $blocks['core/html']['registered'] );
		self::assertFalse( $blocks['core/html']['allowed_for_generation'] );
		self::assertContains( 'Never use the Custom HTML block (core/html). Use registered semantic blocks or patterns instead.', $result['warnings'] );
	}

	public function test_validate_block_content_warns_about_unregistered_blocks(): void {
		$result = $this->abilities->validate_block_content(
			array(
				'content' => '<!-- wp:missing/block --><p>Hello</p><!-- /wp:missing/block -->',
			)
		);

		$blocks = array_column( $result['blocks'], null, 'name' );

		self::assertFalse( $result['valid'] );
		self::assertFalse( $blocks['missing/block']['registered'] );
		self::assertContains( 'Block missing/block is not registered on this site.', $result['warnings'] );
	}

	private function registerTestBlocks(): void {
		\WP_Block_Type_Registry::get_instance()->unregister_all();
		\WP_Block_Type_Registry::get_instance()->register(
			'core/paragraph',
			array(
				'title'       => 'Paragraph',
				'description' => 'Body copy for normal page text.',
				'category'    => 'text',
				'keywords'    => array( 'copy', 'text' ),
				'attributes'  => array(
					'content' => array( 'type' => 'string' ),
				),
				'supports'    => array(
					'align'    => true,
					'inserter' => true,
				),
				'styles'      => array(
					array(
						'name'  => 'default',
						'title' => 'Default',
					),
				),
			)
		);
		\WP_Block_Type_Registry::get_instance()->register(
			'core/html',
			array(
				'title'       => 'Custom HTML',
				'description' => 'Raw HTML block.',
				'category'    => 'widgets',
				'supports'    => array(
					'inserter' => true,
				),
			)
		);
		\WP_Block_Type_Registry::get_instance()->register(
			'plugin/feature-card',
			array(
				'title'       => 'Feature Card',
				'description' => 'Card layout for one feature.',
				'category'    => 'design',
			)
		);
	}

	private function registerTestPatterns(): void {
		\WP_Block_Patterns_Registry::get_instance()->unregister_all();
		\WP_Block_Patterns_Registry::get_instance()->register(
			'theme/hero',
			array(
				'title'         => 'Hero',
				'description'   => 'Opening page section with a headline and action.',
				'categories'    => array( 'hero' ),
				'keywords'      => array( 'landing', 'header' ),
				'blockTypes'    => array( 'core/cover' ),
				'postTypes'     => array( 'page' ),
				'viewportWidth' => 1200,
				'content'       => '<!-- wp:cover --><!-- wp:heading --><h2>Build faster</h2><!-- /wp:heading --><!-- /wp:cover -->',
			)
		);
		\WP_Block_Patterns_Registry::get_instance()->register(
			'theme/raw-html',
			array(
				'title'       => 'Raw HTML CTA',
				'description' => 'Legacy pattern with raw HTML.',
				'categories'  => array( 'call-to-action' ),
				'content'     => '<!-- wp:html --><div class="legacy">Legacy</div><!-- /wp:html -->',
			)
		);
	}
}
