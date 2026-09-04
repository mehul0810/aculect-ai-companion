<?php
/**
 * Content index save flow regression tests.
 *
 * @package Aculect\AICompanion\Tests\Unit
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit;

require_once dirname( __DIR__ ) . '/Support/PluginContentIndexWpdb.php';

use Aculect\AICompanion\Intelligence\ContentIndexRepository;
use Aculect\AICompanion\Intelligence\ContentIndexQueue;
use Aculect\AICompanion\Intelligence\ContentIndexer;
use Aculect\AICompanion\Intelligence\Database\Installer;
use Aculect\AICompanion\Plugin;
use Aculect\AICompanion\Tests\Support\PluginContentIndexWpdb;
use PHPUnit\Framework\TestCase;
use WP_Post;
use WP_Taxonomy;
use WP_Term;

/**
 * Verifies one editor save settles to a final refreshed content index row.
 */
final class PluginContentIndexSaveFlowTest extends TestCase {

	private object|null $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new PluginContentIndexWpdb();

		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_posts']            = array();
		$GLOBALS['aculect_ai_companion_test_post_meta']        = array();
		$GLOBALS['aculect_ai_companion_test_post_terms']       = array();
		$GLOBALS['aculect_ai_companion_test_scheduled_events'] = array();
		$GLOBALS['aculect_ai_companion_test_failed_option_updates']        = array();
		$GLOBALS['aculect_ai_companion_test_failed_option_adds']           = array();
		$GLOBALS['aculect_ai_companion_test_failed_option_deletes']        = array();
		$GLOBALS['aculect_ai_companion_test_schedule_failure']             = false;
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks']       = array();
		$GLOBALS['aculect_ai_companion_test_schedule_literal_false_hooks'] = array();
		$GLOBALS['aculect_ai_companion_test_before_content_replace'] = null;
		$GLOBALS['aculect_ai_companion_test_after_content_replace']  = null;
		$GLOBALS['aculect_ai_companion_test_after_content_delete']   = null;
		$GLOBALS['aculect_ai_companion_test_taxonomies']       = array(
			'category' => new WP_Taxonomy(
				'category',
				array(
					'label'       => 'Categories',
					'hierarchical' => true,
					'object_type' => array( 'post' ),
				)
			),
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'],
			$GLOBALS['aculect_ai_companion_test_schedule_literal_false_hooks']
		);

		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	public function test_save_followed_by_term_and_meta_changes_queues_one_final_refresh_and_reindexes_final_terms(): void {
		$post_id = 34101;

		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Content Index Save Flow',
				'post_name'         => 'content-index-save-flow',
				'post_excerpt'      => 'Initial excerpt.',
				'post_content'      => '<!-- wp:paragraph --><p>Initial indexed body copy.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 09:00:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_post_terms'][ $post_id ] = array(
			'category' => array(
				new WP_Term(
					array(
						'term_id'  => 11,
						'taxonomy' => 'category',
						'name'     => 'Alpha',
						'slug'     => 'alpha',
					)
				),
			),
		);

		$plugin = Plugin::instance();
		$plugin->handle_content_index_save( $post_id, get_post( $post_id ), true );

