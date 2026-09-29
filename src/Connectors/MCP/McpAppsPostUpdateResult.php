<?php

declare(strict_types=1);

namespace Aculect\AICompanion\Connectors\MCP;

/**
 * Builds the bounded, actor-authorized result consumed by the post-update app.
 */
final class McpAppsPostUpdateResult {
	public const SCHEMA          = 'aculect.post-update.v1';
	private const REVISION_LIMIT = 50;

	/**
	 * Capture the exact pre-write post and revision identities for this request.
	 *
	 * @param int $post_id Content ID.
	 * @return array{post:\WP_Post,revision_ids:list<int>,prior_revision_id:int,post_id:int,site_id:int,user_id:int}|null
	 */
	public function capture_before( int $post_id ): ?array {
		if ( ! McpAppsNegotiation::request_enabled() || 0 >= $post_id || ! function_exists( 'get_post' ) ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$revision_ids      = array();
		$prior_revision_id = 0;
		if ( function_exists( 'wp_get_post_revisions' ) ) {
			$revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => self::REVISION_LIMIT ) );
			foreach ( array_slice( $revisions, 0, self::REVISION_LIMIT, true ) as $revision ) {
				if ( $revision instanceof \WP_Post ) {
					$revision_ids[] = (int) $revision->ID;
					if ( 0 === $prior_revision_id && 'revision' === $revision->post_type
						&& (int) $revision->post_parent === $post_id
						&& ( ! function_exists( 'wp_is_post_autosave' ) || false === wp_is_post_autosave( $revision ) )
						&& $this->revision_matches( $revision, $post ) ) {
						$prior_revision_id = (int) $revision->ID;
					}
				}
			}
		}

