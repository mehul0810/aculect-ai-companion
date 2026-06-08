<?php
/**
 * Tests for content indexing helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\ContentIndexer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies long-form block content is chunked for fast MCP retrieval.
 */
final class ContentIndexerTest extends TestCase {

	public function test_chunks_from_content_uses_heading_sections_and_keeps_block_markup(): void {
		$indexer = new ContentIndexer();
		$content = '<!-- wp:heading --><h2>Planning Workflow</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Use the indexed content before writing long form content.</p><!-- /wp:paragraph -->'
			. '<!-- wp:heading --><h2>Internal Links</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Find related content and choose useful anchors.</p><!-- /wp:paragraph -->';

		$chunks = $indexer->chunks_from_content( 42, $content );

		self::assertCount( 2, $chunks );
		self::assertSame( 'Planning Workflow', $chunks[0]['heading'] );
		self::assertSame( 'planning-workflow', $chunks[0]['anchor'] );
		self::assertStringContainsString( '<!-- wp:paragraph -->', $chunks[0]['block_markup'] );
		self::assertStringContainsString( 'Find related content', $chunks[1]['text'] );
		self::assertSame( 'section-002-internal-links', $chunks[1]['chunk_id'] );
	}

	public function test_links_from_content_extracts_anchor_text_and_urls(): void {
		$indexer = new ContentIndexer();
		$links   = $indexer->links_from_content(
			42,
			'<!-- wp:paragraph --><p>Read the <a href="https://example.com/internal-post/">internal guide</a>.</p><!-- /wp:paragraph -->'
		);

		self::assertCount( 1, $links );
		self::assertSame( 'https://example.com/internal-post/', $links[0]['target_url'] );
		self::assertSame( 'internal guide', $links[0]['anchor_text'] );
		self::assertSame( 0, $links[0]['target_id'] );
	}
}
