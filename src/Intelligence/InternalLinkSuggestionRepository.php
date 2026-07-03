<?php
/**
 * Reviewable internal-link suggestion storage.
 *
 * @package Aculect\AICompanion\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence;

/**
 * Stores bounded review-first internal-link suggestions without full post content.
 */
final class InternalLinkSuggestionRepository {

	public const OPTION = 'aculect_ai_companion_internal_link_suggestions';

	private const MAX_ITEMS       = 200;
	private const MAX_BATCH_ITEMS = 20;
	private const STATUSES        = array( 'suggested', 'approved', 'rejected', 'applied', 'skipped', 'stale' );

	/**
	 * Create bounded suggestion records.
	 *
	 * @param array<string, mixed> $args Suggestion payload.
	 * @return array<string, mixed>
	 */
	public function create( array $args ): array {
		$source_id = absint( $args['source_id'] ?? $args['post_id'] ?? 0 );
		$items     = $this->candidate_items( $args );

		if ( $source_id <= 0 || array() === $items ) {
			return $this->error( 'invalid_suggestion', 'Provide a source_id and at least one bounded internal-link suggestion item.' );
		}

		$stored     = $this->all();
		$created    = array();
		$duplicates = array();

		foreach ( $items as $item ) {
			$suggestion = $this->sanitize_suggestion( array_merge( $item, array( 'source_id' => $source_id ) ) );
			if ( array() === $suggestion ) {
				continue;
			}

			$existing = $this->find_duplicate( $stored, $suggestion );
			if ( null !== $existing ) {
				$duplicates[] = $existing;
				continue;
			}

			$stored[]  = $suggestion;
			$created[] = $suggestion;
		}

		if ( array() !== $created ) {
			$this->write( $stored );
		}

		return array(
			'status'        => array() === $created ? 'duplicate' : 'created',
			'items'         => $created,
			'duplicates'    => $duplicates,
			'total_created' => count( $created ),
			'next_actions'  => array(
				'Use content_internal_link_suggestions_list to review stored suggestions.',
				'Use content_internal_link_suggestion_review to approve or reject one suggestion before any apply planning.',
			),
		);
	}

	/**
	 * List stored suggestions.
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public function list( array $args ): array {
		$page     = max( 1, absint( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 50, absint( $args['per_page'] ?? 20 ) ) );
		$status   = $this->status( (string) ( $args['status'] ?? '' ), '' );
		$source   = absint( $args['source_id'] ?? $args['post_id'] ?? 0 );
		$target   = absint( $args['target_id'] ?? 0 );

		$items = array_values(
			array_filter(
				$this->all(),
				static function ( array $item ) use ( $status, $source, $target ): bool {
					if ( '' !== $status && (string) ( $item['status'] ?? '' ) !== $status ) {
						return false;
					}
					if ( 0 < $source && (int) ( $item['source_post']['id'] ?? 0 ) !== $source ) {
						return false;
					}
					if ( 0 < $target && (int) ( $item['target_post']['id'] ?? 0 ) !== $target ) {
						return false;
					}

					return true;
				}
			)
		);

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$score = (int) ( $b['score'] ?? 0 ) <=> (int) ( $a['score'] ?? 0 );
				if ( 0 !== $score ) {
					return $score;
				}

				return strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) );
			}
		);

		$offset = ( $page - 1 ) * $per_page;

		return array(
			'items'        => array_slice( $items, $offset, $per_page ),
			'total'        => count( $items ),
			'page'         => $page,
			'per_page'     => $per_page,
			'status'       => $status,
			'capabilities' => array(
				'approve_reject' => true,
				'dry_run_apply'  => true,
				'execute_apply'  => false,
			),
		);
	}

	/**
	 * Approve, reject, skip, or stale-mark one suggestion.
	 *
	 * @param string $id     Suggestion ID.
	 * @param string $action Review action.
	 * @param string $note   Review note.
	 * @return array<string, mixed>
	 */
	public function review( string $id, string $action, string $note = '' ): array {
		$status = match ( sanitize_key( $action ) ) {
			'approve', 'approved' => 'approved',
			'reject', 'rejected' => 'rejected',
			'skip', 'skipped' => 'skipped',
			'stale' => 'stale',
			default => '',
		};

		if ( '' === $status ) {
			return $this->error( 'invalid_status', 'Review action must approve, reject, skip, or stale.' );
		}

		$stored = $this->all();
		foreach ( $stored as $index => $item ) {
			if ( (string) ( $item['id'] ?? '' ) !== $id ) {
				continue;
			}

			$stored[ $index ]['status']       = $status;
			$stored[ $index ]['review_note']  = $this->text( $note, 500 );
			$stored[ $index ]['reviewed_at']  = gmdate( 'Y-m-d\TH:i:s\Z' );
			$stored[ $index ]['last_checked'] = gmdate( 'Y-m-d\TH:i:s\Z' );
			$stored[ $index ]['updated_at']   = gmdate( 'Y-m-d\TH:i:s\Z' );
			$this->write( $stored );

			return array(
				'status'       => 'updated',
				'suggestion'   => $stored[ $index ],
				'review_state' => array(
					'status'                  => $status,
					'approved_for_apply_plan' => 'approved' === $status,
				),
				'next_actions' => 'approved' === $status
					? array( 'Use content_internal_link_suggestion_apply with dry_run=true to inspect the bounded apply plan.' )
					: array( 'No content apply plan is available unless the suggestion is approved.' ),
			);
		}

		return $this->error( 'suggestion_not_found', 'No internal-link suggestion was found for that ID.' );
	}

