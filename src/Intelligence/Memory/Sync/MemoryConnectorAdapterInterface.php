<?php
/**
 * Neutral synchronization port for optional memory connectors.
 *
 * @package Aculect\AICompanion\Intelligence\Memory\Sync
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Memory\Sync;

/**
 * Adapters implement bounded, caller-checkpointed import and export batches.
 */
interface MemoryConnectorAdapterInterface {

	public function id(): string;

	/**
	 * Pull changes from the adapter-owned store; transport-specific policy controls eligibility.
	 *
	 * @param string $cursor Provider checkpoint.
	 * @param int    $limit  Maximum records.
	 * @return array{items:list<array<string, mixed>>,cursor:string,has_more:bool}
	 */
	public function pull( string $cursor, int $limit ): array;

	/**
	 * Push versioned changes; site adapters quarantine imports as pending proposals.
	 *
	 * @param list<array<string, mixed>> $changes Approved outbound changes.
	 * @param string                     $cursor  Provider checkpoint.
	 * @return array{accepted:list<string>,rejected:array<string, string>,cursor:string}
	 */
	public function push( array $changes, string $cursor ): array;
}
