<?php
/**
 * Attachment trash must never fall through to permanent native deletion.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Keeps disabled trash and uncertain native outcomes explicit. */
final class SafeMediaTrash extends AbstractAbilityService {

	/**
	 * Move an authorized attachment to enabled native trash, never delete it.
	 *
	 * @param array<string,mixed> $data Exact attachment ID and safety controls.
	 * @return array<string,mixed>
	 */
	public function execute( array $data ): array {
		$id   = $data['id'] ?? null;
		$post = is_int( $id ) && $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return $this->error( 'not_found', 'Provide an existing attachment ID.' );
		}
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return $this->error( 'forbidden', 'Native attachment delete permission is required to trash this item.' );
		}
		$state_error = ( new MediaExpectedState() )->error( $post, $data );
		if ( null !== $state_error ) {
			return $state_error;
		}
		if ( ! defined( 'EMPTY_TRASH_DAYS' ) || ! EMPTY_TRASH_DAYS ) {
			return $this->error( 'media_trash_disabled', 'WordPress trash is disabled. This tool never permanently deletes attachments.' );
		}
		if ( 'trash' === $post->post_status ) {
			return array(
				'id'      => $id,
				'status'  => 'trash',
				'changed' => false,
			);
		}
		if ( $this->is_dry_run( $data ) ) {
			return $this->preview_response(
				'media.delete_item',
				$data,
				array(
					'type' => 'attachment',
					'id'   => $id,
				),
				array( $this->change( 'status', $post->post_status, 'trash' ) ),
				array( 'Moves this attachment to enabled WordPress trash. Permanent deletion is never requested; native hooks may run.' )
			);
		}
		try {
			$state_error = ( new MediaExpectedState() )->error( $post, $data );
			if ( null !== $state_error ) {
				return $state_error;
			}
			$result = wp_trash_post( $id );
			$after  = get_post( $id );
			if ( $result instanceof \WP_Post && $result->ID === $id && $after instanceof \WP_Post && 'attachment' === $after->post_type && 'trash' === $after->post_status ) {
				return array(
					'id'      => $id,
					'status'  => 'trash',
					'message' => 'Media item moved to trash.',
				);
			}
		} catch ( \Throwable ) {
			// A hook can fail after a partial native write; never auto-retry.
			return $this->uncertain();
		}
		return $this->uncertain();
	}

	/**
	 * Mark an unverified native operation as terminal.
	 *
	 * @return array<string,mixed>
	 */
	private function uncertain(): array {
		return array(
			'error'         => 'partial_write',
			'status'        => 'partial_write',
			'partial_write' => true,
			'terminal'      => true,
			'message'       => 'The native trash result could not be verified. Inspect this attachment manually; do not retry automatically.',
		);
	}
}