	/**
	 * Return a dry-run apply plan. Execution is intentionally unavailable in this slice.
	 *
	 * @param string $id      Suggestion ID.
	 * @param bool   $dry_run Whether this request is a dry run.
	 * @return array<string, mixed>
	 */
	public function apply_plan( string $id, bool $dry_run ): array {
		$suggestion = $this->find( $id );
		if ( array() === $suggestion ) {
			return $this->error( 'suggestion_not_found', 'No internal-link suggestion was found for that ID.' );
		}

		if ( 'approved' !== (string) ( $suggestion['status'] ?? '' ) ) {
			return $this->error( 'suggestion_not_approved', 'Only approved internal-link suggestions can be planned for apply.' );
		}

		if ( ! $dry_run ) {
			return array(
				'status'       => 'unavailable',
				'error'        => 'execute_apply_unavailable',
				'message'      => 'Executing internal-link suggestions is not enabled in this safe slice. Use dry_run=true for a reviewable plan.',
				'suggestion'   => $suggestion,
				'next_actions' => array(
					'Route execution through content_workflow_update_post only after block-safe insertion support is added.',
				),
			);
		}

		return array(
			'dry_run'      => true,
			'status'       => 'preview',
			'action'       => 'content_internal_link.suggestion_apply',
			'suggestion'   => $suggestion,
			'target'       => array(
				'type' => 'post',
				'id'   => (int) ( $suggestion['source_post']['id'] ?? 0 ),
			),
			'changes'      => array(
				array(
					'field' => 'internal_link',
					'from'  => 'not_applied',
					'to'    => array(
						'target_post_id' => (int) ( $suggestion['target_post']['id'] ?? 0 ),
						'anchor_text'    => (string) ( $suggestion['anchor_text'] ?? '' ),
						'status'         => 'planned',
					),
				),
			),
			'diff'         => array(
				'fields' => array(
					array(
						'field'   => 'post_content',
						'changed' => true,
						'from'    => 'existing block content',
						'to'      => 'existing block content with one approved semantic internal link inserted by content_workflow_update_post',
					),
				),
			),
			'warnings'     => array(
				'Preview only. This tool does not mutate content.',
				'Execution must use content_workflow_update_post after validating semantic blocks and preventing duplicate target links.',
			),
			'next_actions' => array(
				'Inspect source content with search/fetch or content.get_item.',
				'Prepare a block-safe update through content_workflow_update_post when execute support is implemented.',
			),
		);
	}

	/**
	 * Find one stored suggestion.
	 *
	 * @param string $id Suggestion ID.
	 * @return array<string, mixed>
	 */
	public function find( string $id ): array {
		$id = sanitize_key( $id );
		foreach ( $this->all() as $item ) {
			if ( (string) ( $item['id'] ?? '' ) === $id ) {
				return $item;
			}
		}

		return array();
	}

	/**
	 * Return all stored suggestions.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$items = array();
		foreach ( $stored as $item ) {
			if ( is_array( $item ) ) {
				$sanitized = $this->sanitize_stored_suggestion( $item );
				if ( array() !== $sanitized ) {
					$items[] = $sanitized;
				}
			}
		}

		return $items;
	}

	/**
	 * Persist bounded suggestions.
	 *
	 * @param list<array<string, mixed>> $items Suggestion rows.
	 */
	private function write( array $items ): void {
		$items = array_slice( array_values( $items ), -self::MAX_ITEMS );
		update_option( self::OPTION, $items, false );
	}

	/**
	 * Build candidate items from either one item or a batch.
	 *
	 * @param array<string, mixed> $args Raw args.
	 * @return list<array<string, mixed>>
	 */
	private function candidate_items( array $args ): array {
		if ( isset( $args['items'] ) && is_array( $args['items'] ) ) {
			return array_values(
				array_filter(
					array_slice( $args['items'], 0, self::MAX_BATCH_ITEMS ),
					static fn ( mixed $item ): bool => is_array( $item )
				)
			);
		}

		return array( $args );
	}

