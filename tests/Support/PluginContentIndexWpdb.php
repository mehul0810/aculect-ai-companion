<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Tests\Support;

use Aculect\AICompanion\Intelligence\Database\Installer;

/**
 * Transaction-aware WordPress database double for content-index save tests.
 */
final class PluginContentIndexWpdb {

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
	public int $replace_calls        = 0;
	public int $insert_calls         = 0;
	public bool $fail_replacements   = false;
	public bool $fail_deletions      = false;
	public int $fail_insert_call     = 0;
	/** @var array<string, mixed>|null */
	private ?array $transaction_snapshot = null;

	public function query( string $query ): int|false {
		if ( 'START TRANSACTION' === $query ) {
			$this->transaction_snapshot = array(
				'content_rows' => $this->content_rows,
				'chunk_rows'   => $this->chunk_rows,
				'link_rows'    => $this->link_rows,
			);
			return 0;
		}

		if ( 'ROLLBACK' === $query ) {
			if ( null !== $this->transaction_snapshot ) {
				$this->content_rows = $this->transaction_snapshot['content_rows'];
				$this->chunk_rows   = $this->transaction_snapshot['chunk_rows'];
				$this->link_rows    = $this->transaction_snapshot['link_rows'];
			}
			$this->transaction_snapshot = null;
			return 0;
		}

		if ( 'COMMIT' === $query ) {
			$this->transaction_snapshot = null;
			return 0;
		}

		return false;
	}

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
		++$this->replace_calls;

		if ( Installer::content_index_table() !== $table || $this->fail_replacements ) {
			return false;
		}

		$before_callback = $GLOBALS['aculect_ai_companion_test_before_content_replace'] ?? null;
		if ( is_callable( $before_callback ) ) {
			$GLOBALS['aculect_ai_companion_test_before_content_replace'] = null;
			$before_callback( (int) $data['object_id'] );
		}
		$this->content_rows[ (int) $data['object_id'] ] = $data;
		$callback = $GLOBALS['aculect_ai_companion_test_after_content_replace'] ?? null;
		if ( is_callable( $callback ) ) {
			$GLOBALS['aculect_ai_companion_test_after_content_replace'] = null;
			$callback( (int) $data['object_id'] );
		}

		return 1;
	}

	public function insert( string $table, array $data, array $formats ): int|false {
		unset( $formats );
		++$this->insert_calls;
		if ( $this->insert_calls === $this->fail_insert_call ) {
			return false;
		}

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
		if ( $this->fail_deletions ) {
			return false;
		}

		if ( Installer::content_index_table() === $table ) {
			$object_id = (int) ( $where['object_id'] ?? 0 );
			unset( $this->content_rows[ $object_id ] );
			$callback = $GLOBALS['aculect_ai_companion_test_after_content_delete'] ?? null;
			if ( is_callable( $callback ) ) {
				$GLOBALS['aculect_ai_companion_test_after_content_delete'] = null;
				$callback( $object_id );
			}
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

	public function get_row( string $query, string $output ): ?array {
		unset( $query, $output );
		$object_id = (int) ( $this->last_prepare_args[1] ?? 0 );

		return $this->content_rows[ $object_id ] ?? null;
	}

	/** @return list<int> */
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

		return array_map( static fn ( array $row ): int => (int) $row['object_id'], $rows );
	}
}
