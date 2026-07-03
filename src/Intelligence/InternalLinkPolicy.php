<?php
/**
 * Internal-linking guardrails for Aculect Intelligence.
 *
 * @package Aculect\AICompanion\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence;

/**
 * Provides bounded defaults and sanitization for internal-link workflows.
 */
final class InternalLinkPolicy {

	public const OPTION = 'aculect_ai_companion_internal_link_policy';

	private const MAX_EXCLUDED_POST_IDS     = 200;
	private const MAX_EXCLUDED_URL_PATTERNS = 50;
	private const MAX_TAXONOMIES            = 10;

	/**
	 * Return the active policy.
	 *
	 * @return array<string, mixed>
	 */
	public function active(): array {
		$stored = get_option( self::OPTION, array() );

		return $this->sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Sanitize a raw policy payload.
	 *
	 * @param array<string, mixed> $policy Raw policy.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $policy ): array {
		$defaults = $this->defaults();

		$included_post_types = $this->sanitize_keys( $policy['included_post_types'] ?? $defaults['included_post_types'], 20 );
		$included_statuses   = $this->sanitize_keys( $policy['included_statuses'] ?? $defaults['included_statuses'], 20 );
		$excluded_post_ids   = $this->sanitize_ids( $policy['excluded_post_ids'] ?? array(), self::MAX_EXCLUDED_POST_IDS );

		$excluded_url_patterns = $this->sanitize_patterns(
			$policy['excluded_url_patterns'] ?? $defaults['excluded_url_patterns'],
			self::MAX_EXCLUDED_URL_PATTERNS
		);

		$taxonomy = is_array( $policy['taxonomy_relationship_preference'] ?? null )
			? $policy['taxonomy_relationship_preference']
			: $defaults['taxonomy_relationship_preference'];
		$limits   = is_array( $policy['limits'] ?? null ) ? $policy['limits'] : array();
		$anchors  = is_array( $policy['anchor_governance'] ?? null ) ? $policy['anchor_governance'] : array();
		$blocks   = is_array( $policy['excluded_blocks'] ?? null ) ? $policy['excluded_blocks'] : $defaults['excluded_blocks'];
		$areas    = is_array( $policy['excluded_areas'] ?? null ) ? $policy['excluded_areas'] : $defaults['excluded_areas'];

		return array(
			'included_post_types'              => array() === $included_post_types ? $defaults['included_post_types'] : $included_post_types,
			'included_statuses'                => array() === $included_statuses ? $defaults['included_statuses'] : $included_statuses,
			'excluded_post_ids'                => $excluded_post_ids,
			'excluded_url_patterns'            => $excluded_url_patterns,
			'taxonomy_relationship_preference' => array(
				'enabled'    => $this->bool_value( $taxonomy['enabled'] ?? true ),
				'taxonomies' => $this->sanitize_keys( $taxonomy['taxonomies'] ?? array( 'category', 'post_tag' ), self::MAX_TAXONOMIES ),
				'mode'       => $this->enum( $taxonomy['mode'] ?? 'prefer_shared_terms', array( 'prefer_shared_terms', 'require_shared_terms', 'ignore' ), 'prefer_shared_terms' ),
			),
			'limits'                           => array(
				'max_suggestions_per_source' => $this->bounded_int( $limits['max_suggestions_per_source'] ?? 10, 1, 20 ),
				'max_new_links_per_source'   => $this->bounded_int( $limits['max_new_links_per_source'] ?? 3, 1, 20 ),
				'max_repeated_target_links'  => $this->bounded_int( $limits['max_repeated_target_links'] ?? 1, 1, 10 ),
			),
			'prevent_self_links'               => $this->bool_value( $policy['prevent_self_links'] ?? true ),
			'anchor_governance'                => array(
				'avoid_duplicate_anchors'              => $this->bool_value( $anchors['avoid_duplicate_anchors'] ?? true ),
				'avoid_repeated_exact_match_anchors'   => $this->bool_value( $anchors['avoid_repeated_exact_match_anchors'] ?? true ),
				'avoid_boilerplate_placement'          => $this->bool_value( $anchors['avoid_boilerplate_placement'] ?? true ),
				'avoid_excluded_blocks_where_detected' => $this->bool_value( $anchors['avoid_excluded_blocks_where_detected'] ?? true ),
			),
			'excluded_blocks'                  => $this->sanitize_block_names( $blocks ),
			'excluded_areas'                   => $this->sanitize_keys( $areas, 20 ),
			'mutation_policy'                  => array(
				'automatic_site_wide_linking' => false,
				'content_mutation'            => false,
				'requires_user_review'        => true,
			),
		);
	}

