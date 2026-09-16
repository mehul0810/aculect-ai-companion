<?php
/**
 * Safely restore one trashed post or page through WordPress's native lifecycle.
 *
 * @package Aculect\AICompanion
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/** Inspects and restores only native trashed posts and pages. */
final class TrashedContentRecovery extends AbstractAbilityService {

	private const PRIOR_STATUSES = array( 'draft', 'pending', 'private', 'publish', 'future' );

	private const TRASH_STATUS_META = '_wp_trash_meta_status';

	private const TRASH_TIME_META = '_wp_trash_meta_time';

	/**
	 * Inspect one trash item without returning content or metadata values.
	 *
	 * @param array<string, mixed> $args Exact post ID.
	 * @return array<string, mixed>
	 */
	public function inspect_trashed( array $args ): array {
		try {
			$state = $this->state( $args );
			if ( isset( $state['error'] ) ) {
				return $state;
			}

			return array(
				'post_id'        => $state['post']->ID,
				'post_type'      => $state['post']->post_type,
				'prior_status'   => $state['prior_status'],
				'expected_state' => $state['expected_state'],
			);
		} catch ( \Throwable ) {
			return $this->error( 'recovery_unavailable', 'The trashed item could not be inspected safely.' );
		}
	}

	/**
	 * Restore one exact trash item through wp_untrash_post, always to draft.
	 *
	 * @param array<string, mixed> $args Exact post ID and state token.
	 * @return array<string, mixed>
	 */
	public function restore_trashed( array $args ): array {
		try {
			$state = $this->state( $args, true );
			if ( isset( $state['error'] ) ) {
				return $state;
			}

			$expected = $args['expected_state'] ?? null;
			if ( ! is_string( $expected ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected ) || ! hash_equals( $state['expected_state'], $expected ) ) {
				return $this->error( 'stale_trashed_state', 'Inspect this exact trash item again before restoring it.' );
			}

			if ( $this->is_dry_run( $args ) ) {
				return $this->preview( $args, $state );
			}

			$fresh = $this->state( $args, true );
			if ( isset( $fresh['error'] ) ) {
				if ( in_array( $fresh['error'], array( 'forbidden', 'recovery_unavailable' ), true ) ) {
					return $fresh;
				}
				return $this->error( 'stale_trashed_state', 'The trash item changed. Inspect it again before restoring.' );
			}
			if ( ! hash_equals( $expected, $fresh['expected_state'] ) ) {
				return $this->error( 'stale_trashed_state', 'The trash item changed. Inspect it again before restoring.' );
			}
			if ( wp_check_post_lock( $fresh['post']->ID ) ) {
				return $this->error( 'post_locked', 'Another editor holds this item lock. Wait for them to finish.' );
			}
		} catch ( \Throwable ) {
			return $this->error( 'recovery_unavailable', 'The restore could not be validated safely.' );
		}

		return $this->persist( $fresh );
	}

