<?php
/**
 * Tests for content indexing helpers.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\ContentIndexQueue;
use Aculect\AICompanion\Intelligence\ContentIndexer;
use PHPUnit\Framework\TestCase;

/**
 * Verifies long-form block content is chunked for fast MCP retrieval.
 */
final class ContentIndexerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_scheduled_events'] = array();
	}

	protected function tearDown(): void {
		ContentIndexer::delete_options();

		parent::tearDown();
	}

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

	public function test_queue_preserves_more_than_one_thousand_unique_posts(): void {
		$queue = new ContentIndexQueue();
		for ( $post_id = 1; $post_id <= 1001; ++$post_id ) {
			self::assertTrue( $queue->enqueue( $post_id ) );
		}

		self::assertSame( 1001, $queue->pending_count() );
	}

	public function test_new_enqueue_is_not_deleted_by_older_claim_acknowledgement(): void {
		$queue = new ContentIndexQueue();
		self::assertTrue( $queue->enqueue( 77 ) );

		$claims = $queue->claim( 1 );
		self::assertCount( 1, $claims );
		self::assertTrue( $queue->enqueue( 77 ) );

		self::assertFalse(
			$queue->acknowledge(
				77,
				$claims[0]['queue_token'],
				$claims[0]['lock_token']
			)
		);
		self::assertSame( 1, $queue->pending_count() );
	}

	public function test_legacy_shared_queue_migrates_without_losing_ids(): void {
		update_option( 'aculect_ai_companion_pending_index_ids', array( 5, 9, 5 ), false );

		$queue = new ContentIndexQueue();

		self::assertSame( 2, $queue->pending_count() );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_pending_index_ids', 'missing' ) );
	}

	public function test_failed_item_backoff_does_not_starve_later_queue_rows(): void {
		$queue = new ContentIndexQueue();
		self::assertTrue( $queue->enqueue( 1 ) );
		self::assertTrue( $queue->enqueue( 2 ) );

		$first = $queue->claim( 1 );
		self::assertSame( 1, $first[0]['object_id'] ?? 0 );
		$queue->retry( 1, $first[0]['queue_token'], $first[0]['lock_token'] );

		$second = $queue->claim( 1 );
		self::assertSame( 2, $second[0]['object_id'] ?? 0 );
		self::assertSame( 2, $queue->pending_count() );
	}

	public function test_expired_lease_is_replaced_and_work_can_be_reclaimed(): void {
		$queue         = new ContentIndexQueue();
		$expired_token = 'expired-worker:' . ( time() - 1 );
		self::assertTrue( $queue->enqueue( 88 ) );
		update_option( 'aculect_ai_companion_index_lock_88', $expired_token, false );

		$claims = $queue->claim( 1 );

		self::assertCount( 1, $claims );
		self::assertSame( 88, $claims[0]['object_id'] );
		self::assertNotSame( $expired_token, $claims[0]['lock_token'] );
	}

	public function test_production_generation_replacement_uses_atomic_upsert_and_cache_invalidation(): void {
		$original_options = $GLOBALS['aculect_ai_companion_test_options'];
		$original_wpdb    = $GLOBALS['wpdb'] ?? null;
		$wpdb             = new class() {
			public string $options = 'wp_options';
			/** @var list<array{query: string, args: array<int, mixed>}> */
			public array $prepared = array();
			public string $executed = '';

			public function prepare( string $query, mixed ...$args ): string {
				$this->prepared[] = array(
					'query' => $query,
					'args'  => $args,
				);
				return $query;
			}

			public function query( string $query ): int {
				$this->executed = $query;
				return 1;
			}
		};

		try {
			unset( $GLOBALS['aculect_ai_companion_test_options'] );
			$GLOBALS['aculect_ai_companion_test_cache_deletes'] = array();
			$GLOBALS['wpdb']                                    = $wpdb;
			$queue                                               = new ContentIndexQueue();
			$method                                              = new \ReflectionMethod( $queue, 'replace_queue_generation' );

			self::assertTrue( $method->invoke( $queue, 91, 'generation-91' ) );
			self::assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $wpdb->executed );
			self::assertSame(
				array(
					'aculect_ai_companion_pending_index_91',
					'generation-91',
				),
				$wpdb->prepared[0]['args']
			);
			self::assertContains(
				array(
					'key'   => 'aculect_ai_companion_pending_index_91',
					'group' => 'options',
				),
				$GLOBALS['aculect_ai_companion_test_cache_deletes']
			);
			self::assertContains(
				array(
					'key'   => 'notoptions',
					'group' => 'options',
				),
				$GLOBALS['aculect_ai_companion_test_cache_deletes']
			);
		} finally {
			$GLOBALS['aculect_ai_companion_test_options'] = $original_options;
			if ( null !== $original_wpdb ) {
				$GLOBALS['wpdb'] = $original_wpdb;
			} else {
				unset( $GLOBALS['wpdb'] );
			}
		}
	}
}