	/**
	 * Return plug-and-play defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'included_post_types'              => array( 'post', 'page' ),
			'included_statuses'                => array( 'publish' ),
			'excluded_post_ids'                => array(),
			'excluded_url_patterns'            => array(),
			'taxonomy_relationship_preference' => array(
				'enabled'    => true,
				'taxonomies' => array( 'category', 'post_tag' ),
				'mode'       => 'prefer_shared_terms',
			),
			'limits'                           => array(
				'max_suggestions_per_source' => 10,
				'max_new_links_per_source'   => 3,
				'max_repeated_target_links'  => 1,
			),
			'prevent_self_links'               => true,
			'anchor_governance'                => array(
				'avoid_duplicate_anchors'              => true,
				'avoid_repeated_exact_match_anchors'   => true,
				'avoid_boilerplate_placement'          => true,
				'avoid_excluded_blocks_where_detected' => true,
			),
			'excluded_blocks'                  => array( 'core/html', 'core/navigation', 'core/query', 'core/post-template', 'core/latest-posts' ),
			'excluded_areas'                   => array( 'header', 'footer', 'sidebar', 'navigation', 'comment' ),
		);
	}

	/**
	 * Filter candidate rows against the active policy.
	 *
	 * @param int                       $source_id Source post ID.
	 * @param array<string,mixed>       $source    Source row.
	 * @param list<array<string,mixed>> $items Candidate rows.
	 * @return list<array<string,mixed>>
	 */
	public function filter_candidates( int $source_id, array $source, array $items ): array {
		$policy       = $this->active();
		$seen_targets = array();
		$seen_anchors = array();
		$filtered     = array();

		foreach ( $items as $item ) {
			$id = absint( $item['id'] ?? $item['post_id'] ?? 0 );
			if ( ! $this->allows_item( $item, $policy ) ) {
				continue;
			}
			if ( ! empty( $policy['prevent_self_links'] ) && $id > 0 && $id === $source_id ) {
				continue;
			}

			$target_count = $seen_targets[ $id ] ?? 0;
			if ( $id > 0 && $target_count >= (int) $policy['limits']['max_repeated_target_links'] ) {
				continue;
			}

			$anchor = $this->anchor_key( (string) ( $item['anchor_text'] ?? $item['title'] ?? '' ) );
			if ( '' !== $anchor && ! empty( $policy['anchor_governance']['avoid_duplicate_anchors'] ) && isset( $seen_anchors[ $anchor ] ) ) {
				continue;
			}

			if ( $id > 0 ) {
				$seen_targets[ $id ] = $target_count + 1;
			}
			if ( '' !== $anchor ) {
				$seen_anchors[ $anchor ] = true;
			}

			$filtered[] = $item;
			if ( count( $filtered ) >= (int) $policy['limits']['max_suggestions_per_source'] ) {
				break;
			}
		}

		unset( $source );

		return $filtered;
	}

	/**
	 * Check whether a public content row is allowed by the policy.
	 *
	 * @param array<string,mixed> $item   Public content row.
	 * @param array<string,mixed> $policy Active policy.
	 */
	public function allows_item( array $item, array $policy ): bool {
		$id = absint( $item['id'] ?? $item['post_id'] ?? 0 );
		if ( $id > 0 && in_array( $id, (array) $policy['excluded_post_ids'], true ) ) {
			return false;
		}

		$type = sanitize_key( (string) ( $item['post_type'] ?? $item['type'] ?? '' ) );
		if ( '' !== $type && ! in_array( $type, (array) $policy['included_post_types'], true ) ) {
			return false;
		}

		$status = sanitize_key( (string) ( $item['post_status'] ?? $item['status'] ?? '' ) );
		if ( '' !== $status && ! in_array( $status, (array) $policy['included_statuses'], true ) ) {
			return false;
		}

		$url = (string) ( $item['permalink'] ?? $item['url'] ?? '' );
		return ! $this->url_matches_exclusion( $url, (array) $policy['excluded_url_patterns'] );
	}

