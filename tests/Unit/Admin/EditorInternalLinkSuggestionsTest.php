<?php
/**
 * Tests for editor-side internal link suggestion payloads.
 *
 * @package Aculect\AICompanion\Tests\Unit\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Admin;

use Aculect\AICompanion\Admin\EditorInternalLinkSuggestions;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Focused editor payload tests replace wpdb with a local test double.

/**
 * Verifies editor internal-link payloads stay read-only and bounded.
 */
final class EditorInternalLinkSuggestionsTest extends TestCase {

	private mixed $original_wpdb = null;

	private EditorInternalLinkSuggestionsWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = new EditorInternalLinkSuggestionsWpdb();

		$GLOBALS['wpdb']                                      = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_posts']           = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps']     = array();
		$GLOBALS['aculect_ai_companion_test_denied_post_ids'] = array();
		$GLOBALS['aculect_ai_companion_test_post_meta']       = array();
		$GLOBALS['aculect_ai_companion_test_post_type_supports'] = array(
			'post' => array( 'editor' ),
			'page' => array( 'editor' ),
		);
	}

	protected function tearDown(): void {
		if ( null !== $this->original_wpdb ) {
			$GLOBALS['wpdb'] = $this->original_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}

		unset( $GLOBALS['aculect_ai_companion_test_post_type_supports'] );

		parent::tearDown();
	}

	public function test_payload_returns_suggestions_and_already_linked_rows_without_apply(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][101] = new \WP_Post(
			array(
				'ID'          => 101,
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Source Guide',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][202] = new \WP_Post(
			array(
				'ID'          => 202,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Internal Link Strategy',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][303] = new \WP_Post(
			array(
				'ID'          => 303,
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Already Linked Target',
			)
		);

		$this->wpdb->rows['content-101']    = $this->indexed_content_row( 101, 'Source Guide', 'Source guide about link strategy.' );
		$this->wpdb->rows['content-202']    = $this->indexed_content_row( 202, 'Internal Link Strategy', 'Practical internal link strategy for editors.' );
		$this->wpdb->rows['content-303']    = $this->indexed_content_row( 303, 'Already Linked Target', 'A target already linked from the source.' );
		$this->wpdb->linked_target_ids[101] = array( 303 );
		$this->wpdb->link_stats             = array(
			101 => array(
				'object_id'               => 101,
				'inbound_internal_links'  => 1,
				'outbound_internal_links' => 1,
			),
			202 => array(
				'object_id'               => 202,
				'inbound_internal_links'  => 0,
				'outbound_internal_links' => 2,
			),
		);

		$payload = ( new EditorInternalLinkSuggestions() )->payload_for_post( 101 );

		self::assertSame( 'ready', $payload['status'] );
		self::assertTrue( $payload['readOnly'] );
		self::assertFalse( $payload['apply']['available'] );
		self::assertSame( 202, $payload['items'][0]['postId'] );
		self::assertSame( 'Internal Link Strategy', $payload['items'][0]['anchor'] );
		self::assertFalse( $payload['items'][0]['alreadyLinked'] );
		self::assertNotEmpty( $payload['items'][0]['reason'] );
		self::assertSame( 303, $payload['alreadyLinkedItems'][0]['postId'] );
		self::assertTrue( $payload['alreadyLinkedItems'][0]['alreadyLinked'] );
		self::assertFalse( $payload['alreadyLinkedItems'][0]['actions']['apply'] );
	}

	public function test_payload_reports_stale_source_index(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][101] = new \WP_Post(
			array(
				'ID'          => 101,
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Source Guide',
			)
		);
		$GLOBALS['aculect_ai_companion_test_posts'][202] = new \WP_Post(
			array(
				'ID'          => 202,
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Target Guide',
			)
		);

		$this->wpdb->rows['content-101']          = $this->indexed_content_row( 101, 'Source Guide', 'Source guide about links.', true );
		$this->wpdb->rows['content-202']          = $this->indexed_content_row( 202, 'Target Guide', 'Target guide about links.' );
		$this->wpdb->link_stats[101]['object_id'] = 101;
		$this->wpdb->link_stats[202]['object_id'] = 202;

		$payload = ( new EditorInternalLinkSuggestions() )->payload_for_post( 101 );

		self::assertSame( 'ready', $payload['status'] );
		self::assertTrue( $payload['source']['stale'] );
		self::assertStringContainsString( 'stale index row', $payload['message'] );
		self::assertSame( 1, $payload['index']['stale_items'] );
	}

	public function test_payload_reports_empty_index_without_suggestions(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][101] = new \WP_Post(
			array(
				'ID'          => 101,
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_title'  => 'Source Guide',
			)
		);

		$payload = ( new EditorInternalLinkSuggestions() )->payload_for_post( 101 );

		self::assertSame( 'empty_index', $payload['status'] );
		self::assertSame( array(), $payload['items'] );
		self::assertSame( array(), $payload['alreadyLinkedItems'] );
		self::assertStringContainsString( 'index is empty', $payload['message'] );
	}

	/**
	 * Build one indexed content row.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $title   Indexed title.
	 * @param string $summary Indexed summary.
	 * @param bool   $stale   Whether the row is stale.
	 * @return array<string, mixed>
	 */
	private function indexed_content_row( int $post_id, string $title, string $summary, bool $stale = false ): array {
		return array(
			'object_id'    => $post_id,
			'object_type'  => 'post',
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'title'        => $title,
			'slug'         => sanitize_title( $title ),
			'permalink'    => 'https://example.com/?p=' . $post_id,
			'excerpt'      => '',
			'summary'      => $summary,
			'word_count'   => 700,
			'content_hash' => md5( (string) $post_id ),
			'indexed_at'   => '2026-07-03 09:00:00',
			'modified_gmt' => '2026-07-03 08:00:00',
			'stale'        => $stale ? 1 : 0,
			'search_text'  => $title . ' ' . $summary,
			'metadata'     => wp_json_encode(
				array(
					'taxonomy_terms' => array(
						array(
							'taxonomy' => 'category',
							'slug'     => 'links',
							'name'     => 'Links',
						),
					),
				)
			),
		);
	}
}