	/**
	 * Sanitize one new suggestion.
	 *
	 * @param array<string, mixed> $item Raw item.
	 * @return array<string, mixed>
	 */
	private function sanitize_suggestion( array $item ): array {
		$source_id = absint( $item['source_id'] ?? 0 );
		$target_id = absint( $item['target_id'] ?? $item['post_id'] ?? 0 );
		$anchor    = $this->text( $item['anchor_text'] ?? $item['proposed_anchor_text'] ?? '', 120 );
		$reason    = $this->text( $item['reason'] ?? '', 500 );

		if ( $source_id <= 0 || $target_id <= 0 || '' === $anchor || '' === $reason || $source_id === $target_id ) {
			return array();
		}

		$now = gmdate( 'Y-m-d\TH:i:s\Z' );

		return array(
			'id'           => $this->id( $source_id, $target_id, $anchor ),
			'source_post'  => $this->post_summary( $source_id, $item, 'source' ),
			'target_post'  => $this->post_summary( $target_id, $item, 'target' ),
			'anchor_text'  => $anchor,
			'reason'       => $reason,
			'score'        => max( 0, min( 100, absint( $item['score'] ?? $item['quality_score'] ?? 0 ) ) ),
			'confidence'   => $this->confidence( (string) ( $item['confidence'] ?? 'medium' ) ),
			'status'       => 'suggested',
			'last_checked' => $now,
			'created_at'   => $now,
			'updated_at'   => $now,
			'warnings'     => $this->string_list( $item['warnings'] ?? array(), 10 ),
			'signals'      => $this->scalar_map( $item['quality_signals'] ?? $item['signals'] ?? array(), 12 ),
		);
	}

	/**
	 * Sanitize one stored suggestion.
	 *
	 * @param array<string, mixed> $item Stored item.
	 * @return array<string, mixed>
	 */
	private function sanitize_stored_suggestion( array $item ): array {
		$source = is_array( $item['source_post'] ?? null ) ? $item['source_post'] : array();
		$target = is_array( $item['target_post'] ?? null ) ? $item['target_post'] : array();

		$source_id = absint( $source['id'] ?? 0 );
		$target_id = absint( $target['id'] ?? 0 );
		$anchor    = $this->text( $item['anchor_text'] ?? '', 120 );
		if ( $source_id <= 0 || $target_id <= 0 || '' === $anchor ) {
			return array();
		}

		return array(
			'id'           => sanitize_key( (string) ( $item['id'] ?? $this->id( $source_id, $target_id, $anchor ) ) ),
			'source_post'  => $this->stored_post_summary( $source ),
			'target_post'  => $this->stored_post_summary( $target ),
			'anchor_text'  => $anchor,
			'reason'       => $this->text( $item['reason'] ?? '', 500 ),
			'score'        => max( 0, min( 100, absint( $item['score'] ?? 0 ) ) ),
			'confidence'   => $this->confidence( (string) ( $item['confidence'] ?? 'medium' ) ),
			'status'       => $this->status( (string) ( $item['status'] ?? 'suggested' ), 'suggested' ),
			'last_checked' => $this->date( $item['last_checked'] ?? '' ),
			'created_at'   => $this->date( $item['created_at'] ?? '' ),
			'updated_at'   => $this->date( $item['updated_at'] ?? '' ),
			'reviewed_at'  => $this->date( $item['reviewed_at'] ?? '' ),
			'review_note'  => $this->text( $item['review_note'] ?? '', 500 ),
			'warnings'     => $this->string_list( $item['warnings'] ?? array(), 10 ),
			'signals'      => $this->scalar_map( $item['signals'] ?? array(), 12 ),
		);
	}

