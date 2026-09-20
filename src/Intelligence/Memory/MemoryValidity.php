<?php
/**
 * Shared temporal eligibility for memory retrieval and synchronization.
 *
 * @package Aculect\AICompanion\Intelligence\Memory
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Memory;

/** Keeps SQL and in-memory live-record checks on the same UTC boundaries. */
final class MemoryValidity {
	public const SQL = 'deleted_at IS NULL AND (valid_from IS NULL OR valid_from <= %s) AND (expires_at IS NULL OR expires_at > %s)';

	/**
	 * Test the half-open validity interval [valid_from, expires_at).
	 *
	 * @param array<string, mixed> $record Memory record.
	 * @param string|null          $now    UTC timestamp for deterministic proof.
	 */
	public static function is_live( array $record, ?string $now = null ): bool {
		$now ??= gmdate( 'Y-m-d H:i:s' );
		return empty( $record['deleted_at'] )
			&& ( empty( $record['valid_from'] ) || (string) $record['valid_from'] <= $now )
			&& ( empty( $record['expires_at'] ) || (string) $record['expires_at'] > $now );
	}
}
