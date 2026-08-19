<?php
/**
 * Detached workflow requirement availability snapshot.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

/**
 * Carries exact available IDs; it is never an authorization or capability grant.
 *
 * Runtime callers must perform their own policy and capability checks before
 * supplying this storage-independent snapshot to planning.
 */
final readonly class WorkflowAvailabilitySnapshot {

	/** Maximum requirement IDs accepted per kind. */
	public const MAX_IDS = 50;

	/**
	 * Create one normalized detached availability snapshot.
	 *
	 * @param array $adapter_ids Exact available adapter IDs.
	 * @param array $ability_ids Exact available ability IDs.
	 * @phpstan-param list<string> $adapter_ids
	 * @phpstan-param list<string> $ability_ids
	 */
	private function __construct(
		private array $adapter_ids,
		private array $ability_ids
	) {
	}

	/**
	 * Build a snapshot from an untrusted exact-key map.
	 *
	 * @param mixed $value Candidate availability map with adapter_ids and ability_ids.
	 * @throws WorkflowPlanningException When the snapshot is malformed or unbounded.
	 */
	public static function from_value( mixed $value ): self {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.availability' );
		}

		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		if ( array( 'ability_ids', 'adapter_ids' ) !== $keys ) {
			throw new WorkflowPlanningException( 'invalid_request', '$.availability' );
		}

		return new self(
			self::normalize_ids( $value['adapter_ids'], '$.availability.adapter_ids', true ),
			self::normalize_ids( $value['ability_ids'], '$.availability.ability_ids', false )
		);
	}

	/**
	 * Build a snapshot from separately supplied exact-ID lists.
	 *
	 * @param array $adapter_ids Candidate available adapter IDs.
	 * @param array $ability_ids Candidate available ability IDs.
	 * @throws WorkflowPlanningException When either list is malformed or unbounded.
	 * @phpstan-param list<string> $adapter_ids
	 * @phpstan-param list<string> $ability_ids
	 */
	public static function from_ids( array $adapter_ids, array $ability_ids ): self {
		return self::from_value(
			array(
				'adapter_ids' => $adapter_ids,
				'ability_ids' => $ability_ids,
			)
		);
	}

	/**
	 * Return a detached sorted adapter-ID list.
	 *
	 * @return list<string>
	 */
	public function adapter_ids(): array {
		return $this->adapter_ids;
	}

	/**
	 * Return a detached sorted ability-ID list.
	 *
	 * @return list<string>
	 */
	public function ability_ids(): array {
		return $this->ability_ids;
	}

	/**
	 * Return a detached normalized map without runtime capability information.
	 *
	 * @return array{adapter_ids:list<string>,ability_ids:list<string>}
	 */
	public function to_array(): array {
		return array(
			'adapter_ids' => $this->adapter_ids(),
			'ability_ids' => $this->ability_ids(),
		);
	}

	/**
	 * Validate, deduplicate-check, and sort one exact-ID list.
	 *
	 * @param mixed  $ids        Candidate list.
	 * @param string $path       Public-safe location.
	 * @param bool   $is_adapter Whether adapter rather than ability IDs are expected.
	 * @return list<string>
	 * @throws WorkflowPlanningException When the list is malformed or unbounded.
	 */
	private static function normalize_ids( mixed $ids, string $path, bool $is_adapter ): array {
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) || count( $ids ) > self::MAX_IDS ) {
			throw new WorkflowPlanningException( 'invalid_request', $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
		}

		$normalized = array();
		$seen       = array();
		foreach ( $ids as $index => $id ) {
			if ( ! is_string( $id ) || ! self::valid_id( $id, $is_adapter ) || isset( $seen[ $id ] ) ) {
				throw new WorkflowPlanningException( 'invalid_request', $path . '[' . $index . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
			}

			$seen[ $id ]  = true;
			$normalized[] = $id;
		}

		sort( $normalized, SORT_STRING );

		return $normalized;
	}

	/**
	 * Validate one exact definition-compatible adapter or ability ID.
	 *
	 * @param string $id         Candidate ID.
	 * @param bool   $is_adapter Whether adapter rather than ability IDs are expected.
	 */
	private static function valid_id( string $id, bool $is_adapter ): bool {
		if ( $is_adapter ) {
			return strlen( $id ) >= 2
				&& strlen( $id ) <= 64
				&& 1 === preg_match( '/^[a-z][a-z0-9_]*$/D', $id );
		}

		return strlen( $id ) <= 128
			&& 1 === preg_match( '#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*$#D', $id );
	}
}
