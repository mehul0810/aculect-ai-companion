<?php
/**
 * Review queue for MCP intelligence learning suggestions.
 *
 * @package Aculect\AICompanion\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence;

defined( 'ABSPATH' ) || exit;

/**
 * Stores bounded, admin-reviewed suggestions from connected MCP clients.
 */
final class LearningSuggestionRepository {

	private const OPTION          = 'aculect_ai_companion_learning_suggestions';
	private const MAX_SUGGESTIONS = 100;

	private const DOMAINS = array(
		'brand',
		'site',
		'content',
		'developer',
	);

	private const CONFIDENCE_LEVELS = array(
		'low',
		'medium',
		'high',
	);

	private const STATUSES = array(
		'pending',
		'approved',
		'dismissed',
	);

	/**
	 * Queue a sanitized learning suggestion for admin review.
	 *
	 * @param array<string, mixed> $data   Suggestion data from an MCP tool call.
	 * @param array<string, mixed> $source Authenticated MCP connection context.
	 * @return array<string, mixed>
	 */
	public function submit( array $data, array $source = array() ): array {
		$domain           = $this->sanitize_enum( $data['domain'] ?? '', self::DOMAINS, 'content' );
		$issue            = $this->sanitize_text( $data['issue'] ?? '', 500 );
		$evidence         = $this->sanitize_text( $data['evidence'] ?? '', 1200 );
		$suggested_update = $this->sanitize_text( $data['suggested_update'] ?? '', 1500 );
		$confidence       = $this->sanitize_enum( $data['confidence'] ?? '', self::CONFIDENCE_LEVELS, 'medium' );

		if ( '' === $issue || '' === $suggested_update ) {
			return array(
				'status'  => 'rejected',
				'error'   => 'issue_and_suggested_update_required',
				'message' => 'Learning suggestions require both an issue and a suggested update.',
			);
		}

		$now        = gmdate( 'Y-m-d\TH:i:s\Z' );
		$suggestion = array(
			'id'               => $this->generate_id(),
			'domain'           => $domain,
			'issue'            => $issue,
			'evidence'         => $evidence,
			'suggested_update' => $suggested_update,
			'confidence'       => $confidence,
			'status'           => 'pending',
			'created_at'       => $now,
			'updated_at'       => $now,
			'review_note'      => '',
			'source'           => $this->sanitize_source( $source ),
		);

		$items   = $this->all();
		$items[] = $suggestion;
		$this->save( $items );

		return array(
			'status'        => 'queued',
			'message'       => 'Learning suggestion queued for admin review. No site, content, developer, or brand memory was changed.',
			'suggestion'    => $suggestion,
			'review_status' => array(
				'admin_review_required' => true,
				'updates_memory'        => false,
			),
		);
	}

