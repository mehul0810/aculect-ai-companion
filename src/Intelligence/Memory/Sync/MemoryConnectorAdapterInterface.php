<?php
/**
 * Neutral synchronization port for optional memory connectors.
 *
 * @package Aculect\AICompanion\Intelligence\Memory\Sync
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Memory\Sync;

/**
 * Provider adapters implement bounded asynchronous import and export batches.
 */
interface MemoryConnectorAdapterInterface {

	public function id(): string;

	/**
	 * Pull external changes as untrusted pending proposals.
	 *
	 * @param string $cursor Provider checkpoint.
	 * @param int    $limit  Maximum records.
	 * @return array{items:list<array<string, mixed>>,cursor:string,has_more:bool}
	 */
	public function pull( string $cursor, int $limit ): array;

	/**
	 * Push approved, non-sensitive site changes with idempotency keys.
	 *
	 * @param list<array<string, mixed>> $changes Approved outbound changes.
	 * @param string                     $cursor  Provider checkpoint.
	 * @return array{accepted:list<string>,rejected:array<string, string>,cursor:string}
	 */
	public function push( array $changes, string $cursor ): array;
}
