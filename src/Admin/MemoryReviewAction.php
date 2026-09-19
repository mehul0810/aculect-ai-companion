<?php
/**
 * Validated administration commands for durable memory.
 *
 * @package Aculect\AICompanion\Admin
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Admin;

use Aculect\AICompanion\Intelligence\Memory\MemoryService;

/** Preserves record identity and optimistic concurrency in admin commands. */
final class MemoryReviewAction {

	/**
	 * Build a validated mutation; authorization remains in the calling controller.
	 *
	 * @param string               $action Requested operation.
	 * @param string               $key Original immutable key.
	 * @param array<string, mixed> $item Submitted fields.
	 * @return array<string, mixed>|null
	 */
	public function input( string $action, string $key, array $item ): ?array {
		if ( ! in_array( $action, array( 'approve', 'dismiss', 'delete', 'update' ), true ) || '' === $key ) {
			return null;
		}
		foreach ( $item as $value ) {
			if ( ! is_scalar( $value ) ) {
				return null;
			}
		}
		$namespace = (string) ( $item['namespace'] ?? '' );
		if ( ! is_int( $item['expected_version'] ?? null ) && ! is_string( $item['expected_version'] ?? null ) ) {
			return null;
		}
		$version = filter_var( $item['expected_version'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
		if ( 1 !== preg_match( '/^[a-zA-Z0-9:][a-zA-Z0-9:_\-.]{0,190}$/D', $namespace ) || trim( $namespace, '_-.' ) !== $namespace || false === $version || (string) ( $item['key'] ?? $key ) !== $key ) {
			return null;
		}
		$status = match ( $action ) {
			'approve' => 'approved',
			'dismiss' => 'dismissed',
			default => (string) ( $item['status'] ?? 'pending' ),
		};
		if ( ! in_array( $status, array( 'approved', 'pending', 'dismissed' ), true ) ) {
			return null;
		}
		$visibility = (string) ( $item['visibility'] ?? 'private' );
		if ( ! in_array( $visibility, array( 'private', 'site', 'connection' ), true ) ) {
			return null;
		}
		return array(
			'key'              => $key,
			'namespace'        => $namespace,
			'expected_version' => $version,
			'domain'           => $item['domain'] ?? 'content',
			'value'            => $item['value'] ?? '',
			'evidence'         => $item['evidence'] ?? '',
			'confidence'       => $item['confidence'] ?? 'medium',
			'status'           => $status,
			'visibility'       => $visibility,
			'source'           => $item['source'] ?? 'admin',
		);
	}

	/**
	 * Execute one validated command without hiding storage or version failures.
	 *
	 * @param string               $action Requested operation.
	 * @param string               $key Original key.
	 * @param array<string, mixed> $item Submitted fields.
	 * @return bool Whether the mutation succeeded.
	 */
	public function execute( string $action, string $key, array $item ): bool {
		$input = $this->input( $action, $key, $item );
		if ( null === $input ) {
			return false;
		}
		$service = new MemoryService();
		$result  = 'delete' === $action ? $service->forget( $input ) : $service->save( $input );
		return 'success' === ( $result['status'] ?? '' );
	}
}
