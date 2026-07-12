<?php
/**
 * Tests for the local Aculect Intelligence repository.
 *
 * @package Aculect\AICompanion\Tests\Unit\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Intelligence;

use Aculect\AICompanion\Intelligence\ContentIndexRepository;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Focused repository tests replace wpdb with a local test double.

/**
 * Verifies durable memory persistence remains review-first by default.
 */
final class ContentIndexRepositoryTest extends TestCase {

	private mixed $original_wpdb = null;

	private object $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = new class() {
			public string $prefix = 'wp_';

			/**
			 * Prepared query calls.
			 *
			 * @var list<array{query: string, args: array<int, mixed>}>
			 */
			public array $prepared = array();

			/**
			 * Inserted rows.
			 *
			 * @var list<array{table: string, data: array<string, mixed>, formats: array<int, string>}>
			 */
			public array $inserts = array();

			/**
			 * Stored memory rows keyed by memory key.
			 *
			 * @var array<string, array<string, mixed>>
			 */
			private array $rows = array();

			/**
			 * Last prepared query arguments.
			 *
			 * @var array<int, mixed>
			 */
			private array $last_args = array();

			public function prepare( string $query, mixed ...$args ): string {
				$this->last_args  = $args;
				$this->prepared[] = array(
					'query' => $query,
					'args'  => $args,
				);

				return $query;
			}

			public function get_var( string $query ): ?int {
				if ( str_contains( $query, 'COUNT(*)' ) ) {
					return count( $this->rows );
				}

				$key = $this->last_memory_key();

				return isset( $this->rows[ $key ] ) ? (int) $this->rows[ $key ]['id'] : null;
			}

			/**
			 * Store one row.
			 *
			 * @param string               $table   Table name.
			 * @param array<string, mixed> $data    Row data.
			 * @param array<int, string>   $formats Insert formats.
			 */
			public function insert( string $table, array $data, array $formats ): int {
				$data['id']                                 = count( $this->rows ) + 1;
				$this->rows[ (string) $data['memory_key'] ] = $data;
				$this->inserts[]                            = array(
					'table'   => $table,
					'data'    => $data,
					'formats' => $formats,
				);

				return 1;
			}

			/**
			 * Update one stored row.
			 *
			 * @param string               $table         Table name.
			 * @param array<string, mixed> $data          Row data.
			 * @param array<string, mixed> $where         Where clause data.
			 * @param array<int, string>   $formats       Update formats.
			 * @param array<int, string>   $where_formats Where formats.
			 */
			public function update( string $table, array $data, array $where, array $formats, array $where_formats ): int {
				unset( $table, $formats, $where_formats );

				$key = (string) ( $where['memory_key'] ?? '' );
				if ( isset( $this->rows[ $key ] ) ) {
					$this->rows[ $key ] = array_merge( $this->rows[ $key ], $data );
				}

				return 1;
			}

			/**
			 * Delete one stored row.
			 *
			 * @param string               $table         Table name.
			 * @param array<string, mixed> $where         Where clause data.
			 * @param array<int, string>   $where_formats Where formats.
			 */
			public function delete( string $table, array $where, array $where_formats ): int {
				unset( $table, $where_formats );

				$key = (string) ( $where['memory_key'] ?? '' );
				if ( ! isset( $this->rows[ $key ] ) ) {
					return 0;
				}

				unset( $this->rows[ $key ] );

				return 1;
			}

			/**
			 * Return one stored row.
			 *
			 * @param string $query  Prepared query.
			 * @param string $output Output type.
			 * @return array<string, mixed>|null
			 */
			public function get_row( string $query, string $output ): ?array {
				unset( $query, $output );

				return $this->rows[ $this->last_memory_key() ] ?? null;
			}

			/**
			 * Return stored rows.
			 *
			 * @param string $query  Prepared query.
			 * @param string $output Output type.
			 * @return list<array<string, mixed>>
			 */
			public function get_results( string $query, string $output ): array {
				unset( $query, $output );

				return array_values( $this->rows );
			}