	/**
	 * Find duplicate active suggestion.
	 *
	 * @param list<array<string, mixed>> $stored     Stored suggestions.
	 * @param array<string, mixed>       $suggestion Candidate suggestion.
	 * @return array<string, mixed>|null
	 */
	private function find_duplicate( array $stored, array $suggestion ): ?array {
		foreach ( $stored as $item ) {
			if (
				(int) ( $item['source_post']['id'] ?? 0 ) === (int) ( $suggestion['source_post']['id'] ?? 0 )
				&& (int) ( $item['target_post']['id'] ?? 0 ) === (int) ( $suggestion['target_post']['id'] ?? 0 )
				&& $this->anchor_key( (string) ( $item['anchor_text'] ?? '' ) ) === $this->anchor_key( (string) ( $suggestion['anchor_text'] ?? '' ) )
				&& ! in_array( (string) ( $item['status'] ?? '' ), array( 'rejected', 'skipped', 'stale' ), true )
			) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Build a compact post summary.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $item    Raw item.
	 * @param string               $prefix  Field prefix.
	 * @return array<string, mixed>
	 */
	private function post_summary( int $post_id, array $item, string $prefix ): array {
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		$key  = $prefix . '_';

		return array(
			'id'        => $post_id,
			'title'     => $post instanceof \WP_Post ? (string) get_the_title( $post ) : $this->text( $item[ $key . 'title' ] ?? $item['title'] ?? '', 160 ),
			'type'      => $post instanceof \WP_Post ? (string) $post->post_type : sanitize_key( (string) ( $item[ $key . 'post_type' ] ?? $item['post_type'] ?? '' ) ),
			'status'    => $post instanceof \WP_Post ? (string) $post->post_status : sanitize_key( (string) ( $item[ $key . 'status' ] ?? $item['status'] ?? '' ) ),
			'permalink' => $post instanceof \WP_Post && function_exists( 'get_permalink' ) ? esc_url_raw( (string) get_permalink( $post ) ) : esc_url_raw( (string) ( $item[ $key . 'permalink' ] ?? $item['permalink'] ?? '' ) ),
		);
	}

	/**
	 * Sanitize a stored post summary.
	 *
	 * @param array<string, mixed> $summary Raw summary.
	 * @return array<string, mixed>
	 */
	private function stored_post_summary( array $summary ): array {
		return array(
			'id'        => absint( $summary['id'] ?? 0 ),
			'title'     => $this->text( $summary['title'] ?? '', 160 ),
			'type'      => sanitize_key( (string) ( $summary['type'] ?? '' ) ),
			'status'    => sanitize_key( (string) ( $summary['status'] ?? '' ) ),
			'permalink' => esc_url_raw( (string) ( $summary['permalink'] ?? '' ) ),
		);
	}

	/**
	 * Build stable suggestion ID.
	 *
	 * @param int    $source_id Source post ID.
	 * @param int    $target_id Target post ID.
	 * @param string $anchor    Anchor text.
	 */
	private function id( int $source_id, int $target_id, string $anchor ): string {
		return 'ils_' . substr( hash( 'sha256', $source_id . ':' . $target_id . ':' . $this->anchor_key( $anchor ) ), 0, 20 );
	}

	/**
	 * Normalize status.
	 *
	 * @param string $status  Raw status.
	 * @param string $default Default status.
	 */
	private function status( string $status, string $default ): string {
		$status = sanitize_key( $status );
		return in_array( $status, self::STATUSES, true ) ? $status : $default;
	}

	/**
	 * Normalize confidence.
	 *
	 * @param string $confidence Raw confidence.
	 */
	private function confidence( string $confidence ): string {
		$confidence = sanitize_key( $confidence );
		return in_array( $confidence, array( 'low', 'medium', 'high' ), true ) ? $confidence : 'medium';
	}

	/**
	 * Normalize text with a byte limit.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Max length.
	 */
	private function text( mixed $value, int $limit ): string {
		return sanitize_text_field( substr( (string) ( is_scalar( $value ) ? $value : '' ), 0, $limit ) );
	}

	/**
	 * Normalize date-ish string.
	 *
	 * @param mixed $value Raw value.
	 */
	private function date( mixed $value ): string {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return '' === $value ? gmdate( 'Y-m-d\TH:i:s\Z' ) : substr( $value, 0, 30 );
	}

	/**
	 * Normalize strings.
	 *
	 * @param mixed $values Raw list.
	 * @param int   $limit  Max items.
	 * @return list<string>
	 */
	private function string_list( mixed $values, int $limit ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map(
					fn ( mixed $value ): string => $this->text( $value, 120 ),
					array_slice( $values, 0, $limit )
				)
			)
		);
	}

	/**
	 * Keep only scalar signal metadata.
	 *
	 * @param mixed $values Raw map.
	 * @param int   $limit  Max keys.
	 * @return array<string, mixed>
	 */
	private function scalar_map( mixed $values, int $limit ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$map = array();
		foreach ( array_slice( $values, 0, $limit, true ) as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}
			$map[ $key ] = is_numeric( $value ) ? (int) $value : $this->text( $value, 160 );
		}

		return $map;
	}

	/**
	 * Normalize anchor key.
	 *
	 * @param string $anchor Anchor text.
	 */
	private function anchor_key( string $anchor ): string {
		return trim( strtolower( preg_replace( '/\s+/', ' ', sanitize_text_field( $anchor ) ) ?? '' ) );
	}

	/**
	 * Return an error payload.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return array<string, mixed>
	 */
	private function error( string $code, string $message ): array {
		return array(
			'status'  => 'error',
			'error'   => $code,
			'message' => $message,
		);
	}
}
