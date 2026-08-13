<?php
/**
 * Immutable normalized workflow definition value.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use JsonException;
use stdClass;

/**
 * Holds one validated v1 definition and its deterministic checksum.
 */
final readonly class WorkflowDefinition {

	/**
	 * Create an immutable definition value.
	 *
	 * @param array<string, mixed> $definition Normalized definition.
	 * @param string               $canonical  Canonical JSON representation.
	 * @param string               $checksum   Definition checksum.
	 */
	private function __construct(
		private array $definition,
		private string $canonical,
		private string $checksum
	) {
	}

	/**
	 * Validate and normalize a raw PHP definition.
	 *
	 * PHP arrays retain their native semantics: `array_is_list()` arrays are
	 * lists. Use stdClass or from_json() for explicit JSON objects, especially
	 * objects with numeric member names.
	 *
	 * @param array<mixed> $definition Raw definition.
	 * @throws WorkflowDefinitionValidationException When validation fails.
	 */
	public static function from_array( array $definition ): self {
		( new WorkflowDefinitionValidator() )->validate( $definition );
		$normalized = ( new WorkflowDefinitionNormalizer() )->normalize( $definition );
		try {
			$canonical = ( new WorkflowDefinitionCanonicalizer() )->encode( $normalized );
		} catch ( JsonException ) {
			// The validator owns this invariant; retain a fail-closed boundary if it regresses.
			throw new WorkflowDefinitionValidationException( 'normalization_failed', '$' );
		}

		return new self( $normalized, $canonical, hash( 'sha256', $canonical ) );
	}

	/**
	 * Decode, validate, and normalize an object-preserving JSON definition.
	 *
	 * @param string $json Raw JSON definition.
	 * @throws WorkflowDefinitionValidationException When decoding or validation fails.
	 */
	public static function from_json( string $json ): self {
		if ( strlen( $json ) > WorkflowDefinitionSchema::MAX_ENCODED_BYTES ) {
			throw new WorkflowDefinitionValidationException( 'definition_too_large', '$' );
		}

		try {
			$decoded = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new WorkflowDefinitionValidationException( 'invalid_json', '$' );
		}

		if ( ! $decoded instanceof stdClass ) {
			throw new WorkflowDefinitionValidationException( 'invalid_json_root', '$' );
		}

		return self::from_array( get_object_vars( $decoded ) );
	}

	/**
	 * Return a detached copy of the normalized definition.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		/* @var array<string, mixed> $copy */
		$copy = self::copy_value( $this->definition );

		return $copy;
	}

	/**
	 * Return the canonical JSON representation used by the checksum.
	 */
	public function canonical_json(): string {
		return $this->canonical;
	}

	/**
	 * Return the SHA-256 checksum of the normalized definition.
	 */
	public function checksum(): string {
		return $this->checksum;
	}

	/**
	 * Deep-copy JSON-compatible arrays and explicit objects for safe exposure.
	 *
	 * @param mixed $value Stored value.
	 * @return mixed
	 */
	private static function copy_value( mixed $value ): mixed {
		if ( $value instanceof stdClass ) {
			$copy = new stdClass();
			// @phpstan-ignore-next-line -- Native foreach preserves numeric stdClass member names that array conversion coerces.
			foreach ( $value as $key => $item ) {
				$copy->{$key} = self::copy_value( $item );
			}

			return $copy;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$copy = array();
		foreach ( $value as $key => $item ) {
			$copy[ $key ] = self::copy_value( $item );
		}

		return $copy;
	}
}