		return array(
			'post'              => clone $post,
			'revision_ids'      => array_values( array_unique( $revision_ids ) ),
			'prior_revision_id' => $prior_revision_id,
			'post_id'           => (int) $post->ID,
			'site_id'           => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 0,
			'user_id'           => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
		);
	}

	/**
	 * Build the additive widget contract for a completed or partial update.
	 *
	 * @param int                       $post_id    Updated content ID.
	 * @param array<string, mixed>      $result     Atomic update result.
	 * @param array<string, mixed>|null $before Captured pre-write state.
	 * @return array<string, mixed>|null Null when disabled or result is not an update outcome.
	 */
	public function build( int $post_id, array $result, ?array $before ): ?array {
		if ( ! McpAppsNegotiation::request_enabled() ) {
			return null;
		}

		$outcome = $this->outcome( $result );
		if ( '' === $outcome || 0 >= $post_id ) {
			return null;
		}

		$now  = gmdate( 'Y-m-d\TH:i:s\Z' );
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post instanceof \WP_Post || ! current_user_can( 'read_post', $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $this->payload( 'unavailable', '', '', '', $now, '', array(), null );
		}

		if ( 'success' === $outcome && $this->result_is_stale( $result, $post ) ) {
			$outcome = 'stale';
		}

		$links    = $this->links( $post );
		$revision = 'success' === $outcome ? $this->new_revision( $post, $before ) : null;
		if ( null !== $revision && current_user_can( 'read_post', (int) $revision->ID ) && current_user_can( 'edit_post', (int) $post->ID ) ) {
			$compare_url = self::same_site_https_url( admin_url( 'revision.php?revision=' . (int) $revision->ID ) );
			if ( '' !== $compare_url ) {
				$links['compare'] = $compare_url;
			}
		}

		$undo = 'success' === $outcome ? $this->matching_prechange_revision( $post, $result, $before ) : null;
		if ( null !== $undo ) {
			// WordPress performs the human review and any restore in its authenticated revision screen.
			$links['undo'] = self::same_site_https_url( admin_url( 'revision.php?revision=' . $undo['revision_id'] ) );
			if ( '' === $links['undo'] ) {
				$undo = null;
			}
		}

		return $this->payload(
			$outcome,
			(string) $post->post_title,
			$this->post_type_label( $post ),
			$this->post_status_label( $post ),
			$now,
			null === $revision ? '' : 'Revision ' . (int) $revision->ID,
			$links,
			$undo
		);
	}

	/**
	 * Append the widget contract without changing unrelated ability results.
	 *
	 * @param array<string, mixed>      $response Existing workflow result.
	 * @param int                       $post_id  Updated content ID.
	 * @param array<string, mixed>      $result   Atomic update result.
	 * @param array<string, mixed>|null $before   Captured pre-write state.
	 * @return array<string, mixed>
	 */
	public function append( array $response, int $post_id, array $result, ?array $before ): array {
		$contract = $this->build( $post_id, $result, $before );
		return null === $contract ? $response : array_merge( $response, $contract );
	}

	/**
	 * Render a complete plain-text equivalent for hosts that do not show widgets.
	 *
	 * @param array<string, mixed> $result Structured tool data.
	 */
	public static function text_fallback( array $result ): ?string {
		if ( self::SCHEMA !== ( $result['schema'] ?? null ) ) {
			return self::legacy_text_fallback( $result );
		}

		$content = is_array( $result['content'] ?? null ) ? $result['content'] : array();
		$links   = is_array( $result['links'] ?? null ) ? $result['links'] : array();
		$outcome = sanitize_key( (string) ( $result['outcome'] ?? 'error' ) );
		if ( 'approval_pending' === $outcome ) {
			$approval  = is_array( $result['approval'] ?? null ) ? $result['approval'] : array();
			$expiry    = sanitize_text_field( (string) ( $approval['expires_at'] ?? '' ) );
			$queue     = self::safe_https_url( (string) ( $approval['queue'] ?? '' ) );
			$summary   = array( 'WordPress approval is required for this exact content update. No update has run.' );
			$summary[] = 'Target: ' . sanitize_text_field( (string) ( $result['approval_target'] ?? $content['title'] ?? '' ) );
			$summary[] = 'Risk: ' . sanitize_key( (string) ( $result['risk_level'] ?? 'update' ) );
			$changes   = is_array( $result['changes'] ?? null ) ? array_slice( $result['changes'], 0, 20 ) : array();
			foreach ( $changes as $change ) {
				if ( is_array( $change ) ) {
					$summary[] = sanitize_text_field( (string) ( $change['label'] ?? 'Changed field' ) ) . ': ' . sanitize_textarea_field( (string) ( $change['before'] ?? '' ) ) . ' → ' . sanitize_textarea_field( (string) ( $change['after'] ?? '' ) );
				}
			}
			if ( '' !== $expiry ) {
				$summary[] = 'Expires: ' . $expiry;
			}
			$summary[] = 'Approving records a decision only. The exact operation must be resubmitted, and WordPress rechecks current access and policy.';
			if ( '' !== $queue ) {
				$summary[] = 'Review in WordPress: ' . $queue;
			}
			return implode( "\n", $summary );
		}
		if ( 'success' === $outcome && ( ! empty( $result['error'] ) || 'success' !== ( $result['status'] ?? '' ) ) ) {
			$outcome = 'error';
		} elseif ( 'partial' === $outcome && 'partial_write' !== ( $result['error'] ?? '' ) ) {
			$outcome = 'error';
		} elseif ( ! in_array( $outcome, array( 'success', 'partial', 'stale', 'unavailable', 'error' ), true ) ) {
			$outcome = 'error';
		}
		$title = sanitize_text_field( (string) ( $content['title'] ?? '' ) );
		$lines = array( 'Content update ' . ( 'success' === $outcome ? 'completed' : str_replace( '_', ' ', $outcome ) ) . '.' );
		if ( '' !== $title ) {
			$lines[] = 'Title: ' . $title;
		}
		foreach ( array(
			'type'         => 'Type',
			'status'       => 'Status',
			'completed_at' => 'Completed',
		) as $key => $label ) {
			$value = sanitize_text_field( (string) ( $content[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$lines[] = $label . ': ' . $value;
			}
		}
		$revision = sanitize_text_field( (string) ( $content['revision'] ?? '' ) );
		if ( '' !== $revision ) {
			$lines[] = 'Revision: ' . $revision;
		}
		foreach ( array(
			'view'    => 'View page',
			'edit'    => 'Edit in WordPress',
			'compare' => 'Compare revision',
		) as $key => $label ) {
			$url = self::safe_https_url( (string) ( $links[ $key ] ?? '' ) );
			if ( '' !== $url ) {
				$lines[] = $label . ': ' . $url;
			}
		}
		$undo     = is_array( $result['undo'] ?? null ) ? $result['undo'] : array();
		$undo_url = self::safe_https_url( (string) ( $links['undo'] ?? '' ) );
		if ( 'success' === $outcome && true === ( $undo['available'] ?? false ) && true === ( $undo['safe_snapshot'] ?? false )
			&& 'wordpress_revision_review' === ( $undo['reason'] ?? '' ) && '' !== $undo_url
			&& 0 < (int) ( $undo['post_id'] ?? 0 ) && 0 < (int) ( $undo['revision_id'] ?? 0 ) ) {
			$lines[] = sprintf( 'The pre-change revision for post %d (revision %d) is available to review in WordPress. Opening it does not restore content. Recheck the current post before acting: native Restore can also change revisioned metadata and does not undo status, terms, or featured image changes. Aculect content-only recovery requires a fresh comparison and separate confirmation. The widget did not perform an undo.', (int) $undo['post_id'], (int) $undo['revision_id'] );
			if ( '' !== $undo_url ) {
				$lines[] = 'Review prior revision in WordPress: ' . $undo_url;
			}
		} else {
			$lines[] = 'A verified pre-change revision handoff is unavailable in this result. Review current content and revision history in WordPress before making another change.';
		}
		$lines[] = 'Verify the current WordPress status before sharing. This response does not authorize a separate publish action.';

		return implode( "\n", $lines );
	}

	/**
	 * Build stable top-level widget data while preserving the existing tool result.
	 *
	 * @param string                                  $outcome Outcome name.
	 * @param string                                  $title   Post title.
	 * @param string                                  $type    Content type.
	 * @param string                                  $status  Content status.
	 * @param string                                  $time    Completion time.
	 * @param string                                  $revision Revision label.
	 * @param array<string, string>                   $links   Canonical safe links.
	 * @param array{post_id:int,revision_id:int}|null $undo    Verified pre-change revision, if available.
	 * @return array<string, mixed>
	 */
	private function payload( string $outcome, string $title, string $type, string $status, string $time, string $revision, array $links, ?array $undo ): array {
		$payload = array(
			'schema'  => self::SCHEMA,
			'outcome' => $outcome,
			'status'  => 'partial' === $outcome ? 'partial' : $outcome,
			'content' => array(
				'title'        => sanitize_text_field( $title ),
				'type'         => sanitize_text_field( $type ),
				'status'       => sanitize_text_field( $status ),
				'completed_at' => $time,
				'revision'     => sanitize_text_field( $revision ),
			),
			'links'   => array(
				'view'    => self::safe_https_url( (string) ( $links['view'] ?? '' ) ),
				'edit'    => self::safe_https_url( (string) ( $links['edit'] ?? '' ) ),
				'compare' => self::safe_https_url( (string) ( $links['compare'] ?? '' ) ),
				'undo'    => null !== $undo ? self::safe_https_url( (string) ( $links['undo'] ?? '' ) ) : '',
			),
			'undo'    => array(
				'available'     => null !== $undo,
				'safe_snapshot' => null !== $undo,
				'post_id'       => $undo['post_id'] ?? 0,
				'revision_id'   => $undo['revision_id'] ?? 0,
				'reason'        => null !== $undo ? 'wordpress_revision_review' : ( 'success' === $outcome ? 'revision_unavailable' : $outcome ),
			),
		);
		if ( 'partial' === $outcome ) {
			$payload['error'] = 'partial_write';
		}

		return $payload;
	}

	/**
	 * Resolve whether a failed write left a partial state worth presenting.
	 *
	 * @param array<string, mixed> $result Atomic result.
	 */
	private function outcome( array $result ): string {
		if ( true === ( $result['dry_run'] ?? false ) ) {
			return '';
		}

		if ( 'partial_write' === ( $result['error'] ?? '' ) && 'partial' === ( $result['status'] ?? '' ) ) {
			return 'partial';
		}

		return empty( $result['error'] ) ? 'success' : '';
	}

	/**
	 * Compare the committed result revision with the current post to catch races.
	 *
	 * @param array<string, mixed> $result Atomic update result.
	 * @param \WP_Post             $post   Current target post.
	 */
	private function result_is_stale( array $result, \WP_Post $post ): bool {
		$expected = (string) ( $result['modified_gmt'] ?? '' );
		if ( '' !== $expected && ! hash_equals( (string) $post->post_modified_gmt, $expected ) ) {
			return true;
		}

		$has_saved_fields = is_string( $result['title'] ?? null )
			&& is_string( $result['content'] ?? null )
			&& is_string( $result['excerpt'] ?? null );
		return $has_saved_fields && ! $this->result_matches_current_post( $result, $post );
	}

	/**
	 * Find a pre-existing native revision that exactly matches this update's pre-write snapshot.
	 *
	 * The identifier is a locator only. Comparison and restore abilities still
	 * recheck access/current state, and restore requires the existing confirmation token.
	 *
	 * @param \WP_Post                  $post    Current updated post.
	 * @param array<string, mixed>      $result  Atomic successful update result.
	 * @param array<string, mixed>|null $before  Pre-write snapshot and request identity.
	 * @return array{post_id:int,revision_id:int}|null
	 */
	private function matching_prechange_revision( \WP_Post $post, array $result, ?array $before ): ?array {
		$captured = $before['post'] ?? null;
		$user_id  = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
		if ( ! $captured instanceof \WP_Post || ! function_exists( 'wp_get_post_revisions' ) || ! function_exists( 'get_current_blog_id' )
			|| 0 >= $user_id || (int) ( $before['user_id'] ?? 0 ) !== $user_id
			|| (int) ( $before['site_id'] ?? 0 ) !== get_current_blog_id()
			|| (int) ( $before['post_id'] ?? 0 ) !== (int) $post->ID || (int) $captured->ID !== (int) $post->ID
			|| ! in_array( $post->post_type, array( 'post', 'page' ), true ) || $captured->post_type !== $post->post_type
			|| ! $this->result_matches_current_post( $result, $post ) ) {
			return null;
		}

		$prior_fields = array( 'post_title', 'post_content', 'post_excerpt' );
		$changed      = false;
		foreach ( $prior_fields as $field ) {
			if ( ! is_string( $captured->{$field} ) || ! is_string( $post->{$field} ) ) {
				return null;
			}
			$changed = $changed || $captured->{$field} !== $post->{$field};
		}
		if ( ! $changed ) {
			return null;
		}

		$prior_id = (int) ( $before['prior_revision_id'] ?? 0 );
		if ( 0 >= $prior_id || ! current_user_can( 'read_post', $prior_id ) ) {
			return null;
		}
		$revisions = wp_get_post_revisions( (int) $post->ID, array( 'numberposts' => self::REVISION_LIMIT ) );
		foreach ( array_slice( $revisions, 0, self::REVISION_LIMIT, true ) as $revision ) {
			if ( ! $revision instanceof \WP_Post || (int) $revision->ID !== $prior_id
				|| 'revision' !== $revision->post_type || (int) $revision->post_parent !== (int) $post->ID
				|| ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $revision ) )
				|| ! $this->revision_matches( $revision, $captured ) ) {
				continue;
			}

			return array(
				'post_id'     => (int) $post->ID,
				'revision_id' => (int) $revision->ID,
			);
		}

		return null;
	}

	/**
	 * Verify the workflow's saved response still exactly describes the current post.
	 *
	 * @param array<string, mixed> $result Workflow result's saved fields.
	 * @param \WP_Post             $post   Current post.
	 */
	private function result_matches_current_post( array $result, \WP_Post $post ): bool {
		return (int) ( $result['id'] ?? 0 ) === (int) $post->ID
			&& ( ! isset( $result['type'] ) || (string) $result['type'] === (string) $post->post_type )
			&& ( ! isset( $result['status'] ) || (string) $result['status'] === (string) $post->post_status )
			&& ( ! isset( $result['slug'] ) || (string) $result['slug'] === (string) $post->post_name )
			&& ( ! isset( $result['author'] ) || (int) $result['author'] === (int) $post->post_author )
			&& is_string( $result['modified_gmt'] ?? null )
			&& '' !== $result['modified_gmt']
			&& hash_equals( (string) $post->post_modified_gmt, $result['modified_gmt'] )
			&& is_string( $result['title'] ?? null ) && hash_equals( (string) $post->post_title, $result['title'] )
			&& is_string( $result['content'] ?? null ) && hash_equals( (string) $post->post_content, $result['content'] )
			&& is_string( $result['excerpt'] ?? null ) && hash_equals( (string) $post->post_excerpt, $result['excerpt'] );
	}

	/**
	 * Return a new, non-autosave revision that matches the post saved by this update.
	 *
	 * @param \WP_Post                  $post   Updated parent post, whose revisioned fields are saved by core.
	 * @param array<string, mixed>|null $before Pre-write state.
	 */
	private function new_revision( \WP_Post $post, ?array $before ): ?\WP_Post {
		$captured_post = $before['post'] ?? null;
		if ( ! $captured_post instanceof \WP_Post || ! function_exists( 'wp_get_post_revisions' ) ) {
			return null;
		}

		$old_ids   = array_map( 'absint', (array) ( $before['revision_ids'] ?? array() ) );
		$revisions = wp_get_post_revisions( (int) $post->ID, array( 'numberposts' => self::REVISION_LIMIT ) );
		foreach ( array_slice( $revisions, 0, self::REVISION_LIMIT ) as $revision ) {
			if ( ! $revision instanceof \WP_Post || 'revision' !== $revision->post_type || (int) $revision->post_parent !== (int) $post->ID || in_array( (int) $revision->ID, $old_ids, true ) ) {
				continue;
			}
			if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $revision ) ) {
				continue;
			}
			if ( $this->revision_matches( $revision, $post ) ) {
				return $revision;
			}
		}

		return null;
	}

	/**
	 * Verify that WordPress saved the updated revisioned content fields.
	 *
	 * @param \WP_Post $revision Revision candidate.
	 * @param \WP_Post $before   Updated post snapshot.
	 */
	private function revision_matches( \WP_Post $revision, \WP_Post $before ): bool {
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			if ( (string) $revision->{$field} !== (string) $before->{$field} ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Generate only links allowed by the authenticated user's current capability.
	 *
	 * @param \WP_Post $post Current post.
	 * @return array<string, string>
	 */
	private function links( \WP_Post $post ): array {
		$links = array(
			'view'    => '',
			'edit'    => '',
			'compare' => '',
		);
		if ( current_user_can( 'read_post', (int) $post->ID ) && 'publish' === $post->post_status && function_exists( 'get_permalink' ) ) {
			$links['view'] = self::same_site_https_url( (string) get_permalink( $post ) );
		}
		if ( current_user_can( 'edit_post', (int) $post->ID ) && function_exists( 'get_edit_post_link' ) ) {
			$links['edit'] = self::same_site_https_url( (string) get_edit_post_link( (int) $post->ID, 'raw' ) );
		}

		return $links;
	}

	/**
	 * Require an HTTPS WordPress URL on a configured site host.
	 *
	 * @param string $url Candidate URL.
	 */
	private static function same_site_https_url( string $url ): string {
		$safe = self::safe_https_url( $url );
		if ( '' === $safe ) {
			return '';
		}

		$host          = strtolower( (string) wp_parse_url( $safe, PHP_URL_HOST ) );
		$allowed_hosts = array_filter(
			array_map(
				static fn ( string $base ): string => strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) ),
				array( (string) home_url(), (string) site_url() )
			)
		);

		return in_array( $host, $allowed_hosts, true ) ? $safe : '';
	}

	/**
	 * Render a safe fallback for clients that did not negotiate MCP Apps.
	 *
	 * @param array<string, mixed> $result Existing workflow response.
	 */
	private static function legacy_text_fallback( array $result ): ?string {
		if ( 'content_workflow_update_post' !== ( $result['workflow'] ?? '' ) || 'success' !== ( $result['status'] ?? '' ) || ! empty( $result['error'] ) || true === ( $result['dry_run'] ?? false ) ) {
			return null;
		}

		$fields = is_array( $result['fields'] ?? null ) ? $result['fields'] : array();
		$title  = sanitize_text_field( (string) ( $result['title'] ?? '' ) );
		$type   = sanitize_text_field( (string) ( $result['post_type'] ?? '' ) );
		$status = sanitize_text_field( (string) ( $fields['status'] ?? $fields['post_status'] ?? '' ) );
		$lines  = array( 'Content update completed.' );
		if ( '' !== $title ) {
			$lines[] = 'Title: ' . $title;
		}
		if ( '' !== $type ) {
			$lines[] = 'Type: ' . $type;
		}
		if ( '' !== $status ) {
			$lines[] = 'Status: ' . $status;
		}
		$edit_url = self::same_site_https_url( (string) ( $result['edit_url'] ?? '' ) );
		if ( '' !== $edit_url ) {
			$lines[] = 'Edit in WordPress: ' . $edit_url;
		}
		if ( 'publish' === sanitize_key( $status ) ) {
			$view_url = self::same_site_https_url( (string) ( $result['permalink'] ?? '' ) );
			if ( '' !== $view_url ) {
				$lines[] = 'View page: ' . $view_url;
			}
		}
		$lines[] = 'Revision comparison is not available in this text result.';
		$lines[] = 'Undo is unavailable. Review the content in WordPress before making another change.';
		$lines[] = 'Verify the current WordPress status before sharing. This response does not authorize a separate publish action.';

		return implode( "\n", $lines );
	}

	/**
	 * Reject unsafe or non-HTTPS URLs, including embedded credentials.
	 *
	 * @param string $url Candidate URL.
	 */
	private static function safe_https_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		return esc_url_raw( $url, array( 'https' ) );
	}

	/**
	 * Return a human-readable WordPress content type label.
	 *
	 * @param \WP_Post $post Current post.
	 */
	private function post_type_label( \WP_Post $post ): string {
		$type = get_post_type_object( $post->post_type );
		return $type instanceof \WP_Post_Type ? (string) ( $type->labels->singular_name ?? $type->label ) : $post->post_type;
	}

	/**
	 * Return a human-readable status label.
	 *
	 * @param \WP_Post $post Current post.
	 */
	private function post_status_label( \WP_Post $post ): string {
		$status = function_exists( 'get_post_status_object' ) ? get_post_status_object( $post->post_status ) : null;
		return is_object( $status ) && isset( $status->label ) ? (string) $status->label : (string) $post->post_status;
	}
}