			private function last_memory_key(): string {
				return (string) ( $this->last_args[1] ?? '' );
			}
		};
		$GLOBALS['wpdb']     = $this->wpdb;
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		parent::tearDown();
	}

	public function test_memory_save_defaults_to_pending_review(): void {
		$result = ( new ContentIndexRepository() )->upsert_memory(
			array(
				'key'   => 'brand.voice.primary',
				'value' => 'Use concise expert guidance.',
			)
		);

		self::assertSame( 'success', $result['status'] );
		self::assertSame( 'pending', $result['memory']['status'] );
		self::assertSame( 'pending', $this->wpdb->inserts[0]['data']['status'] );
	}

	public function test_memory_save_preserves_explicit_approved_status(): void {
		$result = ( new ContentIndexRepository() )->upsert_memory(
			array(
				'key'    => 'brand.voice.primary',
				'value'  => 'Use concise expert guidance.',
				'status' => 'approved',
			)
		);

		self::assertSame( 'success', $result['status'] );
		self::assertSame( 'approved', $result['memory']['status'] );
		self::assertSame( 'approved', $this->wpdb->inserts[0]['data']['status'] );
	}

	public function test_memory_delete_removes_existing_row(): void {
		$repository = new ContentIndexRepository();
		$repository->upsert_memory(
			array(
				'key'    => 'workflow.blocks.no_custom_html',
				'domain' => 'workflow',
				'value'  => 'Do not use core/html.',
			)
		);

		$result = $repository->delete_memory( 'workflow.blocks.no_custom_html' );
		$list   = $repository->list_memories( array( 'status' => '' ) );

		self::assertSame( 'success', $result['status'] );
		self::assertSame( 1, $result['deleted'] );
		self::assertSame( array(), $list['items'] );
		self::assertSame( 0, $list['total'] );
	}

	public function test_content_search_filters_by_word_count_post_type_status_and_stale_state(): void {
		( new ContentIndexRepository() )->search_items(
			array(
				'post_type'      => 'page',
				'status'         => 'publish',
				'stale'          => false,
				'max_word_count' => 300,
			)
		);

		$first_query = $this->wpdb->prepared[0]['query'];
		$first_args  = $this->wpdb->prepared[0]['args'];

		self::assertStringContainsString( 'post_type = %s', $first_query );
		self::assertStringContainsString( 'post_status = %s', $first_query );
		self::assertStringContainsString( 'stale = %d', $first_query );
		self::assertStringContainsString( 'word_count <= %d', $first_query );
		self::assertContains( 'page', $first_args );
		self::assertContains( 'publish', $first_args );
		self::assertContains( 0, $first_args );
		self::assertContains( 300, $first_args );
	}

	public function test_content_search_can_skip_total_and_index_summary_queries(): void {
		$result = ( new ContentIndexRepository() )->search_items(
			array(
				'per_page'              => 10,
				'include_total'         => false,
				'include_index_summary' => false,
			)
		);

		self::assertArrayNotHasKey( 'total', $result );
		self::assertArrayNotHasKey( 'index', $result );
		self::assertArrayHasKey( 'has_more', $result );
		self::assertCount( 1, $this->wpdb->prepared );
		self::assertStringNotContainsString( 'COUNT(*)', $this->wpdb->prepared[0]['query'] );
		self::assertContains( 11, $this->wpdb->prepared[0]['args'] );
	}

	public function test_internal_link_audit_filters_by_state_post_type_status_and_thresholds(): void {
		( new ContentIndexRepository() )->internal_link_audit(
			array(
				'state'             => 'underlinked',
				'post_type'         => 'page',
				'status'            => 'publish',
				'min_inbound_links' => 3,
			)
		);

		$first_query = $this->wpdb->prepared[0]['query'];
		$first_args  = $this->wpdb->prepared[0]['args'];

		self::assertStringContainsString( 'FROM %i idx LEFT JOIN %i inbound', $first_query );
		self::assertStringContainsString( 'LEFT JOIN %i outbound', $first_query );
		self::assertStringContainsString( 'idx.post_type = %s', $first_query );
		self::assertStringContainsString( 'idx.post_status = %s', $first_query );
		self::assertStringContainsString( 'HAVING inbound_internal_links > 0 AND inbound_internal_links < %d', $first_query );
		self::assertStringNotContainsString( 'idx.id', $first_query );
		self::assertContains( 'page', $first_args );
		self::assertContains( 'publish', $first_args );
		self::assertContains( 3, $first_args );
	}

	public function test_internal_link_audit_defaults_to_needs_review_thresholds(): void {
		$result = ( new ContentIndexRepository() )->internal_link_audit( array() );

		$first_query = $this->wpdb->prepared[0]['query'];
		$first_args  = $this->wpdb->prepared[0]['args'];

		self::assertSame( 'needs_review', $result['filters']['state'] );
		self::assertSame(
			array(
				'min_inbound_links'  => 2,
				'thin_word_count'    => 300,
				'max_outbound_links' => 25,
			),
			$result['thresholds']
		);
		self::assertStringContainsString( 'HAVING inbound_internal_links = 0 OR (inbound_internal_links > 0 AND inbound_internal_links < %d) OR idx.word_count <= %d OR idx.stale = 1 OR outbound_internal_links > %d', $first_query );
		self::assertContains( 2, $first_args );
		self::assertContains( 300, $first_args );
		self::assertContains( 25, $first_args );
	}
}
