<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Preserves exact JSON argument identity without persisting the arguments. */
final class PendingApprovalCanonicalizer {
	private const MAX_JSON_DEPTH = 12;

	/**
	 * Sort object keys recursively while preserving list order.
	 *
	 * @param mixed $value Argument value.
	 * @param int   $depth Current nesting depth.
	 * @return mixed
	 * @throws \UnexpectedValueException When the value cannot be represented safely.
	 */
	public function canonicalize( mixed $value, int $depth = 0 ): mixed {
		if ( self::MAX_JSON_DEPTH < $depth ) {
			throw new \UnexpectedValueException( 'Maximum approval argument depth exceeded.' );
		}
		if ( ! is_array( $value ) ) {
			if ( is_scalar( $value ) || null === $value ) {
				if ( is_float( $value ) && ! is_finite( $value ) ) {
					throw new \UnexpectedValueException( 'Non-finite numbers are invalid JSON.' );
				}
				return $value;
			}
			throw new \UnexpectedValueException( 'Unsupported approval argument value.' );
		}
		if ( array_is_list( $value ) ) {
			$normalized = array();
			foreach ( $value as $item ) {
				$normalized[] = $this->canonicalize( $item, $depth + 1 );
			}
			return $normalized;
		}
		$normalized = array();
		foreach ( $value as $key => $item ) {
			if ( ! is_string( $key ) ) {
				throw new \UnexpectedValueException( 'Invalid approval argument key.' );
			}
			$normalized[ $key ] = $this->canonicalize( $item, $depth + 1 );
		}
		ksort( $normalized, SORT_STRING );
		return $normalized;
	}
}
