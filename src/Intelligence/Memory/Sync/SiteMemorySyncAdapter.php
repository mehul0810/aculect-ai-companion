<?php
/**
 * Client-driven two-way exchange with Aculect as the authoritative memory store.
 *
 * @package Aculect\AICompanion\Intelligence\Memory\Sync
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence\Memory\Sync;

use Aculect\AICompanion\Intelligence\Memory\MemoryRepository;
use Aculect\AICompanion\Intelligence\Memory\MemoryService;
use RuntimeException;

/** No outbound network calls: a consented connector transports each bounded batch. */
final class SiteMemorySyncAdapter implements MemoryConnectorAdapterInterface {
	public function __construct( private readonly string $connector, private readonly string $namespace = 'site' ) {}

	public function id(): string {
		return $this->connector;
	}

	/**
	 * Pull approved context or content-free invalidations from this site.
	 *
	 * @param string $cursor Feed cursor.
	 * @param int    $limit Batch size.
	 * @return array{items:list<array<string,mixed>>,cursor:string,has_more:bool}
	 */
	public function pull( string $cursor, int $limit ): array {
		$this->authorize();
		return ( new MemorySyncFeed() )->page( $this->namespace, $cursor, $limit );
	}

	/**
	 * Import versioned proposals without overwriting approved site guidance.
	 *
	 * @param array<mixed> $changes External proposals validated at runtime.
	 * @param string       $cursor Opaque caller checkpoint, advanced only on complete acceptance.
	 * @return array{accepted:list<string>,rejected:array<string,string>,cursor:string}
	 * @throws RuntimeException For invalid batch boundaries.
	 */
	public function push( array $changes, string $cursor ): array {
		$this->authorize();
		if ( count( $changes ) > 20 || strlen( $cursor ) > 191 ) {
			throw new RuntimeException( 'memory_sync_batch_limit' );
		}
		$accepted = array();
		$rejected = array();
		$service  = new MemoryService();
		foreach ( $changes as $index => $change ) {
			$id    = is_array( $change ) && is_string( $change['id'] ?? null ) ? $change['id'] : '';
			$error = $this->import( is_array( $change ) ? $change : array(), $service );
			if ( '' === $error ) {
				$accepted[] = $id;
			} else {
				$rejected[ '' === $id ? 'item_' . $index : $id ] = $error;
			}
		}
		return array(
			'accepted' => $accepted,
			'rejected' => $rejected,
			'cursor'   => array() === $rejected ? $cursor : '',
		);
	}

	/**
	 * Stable proposal identity makes replay safe even after admin approval or deletion.
	 *
	 * @param array<string,mixed> $change Versioned external proposal.
	 * @param MemoryService       $service Shared transactional service.
	 */
	private function import( array $change, MemoryService $service ): string {
		$id      = $change['id'] ?? null;
		$version = $change['version'] ?? null;
		$value   = $change['value'] ?? null;
		if ( ! is_string( $id ) || '' === $id || strlen( $id ) > 191 || ! is_int( $version ) || $version < 1 || ! is_string( $value ) || '' === trim( $value ) || strlen( $value ) > 4000 ) {
			return 'invalid_memory_proposal';
		}
		// Stable identity plus a value comparison prevents replacing a reviewed proposal on replay.
		$identity   = hash( 'sha256', $this->connector . "\n" . $id . "\n" . $version );
		$key        = 'sync.' . $identity;
		$existing   = ( new MemoryRepository() )->find( $key, $this->namespace, true );
		$normalized = sanitize_text_field( $value );
		if ( array() !== $existing ) {
			return hash_equals( (string) $existing['value'], $normalized ) ? '' : 'memory_sync_version_conflict';
		}
		$result = $service->save(
			array(
				'key'              => $key,
				'namespace'        => $this->namespace,
				'value'            => $value,
				'domain'           => is_string( $change['domain'] ?? null ) ? $change['domain'] : 'content',
				'status'           => 'pending',
				'visibility'       => 'private',
				'sensitivity'      => 'normal',
				'source'           => 'sync',
				'owner_user_id'    => get_current_user_id(),
				'expected_version' => 0,
				'evidence'         => 'Imported proposal ' . substr( $identity, 0, 16 ) . '; review before sharing with connected tools.',
			)
		);
		return 'success' === ( $result['status'] ?? '' ) ? '' : (string) ( $result['error'] ?? 'memory_sync_save_failed' );
	}

	/**
	 * Fail closed before any read or write when sync is not explicitly enabled.
	 *
	 * @throws RuntimeException When consent, capabilities, or identity are missing.
	 */
	private function authorize(): void {
		if ( ! current_user_can( 'manage_options' ) || get_current_user_id() < 1 ) {
			throw new RuntimeException( 'forbidden' );
		}
		if ( ! (bool) apply_filters( 'aculect_ai_companion_memory_sync_enabled', false ) ) {
			throw new RuntimeException( 'memory_sync_disabled' );
		}
		if ( ! preg_match( '/^[a-zA-Z0-9:_\-.]{1,120}$/', $this->connector ) || ! preg_match( '/^[a-zA-Z0-9:_\-.]{1,191}$/', $this->namespace ) ) {
			throw new RuntimeException( 'invalid_memory_sync_identity' );
		}
	}
}
