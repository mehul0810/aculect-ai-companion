<?php
/**
 * Safely move one database-backed Site Editor record to native WordPress trash.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Keeps Site Editor deletion database-only, reversible, and terminal on doubt.
 */
final class EditorRecordDeletion extends AbstractAbilityService {

	private readonly EditorRecordDeletionSnapshot $snapshotter;

	public function __construct() {
		$this->snapshotter = new EditorRecordDeletionSnapshot();
	}

	/**
	 * Preview or delete one exact Site Editor record.
	 *
	 * The gateway supplies confirmation controls. This service still validates
	 * the target, state token, capabilities, native trash availability and lock
	 * immediately before any native mutation.
	 *
	 * @param array<string,mixed> $args Target, expected_state and safety controls.
	 * @return array<string,mixed>
	 */
	public function delete( array $args ): array {
		$expected = $args['expected_state'] ?? null;
		if ( ! is_string( $expected ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) {
			return $this->error( 'stale_editor_state', 'Read this exact editor record and send its expected_state before deleting it.' );
		}

		try {
			$state = ( new EditorRecordState() )->load( $args );
			if ( isset( $state['error'] ) ) {
				return $state;
			}
			if ( ! hash_equals( $state['expected_state'], $expected ) ) {
				return $this->stale_state();
			}
			$permission = $this->delete_permission( $state['post']->ID );
			if ( null !== $permission ) {
				return $permission;
			}
			$native = $this->native_trash_error( $state['post']->post_type );
			if ( null !== $native ) {
				return $native;
			}

			if ( $this->is_dry_run( $args ) ) {
				$fresh = $this->fresh_state( $args, $expected );
				if ( isset( $fresh['error'] ) ) {
					return $fresh;
				}
				return $this->preview( $args, $fresh );
			}

			$fresh = $this->fresh_state( $args, $expected );
			if ( isset( $fresh['error'] ) ) {
				return $fresh;
			}
			$snapshot = $this->snapshotter->capture( $fresh );
			if ( null === $snapshot ) {
				return $this->error( 'editor_delete_unavailable', 'The editor record could not be snapshotted safely; no deletion was attempted.' );
			}
		} catch ( \Throwable ) {
			return $this->error( 'editor_delete_unavailable', 'The editor record could not be validated safely; no deletion was attempted.' );
		}

		return $this->persist( $args, $expected, $snapshot );
	}

	/**
	 * Re-read the record and require the same token and native edit lock state.
	 *
	 * @param array<string,mixed> $args Original target arguments.
	 * @param string              $expected Expected state token.
	 * @return array<string,mixed>
	 */
	private function fresh_state( array $args, string $expected ): array {
		$fresh = ( new EditorRecordState() )->load( $args );
		if ( isset( $fresh['error'] ) ) {
			return $fresh;
		}
		if ( ! hash_equals( $fresh['expected_state'], $expected ) ) {
			return $this->stale_state();
		}
		$permission = $this->delete_permission( $fresh['post']->ID );
		if ( null !== $permission ) {
			return $permission;
		}
		if ( ( new NativePostRecoveryPoint() )->locked( $fresh['post']->ID ) ) {
			return $this->error( 'post_locked', 'Another editor holds this Site Editor record lock. Wait for them to finish.' );
		}

		return $fresh;
	}

	/**
	 * Build a side-effect-free deletion preview with live-site warnings.
	 *
	 * @param array<string,mixed> $args Original arguments.
	 * @param array<string,mixed> $state Fresh validated state.
	 * @return array<string,mixed>
	 */
	private function preview( array $args, array $state ): array {
		$post = $state['post'];

		return $this->preview_response(
			'site_editor.delete_record',
			$args,
			array(
				'post_id'        => $post->ID,
				'type'           => $post->post_type,
				'status'         => $post->post_status,
				'theme'          => $state['theme'],
				'expected_state' => $state['expected_state'],
			),
			array( $this->change( 'status', $post->post_status, 'trash' ) ),
			$this->warnings( $post->post_type, $post->post_status )
		);
	}

	/**
	 * Execute native trash once and verify the reversible postcondition.
	 *
	 * @param array<string,mixed> $args Original target arguments.
	 * @param string              $expected Expected state token.
	 * @param array<string,mixed> $snapshot Verified pre-mutation snapshot.
	 * @return array<string,mixed>
	 */
	private function persist( array $args, string $expected, array $snapshot ): array {
		try {
			// Metadata is not part of EditorRecordState's token, so compare a
			// fresh bounded snapshot before the final token, capability and lock
			// check. No metadata callbacks run after that final check.
			$state = $this->fresh_state( $args, $expected );
			if ( isset( $state['error'] ) ) {
				return $state;
			}
			$fresh_snapshot = $this->snapshotter->capture( $state );
			if ( null === $fresh_snapshot ) {
				return $this->error( 'editor_delete_unavailable', 'The editor record changed while it was being validated; no deletion was attempted.' );
			}
			if ( $fresh_snapshot !== $snapshot ) {
				return $this->error( 'stale_editor_state', 'The editor record content, terms or metadata changed; read it again before deleting it.' );
			}
			$final = $this->fresh_state( $args, $expected );
			if ( isset( $final['error'] ) ) {
				return $final;
			}
		} catch ( \Throwable ) {
			return $this->error( 'editor_delete_unavailable', 'The editor record could not be validated safely; no deletion was attempted.' );
		}

		$post    = $final['post'];
		$post_id = $post->ID;
		try {
			$result = wp_trash_post( $post_id );
			$after  = get_post( $post_id );
			if ( ! $result instanceof \WP_Post || $result->ID !== $post_id || ! $this->snapshotter->verified( $snapshot, $after ) ) {
				return $this->partial_write();
			}
		} catch ( \Throwable ) {
			return $this->partial_write();
		}

		return array(
			'success'      => true,
			'changed'      => true,
			'verified'     => true,
			'post_id'      => $post_id,
			'type'         => $post->post_type,
			'prior_status' => $snapshot['prior_status'],
			'status'       => 'trash',
			'message'      => 'The Site Editor record was moved to native WordPress trash. Inspect native WordPress trash if this was unintended.',
			'warnings'     => $this->warnings( $post->post_type, (string) $snapshot['prior_status'] ),
		);
	}

	/**
	 * Return a deterministic capability failure for native delete permission.
	 *
	 * @param int $post_id Record ID.
	 * @return array<string,mixed>|null
	 */
	private function delete_permission( int $post_id ): ?array {
		return current_user_can( 'delete_post', $post_id ) ? null : $this->error( 'forbidden', 'Native delete permission is required to move this Site Editor record to trash.' );
	}

	/**
	 * Refuse unsafe or unavailable native trash paths before preview or mutation.
	 *
	 * @param string $post_type Supported editor record type.
	 * @return array<string,mixed>|null
	 */
	private function native_trash_error( string $post_type ): ?array {
		if ( ! defined( 'EMPTY_TRASH_DAYS' ) || ! EMPTY_TRASH_DAYS ) {
			return $this->error( 'editor_trash_disabled', 'WordPress trash is disabled. This tool never permanently deletes Site Editor records.' );
		}
		if ( ! function_exists( 'wp_trash_post' ) || ! function_exists( '_truncate_post_slug' ) || ! function_exists( 'get_post_meta' ) || ! function_exists( 'wp_get_object_terms' ) ) {
			$message = 'Native wp_trash_post and database preservation APIs are required; no Site Editor record was deleted.';
			if ( 'wp_global_styles' === $post_type ) {
				$message = 'Native wp_trash_post and slug-preservation helpers are required for global-style records; theme files are never edited.';
			}

			return $this->error( 'editor_delete_unavailable', $message );
		}
		return null;
	}

	/**
	 * Return the stable stale-token error used by other editor writes.
	 *
	 * @return array<string,mixed>
	 */
	private function stale_state(): array {
		return $this->error( 'stale_editor_state', 'Read this exact editor record again before deleting it.' );
	}

	/**
	 * Explain live-site and native-trash consequences before confirmation.
	 *
	 * @param string $post_type Record type.
	 * @param string $status     Current status.
	 * @return list<string>
	 */
	private function warnings( string $post_type, string $status ): array {
		$warnings = array(
			'Only this database record is moved to enabled native WordPress trash; no theme or plugin files are edited and permanent deletion is never requested.',
			'Native WordPress trash hooks run. Existing content, core identity fields, theme terms, template-part area terms and post metadata are verified after the move; native slug and trash bookkeeping changes are checked separately.',
			'The state and edit lock are checked immediately before trashing, but concurrent writes after that check cannot be made atomic. If native verification fails, inspect the record manually and do not retry automatically.',
		);
		if ( 'publish' === $status && in_array( $post_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
			$warnings[] = 'Deleting a published template or template part may make WordPress fall back to the active theme file or another matching record, which can affect the live site immediately.';
		}
		if ( 'wp_global_styles' === $post_type ) {
			$warnings[] = 'Deleting a global-style record removes its user style overrides and may change the site design immediately; theme.json files are not edited.';
		}
		if ( 'wp_navigation' === $post_type ) {
			$warnings[] = 'Deleting a navigation record may affect navigation menus, locations, or block references that point to it.';
		}

		return $warnings;
	}

	/**
	 * Mark an uncertain native outcome terminal; never compensate or retry.
	 *
	 * @return array<string,mixed>
	 */
	private function partial_write(): array {
		return array(
			'error'         => 'partial_write',
			'status'        => 'partial_write',
			'partial_write' => true,
			'terminal'      => true,
			'message'       => 'The native trash outcome could not be verified. Inspect the Site Editor record manually; no permanent-delete fallback, retry, or deletion compensation is attempted.',
		);
	}
}
