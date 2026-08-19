<?php
/**
 * Immutable workflow requirement availability snapshot.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

use JsonException;
use stdClass;

/**
 * Carries detached exact step-binding availability without granting access.
 */
final readonly class WorkflowAvailabilitySnapshot {

	public const SCHEMA_VERSION    = 2;
	public const MAX_ENCODED_BYTES = 262144;
	public const MAX_BINDINGS      = 50;

	/**
	 * Normalized exact binding availability.
	 *
	 * @var list<array{adapter_id:string,adapter_version:int,ability_id:string,kind:string}>
	 */
	private array $bindings;

	/**
	 * Create a snapshot from validated normalized values.
	 *
	 * @param array $bindings Available exact adapter, ability, and kind tuples.
	 * @phpstan-param list<array{adapter_id:string,adapter_version:int,ability_id:string,kind:string}> $bindings
	 */
	private function __construct( array $bindings ) {
		$this->bindings = $bindings;
	}

	/**
	 * Build from an untrusted object-shaped v2 availability value.
	 *
	 * Legacy adapters/abilities roots are deliberately unsupported because they
	 * cannot prove that one ability belongs to one exact adapter and kind.
	 *
	 * @param mixed $value Candidate map with schema version and exact bindings.
	 * @throws WorkflowPlanningException When availability is malformed.
	 */
	public static function from_value( mixed $value ): self {
		if ( $value instanceof stdClass ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			throw new WorkflowPlanningException( 'invalid_availability_root', '$.availability' );
		}

		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );
		if ( array( 'availability_schema_version', 'bindings' ) !== $keys ) {
			throw new WorkflowPlanningException( 'invalid_availability', '$.availability' );
		}
		if ( ! is_int( $value['availability_schema_version'] ) ) {
			throw new WorkflowPlanningException( 'invalid_availability_schema_version', '$.availability_schema_version' );
		}
		if ( self::SCHEMA_VERSION !== $value['availability_schema_version'] ) {
			throw new WorkflowPlanningException( 'unsupported_availability_schema_version', '$.availability_schema_version' );
		}

		return new self( self::validate_bindings( $value['bindings'] ) );
	}

	/**
	 * Decode and build from bounded object-preserving JSON.
	 *
	 * @param mixed $json Candidate JSON string.
	 * @throws WorkflowPlanningException When JSON is malformed or unbounded.
	 */
	public static function from_json( mixed $json ): self {
		if ( ! is_string( $json ) ) {
			throw new WorkflowPlanningException( 'invalid_availability_json', '$.availability' );
		}
		if ( strlen( $json ) > self::MAX_ENCODED_BYTES ) {
			throw new WorkflowPlanningException( 'availability_too_large', '$.availability' );
		}

		try {
			$value = json_decode( $json, false, 32, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new WorkflowPlanningException( 'invalid_availability_json', '$.availability' );
		}

		return self::from_value( $value );
	}

	/**
	 * Return detached exact bindings in canonical token order.
	 *
	 * @return list<array{adapter_id:string,adapter_version:int,ability_id:string,kind:string}>
	 */
	public function bindings(): array {
		return array_map(
			static fn ( array $binding ): array => array(
				'adapter_id'      => $binding['adapter_id'],
				'adapter_version' => $binding['adapter_version'],
				'ability_id'      => $binding['ability_id'],
				'kind'            => $binding['kind'],
			),
			$this->bindings
		);
	}

	/**
	 * Validate and normalize exact binding availability.
	 *
	 * @param mixed $bindings Raw binding availability.
	 * @return list<array{adapter_id:string,adapter_version:int,ability_id:string,kind:string}>
	 * @throws WorkflowPlanningException When a binding is malformed.
	 */
	private static function validate_bindings( mixed $bindings ): array {
		if ( ! is_array( $bindings ) || ! array_is_list( $bindings ) || count( $bindings ) > self::MAX_BINDINGS ) {
			throw new WorkflowPlanningException( 'invalid_availability', '$.bindings' );
		}

		$seen        = array();
		$seen_owners = array();
		foreach ( $bindings as $index => $binding_value ) {
			$path    = '$.bindings[' . $index . ']';
			$binding = $binding_value instanceof stdClass ? get_object_vars( $binding_value ) : $binding_value;
			if ( ! is_array( $binding ) || array_is_list( $binding ) ) {
				self::fail( 'invalid_availability', $path );
			}

			$keys = array_keys( $binding );
			sort( $keys, SORT_STRING );
			if ( array( 'ability_id', 'adapter_id', 'adapter_version', 'kind' ) !== $keys ) {
				self::fail( 'invalid_availability', $path );
			}

			$adapter_id      = $binding['adapter_id'];
			$adapter_version = $binding['adapter_version'];
			$ability_id      = $binding['ability_id'];
			$kind            = $binding['kind'];
			if ( ! is_string( $adapter_id ) || strlen( $adapter_id ) < 2 || strlen( $adapter_id ) > 64 || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/D', $adapter_id ) ) {
				self::fail( 'invalid_adapter_id', $path . '.adapter_id' );
			}
			if ( ! is_int( $adapter_version ) || $adapter_version < 1 ) {
				self::fail( 'invalid_adapter_version', $path . '.adapter_version' );
			}
			if ( ! is_string( $ability_id ) || strlen( $ability_id ) > 128 || 1 !== preg_match( '#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*$#D', $ability_id ) ) {
				self::fail( 'invalid_ability_id', $path . '.ability_id' );
			}
			if ( ! is_string( $kind ) || ! in_array( $kind, array( 'read', 'proposal', 'write' ), true ) ) {
				self::fail( 'invalid_binding_kind', $path . '.kind' );
			}

			$token = self::token( $adapter_id, $adapter_version, $ability_id, $kind );
			if ( isset( $seen[ $token ] ) ) {
				self::fail( 'duplicate_binding', $path );
			}
			$owner = $adapter_id . '@' . $adapter_version;
			if ( isset( $seen_owners[ $owner ] ) ) {
				self::fail( 'duplicate_binding_owner', $path );
			}
			$seen_owners[ $owner ] = true;
			$seen[ $token ]        = array(
				'adapter_id'      => $adapter_id,
				'adapter_version' => $adapter_version,
				'ability_id'      => $ability_id,
				'kind'            => $kind,
			);
		}

		ksort( $seen, SORT_STRING );

		return array_values( $seen );
	}

	/**
	 * Build one canonical exact-binding token.
	 *
	 * @param string $adapter_id      Exact adapter ID.
	 * @param int    $adapter_version Exact adapter version.
	 * @param string $ability_id      Exact ability ID.
	 * @param string $kind            Exact binding kind.
	 */
	private static function token( string $adapter_id, int $adapter_version, string $ability_id, string $kind ): string {
		return $adapter_id . '@' . $adapter_version . '|' . $ability_id . '|' . $kind;
	}

	/**
	 * Throw a bounded availability failure.
	 *
	 * @param string $code Stable error code.
	 * @param string $path Bounded structural path.
	 * @throws WorkflowPlanningException Always.
	 */
	private static function fail( string $code, string $path ): never {
		throw new WorkflowPlanningException( $code, $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal bounded evidence only.
	}
}
