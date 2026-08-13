<?php
/**
 * Canonical workflow definition JSON serialization.
 *
 * @package Aculect\AICompanion\Workflows\Definitions
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Definitions;

use JsonException;
use stdClass;

/**
 * Preserves the v1 contract's object and list semantics for checksums.
 */
final class WorkflowDefinitionCanonicalizer {

	/**
	 * Encode a normalized definition to deterministic canonical JSON.
	 *
	 * @param array<string, mixed> $definition Normalized definition.
	 * @throws JsonException When the normalized invariant is broken.
	 */
	public function encode( array $definition ): string {
		$canonical = new stdClass();

		foreach ( $definition as $key => $value ) {
			$canonical->{$key} = match ( $key ) {
				'content_target', 'write_policy', 'compatibility' => $this->canonicalize_value( $value, true ),
				'input_schema', 'output_contract' => $this->canonicalize_schema( $value ),
				'steps' => $this->canonicalize_steps( $value ),
				'validation_rules' => $this->canonicalize_object_list( $value ),
				default => $this->canonicalize_value( $value ),
			};
		}

		return json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact flags and exceptions define the checksum contract.
			$canonical,
			JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
		);
	}

	/**
	 * Canonicalize ordered workflow steps.
	 *
	 * @param mixed $steps Validated steps.
	 * @return list<mixed>
	 */
	private function canonicalize_steps( mixed $steps ): array {
		$canonical = array();
		foreach ( $steps as $step ) {
			$canonical_step = new stdClass();
			foreach ( $step as $key => $value ) {
				$canonical_step->{$key} = 'arguments' === $key
					? $this->canonicalize_value( $value, true )
					: $this->canonicalize_value( $value );
			}
			$canonical[] = $canonical_step;
		}

		return $canonical;
	}

	/**
	 * Canonicalize a list whose entries are contract maps.
	 *
	 * @param mixed $items Validated list.
	 * @return list<mixed>
	 */
	private function canonicalize_object_list( mixed $items ): array {
		$canonical = array();
		foreach ( $items as $item ) {
			$canonical[] = $this->canonicalize_value( $item, true );
		}

		return $canonical;
	}

	/**
	 * Canonicalize one schema from the supported v1 subset.
	 *
	 * @param mixed $schema Validated schema.
	 */
	private function canonicalize_schema( mixed $schema ): stdClass {
		$canonical = new stdClass();

		foreach ( $schema as $key => $value ) {
			if ( 'properties' === $key ) {
				$properties = new stdClass();
				foreach ( $value as $property_name => $property_schema ) {
					$properties->{$property_name} = $this->canonicalize_schema( $property_schema );
				}
				$canonical->{$key} = $properties;
				continue;
			}

			$canonical->{$key} = 'items' === $key
				? $this->canonicalize_schema( $value )
				: $this->canonicalize_value( $value );
		}

		return $canonical;
	}

	/**
	 * Canonicalize a generic JSON-compatible value.
	 *
	 * @param mixed $value        Validated value.
	 * @param bool  $force_object Whether an empty array is a contract map.
	 * @return mixed
	 */
	private function canonicalize_value( mixed $value, bool $force_object = false ): mixed {
		if ( $value instanceof stdClass ) {
			$canonical = new stdClass();
			// @phpstan-ignore-next-line -- Native foreach preserves numeric stdClass member names that array conversion coerces.
			foreach ( $value as $key => $item ) {
				$canonical->{$key} = $this->canonicalize_value( $item );
			}

			return $canonical;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! $force_object && array_is_list( $value ) ) {
			return array_map( array( $this, 'canonicalize_value' ), $value );
		}

		$canonical = new stdClass();
		foreach ( $value as $key => $item ) {
			$canonical->{$key} = $this->canonicalize_value( $item );
		}

		return $canonical;
	}
}