		self::assertSame( 0, $GLOBALS['wpdb']->replace_calls );
		self::assertSame( 0, $GLOBALS['wpdb']->insert_calls );
		self::assertSame( 1, ( new ContentIndexer() )->pending_index_count() );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::STALE_SWEEP_HOOK ) );

		$GLOBALS['aculect_ai_companion_test_post_terms'][ $post_id ] = array(
			'category' => array(
				new WP_Term(
					array(
						'term_id'  => 22,
						'taxonomy' => 'category',
						'name'     => 'Beta',
						'slug'     => 'beta',
					)
				),
			),
		);
		update_post_meta( $post_id, '_thumbnail_id', 99 );

		$plugin->handle_content_index_terms_changed( $post_id, array(), array(), 'category', false, array() );
		$plugin->handle_content_index_meta_changed( 1, $post_id, '_thumbnail_id', 99 );
		$plugin->handle_content_index_meta_changed( 2, $post_id, '_thumbnail_id', 99 );

		self::assertSame( 1, ( new ContentIndexer() )->pending_index_count() );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::STALE_SWEEP_HOOK ) );

		$result = ( new ContentIndexer() )->run_stale_sweep();
		$final  = ( new ContentIndexRepository() )->content_item( $post_id );

		self::assertSame( 'complete', $result['status'] );
		self::assertSame( 1, $result['processed_items'] );
		self::assertSame( 0, $result['error_count'] );
		self::assertSame( 0, $result['remaining_items'] );
		self::assertSame( 'Beta', $final['metadata']['terms'][0]['name'] ?? '' );
		self::assertStringContainsString( 'Beta', (string) ( $GLOBALS['wpdb']->content_rows[ $post_id ]['search_text'] ?? '' ) );
		self::assertFalse( (bool) ( $final['stale'] ?? false ) );
		self::assertSame( 0, ( new ContentIndexer() )->pending_index_count() );
	}

	public function test_failed_index_write_remains_queued_for_retry(): void {
		$post_id = 34102;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Retry queued index',
				'post_content'      => '<!-- wp:paragraph --><p>Retry this content.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 10:00:00',
			)
		);

		Plugin::instance()->handle_content_index_save( $post_id, get_post( $post_id ), true );
		$GLOBALS['wpdb']->fail_replacements = true;

		$failed = ( new ContentIndexer() )->run_stale_sweep();

		self::assertSame( 1, $failed['error_count'] );
		self::assertSame( 1, $failed['remaining_items'] );
		self::assertSame( 1, ( new ContentIndexer() )->pending_index_count() );

		$GLOBALS['wpdb']->fail_replacements = false;
		( new ContentIndexer() )->defer_index_post( $post_id );
		$retried = ( new ContentIndexer() )->run_stale_sweep();

		self::assertSame( 0, $retried['error_count'] );
		self::assertSame( 0, $retried['remaining_items'] );
		self::assertSame( 0, ( new ContentIndexer() )->pending_index_count() );
	}

	public function test_partial_child_write_rolls_back_the_projection_and_remains_stale(): void {
		$post_id = 34113;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Atomic projection',
				'post_content'      => '<!-- wp:paragraph --><p>Original indexed content.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 10:05:00',
			)
		);
		$indexer = new ContentIndexer();
		self::assertSame( 'indexed', $indexer->index_post( $post_id )['status'] );
		$original_row    = $GLOBALS['wpdb']->content_rows[ $post_id ];
		$original_chunks = $GLOBALS['wpdb']->chunk_rows[ $post_id ];

		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ]->post_content = '<!-- wp:heading --><h2>First section</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>Replacement content with a <a href="https://example.com/target/">link</a>.</p><!-- /wp:paragraph -->'
			. '<!-- wp:heading --><h2>Second section</h2><!-- /wp:heading -->'
			. '<!-- wp:paragraph --><p>This child insert must fail.</p><!-- /wp:paragraph -->';
		$GLOBALS['wpdb']->fail_insert_call = $GLOBALS['wpdb']->insert_calls + 2;

		$result = $indexer->index_post( $post_id );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'index_write_failed', $result['reason'] ?? '' );
		self::assertSame( $original_row['search_text'], $GLOBALS['wpdb']->content_rows[ $post_id ]['search_text'] );
		self::assertSame( $original_chunks, $GLOBALS['wpdb']->chunk_rows[ $post_id ] );
		self::assertArrayNotHasKey( $post_id, $GLOBALS['wpdb']->link_rows );
		self::assertSame( 1, $GLOBALS['wpdb']->content_rows[ $post_id ]['stale'] );

		$GLOBALS['wpdb']->fail_insert_call = $GLOBALS['wpdb']->insert_calls + 3;
		$link_failure = $indexer->index_post( $post_id );

		self::assertSame( 'error', $link_failure['status'] );
		self::assertSame( 'index_write_failed', $link_failure['reason'] ?? '' );
		self::assertSame( $original_row['search_text'], $GLOBALS['wpdb']->content_rows[ $post_id ]['search_text'] );
		self::assertSame( $original_chunks, $GLOBALS['wpdb']->chunk_rows[ $post_id ] );
		self::assertArrayNotHasKey( $post_id, $GLOBALS['wpdb']->link_rows );
		self::assertSame( 1, $GLOBALS['wpdb']->content_rows[ $post_id ]['stale'] );
	}

	public function test_stale_import_preserves_failed_item_backoff(): void {
		$post_id = 34104;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Preserve retry backoff',
				'post_content'      => '<!-- wp:paragraph --><p>Initial indexed content.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 10:15:00',
			)
		);

		$indexer = new ContentIndexer();
		Plugin::instance()->handle_content_index_save( $post_id, get_post( $post_id ), true );
		self::assertSame( 'complete', $indexer->run_stale_sweep()['status'] );

		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ]->post_content = '<!-- wp:paragraph --><p>Updated content awaiting retry.</p><!-- /wp:paragraph -->';
		Plugin::instance()->handle_content_index_save( $post_id, get_post( $post_id ), true );
		$GLOBALS['wpdb']->fail_replacements = true;

		$failed = $indexer->run_stale_sweep();
		self::assertSame( 1, $failed['processed_items'] );
		self::assertSame( 1, $failed['error_count'] );

		$option_name      = 'aculect_ai_companion_pending_index_' . $post_id;
		$retry_generation = (string) get_option( $option_name, '' );
		$retry_state      = json_decode( $retry_generation, true );
		self::assertSame( 1, $retry_state['attempts'] ?? 0 );
		self::assertGreaterThan( time(), $retry_state['available_at'] ?? 0 );

		$deferred_retry = $indexer->run_stale_sweep();
		self::assertSame( 0, $deferred_retry['processed_items'] );
		self::assertSame( 0, $deferred_retry['error_count'] );
		self::assertSame( $retry_generation, get_option( $option_name, '' ) );
	}

	public function test_stale_sweep_processes_at_most_five_queued_posts_per_run(): void {
		$indexer = new ContentIndexer();
		for ( $post_id = 34201; $post_id <= 34207; ++$post_id ) {
			$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
				array(
					'ID'                => $post_id,
					'post_type'         => 'post',
					'post_status'       => 'publish',
					'post_title'        => 'Bounded sweep ' . $post_id,
					'post_content'      => '<!-- wp:paragraph --><p>Process this queued post in a bounded cron slice.</p><!-- /wp:paragraph -->',
					'post_modified_gmt' => '2026-07-10 10:30:00',
				)
			);
			self::assertTrue( $indexer->defer_index_post( $post_id ) );
		}
		unset( $GLOBALS['aculect_ai_companion_test_scheduled_events'][ ContentIndexer::STALE_SWEEP_HOOK ] );

		$result = $indexer->run_stale_sweep();

		self::assertSame( 'partial', $result['status'] );
		self::assertSame( 5, $result['processed_items'] );
		self::assertSame( 0, $result['error_count'] );
		self::assertSame( 2, $result['remaining_items'] );
		self::assertSame( 2, $indexer->pending_index_count() );
		self::assertCount( 5, $GLOBALS['wpdb']->content_rows );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::STALE_SWEEP_HOOK ) );
		self::assertFalse( wp_next_scheduled( ContentIndexer::STALE_SWEEP_RECOVERY_HOOK ) );
	}

	public function test_failed_continuation_keeps_the_independent_recovery_watchdog(): void {
		$queue = new ContentIndexQueue();
		for ( $post_id = 34211; $post_id <= 34216; ++$post_id ) {
			$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
				array(
					'ID'                => $post_id,
					'post_type'         => 'post',
					'post_status'       => 'publish',
					'post_title'        => 'Recovery watchdog ' . $post_id,
					'post_content'      => '<!-- wp:paragraph --><p>Keep recovery until continuation is safe.</p><!-- /wp:paragraph -->',
					'post_modified_gmt' => '2026-07-10 10:45:00',
				)
			);
			self::assertTrue( $queue->enqueue( $post_id ) );
		}
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'] = array( ContentIndexer::STALE_SWEEP_HOOK );

		$result = ( new ContentIndexer() )->run_stale_sweep();

		self::assertSame( 5, $result['processed_items'] );
		self::assertSame( 1, $result['remaining_items'] );
		self::assertFalse( wp_next_scheduled( ContentIndexer::STALE_SWEEP_HOOK ) );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::STALE_SWEEP_RECOVERY_HOOK ) );
	}

	public function test_schedule_failure_keeps_durable_queue_without_inline_indexing(): void {
		$post_id = 34103;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Schedule fallback',
				'post_content'      => '<!-- wp:paragraph --><p>Index inline only when scheduling fails.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 11:00:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'] = array( ContentIndexer::STALE_SWEEP_HOOK );

		Plugin::instance()->handle_content_index_save( $post_id, get_post( $post_id ), true );

		self::assertSame( 0, $GLOBALS['wpdb']->replace_calls );
		self::assertArrayNotHasKey( $post_id, $GLOBALS['wpdb']->content_rows );
		self::assertSame( 1, ( new ContentIndexer() )->pending_index_count() );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::INDEX_RETRY_HOOK, array( $post_id ) ) );
	}

	public function test_schedule_failure_coalesces_later_same_request_mutations(): void {
		$post_id = 34909;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Sequential inline mutations',
				'post_content'      => '<!-- wp:paragraph --><p>Initial content.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 11:15:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'] = array( ContentIndexer::STALE_SWEEP_HOOK );
		$plugin = Plugin::instance();
		$plugin->handle_content_index_save( $post_id, get_post( $post_id ), true );

		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ]->post_content = '<!-- wp:paragraph --><p>First mutation.</p><!-- /wp:paragraph -->';
		$plugin->handle_content_index_meta_changed( 1, $post_id, '_thumbnail_id', 1 );
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ]->post_content = '<!-- wp:paragraph --><p>Second mutation.</p><!-- /wp:paragraph -->';
		$plugin->handle_content_index_meta_changed( 2, $post_id, '_thumbnail_id', 2 );

		self::assertSame( 0, $GLOBALS['wpdb']->replace_calls );
		self::assertSame( 1, ( new ContentIndexer() )->pending_index_count() );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::INDEX_RETRY_HOOK, array( $post_id ) ) );
	}

	public function test_retry_hook_restores_queue_after_initial_persistence_failure(): void {
		$post_id = 34910;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Inline error recovery',
				'post_content'      => '<!-- wp:paragraph --><p>Initial content.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 11:30:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array(
			'aculect_ai_companion_pending_index_' . $post_id,
		);
		$plugin = Plugin::instance();
		$plugin->handle_content_index_save( $post_id, get_post( $post_id ), true );

		self::assertSame( 0, $GLOBALS['wpdb']->replace_calls );
		self::assertSame( 0, ( new ContentIndexer() )->pending_index_count() );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::INDEX_RETRY_HOOK, array( $post_id ) ) );

		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array();
		$plugin->handle_content_index_retry( $post_id );
		self::assertSame( 1, ( new ContentIndexer() )->pending_index_count() );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::STALE_SWEEP_HOOK ) );
	}

	public function test_failed_schedule_does_not_delete_the_queued_generation(): void {
		$post_id = 34107;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Concurrent fallback generation',
				'post_content'      => '<!-- wp:paragraph --><p>Preserve the newer queued generation.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 14:00:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'] = array( ContentIndexer::STALE_SWEEP_HOOK );

		Plugin::instance()->handle_content_index_save( $post_id, get_post( $post_id ), true );

		self::assertSame( 1, ( new ContentIndexer() )->pending_index_count() );
		self::assertSame( 'index', ( new ContentIndexQueue() )->current_generation( $post_id )['action'] );
		self::assertSame( 0, $GLOBALS['wpdb']->replace_calls );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::INDEX_RETRY_HOOK, array( $post_id ) ) );
	}

	public function test_delete_during_claimed_index_cannot_reintroduce_content(): void {
		$post_id = 34108;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Delete during index',
				'post_content'      => '<!-- wp:paragraph --><p>This content must not survive deletion.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 15:00:00',
			)
		);
		$indexer = new ContentIndexer();
		self::assertTrue( $indexer->defer_index_post( $post_id ) );
		$GLOBALS['aculect_ai_companion_test_after_content_replace'] = static function ( int $replaced_post_id ): void {
			( new ContentIndexer() )->delete_post( $replaced_post_id );
			unset( $GLOBALS['aculect_ai_companion_test_posts'][ $replaced_post_id ] );
		};

		$result = $indexer->run_stale_sweep();

		self::assertSame( 1, $result['processed_items'] );
		self::assertArrayNotHasKey( $post_id, $GLOBALS['wpdb']->content_rows );
		self::assertSame( 0, $indexer->pending_index_count() );
	}

	public function test_new_save_after_delete_fence_is_reindexed_without_being_removed(): void {
		$post_id = 34109;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Old generation',
				'post_content'      => '<!-- wp:paragraph --><p>Old indexed generation.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 16:00:00',
			)
		);
		$indexer = new ContentIndexer();
		self::assertTrue( $indexer->defer_index_post( $post_id ) );
		$GLOBALS['aculect_ai_companion_test_after_content_replace'] = static function ( int $replaced_post_id ): void {
			( new ContentIndexer() )->delete_post( $replaced_post_id );
			$GLOBALS['aculect_ai_companion_test_posts'][ $replaced_post_id ] = new WP_Post(
				array(
					'ID'                => $replaced_post_id,
					'post_type'         => 'post',
					'post_status'       => 'publish',
					'post_title'        => 'New generation',
					'post_content'      => '<!-- wp:paragraph --><p>New indexed generation.</p><!-- /wp:paragraph -->',
					'post_modified_gmt' => '2026-07-10 16:01:00',
				)
			);
			( new ContentIndexer() )->defer_index_post( $replaced_post_id );
		};

		$first = $indexer->run_stale_sweep();
		self::assertSame( 1, $first['remaining_items'] );

		$second = $indexer->run_stale_sweep();

		self::assertSame( 1, $second['processed_items'] );
		self::assertSame( 0, $second['remaining_items'] );
		self::assertStringContainsString(
			'New generation',
			(string) ( $GLOBALS['wpdb']->content_rows[ $post_id ]['search_text'] ?? '' )
		);
	}

	public function test_delete_tombstone_recovers_after_worker_stops_before_finalize(): void {
		$post_id = 34110;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Interrupted worker',
				'post_content'      => '<!-- wp:paragraph --><p>A tombstone must remove this stale write.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 17:00:00',
			)
		);
		$queue = new ContentIndexQueue();
		self::assertTrue( $queue->enqueue( $post_id ) );
		$claim = $queue->claim( 1 );
		self::assertCount( 1, $claim );
		$GLOBALS['aculect_ai_companion_test_before_content_replace'] = static function ( int $replaced_post_id ): void {
			( new ContentIndexer() )->delete_post( $replaced_post_id );
			unset( $GLOBALS['aculect_ai_companion_test_posts'][ $replaced_post_id ] );
		};

		$result = ( new ContentIndexer() )->index_post( $post_id );

		self::assertSame( 'indexed', $result['status'] );
		self::assertArrayHasKey( $post_id, $GLOBALS['wpdb']->content_rows );
		self::assertSame( 1, $queue->pending_count() );
		self::assertSame( 'delete', $queue->current_generation( $post_id )['action'] );

		update_option( 'aculect_ai_companion_index_lock_' . $post_id, 'expired:' . ( time() - 1 ), false );
		$recovered = ( new ContentIndexer() )->run_stale_sweep();

		self::assertSame( 1, $recovered['processed_items'] );
		self::assertSame( 0, $recovered['remaining_items'] );
		self::assertArrayNotHasKey( $post_id, $GLOBALS['wpdb']->content_rows );
	}

	public function test_save_crossing_delete_cleanup_preserves_the_last_queue_generation(): void {
		$post_id = 34111;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Before restore',
				'post_content'      => '<!-- wp:paragraph --><p>Content before restore.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 18:00:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_after_content_delete'] = static function ( int $deleted_post_id ): void {
			$GLOBALS['aculect_ai_companion_test_posts'][ $deleted_post_id ] = new WP_Post(
				array(
					'ID'                => $deleted_post_id,
					'post_type'         => 'post',
					'post_status'       => 'publish',
					'post_title'        => 'Restored after delete',
					'post_content'      => '<!-- wp:paragraph --><p>The last queue generation wins.</p><!-- /wp:paragraph -->',
					'post_modified_gmt' => '2026-07-10 18:01:00',
				)
			);
			( new ContentIndexer() )->defer_index_post( $deleted_post_id );
		};

		$queue = new ContentIndexQueue();
		self::assertTrue( $queue->enqueue( $post_id ) );
		self::assertCount( 1, $queue->claim( 1 ) );
		$indexer = new ContentIndexer();
		$indexer->delete_post( $post_id );

		$queue_state = $queue->current_generation( $post_id );
		self::assertSame( 'index', $queue_state['action'] );
		self::assertSame( 1, $indexer->pending_index_count() );

		update_option( 'aculect_ai_companion_index_lock_' . $post_id, 'expired:' . ( time() - 1 ), false );
		$result = $indexer->run_stale_sweep();

		self::assertSame( 1, $result['processed_items'] );
		self::assertSame( 0, $result['remaining_items'] );
		self::assertStringContainsString(
			'Restored after delete',
			(string) ( $GLOBALS['wpdb']->content_rows[ $post_id ]['search_text'] ?? '' )
		);
	}

	public function test_settled_post_delete_failure_keeps_a_retryable_tombstone(): void {
		$post_id = 34112;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Settled delete retry',
				'post_content'      => '<!-- wp:paragraph --><p>Retry deletion after a transient database failure.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 19:00:00',
			)
		);
		$indexer = new ContentIndexer();
		self::assertSame( 'indexed', $indexer->index_post( $post_id )['status'] );
		self::assertSame( 0, $indexer->pending_index_count() );

		$GLOBALS['wpdb']->fail_deletions = true;
		$indexer->delete_post( $post_id );

		$queue = new ContentIndexQueue();
		self::assertSame( 1, $queue->pending_count() );
		self::assertSame( 'delete', $queue->current_generation( $post_id )['action'] );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::STALE_SWEEP_HOOK ) );

		$GLOBALS['wpdb']->fail_deletions = false;
		$result                          = $indexer->run_stale_sweep();

		self::assertSame( 1, $result['processed_items'] );
		self::assertSame( 0, $result['error_count'] );
		self::assertSame( 0, $queue->pending_count() );
		self::assertArrayNotHasKey( $post_id, $GLOBALS['wpdb']->content_rows );
	}

	public function test_non_indexable_attachment_save_does_not_enter_queue(): void {
		$post_id = 34104;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'          => $post_id,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
				'post_title'  => 'Media attachment',
			)
		);

		Plugin::instance()->handle_content_index_save( $post_id, get_post( $post_id ), true );

		$indexer = new ContentIndexer();
		self::assertSame( 1, $indexer->pending_index_count() );
		self::assertSame( 0, $GLOBALS['wpdb']->replace_calls );

		$result = $indexer->run_stale_sweep();
		self::assertSame( 1, $result['processed_items'] );
		self::assertSame( 0, $indexer->pending_index_count() );
	}

	public function test_queue_persistence_failure_schedules_a_bounded_retry_without_inline_indexing(): void {
		$post_id = 34105;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Queue fallback',
				'post_content'      => '<!-- wp:paragraph --><p>Index inline when queue persistence fails.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 12:00:00',
			)
		);
		$GLOBALS['aculect_ai_companion_test_failed_option_updates'] = array(
			'aculect_ai_companion_pending_index_' . $post_id,
		);

		Plugin::instance()->handle_content_index_save( $post_id, get_post( $post_id ), true );

		self::assertArrayNotHasKey( $post_id, $GLOBALS['wpdb']->content_rows );
		self::assertSame( 0, ( new ContentIndexer() )->pending_index_count() );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::INDEX_RETRY_HOOK, array( $post_id ) ) );
	}

	public function test_non_indexable_status_transition_removes_existing_index_and_queue_state(): void {
		$post_id = 34106;
		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ] = new WP_Post(
			array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'Status transition',
				'post_content'      => '<!-- wp:paragraph --><p>Initially indexable.</p><!-- /wp:paragraph -->',
				'post_modified_gmt' => '2026-07-10 13:00:00',
			)
		);
		$indexer = new ContentIndexer();
		self::assertSame( 'indexed', $indexer->index_post( $post_id )['status'] ?? '' );
		self::assertTrue( $indexer->defer_index_post( $post_id ) );

		$GLOBALS['aculect_ai_companion_test_posts'][ $post_id ]->post_status = 'archived';
		Plugin::instance()->handle_content_index_save( $post_id, get_post( $post_id ), true );

		self::assertArrayNotHasKey( $post_id, $GLOBALS['wpdb']->content_rows );
		self::assertSame( 1, $indexer->pending_index_count() );
		self::assertSame( 'delete', ( new ContentIndexQueue() )->current_generation( $post_id )['action'] );

		$result = $indexer->run_stale_sweep();
		self::assertSame( 1, $result['processed_items'] );
		self::assertSame( 0, $indexer->pending_index_count() );
	}
}
