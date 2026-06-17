<?php
/**
 * Tests for MCP intelligence index abilities.
 *
 * @package Aculect\AICompanion\Tests\Unit\Connectors\MCP
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Unit\Connectors\MCP;

use Aculect\AICompanion\Connectors\MCP\IntelligenceIndexAbilities;
use PHPUnit\Framework\TestCase;

/**
 * Verifies provider-facing intelligence index responses stay aligned with public tool names.
 */
final class IntelligenceIndexAbilitiesTest extends TestCase {

	private mixed $original_wpdb = null;

	private IntelligenceIndexMemoryWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->wpdb          = new IntelligenceIndexMemoryWpdb();

		$GLOBALS['wpdb']                                  = $this->wpdb;
		$GLOBALS['aculect_ai_companion_test_posts']       = array();
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array();
		$GLOBALS['aculect_ai_companion_test_options']     = array(
			'blogname' => 'Aculect Demo',
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

	public function test_memory_save_dry_run_uses_registered_internal_action(): void {
		$result = ( new IntelligenceIndexAbilities() )->save_memory(
			array(
				'key'     => 'brand.voice.primary',
				'value'   => 'Use a concise, expert tone.',
				'dry_run' => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'memory.save', $result['action'] );
		self::assertSame( 'update', $result['risk_level'] );
		self::assertTrue( $result['confirmation_required'] );
		self::assertSame( 'status', $result['changes'][1]['field'] );
		self::assertSame( 'pending', $result['changes'][1]['to'] );
		self::assertStringContainsString( 'admin review', $result['warnings'][0] );
	}

	public function test_empty_memory_save_bootstraps_initial_memory_preview(): void {
		$result = ( new IntelligenceIndexAbilities() )->save_memory(
			array(
				'dry_run' => true,
			)
		);

		self::assertSame( 'preview', $result['status'] );
		self::assertSame( 'memory.save', $result['action'] );
		self::assertSame( 'memory_bootstrap', $result['target']['type'] );
		self::assertTrue( $result['confirmation_required'] );
		self::assertNotEmpty( $result['items'] );
		self::assertContains( 'workflow.blocks.no_custom_html', array_column( $result['items'], 'key' ) );
	}

	public function test_memory_bootstrap_saves_initial_guidance(): void {
		$result = ( new IntelligenceIndexAbilities() )->bootstrap_memory(
			array(
				'status' => 'approved',
			)
		);

		self::assertSame( 'success', $result['status'] );
		self::assertSame( 'approved', $result['review_status']['status'] );
		self::assertFalse( $result['review_status']['admin_review_required'] );
		self::assertArrayHasKey( 'workflow.blocks.no_custom_html', $this->wpdb->rows );
		self::assertSame( 'approved', $this->wpdb->rows['workflow.blocks.no_custom_html']['status'] );
	}

	public function test_canonical_search_returns_empty_results_without_query(): void {
		$result = ( new IntelligenceIndexAbilities() )->canonical_search( array( 'query' => '' ) );

		self::assertSame( array(), $result['results'] );
	}

	public function test_canonical_fetch_returns_readable_post_document(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123] = new \WP_Post(
			array(
				'ID'           => 123,
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_title'   => 'Canonical Retrieval',
				'post_content' => '<!-- wp:paragraph --><p>Hello <strong>world</strong>.</p><!-- /wp:paragraph -->',
				'post_name'    => 'canonical-retrieval',
			)
		);

		$result = ( new IntelligenceIndexAbilities() )->canonical_fetch( array( 'id' => 'wp-post:123' ) );

		self::assertSame( 'wp-post:123', $result['id'] );
		self::assertSame( 'Canonical Retrieval', $result['title'] );
		self::assertSame( 'Hello world.', $result['text'] );
		self::assertSame( 'https://example.com/?p=123', $result['url'] );
		self::assertSame( 123, $result['metadata']['post_id'] );
		self::assertSame( 'post', $result['metadata']['post_type'] );
		self::assertSame( 'publish', $result['metadata']['status'] );

		$plain_id_result = ( new IntelligenceIndexAbilities() )->canonical_fetch( array( 'id' => '123' ) );
		self::assertSame( $result['id'], $plain_id_result['id'] );
	}

	public function test_canonical_fetch_respects_read_post_permission(): void {
		$GLOBALS['aculect_ai_companion_test_posts'][123]  = new \WP_Post(
			array(
				'ID'           => 123,
				'post_title'   => 'Private Post',
				'post_content' => 'Secret',
			)
		);
		$GLOBALS['aculect_ai_companion_test_denied_caps'] = array( 'read_post' );

		$result = ( new IntelligenceIndexAbilities() )->canonical_fetch( array( 'id' => 'wp-post:123' ) );

		self::assertSame( 'error', $result['status'] );
		self::assertSame( 'forbidden', $result['error'] );
	}
}

/**
 * Minimal wpdb double for memory bootstrap side effects.
 */
final class IntelligenceIndexMemoryWpdb {

	public string $prefix = 'wp_';

	/**
	 * @var array<string, array<string, mixed>>
	 */
	public array $rows = array();

	/**
	 * @var array<int, mixed>
	 */
	private array $last_args = array();

	public function prepare( string $query, mixed ...$args ): string {
		$this->last_args = $args;

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
	 * @return list<array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $query, $output );

		return array_values( $this->rows );
	}

	/**
	 * @param array<string, mixed> $data Row data.
	 * @param array<int, string>   $formats Insert formats.
	 */
	public function insert( string $table, array $data, array $formats ): int {
		unset( $table, $formats );

		$data['id']                                  = count( $this->rows ) + 1;
		$this->rows[ (string) $data['memory_key'] ] = $data;

		return 1;
	}

	/**
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
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output ): ?array {
		unset( $query, $output );

		return $this->rows[ $this->last_memory_key() ] ?? null;
	}

	private function last_memory_key(): string {
		return (string) ( $this->last_args[1] ?? '' );
	}
}