	/**
	 * Return learning suggestions for the admin app.
	 *
	 * @return array<string, mixed>
	 */
	public function admin_payload(): array {
		$items = $this->all();
		usort(
			$items,
			static fn( array $a, array $b ): int => strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) )
		);

		return array(
			'items'   => $items,
			'summary' => $this->summary( $items ),
		);
	}

	/**
	 * Return an empty admin payload shape.
	 *
	 * @return array<string, mixed>
	 */
	public static function empty_payload(): array {
		return array(
			'items'   => array(),
			'summary' => array(
				'total'     => 0,
				'pending'   => 0,
				'approved'  => 0,
				'dismissed' => 0,
			),
		);
	}

	/**
	 * Approve or dismiss a queued suggestion.
	 *
	 * @param string $id          Suggestion ID.
	 * @param string $action      Review action: approve or dismiss.
	 * @param string $review_note Optional admin note.
	 */
	public function review( string $id, string $action, string $review_note = '' ): bool {
		$id     = $this->sanitize_text( $id, 80 );
		$action = $this->sanitize_enum( $action, array( 'approve', 'dismiss' ), '' );
		if ( '' === $id || '' === $action ) {
			return false;
		}

		$items   = $this->all();
		$updated = false;
		$status  = 'approve' === $action ? 'approved' : 'dismissed';

		foreach ( $items as &$item ) {
			if ( ! hash_equals( (string) ( $item['id'] ?? '' ), $id ) ) {
				continue;
			}

			$item['status']      = $status;
			$item['review_note'] = $this->sanitize_text( $review_note, 800 );
			$item['updated_at']  = gmdate( 'Y-m-d\TH:i:s\Z' );
			$updated             = true;
			break;
		}
		unset( $item );

		if ( $updated ) {
			$this->save( $items );
		}

		return $updated;
	}

	/**
	 * Update suggestion content before admin review.
	 *
	 * @param string               $id          Suggestion ID.
	 * @param array<string, mixed> $data        Updated suggestion fields.
	 * @param string               $review_note Optional admin note.
	 */
	public function update( string $id, array $data, string $review_note = '' ): bool {
		$id               = $this->sanitize_text( $id, 80 );
		$domain           = $this->sanitize_enum( $data['domain'] ?? '', self::DOMAINS, 'content' );
		$issue            = $this->sanitize_text( $data['issue'] ?? '', 500 );
		$evidence         = $this->sanitize_text( $data['evidence'] ?? '', 1200 );
		$suggested_update = $this->sanitize_text( $data['suggested_update'] ?? '', 1500 );
		$confidence       = $this->sanitize_enum( $data['confidence'] ?? '', self::CONFIDENCE_LEVELS, 'medium' );
		if ( '' === $id || '' === $issue || '' === $suggested_update ) {
			return false;
		}

		$items   = $this->all();
		$updated = false;

		foreach ( $items as &$item ) {
			if ( ! hash_equals( (string) ( $item['id'] ?? '' ), $id ) ) {
				continue;
			}

			$item['domain']           = $domain;
			$item['issue']            = $issue;
			$item['evidence']         = $evidence;
			$item['suggested_update'] = $suggested_update;
			$item['confidence']       = $confidence;
			$item['review_note']      = $this->sanitize_text( $review_note, 800 );
			$item['updated_at']       = gmdate( 'Y-m-d\TH:i:s\Z' );
			$updated                  = true;
			break;
		}
		unset( $item );

		if ( $updated ) {
			$this->save( $items );
		}

		return $updated;
	}

	/**
	 * Delete stored suggestions during full plugin cleanup.
	 */
	public static function delete(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Return stored suggestions.
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
				$normalized = $this->normalize_stored_item( $item );
				if ( array() !== $normalized ) {
					$items[] = $normalized;
				}
			}
		}

		return $items;
	}

	/**
	 * Persist a bounded suggestion list.
	 *
	 * @param list<array<string, mixed>> $items Stored suggestions.
	 */
	private function save( array $items ): void {
		$items = array_values( array_slice( $items, -self::MAX_SUGGESTIONS ) );
		update_option( self::OPTION, $items, false );
	}

	/**
	 * Normalize a stored item before returning it to callers.
	 *
	 * @param array<string, mixed> $item Stored item.
	 * @return array<string, mixed>
	 */
	private function normalize_stored_item( array $item ): array {
		$id = $this->sanitize_text( $item['id'] ?? '', 80 );
		if ( '' === $id ) {
			return array();
		}

		return array(
			'id'               => $id,
			'domain'           => $this->sanitize_enum( $item['domain'] ?? '', self::DOMAINS, 'content' ),
			'issue'            => $this->sanitize_text( $item['issue'] ?? '', 500 ),
			'evidence'         => $this->sanitize_text( $item['evidence'] ?? '', 1200 ),
			'suggested_update' => $this->sanitize_text( $item['suggested_update'] ?? '', 1500 ),
			'confidence'       => $this->sanitize_enum( $item['confidence'] ?? '', self::CONFIDENCE_LEVELS, 'medium' ),
			'status'           => $this->sanitize_enum( $item['status'] ?? '', self::STATUSES, 'pending' ),
			'created_at'       => $this->sanitize_text( $item['created_at'] ?? '', 40 ),
			'updated_at'       => $this->sanitize_text( $item['updated_at'] ?? '', 40 ),
			'review_note'      => $this->sanitize_text( $item['review_note'] ?? '', 800 ),
			'source'           => $this->sanitize_source( is_array( $item['source'] ?? null ) ? (array) $item['source'] : array() ),
		);
	}

	/**
	 * Build status summary counts.
	 *
	 * @param list<array<string, mixed>> $items Stored suggestions.
	 * @return array<string, int>
	 */
	private function summary( array $items ): array {
		$summary = array(
			'total'     => count( $items ),
			'pending'   => 0,
			'approved'  => 0,
			'dismissed' => 0,
		);

		foreach ( $items as $item ) {
			$status = (string) ( $item['status'] ?? 'pending' );
			if ( array_key_exists( $status, $summary ) ) {
				++$summary[ $status ];
			}
		}

		return $summary;
	}

	/**
	 * Sanitize MCP source metadata without storing secrets or raw arguments.
	 *
	 * @param array<string, mixed> $source Source metadata.
	 * @return array<string, mixed>
	 */
	private function sanitize_source( array $source ): array {
		return array(
			'provider'    => $this->sanitize_enum( $source['provider'] ?? '', array( 'chatgpt', 'claude', 'codex', 'mcp' ), 'mcp' ),
			'client_id'   => $this->sanitize_text( $source['client_id'] ?? '', 100 ),
			'client_name' => $this->sanitize_text( $source['client_name'] ?? '', 160 ),
			'user_id'     => absint( $source['user_id'] ?? 0 ),
		);
	}

	/**
	 * Sanitize a scalar text value and bound its length.
	 *
	 * @param mixed $value      Raw value.
	 * @param int   $max_length Maximum returned length.
	 */
	private function sanitize_text( mixed $value, int $max_length ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = (string) $value;
		$text = function_exists( 'sanitize_textarea_field' )
			? sanitize_textarea_field( $text )
			: sanitize_text_field( $text );
		$text = trim( $text );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max_length ) : substr( $text, 0, $max_length );
	}

	/**
	 * Sanitize a value against an allowlist.
	 *
	 * @param mixed    $value   Raw value.
	 * @param string[] $allowed Allowed values.
	 * @param string   $default Default value.
	 */
	private function sanitize_enum( mixed $value, array $allowed, string $default ): string {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Generate an opaque suggestion ID.
	 */
	private function generate_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'learn_' . str_replace( '-', '', (string) wp_generate_uuid4() );
		}

		try {
			return 'learn_' . bin2hex( random_bytes( 16 ) );
		} catch ( \Exception ) {
			return 'learn_' . uniqid( '', true );
		}
	}
}
