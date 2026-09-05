<?php
/**
 * Normalizes the durable Aculect Memory record contract.
 *
 * @package Aculect\AICompanion\Intelligence\Memory
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Memory;

/**
 * Stateless record factory shared by persistence and transport adapters.
 */
final class MemoryRecord {

	/**
	 * Build a bounded persistence record.
	 *
	 * @param array<string, mixed>      $input    Untrusted record input.
	 * @param array<string, mixed>|null $existing Existing persistence row.
	 * @return array<string, int|string|null>
	 */
	public function normalize( array $input, ?array $existing = null ): array {
		$value      = $this->text( $input['value'] ?? '', 4000 );
		$namespace  = $this->identifier( $input['namespace'] ?? $existing['namespace'] ?? 'site', 191, 'site' );
		$memory_key = $this->identifier( $input['key'] ?? $input['memory_key'] ?? $existing['memory_key'] ?? '', 120 );
		$uuid       = $this->uuid( $input['memory_uuid'] ?? $existing['memory_uuid'] ?? '' );
		$uuid       = '' === $uuid ? $this->generate_uuid() : $uuid;
		$version    = null === $existing ? 1 : max( 1, absint( $existing['version'] ?? 1 ) + 1 );
		$source     = $this->identifier( $input['source'] ?? $existing['source'] ?? 'manual', 40, 'manual' );
		$visibility = in_array( $source, array( 'admin', 'bootstrap', 'detected', 'learning' ), true ) ? 'site' : 'private';

		return array(
			'memory_key'    => $memory_key,
			'memory_uuid'   => $uuid,
			'namespace'     => $namespace,
			'owner_user_id' => absint( $input['owner_user_id'] ?? $existing['owner_user_id'] ?? 0 ),
			'domain'        => $this->choice( $input['domain'] ?? $existing['domain'] ?? 'content', array( 'brand', 'site', 'content', 'developer', 'seo', 'workflow' ), 'content' ),
			'value'         => $value,
			'evidence'      => $this->text( $input['evidence'] ?? $existing['evidence'] ?? '', 2000 ),
			'confidence'    => $this->choice( $input['confidence'] ?? $existing['confidence'] ?? 'medium', array( 'low', 'medium', 'high' ), 'medium' ),
			'status'        => $this->choice( $input['status'] ?? $existing['status'] ?? 'pending', array( 'approved', 'pending', 'dismissed' ), 'pending' ),
			'visibility'    => $this->choice( $input['visibility'] ?? $existing['visibility'] ?? $visibility, array( 'private', 'connection', 'site' ), $visibility ),
			'sensitivity'   => $this->choice( $input['sensitivity'] ?? $existing['sensitivity'] ?? 'normal', array( 'normal', 'sensitive', 'restricted' ), 'normal' ),
			'version'       => $version,
			'content_hash'  => hash( 'sha256', $namespace . "\n" . $memory_key . "\n" . $value ),
			'source'        => $source,
			'valid_from'    => $this->datetime( $input['valid_from'] ?? $existing['valid_from'] ?? null ),
			'expires_at'    => $this->datetime( $input['expires_at'] ?? $existing['expires_at'] ?? null ),
			'deleted_at'    => $this->datetime( $input['deleted_at'] ?? $existing['deleted_at'] ?? null ),
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * Return whether a record is eligible for outbound synchronization.
	 *
	 * @param array<string, mixed> $record Memory record.
	 */
	public function can_sync( array $record ): bool {
		return 'approved' === (string) ( $record['status'] ?? '' )
			&& 'site' === (string) ( $record['visibility'] ?? '' )
			&& 'normal' === (string) ( $record['sensitivity'] ?? '' )
			&& empty( $record['deleted_at'] );
	}

	private function text( mixed $value, int $limit ): string {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
	}

	/**
	 * Normalize one value against an allowlist.
	 *
	 * @param mixed         $value   Untrusted value.
	 * @param array<string> $allowed Allowed values.
	 * @param string        $default Default value.
	 */
	private function choice( mixed $value, array $allowed, string $default ): string {
		$value = sanitize_key( is_scalar( $value ) ? (string) $value : '' );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private function identifier( mixed $value, int $limit, string $default = '' ): string {
		$value = preg_replace( '/[^a-zA-Z0-9:_\-.]/', '_', is_scalar( $value ) ? (string) $value : '' ) ?? '';
		$value = trim( $value, '_-.' );
		return substr( '' === $value ? $default : $value, 0, $limit );
	}

	private function uuid( mixed $value ): string {
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value ) ? $value : '';
	}

	private function generate_uuid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
	}

	private function datetime( mixed $value ): ?string {
		$value = is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
		if ( '' === $value ) {
			return null;
		}

		$timestamp = strtotime( $value );
		return false === $timestamp ? null : gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