/**
 * Minimal wpdb double for editor internal-link payload tests.
 */
final class EditorInternalLinkSuggestionsWpdb {

	public string $prefix = 'wp_';

	/**
	 * Stored rows keyed by fixture ID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = array();

	/**
	 * Linked target IDs keyed by source post ID.
	 *
	 * @var array<int, list<int>>
	 */
	public array $linked_target_ids = array();

	/**
	 * Internal-link stats keyed by object ID.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $link_stats = array();

	/**
	 * Existing anchor usage aggregate rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $anchor_usage = array();

	/**
	 * Last prepared query arguments.
	 *
	 * @var array<int, mixed>
	 */
	private array $last_args = array();

	/**
	 * Record prepared query arguments.
	 *
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Prepared values.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		$this->last_args = $args;

		return $query;
	}

	/**
	 * Escape LIKE fragments.
	 *
	 * @param string $text Search text.
	 */
	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * Return one scalar result.
	 *
	 * @param string $query Prepared query.
	 */
	public function get_var( string $query ): int {
		if ( str_contains( $query, 'COUNT(*)' ) ) {
			return count( $this->content_rows() );
		}

		return 0;
	}

	/**
	 * Return one row result.
	 *
	 * @param string $query  Prepared query.
	 * @param string $output Output type.
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output ): ?array {
		unset( $output );

		if ( str_contains( $query, 'COUNT(*) AS total' ) ) {
			$latest = '';
			$stale  = 0;
			foreach ( $this->content_rows() as $row ) {
				$stale += ! empty( $row['stale'] ) ? 1 : 0;
				$latest = max( $latest, (string) ( $row['indexed_at'] ?? '' ) );
			}

			return array(
				'total'             => count( $this->content_rows() ),
				'stale'             => $stale,
				'latest_indexed_at' => $latest,
			);
		}

		return $this->rows[ 'content-' . $this->last_post_id() ] ?? null;
	}

	/**
	 * Return query rows.
	 *
	 * @param string $query  Prepared query.
	 * @param string $output Output type.
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );

		if ( str_contains( $query, 'COUNT(DISTINCT inbound.source_id)' ) && str_contains( $query, 'idx.object_id IN' ) ) {
			return array_values( $this->link_stats );
		}

		if ( str_contains( $query, 'COUNT(*) AS total' ) && str_contains( $query, 'anchor_text' ) ) {
			return $this->anchor_usage;
		}

		if ( str_contains( $query, 'aculect_ai_content_index' ) || str_contains( $query, 'ORDER BY stale ASC' ) ) {
			return $this->content_rows();
		}

		return array();
	}

	/**
	 * Return linked target IDs.
	 *
	 * @param string $query Prepared query.
	 * @return list<int>
	 */
	public function get_col( string $query ): array {
		unset( $query );

		return $this->linked_target_ids[ $this->last_post_id() ] ?? array();
	}

	/**
	 * Return the last integer post ID from prepared args.
	 */
	private function last_post_id(): int {
		for ( $index = count( $this->last_args ) - 1; $index >= 0; --$index ) {
			if ( is_int( $this->last_args[ $index ] ) ) {
				return (int) $this->last_args[ $index ];
			}
		}

		return 0;
	}

	/**
	 * Return indexed content rows.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function content_rows(): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn ( array $row ): bool => isset( $row['object_id'] )
			)
		);
	}
}
