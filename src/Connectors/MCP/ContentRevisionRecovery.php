<?php
/**
 * Bounded content-field recovery, without revisioned metadata restoration.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Compares and restores the three native content fields on posts and pages. */
final class ContentRevisionRecovery extends AbstractAbilityService {

	private const FIELDS = array( 'post_title', 'post_content', 'post_excerpt' );

	/**
	 * Compare one revision with its current parent, without rendering content.
	 *
	 * @param array<string,mixed> $args Exact parent and revision IDs.
	 * @return array<string,mixed>
	 */
	public function compare( array $args ): array {
		try {
			$state = $this->state( $args );
			return isset( $state['error'] ) ? $state : $this->summary( $state );
		} catch ( \Throwable ) {
			return $this->error( 'recovery_unavailable', 'The revision could not be inspected safely.' );
		}
	}

	/**
	 * Restore content only after preview, state validation and a recovery revision.
	 *
	 * @param array<string,mixed> $args Exact target and expected state.
	 * @return array<string,mixed>
	 */
	public function restore( array $args ): array {
		try {
			$state = $this->state( $args );
			if ( isset( $state['error'] ) ) {
				return $state;
			}
			$expected = $args['expected_state'] ?? null;
			if ( ! is_string( $expected ) || ! hash_equals( $state['expected_state'], $expected ) ) {
				return $this->error( 'stale_revision_state', 'Compare the revision again before restoring.' );
			}
			if ( ! $this->runtime_available() || ! wp_revisions_enabled( $state['post'] ) ) {
				return $this->error( 'recovery_unavailable', 'Native revisions and edit-lock checks must be available.' );
			}
			if ( wp_check_post_lock( $state['post']->ID ) ) {
				return $this->error( 'post_locked', 'Another editor holds this post lock. Wait for them to finish.' );
			}
			if ( $this->is_dry_run( $args ) ) {
				return $this->preview_response( 'revisions.restore_content', $args, $this->summary( $state ), array(), $this->warnings() );
			}
			if ( $state['before'] === $state['after'] ) {
				return array(
					'success' => true,
					'changed' => false,
					'post_id' => $state['post']->ID,
				);
			}
		} catch ( \Throwable ) {
			return $this->error( 'recovery_unavailable', 'Recovery could not be validated safely.' );
		}
		return $this->persist( $args, $state );
	}

