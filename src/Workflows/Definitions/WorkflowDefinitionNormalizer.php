<?php
/**
 * Deterministic workflow definition normalization.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use stdClass;

/**
 * Sorts map keys recursively while preserving semantically ordered lists.
 */
final class WorkflowDefinitionNormalizer {

	/**
	 * Return a detached deterministic representation of a validated definition.
	 *
	 * @param array<string, mixed> $definition Validated workflow definition.
	 * @return array<string, mixed>
	 */
	public function normalize( array $definition ): array {
		/* @var array<string, mixed> $normalized */
		$normalized = $this->normalize_value( $definition );

		return $normalized;
	}

	/**
	 * Normalize one JSON-compatible value.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private function normalize_value( mixed $value ): mixed {
		if ( $value instanceof stdClass ) {
			$normalized = new stdClass();
			$keys       = array();
			// @phpstan-ignore-next-line -- Native foreach preserves numeric stdClass member names that array conversion coerces.
			foreach ( $value as $key => $item ) {
				unset( $item );
				$keys[] = $key;
			}
			sort( $keys, SORT_STRING );

			foreach ( $keys as $key ) {
				$normalized->{$key} = $this->normalize_value( $value->{$key} );
			}

			return $normalized;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$normalized = array();
		if ( array_is_list( $value ) ) {
			foreach ( $value as $item ) {
				$normalized[] = $this->normalize_value( $item );
			}

			return $normalized;
		}

		$keys = array_keys( $value );
		sort( $keys, SORT_STRING );

		foreach ( $keys as $key ) {
			$normalized[ $key ] = $this->normalize_value( $value[ $key ] );
		}

		return $normalized;
	}
}
