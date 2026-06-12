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
				unset( $query );

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
}
