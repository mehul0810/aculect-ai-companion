<?php
/**
 * Immutable normalized workflow input value.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

use JsonException;
use stdClass;

/**
 * Binds a JSON object input to deterministic canonical JSON and a SHA-256 hash.
 */
final readonly class WorkflowInputContract {

	public const MAX_ENCODED_BYTES = 262144;
	public const MAX_DEPTH         = 16;
	public const MAX_NODES         = 512;

	/**
	 * Create a validated input contract.
	 *
	 * @param stdClass $value     Normalized object value.
	 * @param string   $canonical Canonical JSON.
	 * @param string   $hash      SHA-256 input hash.
	 */
	private function __construct(
		private stdClass $value,
		private string $canonical,
		private string $hash
	) {
	}

	/**
	 * Build from a PHP JSON-object representation.
	 *
	 * @param array<mixed>|stdClass $input Raw input object.
	 * @throws WorkflowPlanningException When input is not a safe bounded object.
	 */
	public static function from_value( array|stdClass $input ): self {
		if ( is_array( $input ) && array_is_list( $input ) ) {
			throw new WorkflowPlanningException( 'invalid_input_root', '$' );
		}

		$canonicalizer = new WorkflowPlanningCanonicalizer();
		$encoded       = $canonicalizer->normalize_and_encode( $input );
		if ( ! $encoded['value'] instanceof stdClass ) {
			throw new WorkflowPlanningException( 'invalid_input_root', '$' );
		}

		return new self( $encoded['value'], $encoded['json'], hash( 'sha256', $encoded['json'] ) );
	}

	/**
	 * Decode and build from object-preserving JSON.
	 *
	 * @param string $json Raw input JSON.
	 * @throws WorkflowPlanningException When input JSON is invalid.
	 */
	public static function from_json( string $json ): self {
		if ( strlen( $json ) > self::MAX_ENCODED_BYTES ) {
			throw new WorkflowPlanningException( 'input_too_large', '$' );
		}

		try {
			$decoded = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException ) {
			throw new WorkflowPlanningException( 'non_json_input', '$' );
		}

		if ( ! $decoded instanceof stdClass ) {
			throw new WorkflowPlanningException( 'invalid_input_root', '$' );
		}

		return self::from_value( $decoded );
	}

	/**
	 * Return a detached normalized object.
	 */
	public function value(): stdClass {
		$value = ( new WorkflowPlanningCanonicalizer() )->copy( $this->value );

		return $value instanceof stdClass ? $value : new stdClass();
	}

	/**
	 * Return canonical JSON.
	 */
	public function canonical_json(): string {
		return $this->canonical;
	}

	/**
	 * Return the SHA-256 input hash.
	 */
	public function hash(): string {
		return $this->hash;
	}
}
