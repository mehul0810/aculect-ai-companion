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

		$GLOBALS['aculect_ai_companion_test_options']                      = array();
		$GLOBALS['aculect_ai_companion_test_scheduled_events']             = array();
		$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks']       = array();
		$GLOBALS['aculect_ai_companion_test_schedule_literal_false_hooks'] = array();
		$GLOBALS['aculect_ai_companion_test_failed_option_adds']           = array();
		$GLOBALS['aculect_ai_companion_test_failed_option_deletes']        = array();
	}

	protected function tearDown(): void {
		ContentIndexer::delete_options();
		unset(
			$GLOBALS['aculect_ai_companion_test_schedule_failure_hooks'],
			$GLOBALS['aculect_ai_companion_test_schedule_literal_false_hooks'],
			$GLOBALS['aculect_ai_companion_test_failed_option_adds'],
			$GLOBALS['aculect_ai_companion_test_failed_option_deletes']
		);

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
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_index_lock_77', 'missing' ) );
	}

	public function test_new_enqueue_is_not_replaced_by_older_claim_retry(): void {
		$queue = new ContentIndexQueue();
		self::assertTrue( $queue->enqueue( 76 ) );

		$claims = $queue->claim( 1 );
		self::assertCount( 1, $claims );
		$new_generation = $queue->enqueue_generation( 76 );
		self::assertNotSame( '', $new_generation );

		self::assertFalse(
			$queue->retry(
				76,
				$claims[0]['queue_token'],
				$claims[0]['lock_token']
			)
		);
		self::assertSame( $new_generation, $queue->current_generation( 76 )['queue_token'] );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_index_lock_76', 'missing' ) );
	}

	public function test_expired_lease_owner_cannot_acknowledge_after_reclaim(): void {
		$queue = new ContentIndexQueue();
		self::assertTrue( $queue->enqueue( 78 ) );
		$first = $queue->claim( 1 );
		self::assertCount( 1, $first );

		$expired_token = explode( ':', $first[0]['lock_token'] )[0] . ':' . ( time() - 1 );
		update_option( 'aculect_ai_companion_index_lock_78', $expired_token, false );
		$second = $queue->claim( 1 );
		self::assertCount( 1, $second );

		self::assertFalse( $queue->acknowledge( 78, $first[0]['queue_token'], $expired_token ) );
		self::assertSame( 1, $queue->pending_count() );
		self::assertSame( $second[0]['lock_token'], get_option( 'aculect_ai_companion_index_lock_78', '' ) );
	}

	public function test_expired_lease_owner_cannot_retry_after_reclaim(): void {
		$queue = new ContentIndexQueue();
		self::assertTrue( $queue->enqueue( 79 ) );
		$first = $queue->claim( 1 );
		self::assertCount( 1, $first );

		$expired_token = explode( ':', $first[0]['lock_token'] )[0] . ':' . ( time() - 1 );
		update_option( 'aculect_ai_companion_index_lock_79', $expired_token, false );
		$second = $queue->claim( 1 );
		self::assertCount( 1, $second );

		self::assertFalse( $queue->retry( 79, $first[0]['queue_token'], $expired_token ) );
		self::assertSame( $first[0]['queue_token'], $queue->current_generation( 79 )['queue_token'] );
		self::assertSame( $second[0]['lock_token'], get_option( 'aculect_ai_companion_index_lock_79', '' ) );
	}

	public function test_legacy_shared_queue_migrates_without_losing_ids(): void {
		update_option( 'aculect_ai_companion_pending_index_ids', array( 5, 9, 5 ), false );

		$queue = new ContentIndexQueue();

		self::assertSame( 2, $queue->pending_count() );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_pending_index_ids', 'missing' ) );
	}

	public function test_legacy_migration_preserves_a_newer_deletion_tombstone(): void {
		update_option( 'aculect_ai_companion_pending_index_ids', array( 5 ), false );
		$queue     = new ContentIndexQueue();
		$tombstone = $queue->invalidate_for_delete( 5 );

		self::assertSame( 1, $queue->pending_count() );
		self::assertSame( $tombstone, $queue->current_generation( 5 )['queue_token'] );
		self::assertSame( 'delete', $queue->current_generation( 5 )['action'] );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_pending_index_ids', 'missing' ) );
	}

	public function test_partial_legacy_migration_retries_only_missing_ids(): void {
		update_option( 'aculect_ai_companion_pending_index_ids', array( 5, 9 ), false );
		$GLOBALS['aculect_ai_companion_test_failed_option_adds'] = array( 'aculect_ai_companion_pending_index_9' );
		$queue = new ContentIndexQueue();

		self::assertSame( 1, $queue->pending_count() );
		self::assertSame( array( 5, 9 ), get_option( 'aculect_ai_companion_pending_index_ids', array() ) );
		$first_generation = $queue->current_generation( 5 )['queue_token'];

		$GLOBALS['aculect_ai_companion_test_failed_option_adds'] = array();
		$tombstone = $queue->invalidate_for_delete( 9 );
		self::assertSame( 2, $queue->pending_count() );
		self::assertSame( $first_generation, $queue->current_generation( 5 )['queue_token'] );
		self::assertSame( $tombstone, $queue->current_generation( 9 )['queue_token'] );
		self::assertSame( 'delete', $queue->current_generation( 9 )['action'] );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_pending_index_ids', 'missing' ) );
	}

	public function test_legacy_delete_failure_does_not_replace_durable_generations(): void {
		update_option( 'aculect_ai_companion_pending_index_ids', array( 5 ), false );
		$GLOBALS['aculect_ai_companion_test_failed_option_deletes'] = array( 'aculect_ai_companion_pending_index_ids' );
		$queue = new ContentIndexQueue();

		self::assertSame( 1, $queue->pending_count() );
		$generation = $queue->current_generation( 5 )['queue_token'];
		self::assertSame( array( 5 ), get_option( 'aculect_ai_companion_pending_index_ids', array() ) );

		$GLOBALS['aculect_ai_companion_test_failed_option_deletes'] = array();
		self::assertSame( 1, $queue->pending_count() );
		self::assertSame( $generation, $queue->current_generation( 5 )['queue_token'] );
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

	public function test_stale_import_does_not_replace_an_existing_retry_generation(): void {
		$queue = new ContentIndexQueue();
		self::assertTrue( $queue->enqueue( 41 ) );

		$claim = $queue->claim( 1 );
		self::assertCount( 1, $claim );
		$queue->retry( 41, $claim[0]['queue_token'], $claim[0]['lock_token'] );
		$retry_generation = $queue->current_generation( 41 )['queue_token'];

		self::assertFalse( $queue->enqueue_if_absent( 41 ) );
		self::assertSame( $retry_generation, $queue->current_generation( 41 )['queue_token'] );
		self::assertTrue( $queue->enqueue_if_absent( 42 ) );
		self::assertSame( 2, $queue->pending_count() );
	}

	public function test_stale_sweep_keeps_an_existing_later_watchdog(): void {
		$indexer  = new ContentIndexer();
		$watchdog = time() + 360;
		$GLOBALS['aculect_ai_companion_test_scheduled_events'][ ContentIndexer::STALE_SWEEP_HOOK ] = $watchdog;

		self::assertTrue( $indexer->schedule_stale_sweep( 30 ) );
		self::assertSame(
			$watchdog,
			$GLOBALS['aculect_ai_companion_test_scheduled_events'][ ContentIndexer::STALE_SWEEP_HOOK ]
		);
	}

	public function test_stale_sweep_treats_literal_false_as_schedule_failure(): void {
		$GLOBALS['aculect_ai_companion_test_schedule_literal_false_hooks'] = array( ContentIndexer::STALE_SWEEP_HOOK );

		self::assertFalse( ( new ContentIndexer() )->schedule_stale_sweep( 30 ) );
	}

	public function test_stale_sweep_does_not_claim_when_recovery_schedule_returns_false(): void {
		$queue = new ContentIndexQueue();
		for ( $object_id = 43; $object_id < 143; ++$object_id ) {
			self::assertTrue( $queue->enqueue( $object_id ) );
		}
		$GLOBALS['aculect_ai_companion_test_schedule_literal_false_hooks'] = array( ContentIndexer::STALE_SWEEP_RECOVERY_HOOK );

		$result = ( new ContentIndexer() )->run_stale_sweep();

		self::assertSame( 0, $result['processed_items'] );
		self::assertSame( 1, $result['error_count'] );
		self::assertSame( 100, $result['remaining_items'] );
		$GLOBALS['aculect_ai_companion_test_schedule_literal_false_hooks'] = array();
		self::assertCount( 1, $queue->claim( 1 ) );
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

	public function test_expired_finalization_fence_can_be_reclaimed_after_interruption(): void {
		$queue = new ContentIndexQueue();
		self::assertTrue( $queue->enqueue( 89 ) );
		$claim = $queue->claim( 1 );
		self::assertCount( 1, $claim );

		$begin_transition = new \ReflectionMethod( $queue, 'begin_owned_transition' );
		$transition_token = (string) $begin_transition->invoke( $queue, 89, $claim[0]['lock_token'] );
		self::assertNotSame( '', $transition_token );

		$expired_transition = substr( $transition_token, 0, (int) strrpos( $transition_token, ':' ) + 1 ) . ( time() - 1 );
		update_option( 'aculect_ai_companion_index_lock_89', $expired_transition, false );
		$reclaimed = $queue->claim( 1 );

		self::assertCount( 1, $reclaimed );
		self::assertSame( 89, $reclaimed[0]['object_id'] );
		self::assertNotSame( $claim[0]['queue_token'], $reclaimed[0]['queue_token'] );
		self::assertNotSame( $expired_transition, $reclaimed[0]['lock_token'] );
	}

	public function test_expired_finalizer_cannot_acknowledge_after_transition_reclaim(): void {
		$queue = new ContentIndexQueue();
		self::assertTrue( $queue->enqueue( 90 ) );
		$first = $queue->claim( 1 );
		self::assertCount( 1, $first );

		$begin_transition = new \ReflectionMethod( $queue, 'begin_owned_transition' );
		$transition_token = (string) $begin_transition->invoke( $queue, 90, $first[0]['lock_token'] );
		$expired_token    = substr( $transition_token, 0, (int) strrpos( $transition_token, ':' ) + 1 ) . ( time() - 1 );
		update_option( 'aculect_ai_companion_index_lock_90', $expired_token, false );

		$second = $queue->claim( 1 );
		self::assertCount( 1, $second );
		self::assertNotSame( $first[0]['queue_token'], $second[0]['queue_token'] );
		self::assertFalse( $queue->clear_generation( 90, $first[0]['queue_token'] ) );
		self::assertSame( $second[0]['queue_token'], $queue->current_generation( 90 )['queue_token'] );
		self::assertSame( $second[0]['lock_token'], get_option( 'aculect_ai_companion_index_lock_90', '' ) );
	}

	public function test_expired_finalizer_cannot_retry_after_transition_reclaim(): void {
		$queue = new ContentIndexQueue();
		self::assertTrue( $queue->enqueue( 91 ) );
		$first = $queue->claim( 1 );
		self::assertCount( 1, $first );

		$begin_transition = new \ReflectionMethod( $queue, 'begin_owned_transition' );
		$transition_token = (string) $begin_transition->invoke( $queue, 91, $first[0]['lock_token'] );
		$expired_token    = substr( $transition_token, 0, (int) strrpos( $transition_token, ':' ) + 1 ) . ( time() - 1 );
		update_option( 'aculect_ai_companion_index_lock_91', $expired_token, false );

		$second = $queue->claim( 1 );
		self::assertCount( 1, $second );
		self::assertNotSame( $first[0]['queue_token'], $second[0]['queue_token'] );

		$update_generation = new \ReflectionMethod( $queue, 'update_option_if_value' );
		self::assertFalse(
			$update_generation->invoke(
				$queue,
				'aculect_ai_companion_pending_index_91',
				$first[0]['queue_token'],
				'stale-finalizer-retry'
			)
		);
		self::assertSame( $second[0]['queue_token'], $queue->current_generation( 91 )['queue_token'] );
		self::assertSame( $second[0]['lock_token'], get_option( 'aculect_ai_companion_index_lock_91', '' ) );
	}

	public function test_production_competing_claimers_cannot_both_acquire_the_lease(): void {
		$original_options = $GLOBALS['aculect_ai_companion_test_options'];
		$original_wpdb    = $GLOBALS['wpdb'] ?? null;
		$wpdb             = new class() {
			public string $options = 'wp_options';
			/** @var array<string, string> */
			public array $rows = array();
			/** @var array<int, mixed> */
			private array $prepared_args = array();
			public int $insert_attempts = 0;

			public function prepare( string $query, mixed ...$args ): string {
				$this->prepared_args = $args;
				return $query;
			}

			public function query( string $query ): int {
				if ( str_contains( $query, 'INSERT IGNORE' ) ) {
					++$this->insert_attempts;
					$key = (string) ( $this->prepared_args[0] ?? '' );
					if ( isset( $this->rows[ $key ] ) ) {
						return 0;
					}

					$this->rows[ $key ] = (string) ( $this->prepared_args[1] ?? '' );
					return 1;
				}

				return 0;
			}

			public function get_var( string $query ): string|null {
				unset( $query );
				return $this->rows[ (string) ( $this->prepared_args[0] ?? '' ) ] ?? null;
			}
		};

		try {
			unset( $GLOBALS['aculect_ai_companion_test_options'] );
			$GLOBALS['aculect_ai_companion_test_cache_deletes'] = array();
			$GLOBALS['wpdb']                                    = $wpdb;
			$queue                                               = new ContentIndexQueue();
			$claim_lock                                         = new \ReflectionMethod( $queue, 'claim_lock' );

			$first_owner  = (string) $claim_lock->invoke( $queue, 95 );
			$second_owner = (string) $claim_lock->invoke( $queue, 95 );

			self::assertNotSame( '', $first_owner );
			self::assertSame( '', $second_owner );
			self::assertSame( 2, $wpdb->insert_attempts );
			self::assertSame( $first_owner, $wpdb->rows['aculect_ai_companion_index_lock_95'] ?? '' );
			self::assertContains(
				array(
					'key'   => 'aculect_ai_companion_index_lock_95',
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

	public function test_production_generation_replacement_uses_atomic_upsert_and_cache_invalidation(): void {
		$original_options = $GLOBALS['aculect_ai_companion_test_options'];
		$original_wpdb    = $GLOBALS['wpdb'] ?? null;
		$wpdb             = new class() {
			public string $options = 'wp_options';
			/** @var list<array{query: string, args: array<int, mixed>}> */
			public array $prepared = array();
			public string $executed = '';
			public string $selected = '';
			public int $query_result = 1;

			public function prepare( string $query, mixed ...$args ): string {
				$this->prepared[] = array(
					'query' => $query,
					'args'  => $args,
				);
				return $query;
			}

			public function query( string $query ): int {
				$this->executed = $query;
				return $this->query_result;
			}

			public function get_var( string $query ): string {
				$this->executed = $query;
				return $this->selected;
			}

			public function delete( string $table, array $where, array $formats ): int {
				unset( $table, $where, $formats );
				return $this->query_result;
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

			$GLOBALS['aculect_ai_companion_test_cache_deletes'] = array();
			$wpdb->query_result                                 = 1;
			$add_method                                         = new \ReflectionMethod( $queue, 'add_queue_generation_if_absent' );
			self::assertTrue( $add_method->invoke( $queue, 92, 'generation-92' ) );
			self::assertStringContainsString( 'INSERT IGNORE', $wpdb->executed );
			$last_prepare = end( $wpdb->prepared );
			self::assertSame(
				array(
					'aculect_ai_companion_pending_index_92',
					'generation-92',
				),
				is_array( $last_prepare ) ? $last_prepare['args'] : array()
			);
			self::assertContains(
				array(
					'key'   => 'aculect_ai_companion_pending_index_92',
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

			$active_lock        = 'worker-a:' . ( time() + 300 );
			$wpdb->query_result = 1;
			self::assertTrue( $queue->acknowledge( 93, 'generation-93', $active_lock ) );
			$transition_prepare = $wpdb->prepared[ count( $wpdb->prepared ) - 1 ] ?? array();
			self::assertStringContainsString( 'UPDATE wp_options SET option_value', $transition_prepare['query'] ?? '' );
			self::assertSame(
				'aculect_ai_companion_index_lock_93',
				$transition_prepare['args'][1] ?? ''
			);
			self::assertSame( $active_lock, $transition_prepare['args'][2] ?? '' );

			$wpdb->query_result = 1;
			self::assertTrue( $queue->retry( 94, 'generation-94', $active_lock ) );
			$retry_prepare = end( $wpdb->prepared );
			self::assertStringContainsString( 'UPDATE wp_options SET option_value', $retry_prepare['query'] ?? '' );
			self::assertSame( 'aculect_ai_companion_pending_index_94', $retry_prepare['args'][1] ?? '' );
			self::assertSame( 'generation-94', $retry_prepare['args'][2] ?? '' );
			foreach ( $wpdb->prepared as $prepared_query ) {
				self::assertStringNotContainsString( 'SUBSTRING_INDEX', $prepared_query['query'] );
				self::assertStringNotContainsString( 'UNIX_TIMESTAMP', $prepared_query['query'] );
				self::assertStringNotContainsString( 'INNER JOIN', $prepared_query['query'] );
			}

			$GLOBALS['aculect_ai_companion_test_cache_deletes'] = array();
			$wpdb->query_result                                 = 0;
			self::assertFalse( $add_method->invoke( $queue, 92, 'newer-generation-must-not-replace' ) );
			self::assertContains(
				array(
					'key'   => 'aculect_ai_companion_pending_index_92',
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

			$wpdb->selected = '{"token":"delete-91","attempts":0,"available_at":0,"action":"delete"}';
			self::assertSame( 'delete', $queue->current_generation( 91 )['action'] );
			self::assertStringContainsString( 'SELECT option_value', $wpdb->executed );

			$GLOBALS['aculect_ai_companion_test_cache_deletes'] = array();
			$wpdb->query_result                                 = 0;
			$delete_method                                      = new \ReflectionMethod( $queue, 'delete_option_if_value' );
			self::assertFalse(
				$delete_method->invoke(
					$queue,
					'aculect_ai_companion_pending_index_91',
					'stale-reader-generation'
				)
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