	/**
	 * Resolve a readable/editable parent and a non-autosave revision belonging to it.
	 *
	 * @param array<string,mixed> $args Target arguments.
	 * @return array<string,mixed>
	 */
	private function state( array $args ): array {
		$id       = $args['post_id'] ?? null;
		$revision = $args['revision_id'] ?? null;
		if ( ! is_int( $id ) || $id < 1 || ! is_int( $revision ) || $revision < 1 ) {
			return $this->error( 'invalid_revision_target', 'Provide positive integer post and revision IDs.' );
		}
		if ( ! current_user_can( 'read_post', $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return $this->error( 'forbidden', 'Reading and editing the parent content are required.' );
		}
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || ! in_array( $post->post_status, array( 'draft', 'pending', 'publish', 'private', 'future' ), true ) ) {
			return $this->error( 'unsupported_post', 'Only existing posts and pages outside the trash are supported.' );
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type instanceof \WP_Post_Type || ( in_array( $post->post_status, array( 'publish', 'private', 'future' ), true ) && ! current_user_can( $type->cap->publish_posts ) ) ) {
			return $this->error( 'forbidden', 'Publishing permission is required for live, private or scheduled content.' );
		}
		$source = get_post( $revision );
		if ( ! $source instanceof \WP_Post || 'revision' !== $source->post_type || $id !== (int) $source->post_parent || ! function_exists( 'wp_is_post_autosave' ) || false !== wp_is_post_autosave( $source ) ) {
			return $this->error( 'invalid_revision', 'Choose a saved, non-autosave revision of this exact post.' );
		}
		$before = $this->fields( $post );
		$after  = $this->fields( $source );
		foreach ( array_merge( array_values( $before ), array_values( $after ) ) as $value ) {
			if ( strlen( $value ) > 1048576 ) {
				return $this->error( 'content_too_large', 'Each content field must be at most one MiB for recovery.' );
			}
		}
		$encoded = wp_json_encode( array( get_current_blog_id(), $id, $post->post_type, $post->post_status, $post->post_name, $post->post_author, $post->post_parent, $post->post_modified_gmt, $before, $revision, $source->post_modified_gmt, $source->post_name, $after ) );
		if ( ! is_string( $encoded ) ) {
			return $this->error( 'invalid_revision_state', 'The current state could not be encoded safely.' );
		}
		return array(
			'post'           => $post,
			'revision_id'    => $revision,
			'before'         => $before,
			'after'          => $after,
			'expected_state' => hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) ),
		);
	}

	/**
	 * Return only native content fields; never metadata or rendered values.
	 *
	 * @param \WP_Post $post Native post.
	 * @return array<string,string>
	 */
	private function fields( \WP_Post $post ): array {
		$result = array();
		foreach ( self::FIELDS as $field ) {
			$result[ $field ] = $post->$field;
		}
		return $result;
	}

	/**
	 * Return a bounded comparison, not complete historical content.
	 *
	 * @param array<string,mixed> $state Internal validated state.
	 * @return array<string,mixed>
	 */
	private function summary( array $state ): array {
		$changes = array();
		foreach ( self::FIELDS as $field ) {
			$changes[ $field ] = array(
				'changed'        => $state['before'][ $field ] !== $state['after'][ $field ],
				'current_bytes'  => strlen( $state['before'][ $field ] ),
				'revision_bytes' => strlen( $state['after'][ $field ] ),
			);
		}
		return array(
			'post_id'        => $state['post']->ID,
			'revision_id'    => $state['revision_id'],
			'status'         => $state['post']->post_status,
			'expected_state' => $state['expected_state'],
			'fields'         => $changes,
			'warnings'       => $this->warnings(),
		);
	}

	/**
	 * Explain intentionally limited recovery semantics.
	 *
	 * @return list<string>
	 */
	private function warnings(): array {
		return array(
			'Only title, content and excerpt are restored. Metadata, terms, featured media, status and slug are not restored.',
			'Live content changes immediately. Native save hooks still run; this is not a full-site backup or atomic rollback.',
			'A matching recovery revision is required before writing. Native retention policies may prune older revisions.',
			'State and edit locks are checked before writing, but concurrent native or plugin writes cannot be made atomic.',
		);
	}

	/** Load the fixed native edit-lock helper when invoked outside wp-admin. */
	private function runtime_available(): bool {
		if ( ! function_exists( 'wp_check_post_lock' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/post.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}
		return function_exists( 'wp_check_post_lock' ) && function_exists( 'wp_revisions_enabled' ) && function_exists( 'wp_save_post_revision' );
	}

	/**
	 * Save recovery state, recheck, then write once without full meta restoration.
	 *
	 * @param array<string,mixed> $args Original arguments.
	 * @param array<string,mixed> $state Validated state.
	 * @return array<string,mixed>
	 */
	private function persist( array $args, array $state ): array {
		try {
			$id       = $state['post']->ID;
			$backup   = wp_save_post_revision( $id );
			$revision = is_int( $backup ) && $backup > 0 ? get_post( $backup ) : null;
			if ( ! $revision instanceof \WP_Post ) {
				$latest   = wp_get_post_revisions(
					$id,
					array(
						'posts_per_page' => 1,
						'orderby'        => 'ID',
						'order'          => 'DESC',
						'check_enabled'  => false,
					)
				);
				$revision = reset( $latest );
			}
			if ( ! $revision instanceof \WP_Post || 'revision' !== $revision->post_type || $id !== (int) $revision->post_parent || false !== wp_is_post_autosave( $revision ) || $state['before'] !== $this->fields( $revision ) ) {
				return $this->uncertain( 'The pre-restore recovery revision could not be verified. Content restoration was not attempted.' );
			}
			$current = $this->state( $args );
			if ( isset( $current['error'] ) || ! hash_equals( $state['expected_state'], $current['expected_state'] ) || wp_check_post_lock( $id ) ) {
				return $this->uncertain( 'The target changed or became locked after saving recovery state. Content restoration was not attempted.' );
			}
			// Full wp_restore_post_revision also restores registered metadata, outside this contract.
			$result = wp_update_post( wp_slash( array( 'ID' => $id ) + $state['after'] ), true );
			$after  = get_post( $id );
			$saved  = get_post( $revision->ID );
			if ( is_wp_error( $result ) || ! $after instanceof \WP_Post || $state['after'] !== $this->fields( $after ) || $state['post']->post_status !== $after->post_status || ! $saved instanceof \WP_Post || $state['before'] !== $this->fields( $saved ) ) {
				return $this->uncertain( 'The restore outcome could not be verified. Inspect the post manually; do not retry automatically.' );
			}
			return array(
				'success'              => true,
				'changed'              => true,
				'post_id'              => $id,
				'revision_id'          => $state['revision_id'],
				'recovery_revision_id' => $revision->ID,
				'restored_fields'      => self::FIELDS,
				'warnings'             => $this->warnings(),
			);
		} catch ( \Throwable ) {
			return $this->uncertain( 'Native recovery hooks failed. Inspect the post and revision history manually; do not retry automatically.' );
		}
	}

	/**
	 * Prevent automatic retry after a native operation may have persisted.
	 *
	 * @param string $message Fixed public explanation.
	 * @return array<string,mixed>
	 */
	private function uncertain( string $message ): array {
		return array(
			'error'         => 'partial_write',
			'terminal'      => true,
			'status'        => 'partial_write',
			'partial_write' => true,
			'message'       => $message,
		);
	}
}
