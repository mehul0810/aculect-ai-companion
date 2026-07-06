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

		$this->abilities                                   = new BlockKnowledgeAbilities();
		$GLOBALS['aculect_ai_companion_test_denied_caps']  = array();
		$GLOBALS['aculect_ai_companion_test_blog_id']      = 7;
		$GLOBALS['aculect_ai_companion_test_is_multisite'] = true;
		$GLOBALS['aculect_ai_companion_test_stylesheet']   = 'pattern-theme';
		$GLOBALS['aculect_ai_companion_test_template']     = 'pattern-parent';
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

	public function test_list_blocks_can_filter_layout_generation_family(): void {
		$result = $this->abilities->list_blocks(
			array(
				'purpose'  => 'layout',
				'context'  => 'compact',
				'per_page' => 10,
			)
		);

		$names = array_column( $result['items'], 'name' );

		self::assertContains( 'core/columns', $names );
		self::assertContains( 'core/group', $names );
		self::assertContains( 'plugin/feature-card', $names );
		self::assertContains( 'layout', $result['items'][0]['families'] );
		self::assertStringContainsString( 'image, screenshot, grid', $result['content_guidance']['layout_rule'] );
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
		self::assertSame( 'theme/hero', $result['items'][0]['id'] );
		self::assertSame( 'theme', $result['items'][0]['source'] );
		self::assertSame( array( 'hero' ), $result['items'][0]['categories'] );
		self::assertSame( array( 'hero' => 'Hero' ), $result['items'][0]['category_labels'] );
		self::assertSame( array( 'core/cover' ), $result['items'][0]['block_types'] );
		self::assertSame( array( 'page' ), $result['items'][0]['post_types'] );
		self::assertSame( array( 'wp_template' ), $result['items'][0]['template_types'] );
		self::assertSame( 1200, $result['items'][0]['viewport_width'] );
		self::assertTrue( $result['items'][0]['content_available'] );
		self::assertSame( 2, $result['items'][0]['content_block_count'] );
		self::assertSame( array( 'core/cover', 'core/heading' ), $result['items'][0]['content_blocks'] );
		self::assertArrayHasKey( 'content_preview', $result['items'][0] );
		self::assertArrayNotHasKey( 'content', $result['items'][0] );
		self::assertTrue( $result['items'][0]['allowed_for_generation'] );
		self::assertSame( 7, $result['site_context']['blog_id'] );
		self::assertTrue( $result['site_context']['multisite'] );
		self::assertSame( 'pattern-theme', $result['site_context']['stylesheet'] );
		self::assertSame( 'current_site', $result['site_context']['registry'] );
	}

	public function test_list_patterns_is_bounded_and_deterministic(): void {
		foreach ( range( 1, 110 ) as $index ) {
			\WP_Block_Patterns_Registry::get_instance()->register(
				sprintf( 'plugin/pattern-%03d', $index ),
				array(
					'title'      => sprintf( 'Pattern %03d', $index ),
					'categories' => array( 'query' ),
					'source'     => 'plugin',
					'content'    => '<!-- wp:paragraph --><p>Pattern</p><!-- /wp:paragraph -->',
				)
			);
		}

		$result = $this->abilities->list_patterns(
			array(
				'per_page' => 250,
				'page'     => 1,
			)
		);

		self::assertSame( 112, $result['total'] );
		self::assertSame( 100, $result['per_page'] );
		self::assertCount( 100, $result['items'] );
		self::assertSame( 'plugin/pattern-001', $result['items'][0]['name'] );
		self::assertSame( 'plugin/pattern-100', $result['items'][99]['name'] );
		self::assertArrayNotHasKey( 'content', $result['items'][0] );
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
		self::assertSame( array( 'core/cover', 'core/heading' ), $result['content_blocks'] );
		self::assertSame( 'pattern-parent', $result['site_context']['template'] );

		$html_pattern = $this->abilities->get_pattern_info( array( 'name' => 'theme/raw-html' ) );

		self::assertFalse( $html_pattern['allowed_for_generation'] );
		self::assertTrue( $html_pattern['contains_custom_html'] );
		self::assertStringContainsString( 'Custom HTML block', $html_pattern['guidance'] );
	}

	public function test_get_pattern_info_returns_clear_error_when_pattern_disappears(): void {
		$result = $this->abilities->get_pattern_info( array( 'name' => 'theme/missing' ) );

		self::assertSame( 'not_found', $result['error'] );
		self::assertSame( 'Pattern is not registered on this site.', $result['message'] );
	}

	public function test_pattern_abilities_require_read_capability(): void {
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'read' );

		$list = $this->abilities->list_patterns();
		$get  = $this->abilities->get_pattern_info( array( 'name' => 'theme/hero' ) );

		self::assertSame( 'forbidden', $list['error'] );
		self::assertSame( 'forbidden', $get['error'] );
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

	public function test_validate_block_content_rejects_registered_blocks_with_mismatched_structure(): void {
		$result = $this->abilities->validate_block_content(
			array(
				'content' => '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Hello</p><!-- /wp:group --><!-- /wp:paragraph --></div>',
			)
		);

		self::assertFalse( $result['valid'] );
		self::assertFalse( $result['structure']['valid'] );
		self::assertSame( 2, $result['structure']['tokenized_block_count'] );
		self::assertContains( 'Block markup contains malformed block-comment structure. Review the reported structure issues and retry.', $result['warnings'] );
		self::assertSame( 'mismatched_closing_block', $result['structure']['issues'][0]['code'] );
		self::assertSame( 'core/group', $result['structure']['issues'][0]['block'] );
		self::assertSame( 'core/paragraph', $result['structure']['issues'][0]['expected_block'] );
		self::assertStringContainsString( 'valid serialized block structure', $result['message'] );
	}

	public function test_validate_block_content_rejects_registered_blocks_with_missing_closer(): void {
		$result = $this->abilities->validate_block_content(
			array(
				'content' => '<!-- wp:paragraph --><p>Hello</p>',
			)
		);

		self::assertFalse( $result['valid'] );
		self::assertFalse( $result['structure']['valid'] );
		self::assertSame( 'missing_closing_block', $result['structure']['issues'][0]['code'] );
		self::assertSame( 'core/paragraph', $result['structure']['issues'][0]['block'] );
	}

	public function test_validate_block_content_warns_when_layout_intent_has_no_layout_blocks(): void {
		$result = $this->abilities->validate_block_content(
			array(
				'content'                 => '<!-- wp:paragraph --><p>This reads like a blog post.</p><!-- /wp:paragraph -->',
				'content_mode'            => 'visual_layout',
				'layout_intent'           => 'Use a three-column grid of cards.',
				'expected_block_families' => array( 'layout' ),
			)
		);

		self::assertTrue( $result['valid'] );
		self::assertContains( 'Layout intent was provided, but no layout container blocks were detected. Use registered patterns or layout blocks such as core/group, core/columns, core/cover, or core/media-text instead of paragraph-only article markup.', $result['warnings'] );
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
		foreach ( array( 'core/columns', 'core/column', 'core/group', 'core/cover', 'core/media-text' ) as $name ) {
			\WP_Block_Type_Registry::get_instance()->register(
				$name,
				array(
					'title'       => $name,
					'description' => 'Layout block for visual page composition.',
					'category'    => 'design',
					'supports'    => array(
						'inserter' => true,
					),
				)
			);
		}
	}

	private function registerTestPatterns(): void {
		\WP_Block_Pattern_Categories_Registry::get_instance()->unregister_all();
		\WP_Block_Pattern_Categories_Registry::get_instance()->register(
			'hero',
			array(
				'label' => 'Hero',
			)
		);
		\WP_Block_Pattern_Categories_Registry::get_instance()->register(
			'query',
			array(
				'label' => 'Query',
			)
		);

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
				'templateTypes' => array( 'wp_template' ),
				'source'        => 'theme',
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
