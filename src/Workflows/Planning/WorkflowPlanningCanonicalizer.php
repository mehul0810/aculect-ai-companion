<?php
/**
 * Bounded deterministic JSON normalization for workflow planning values.
 *
 * @package Aculect\AICompanion\Workflows\Planning
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Workflows\Planning;

use JsonException;
use SplObjectStorage;
use stdClass;

/**
 * Preserves JSON object/list semantics while bounding recursive work.
 */
final class WorkflowPlanningCanonicalizer {

	/**
	 * Normalize and encode a JSON-compatible value.
	 *
	 * @param mixed $value JSON-compatible value.
	 * @return array{value:mixed,json:string}
	 * @throws WorkflowPlanningException When the value is unsafe or unbounded.
	 */
	public function normalize_and_encode( mixed $value ): array {
		$nodes      = 0;
		$bytes      = 0;
		$visiting   = new SplObjectStorage();
		$normalized = $this->normalize_value( $value, '$', 1, $nodes, $bytes, $visiting );

		try {
			$json = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Exact canonical flags define planning identity.
				$normalized,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
			);
		} catch ( JsonException ) {
			throw new WorkflowPlanningException( 'non_json_input', '$' );
		}

		if ( strlen( $json ) > WorkflowInputContract::MAX_ENCODED_BYTES ) {
			throw new WorkflowPlanningException( 'input_too_large', '$' );
		}

		return array(
			'value' => $normalized,
			'json'  => $json,
		);
	}

	/**
	 * Return a detached copy of a normalized value.
	 *
	 * @param mixed $value Normalized value.
	 * @return mixed
	 */
	public function copy( mixed $value ): mixed {
		if ( $value instanceof stdClass ) {
			$copy = new stdClass();
			// @phpstan-ignore-next-line -- Native iteration preserves numeric JSON object member names.
			foreach ( $value as $key => $item ) {
				$copy->{$key} = $this->copy( $item );
			}

			return $copy;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$copy = array();
		foreach ( $value as $key => $item ) {
			$copy[ $key ] = $this->copy( $item );
		}

		return $copy;
	}

	/**
	 * Normalize one bounded value.
	 *
	 * @param mixed            $value    Raw value.
	 * @param string           $path     Bounded path.
	 * @param int              $depth    Current depth.
	 * @param int              $nodes    Visited node count.
	 * @param int              $bytes    Approximate scalar/key bytes.
	 * @param SplObjectStorage $visiting Object recursion guard.
	 * @phpstan-param SplObjectStorage<object, null> $visiting
	 * @return mixed
	 * @throws WorkflowPlanningException When the value exceeds a bound or is not JSON-safe.
	 */
	private function normalize_value(
		mixed $value,
		string $path,
		int $depth,
		int &$nodes,
		int &$bytes,
		SplObjectStorage $visiting
	): mixed {
		++$nodes;
		if ( $nodes > WorkflowInputContract::MAX_NODES ) {
				throw new WorkflowPlanningException( 'input_too_complex', $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
		}

		if ( $depth > WorkflowInputContract::MAX_DEPTH ) {
				throw new WorkflowPlanningException( 'input_too_deep', $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
		}

		if ( is_string( $value ) ) {
			$bytes += strlen( $value );
			$this->assert_bytes( $bytes, $path );
			return $value;
		}

		if ( is_int( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}

		if ( is_float( $value ) ) {
			if ( ! is_finite( $value ) ) {
					throw new WorkflowPlanningException( 'non_json_input', $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
			}

			return $value;
		}

		if ( $value instanceof stdClass ) {
			if ( $visiting->contains( $value ) ) {
					throw new WorkflowPlanningException( 'non_json_input', $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
			}

			$visiting->attach( $value );
			$entries = array();
			// @phpstan-ignore-next-line -- Native iteration preserves numeric JSON object member names.
			foreach ( $value as $key => $item ) {
				$entries[ $key ] = $item;
				$bytes          += strlen( $key );
				$this->assert_bytes( $bytes, $path );
			}
			ksort( $entries, SORT_STRING );

			$normalized = new stdClass();
			foreach ( $entries as $key => $item ) {
				$normalized->{$key} = $this->normalize_value( $item, $path . '.' . $key, $depth + 1, $nodes, $bytes, $visiting );
			}
			$visiting->detach( $value );

			return $normalized;
		}

		if ( ! is_array( $value ) ) {
			throw new WorkflowPlanningException( 'non_json_input', $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
		}

		if ( array_is_list( $value ) ) {
			$normalized = array();
			foreach ( $value as $index => $item ) {
				$normalized[] = $this->normalize_value( $item, $path . '[' . $index . ']', $depth + 1, $nodes, $bytes, $visiting );
			}

			return $normalized;
		}

		$keys = array_keys( $value );
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) ) {
					throw new WorkflowPlanningException( 'non_json_input', $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
			}
			$bytes += strlen( $key );
			$this->assert_bytes( $bytes, $path );
		}
		sort( $keys, SORT_STRING );

		$normalized = new stdClass();
		foreach ( $keys as $key ) {
			$normalized->{$key} = $this->normalize_value( $value[ $key ], $path . '.' . $key, $depth + 1, $nodes, $bytes, $visiting );
		}

		return $normalized;
	}

	/**
	 * Enforce the pre-encode byte budget.
	 *
	 * @param int    $bytes Cumulative bytes.
	 * @param string $path  Current path.
	 * @throws WorkflowPlanningException When the byte budget is exceeded.
	 */
	private function assert_bytes( int $bytes, string $path ): void {
		if ( $bytes > WorkflowInputContract::MAX_ENCODED_BYTES ) {
			throw new WorkflowPlanningException( 'input_too_large', $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Bounded internal validation path.
		}
	}
}
