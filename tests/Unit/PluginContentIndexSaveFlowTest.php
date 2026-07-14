<?php
/**
 * Content index save flow regression tests.
 *
 * @package Aculect\AICompanion\Tests\Unit
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit;

use Aculect\AICompanion\Intelligence\ContentIndexRepository;
use Aculect\AICompanion\Intelligence\ContentIndexer;
use Aculect\AICompanion\Intelligence\Database\Installer;
use Aculect\AICompanion\Plugin;
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
		$GLOBALS['wpdb']     = new class() {
			public string $prefix = 'wp_';
			/** @var array<int, array<string, mixed>> */
			public array $content_rows = array();
			/** @var array<int, array<int, array<string, mixed>>> */
			public array $chunk_rows = array();
			/** @var array<int, array<int, array<string, mixed>>> */
			public array $link_rows = array();
			/** @var list<array{query: string, args: array<int, mixed>}> */
			public array $prepared = array();
			/** @var array<int, mixed> */
			private array $last_prepare_args = array();

			public function prepare( string $query, mixed ...$args ): string {
				$this->last_prepare_args = $args;
				$this->prepared[]        = array(
					'query' => $query,
					'args'  => $args,
				);

				return $query;
			}

			public function replace( string $table, array $data, array $formats ): int|false {
				unset( $formats );

				if ( Installer::content_index_table() !== $table ) {
					return false;
				}

				$this->content_rows[ (int) $data['object_id'] ] = $data;

				return 1;
			}

			public function insert( string $table, array $data, array $formats ): int|false {
				unset( $formats );

				if ( Installer::content_chunks_table() === $table ) {
					$this->chunk_rows[ (int) $data['object_id'] ][] = $data;
					return 1;
				}

				if ( Installer::link_graph_table() === $table ) {
					$this->link_rows[ (int) $data['source_id'] ][] = $data;
					return 1;
				}

				return false;
			}

			public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int|false {
				unset( $formats, $where_formats );

				if ( Installer::content_index_table() !== $table ) {
					return false;
				}

				$object_id = (int) ( $where['object_id'] ?? 0 );
				if ( ! isset( $this->content_rows[ $object_id ] ) ) {
					return 0;
				}

				$this->content_rows[ $object_id ] = array_merge( $this->content_rows[ $object_id ], $data );

				return 1;
			}

			public function delete( string $table, array $where, array $where_formats ): int|false {
				unset( $where_formats );

				if ( Installer::content_index_table() === $table ) {
					unset( $this->content_rows[ (int) ( $where['object_id'] ?? 0 ) ] );
					return 1;
				}

				if ( Installer::content_chunks_table() === $table ) {
					unset( $this->chunk_rows[ (int) ( $where['object_id'] ?? 0 ) ] );
					return 1;
				}

				if ( Installer::link_graph_table() === $table ) {
					$source_id = (int) ( $where['source_id'] ?? 0 );
					if ( 0 < $source_id ) {
						unset( $this->link_rows[ $source_id ] );
					}

					return 1;
				}

				return false;
			}

			public function get_row( string $query, string $output ) : ?array {
				unset( $query, $output );

				$object_id = (int) ( $this->last_prepare_args[1] ?? 0 );

				return $this->content_rows[ $object_id ] ?? null;
			}

			public function get_col( string $query ): array {
				unset( $query );

				$rows = array_values(
					array_filter(
						$this->content_rows,
						static fn ( array $row ): bool => ! empty( $row['stale'] )
					)
				);

				usort(
					$rows,
					static fn ( array $left, array $right ): int => strcmp(
						(string) ( $right['modified_gmt'] ?? '' ),
						(string) ( $left['modified_gmt'] ?? '' )
					)
				);

				return array_map(
					static fn ( array $row ): int => (int) $row['object_id'],
					$rows
				);
			}
		};

		$GLOBALS['aculect_ai_companion_test_options']          = array();
		$GLOBALS['aculect_ai_companion_test_posts']            = array();
		$GLOBALS['aculect_ai_companion_test_post_meta']        = array();
		$GLOBALS['aculect_ai_companion_test_post_terms']       = array();
		$GLOBALS['aculect_ai_companion_test_scheduled_events'] = array();
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

		$initial = ( new ContentIndexRepository() )->content_item( $post_id );
		self::assertSame( 'Alpha', $initial['metadata']['terms'][0]['name'] ?? '' );
		self::assertFalse( (bool) ( $initial['stale'] ?? false ) );

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

		self::assertSame( array( $post_id ), get_option( 'aculect_ai_companion_pending_index_ids', array() ) );
		self::assertGreaterThan( 0, (int) wp_next_scheduled( ContentIndexer::STALE_SWEEP_HOOK ) );

		$queued = ( new ContentIndexRepository() )->content_item( $post_id );
		self::assertTrue( (bool) ( $queued['stale'] ?? false ) );

		$result = ( new ContentIndexer() )->run_stale_sweep();
		$final  = ( new ContentIndexRepository() )->content_item( $post_id );

		self::assertSame( 'complete', $result['status'] );
		self::assertSame( 1, $result['processed_items'] );
		self::assertSame( 0, $result['error_count'] );
		self::assertSame( 0, $result['remaining_items'] );
		self::assertSame( 'Beta', $final['metadata']['terms'][0]['name'] ?? '' );
		self::assertStringContainsString( 'Beta', (string) ( $GLOBALS['wpdb']->content_rows[ $post_id ]['search_text'] ?? '' ) );
		self::assertFalse( (bool) ( $final['stale'] ?? false ) );
		self::assertSame( 'missing', get_option( 'aculect_ai_companion_pending_index_ids', 'missing' ) );
	}
}