	/**
	 * Resolve the exact trashed parent, native trash metadata and keyed state.
	 *
	 * @param array<string, mixed> $args Target arguments.
	 * @param bool                 $for_restore Whether delete_post is required.
	 * @return array<string, mixed>
	 */
	private function state( array $args, bool $for_restore = false ): array {
		if ( ! $this->runtime_available() ) {
			return $this->error( 'recovery_unavailable', 'Required native WordPress recovery APIs are unavailable.' );
		}

		$id = $args['post_id'] ?? null;
		if ( ! is_int( $id ) || $id < 1 ) {
			return $this->error( 'invalid_trashed_target', 'Provide one positive integer post ID.' );
		}
		if ( ! current_user_can( 'read_post', $id ) || ! current_user_can( 'edit_post', $id ) || ( $for_restore && ! current_user_can( 'delete_post', $id ) ) ) {
			return $this->error( 'forbidden', 'Reading and editing this item are required; restoring also requires native delete permission.' );
		}

		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || $post->ID !== $id ) {
			return $this->error( 'invalid_trashed_target', 'The requested ID does not resolve to that exact content item.' );
		}
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return $this->error( 'unsupported_post', 'Only an existing post or page can be inspected or restored.' );
		}
		if ( 'trash' !== $post->post_status ) {
			return $this->error( 'not_trashed', 'The post or page must still be in the native trash.' );
		}

		$meta = $this->trash_metadata( $id );
		if ( isset( $meta['error'] ) ) {
			return $meta;
		}
		$digest = $this->content_digest( $post );
		if ( ! is_string( $digest ) ) {
			return $this->error( 'invalid_trashed_state', 'The item state could not be encoded safely.' );
		}

		$encoded = wp_json_encode(
			array(
				'site_id'           => get_current_blog_id(),
				'post_id'           => $post->ID,
				'post_type'         => $post->post_type,
				'post_status'       => $post->post_status,
				'post_name'         => $post->post_name,
				'post_author'       => $post->post_author,
				'post_parent'       => $post->post_parent,
				'post_date_gmt'     => $post->post_date_gmt,
				'post_modified_gmt' => $post->post_modified_gmt,
				'content_digest'    => $digest,
				'trash_status'      => $meta['prior_status'],
				'trash_time'        => $meta['trash_time'],
			)
		);
		$salt    = wp_salt( 'auth' );
		if ( ! is_string( $encoded ) || ! is_string( $salt ) || '' === $salt ) {
			return $this->error( 'invalid_trashed_state', 'The item state could not be keyed safely.' );
		}

		return array(
			'post'           => $post,
			'prior_status'   => $meta['prior_status'],
			'content_digest' => $digest,
			'expected_state' => hash_hmac( 'sha256', $encoded, $salt ),
		);
	}

	/**
	 * Require the two native trash metadata values used by wp_untrash_post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private function trash_metadata( int $post_id ): array {
		if ( ! metadata_exists( 'post', $post_id, self::TRASH_STATUS_META ) || ! metadata_exists( 'post', $post_id, self::TRASH_TIME_META ) ) {
			return $this->error( 'invalid_trash_state', 'Native trash metadata is missing; this item cannot be restored safely.' );
		}

		$prior_status = get_post_meta( $post_id, self::TRASH_STATUS_META, true );
		$trash_time   = get_post_meta( $post_id, self::TRASH_TIME_META, true );
		if ( ! is_string( $prior_status ) || ! in_array( $prior_status, self::PRIOR_STATUSES, true ) || ! $this->valid_trash_time( $trash_time ) ) {
			return $this->error( 'invalid_trash_state', 'Native trash metadata has an unsupported value.' );
		}

		return array(
			'prior_status' => $prior_status,
			'trash_time'   => $trash_time,
		);
	}

	/**
	 * Accept only the positive integer timestamp shape used by WordPress core.
	 *
	 * @param mixed $value Native trash time value.
	 */
	private function valid_trash_time( mixed $value ): bool {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return false;
		}
		return 1 === preg_match( '/^[1-9][0-9]*$/D', (string) $value );
	}

	/**
	 * Hash content-bearing parent fields without returning their values.
	 *
	 * @param \WP_Post $post Native post.
	 * @return string|false
	 */
	private function content_digest( \WP_Post $post ): string|false {
		$encoded = wp_json_encode(
			array(
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_excerpt' => $post->post_excerpt,
			)
		);
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : false;
	}

	/**
	 * Return a side-effect-free preview and disclose native comment restoration.
	 *
	 * @param array<string, mixed> $args Original arguments.
	 * @param array<string, mixed> $state Validated trash state.
	 * @return array<string, mixed>
	 */
	private function preview( array $args, array $state ): array {
		return $this->preview_response(
			'content.restore_trashed',
			$args,
			array(
				'post_id'        => $state['post']->ID,
				'post_type'      => $state['post']->post_type,
				'prior_status'   => $state['prior_status'],
				'restore_status' => 'draft',
			),
			array( $this->change( 'post_status', 'trash', 'draft' ) ),
			$this->warnings()
		);
	}

	/**
	 * Run native untrash with an exact-target, highest-priority draft filter.
	 *
	 * @param array<string, mixed> $state Fresh validated state.
	 * @return array<string, mixed>
	 */
	private function persist( array $state ): array {
		$post_id = $state['post']->ID;
		$filter  = static function ( mixed $status, mixed $target_id ) use ( $post_id ): mixed {
			return $target_id === $post_id ? 'draft' : $status;
		};
		$removed = false;
		$failed  = false;
		$result  = null;

		try {
			add_filter( 'wp_untrash_post_status', $filter, PHP_INT_MAX, 2 );
			$result = wp_untrash_post( $post_id );
		} catch ( \Throwable ) {
			$failed = true;
		} finally {
			try {
				$removed = remove_filter( 'wp_untrash_post_status', $filter, PHP_INT_MAX );
			} catch ( \Throwable ) {
				$removed = false;
			}
		}

		if ( $failed || ! $removed ) {
			return $this->uncertain();
		}
		if ( ! $result instanceof \WP_Post || $result->ID !== $post_id ) {
			return $this->uncertain();
		}

		try {
			$after = get_post( $post_id );
			if ( ! $after instanceof \WP_Post || $after->ID !== $post_id || $after->post_type !== $state['post']->post_type || 'draft' !== $after->post_status || ! $this->trash_metadata_removed( $post_id ) ) {
				return $this->uncertain();
			}
			$digest = $this->content_digest( $after );
			if ( ! is_string( $digest ) || ! hash_equals( $state['content_digest'], $digest ) ) {
				return $this->uncertain();
			}
		} catch ( \Throwable ) {
			return $this->uncertain();
		}

		return array(
			'success'        => true,
			'changed'        => true,
			'post_id'        => $post_id,
			'post_type'      => $after->post_type,
			'prior_status'   => $state['prior_status'],
			'restore_status' => 'draft',
			'warnings'       => $this->warnings(),
		);
	}

	/**
	 * Verify that both core trash metadata records were removed.
	 *
	 * @param int $post_id Post ID.
	 */
	private function trash_metadata_removed( int $post_id ): bool {
		return ! metadata_exists( 'post', $post_id, self::TRASH_STATUS_META ) && ! metadata_exists( 'post', $post_id, self::TRASH_TIME_META );
	}

	/**
	 * Load and validate the fixed native edit-lock and trash APIs.
	 */
	private function runtime_available(): bool {
		if ( ! function_exists( 'wp_check_post_lock' ) && defined( 'ABSPATH' ) ) {
			$lock_file = ABSPATH . 'wp-admin/includes/post.php';
			if ( is_readable( $lock_file ) ) {
				require_once $lock_file;
			}
		}

		$required = array( 'wp_check_post_lock', 'current_user_can', 'get_post', 'get_current_blog_id', 'metadata_exists', 'get_post_meta', 'wp_json_encode', 'wp_salt', 'wp_untrash_post', 'add_filter', 'remove_filter' );
		foreach ( $required as $function ) {
			if ( ! function_exists( $function ) || ! is_callable( $function ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * State which native WordPress will restore along with the post.
	 *
	 * @return list<string>
	 */
	private function warnings(): array {
		return array(
			'Every restored post or page returns to draft, including items previously published, private or scheduled; nothing is republished.',
			'WordPress native untrash hooks run and restore associated trashed comments. Plugin hooks may also run.',
			'The state and edit lock are checked immediately before restoration, but concurrent writes after that check cannot be made atomic.',
		);
	}

	/**
	 * Return a terminal result whenever native hooks or outcomes are uncertain.
	 *
	 * @return array<string, mixed>
	 */
	private function uncertain(): array {
		return array(
			'error'         => 'partial_write',
			'terminal'      => true,
			'status'        => 'partial_write',
			'partial_write' => true,
			'message'       => 'The native restore outcome or hooks could not be verified. Inspect the post and comments manually; do not retry automatically.',
		);
	}
}