	/**
	 * Build assistant-facing guidance for this policy.
	 *
	 * @return array<string, mixed>
	 */
	public function guidance(): array {
		return array(
			'read_only'       => 'Internal-link tools only expose policy, audit rows, and candidate suggestions; they do not mutate content.',
			'before_applying' => 'Review the active policy, source content, and candidate anchors before using any content update tool.',
			'limits'          => $this->active()['limits'],
			'placement'       => 'Place approved links in semantic body content, not excluded blocks, navigation, footer, sidebar, comments, or boilerplate areas.',
		);
	}

	/**
	 * Check URL patterns using simple wildcard matching.
	 *
	 * @param string $url      Candidate URL.
	 * @param array  $patterns Sanitized wildcard patterns.
	 * @phpstan-param list<string> $patterns
	 */
	private function url_matches_exclusion( string $url, array $patterns ): bool {
		if ( '' === $url || array() === $patterns ) {
			return false;
		}

		foreach ( $patterns as $pattern ) {
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
			if ( 1 === preg_match( $regex, $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a mixed value to a boolean.
	 *
	 * @param mixed $value Raw value.
	 */
	private function bool_value( mixed $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
	}

	/**
	 * Return an allowed enum value.
	 *
	 * @param mixed  $value   Raw value.
	 * @param array  $allowed Allowed values.
	 * @param string $default Default value.
	 * @phpstan-param list<string> $allowed
	 */
	private function enum( mixed $value, array $allowed, string $default ): string {
		$value = sanitize_key( (string) $value );
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Clamp an integer into a safe range.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Minimum allowed value.
	 * @param int   $max   Maximum allowed value.
	 */
	private function bounded_int( mixed $value, int $min, int $max ): int {
		return max( $min, min( $max, absint( $value ) ) );
	}

	/**
	 * Sanitize bounded post IDs.
	 *
	 * @param mixed $values Raw values.
	 * @param int   $max    Maximum values to retain.
	 * @return list<int>
	 */
	private function sanitize_ids( mixed $values, int $max ): array {
		$values = is_array( $values ) ? $values : array( $values );
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) );

		return array_slice( $ids, 0, $max );
	}

	/**
	 * Sanitize bounded WordPress keys.
	 *
	 * @param mixed $values Raw values.
	 * @param int   $max    Maximum values to retain.
	 * @return list<string>
	 */
	private function sanitize_keys( mixed $values, int $max ): array {
		$values = is_array( $values ) ? $values : array( $values );
		$keys   = array();
		foreach ( $values as $value ) {
			$key = sanitize_key( (string) $value );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}

		return array_slice( array_values( array_unique( $keys ) ), 0, $max );
	}

	/**
	 * Sanitize bounded URL exclusion patterns.
	 *
	 * @param mixed $values Raw values.
	 * @param int   $max    Maximum values to retain.
	 * @return list<string>
	 */
	private function sanitize_patterns( mixed $values, int $max ): array {
		$values   = is_array( $values ) ? $values : array( $values );
		$patterns = array();
		foreach ( $values as $value ) {
			$pattern = trim( sanitize_text_field( (string) $value ) );
			if ( '' !== $pattern ) {
				$patterns[] = substr( $pattern, 0, 200 );
			}
		}

		return array_slice( array_values( array_unique( $patterns ) ), 0, $max );
	}

	/**
	 * Sanitize bounded block names.
	 *
	 * @param mixed $values Raw values.
	 * @return list<string>
	 */
	private function sanitize_block_names( mixed $values ): array {
		$values = is_array( $values ) ? $values : array( $values );
		$blocks = array();
		foreach ( $values as $value ) {
			$block = strtolower( trim( (string) $value ) );
			if ( 1 === preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $block ) ) {
				$blocks[] = $block;
			}
		}

		return array_slice( array_values( array_unique( $blocks ) ), 0, 30 );
	}

	/**
	 * Normalize an anchor for duplicate checks.
	 *
	 * @param string $anchor Anchor text.
	 */
	private function anchor_key( string $anchor ): string {
		return strtolower( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $anchor ) ) ?? '' ) );
	}
}
