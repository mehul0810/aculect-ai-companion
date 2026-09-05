<?php
/**
 * Transactional application service for Aculect Memory.
 *
 * @package Aculect\AICompanion\Intelligence\Memory
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Memory;

/**
 * Keeps record mutations and their immutable events consistent.
 */
final class MemoryService {
	private const MAX_BATCH = 100;

	public function __construct(
		private readonly ?MemoryRepository $memories = null,
		private readonly ?MemoryEventRepository $events = null,
		private readonly ?MemoryStorageRequirements $storage = null
	) {
	}

	/**
	 * Save a proposal or approved record with optimistic concurrency.
	 *
	 * @param array<string, mixed> $input Memory input.
	 * @return array<string, mixed>
	 */
	public function save( array $input ): array {
		return $this->mutate(
			static fn ( MemoryRepository $repository ): array => $repository->save( $input ),
			'updated',
			$input
		);
	}

	/**
	 * Save a bounded caller-supplied batch and return successful records.
	 *
	 * @param list<array<string, mixed>> $items Memory inputs.
	 * @return array{saved:list<array<string, mixed>>,failures:list<array{index:int,error:string}>,truncated:bool}
	 */
	public function save_batch( array $items ): array {
		$saved     = array();
		$failures  = array();
		$truncated = count( $items ) > self::MAX_BATCH;
		foreach ( array_slice( $items, 0, self::MAX_BATCH ) as $index => $item ) {
			$result = $this->save( $item );
			if ( 'success' === ( $result['status'] ?? '' ) && is_array( $result['memory'] ?? null ) ) {
				$saved[] = $result['memory'];
			} else {
				$failures[] = array(
					'index' => $index,
					'error' => (string) ( $result['error'] ?? 'memory_save_failed' ),
				);
			}
		}
		if ( $truncated ) {
			$failures[] = array(
				'index' => self::MAX_BATCH,
				'error' => 'memory_batch_truncated',
			);
		}

		return compact( 'saved', 'failures', 'truncated' );
	}

	/**
	 * Create a synchronization-safe tombstone.
	 *
	 * @param array<string, mixed> $input Forget input.
	 * @return array<string, mixed>
	 */
	public function forget( array $input ): array {
		$key       = is_scalar( $input['key'] ?? null ) ? (string) $input['key'] : '';
		$expected  = isset( $input['expected_version'] ) ? absint( $input['expected_version'] ) : null;
		$namespace = is_scalar( $input['namespace'] ?? null ) ? (string) $input['namespace'] : 'site';

		return $this->mutate(
			static fn ( MemoryRepository $repository ): array => $repository->forget( $key, $namespace, $expected ),
			'forgotten',
			$input
		);
	}

	/**
	 * Return immutable history for one memory.
	 *
	 * @param string $memory_uuid Stable memory UUID.
	 * @param string $namespace   Site-owned namespace.
	 * @param int    $limit       Maximum history events.
	 * @return list<array<string, mixed>>
	 */
	public function history( string $memory_uuid, string $namespace = 'site', int $limit = 25 ): array {
		return $this->event_repository()->history( $memory_uuid, $namespace, $limit );
	}

	/**
	 * Return an ordered synchronization change feed.
	 *
	 * @param array<string, mixed> $args Feed arguments.
	 * @return array{items:list<array<string, mixed>>,cursor:int,has_more:bool}
	 */
	public function changes( array $args = array() ): array {
		return $this->event_repository()->changes( $args );
	}

	/**
	 * Execute a record mutation and event append in one database transaction.
	 *
	 * @param callable             $callback  Mutation callback accepting the memory repository.
	 * @param string               $event_type Event type.
	 * @param array<string, mixed> $input Input context.
	 * @return array<string, mixed>
	 */
	private function mutate( callable $callback, string $event_type, array $input ): array {
		global $wpdb;
		/** @var \wpdb $wpdb */ // phpcs:ignore Generic.Commenting.DocComment.MissingShort

		if ( ! method_exists( $wpdb, 'query' ) || ! $this->storage_requirements()->supports_transactions() ) {
			return $this->transaction_error();
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return $this->transaction_error();
		}
		$result = $callback( $this->memory_repository() );
		$memory = is_array( $result['memory'] ?? null ) ? $result['memory'] : array();
		if ( 'success' !== ( $result['status'] ?? '' ) || array() === $memory ) {
			$wpdb->query( 'ROLLBACK' );
			return $result;
		}

		$event_saved = $this->event_repository()->append(
			array(
				'memory_uuid'    => $memory['memory_uuid'] ?? $memory['uuid'] ?? '',
				'namespace'      => $memory['namespace'] ?? 'site',
				'event_type'     => $event_type,
				'memory_version' => $memory['version'] ?? 1,
				'actor_user_id'  => get_current_user_id(),
				'connection_id'  => $input['connection_id'] ?? '',
				'payload'        => array(
					'key'          => $memory['memory_key'] ?? $memory['key'] ?? '',
					'content_hash' => $memory['content_hash'] ?? '',
					'status'       => $memory['status'] ?? '',
					'deleted'      => ! empty( $memory['deleted_at'] ),
				),
			)
		);
		if ( ! $event_saved ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'status'  => 'error',
				'error'   => 'memory_event_failed',
				'message' => 'Memory history could not be recorded.',
			);
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $this->transaction_error();
		}
		$result['event_recorded'] = true;
		return $result;
	}

	private function memory_repository(): MemoryRepository {
		return $this->memories ?? new MemoryRepository();
	}

	private function event_repository(): MemoryEventRepository {
		return $this->events ?? new MemoryEventRepository();
	}

	private function storage_requirements(): MemoryStorageRequirements {
		return $this->storage ?? new MemoryStorageRequirements();
	}

	/**
	 * Return a fail-closed transaction error without database details.
	 *
	 * @return array{status:string,error:string,message:string}
	 */
	private function transaction_error(): array {
		return array(
			'status'  => 'error',
			'error'   => 'memory_transaction_failed',
			'message' => 'Memory and history could not be saved atomically.',
		);
	}
}
