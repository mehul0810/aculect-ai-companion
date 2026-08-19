<?php
/**
 * Immutable workflow requirement availability snapshot.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

use stdClass;

/**
 * Carries detached adapter and ability availability without granting access.
 */
final readonly class WorkflowAvailabilitySnapshot {

	private const MAX_ADAPTERS             = 50;
	private const MAX_ABILITIES            = 50;
	private const MAX_VERSIONS_PER_ADAPTER = 50;

	/**
	 * Normalized adapter availability.
	 *
	 * @var list<array{adapter_id:string,adapter_versions:list<int>}>
	 */
	private array $adapters;

	/**
	 * Normalized ability availability.
	 *
	 * @var list<string>
	 */
	private array $abilities;

	/**
	 * Create a validated availability snapshot.
	 *
	 * @param array $adapters  Available adapter IDs and exact versions.
	 * @param array $abilities Available ability IDs.
	 * @throws WorkflowPlanningException When availability is malformed.
	 * @phpstan-param list<array{adapter_id:string,adapter_versions:list<int>}|stdClass> $adapters
	 * @phpstan-param list<string> $abilities
	 */
	public function __construct(
		array $adapters,
		array $abilities
	) {
		$this->adapters  = $this->validate_adapters( $adapters );
		$this->abilities = $this->validate_abilities( $abilities );
	}

	/**
	 * Return detached adapter availability sorted by ID and version.
	 *
	 * @return list<array{adapter_id:string,adapter_versions:list<int>}>
	 */
	public function adapters(): array {
		return array_map(
			static fn ( array $adapter ): array => array(
				'adapter_id'       => $adapter['adapter_id'],
				'adapter_versions' => array_values( $adapter['adapter_versions'] ),
			),
			$this->adapters
		);
	}

	/**
	 * Return detached ability availability sorted by ID.
	 *
	 * @return list<string>
	 */
	public function abilities(): array {
		return array_values( $this->abilities );
	}

	/**
	 * Validate and normalize adapter availability.
	 *
	 * @param array $adapters Raw adapter availability.
	 * @return list<array{adapter_id:string,adapter_versions:list<int>}>
	 * @throws WorkflowPlanningException When an adapter entry is malformed.
	 * @phpstan-param list<array{adapter_id:string,adapter_versions:list<int>}|stdClass> $adapters
	 */
	private function validate_adapters( array $adapters ): array {
		if ( ! array_is_list( $adapters ) || count( $adapters ) > self::MAX_ADAPTERS ) {
			throw new WorkflowPlanningException( 'invalid_availability', '$.adapters' );
		}

		$seen = array();
		foreach ( $adapters as $index => $adapter_value ) {
			$path    = '$.adapters[' . $index . ']';
			$adapter = $adapter_value instanceof stdClass ? get_object_vars( $adapter_value ) : $adapter_value;
			if ( ! is_array( $adapter ) ) {
				$this->fail( 'invalid_availability', $path );
			}
			$keys = array_keys( $adapter );
			sort( $keys, SORT_STRING );
			if ( array( 'adapter_id', 'adapter_versions' ) !== $keys ) {
				$this->fail( 'invalid_availability', $path );
			}

			$adapter_id = $adapter['adapter_id'];
			$versions   = $adapter['adapter_versions'];
			if ( ! is_string( $adapter_id ) || strlen( $adapter_id ) < 2 || strlen( $adapter_id ) > 64 || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/D', $adapter_id ) ) {
				$this->fail( 'invalid_adapter_id', $path . '.adapter_id' );
			}
			if ( isset( $seen[ $adapter_id ] ) ) {
				$this->fail( 'duplicate_adapter_id', $path . '.adapter_id' );
			}
			if ( ! is_array( $versions ) || ! array_is_list( $versions ) || array() === $versions || count( $versions ) > self::MAX_VERSIONS_PER_ADAPTER ) {
				$this->fail( 'invalid_adapter_versions', $path . '.adapter_versions' );
			}

			$version_seen = array();
			foreach ( $versions as $version_index => $version ) {
				if ( ! is_int( $version ) || $version < 1 ) {
					$this->fail( 'invalid_adapter_version', $path . '.adapter_versions[' . $version_index . ']' );
				}
				if ( isset( $version_seen[ $version ] ) ) {
					$this->fail( 'duplicate_adapter_version', $path . '.adapter_versions[' . $version_index . ']' );
				}
				$version_seen[ $version ] = true;
			}

			$normalized_versions = array_keys( $version_seen );
			sort( $normalized_versions, SORT_NUMERIC );
			$seen[ $adapter_id ] = array(
				'adapter_id'       => $adapter_id,
				'adapter_versions' => $normalized_versions,
			);
		}

		ksort( $seen, SORT_STRING );

		return array_values( $seen );
	}

	/**
	 * Validate and normalize ability availability.
	 *
	 * @param array $abilities Raw ability availability.
	 * @return list<string>
	 * @throws WorkflowPlanningException When an ability ID is malformed.
	 * @phpstan-param list<string> $abilities
	 */
	private function validate_abilities( array $abilities ): array {
		if ( ! array_is_list( $abilities ) || count( $abilities ) > self::MAX_ABILITIES ) {
			throw new WorkflowPlanningException( 'invalid_availability', '$.abilities' );
		}

		$seen = array();
		foreach ( $abilities as $index => $ability_id ) {
			$path = '$.abilities[' . $index . ']';
			if ( ! is_string( $ability_id ) || strlen( $ability_id ) > 128 || 1 !== preg_match( '#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*$#D', $ability_id ) ) {
				$this->fail( 'invalid_ability_id', $path );
			}
			if ( isset( $seen[ $ability_id ] ) ) {
				$this->fail( 'duplicate_ability_id', $path );
			}
			$seen[ $ability_id ] = true;
		}

		$abilities = array_keys( $seen );
		sort( $abilities, SORT_STRING );

		return $abilities;
	}

	/**
	 * Throw a bounded availability failure.
	 *
	 * @param string $code Stable error code.
	 * @param string $path Bounded structural path.
	 * @throws WorkflowPlanningException Always.
	 */
	private function fail( string $code, string $path ): never {
		throw new WorkflowPlanningException( $code, $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal bounded evidence only.
	}
}
