<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence;

use Aculect\AICompanion\Intelligence\Database\Installer;

/**
 * Atomically replaces the parent, chunk, and link rows for one index projection.
 */
final class ContentProjectionWriter {

	public function __construct(
		private readonly ContentIndexRepository $repository
	) {
	}

	/**
	 * Replace one complete content projection.
	 *
	 * @param array<string, mixed>       $record Indexed content row.
	 * @param list<array<string, mixed>> $chunks Chunk rows.
	 * @param list<array<string, mixed>> $links  Link rows.
	 * @return array{chunks: int, links: int}|false
	 */
	public function replace( array $record, array $chunks, array $links ): array|false {
		global $wpdb;
		/**
		 * WordPress database abstraction.
		 *
		 * @var \wpdb $wpdb
		 */

		if ( ! is_callable( array( $wpdb, 'query' ) ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
			return false;
		}

		$object_id = absint( $record['object_id'] ?? 0 );
		$committed = false;
		try {
			if ( 0 >= $object_id || ! $this->repository->upsert_content_item( $record ) ) {
				return false;
			}

			$chunk_count = $this->repository->replace_chunks( $object_id, $chunks );
			$link_count  = false === $chunk_count ? false : $this->replace_links( $object_id, $links );
			if ( false === $chunk_count || false === $link_count || false === $wpdb->query( 'COMMIT' ) ) {
				return false;
			}

			$committed = true;
			return array(
				'chunks' => $chunk_count,
				'links'  => $link_count,
			);
		} finally {
			if ( ! $committed ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}

	/**
	 * Replace outbound links for one content item.
	 *
	 * @param int                        $source_id Source content ID.
	 * @param list<array<string, mixed>> $links Link rows.
	 */
	private function replace_links( int $source_id, array $links ): int|false {
		global $wpdb;

		if ( false === $wpdb->delete( Installer::link_graph_table(), array( 'source_id' => $source_id ), array( '%d' ) ) ) {
			return false;
		}

		$inserted = 0;
		foreach ( array_slice( $links, 0, 300 ) as $link ) {
			$result = $wpdb->insert(
				Installer::link_graph_table(),
				array(
					'source_id'   => $source_id,
					'target_id'   => isset( $link['target_id'] ) ? absint( $link['target_id'] ) : null,
					'target_url'  => is_scalar( $link['target_url'] ?? null ) ? esc_url_raw( (string) $link['target_url'] ) : '',
					'anchor_text' => $this->text( $link['anchor_text'] ?? '', 255 ),
					'rel'         => $this->text( $link['rel'] ?? '', 80 ),
					'context'     => $this->text( $link['context'] ?? '', 1000 ),
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( 1 !== (int) $result ) {
				return false;
			}
			++$inserted;
		}

		return $inserted;
	}

	private function text( mixed $value, int $limit ): string {
		return substr( sanitize_text_field( is_scalar( $value ) ? (string) $value : '' ), 0, $limit );
	}
}
