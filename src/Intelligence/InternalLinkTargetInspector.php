<?php
/**
 * Classifies indexed internal-link targets.
 *
 * @package Aculect\AICompanion\Intelligence
 */

declare(strict_types=1);

namespace Aculect\AICompanion\Intelligence;

/**
 * Resolves local link targets using WordPress and optional Rank Math redirect data.
 */
final class InternalLinkTargetInspector {

	/**
	 * Inspect one indexed outbound link row.
	 *
	 * @param array<string, mixed> $link Indexed link row.
	 * @return array<string, mixed>
	 */
	public function inspect( array $link ): array {
		$target_url = esc_url_raw( (string) ( $link['target_url'] ?? '' ) );
		$target_id  = absint( $link['target_id'] ?? 0 );
		if ( '' === $target_url ) {
			return $this->result( 'missing_target', 'Review or remove this internal link because the indexed target URL is empty.', array( 'reason' => 'empty_target_url' ) );
		}

		$redirect = $this->rank_math_redirect( $target_url );
		if ( array() !== $redirect ) {
			return $this->result(
				'redirected',
				'Update the source link to the redirect destination after confirming it is still the intended target.',
				array(
					'redirect_source'      => $redirect['source'],
					'redirect_destination' => $redirect['destination'],
					'redirect_code'        => $redirect['redirect_code'],
					'redirect_id'          => $redirect['id'],
				)
			);
		}

		$resolved_id = $this->resolved_post_id( $target_url );
		if ( $target_id <= 0 ) {
			$target_id = $resolved_id;
		}

		if ( $target_id <= 0 ) {
			return $this->result(
				'missing_target',
				'Remove this link or recreate the missing destination before adding more internal links.',
				array(
					'resolved_post_id' => 0,
					'checked_url'      => $target_url,
				)
			);
		}

		$post = function_exists( 'get_post' ) ? get_post( $target_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return $this->result(
				'missing_target',
				'Remove this link or recreate the missing destination before adding more internal links.',
				array(
					'target_id' => $target_id,
					'reason'    => 'post_not_found',
				)
			);
		}

		if ( ! $this->can_read_post( $target_id ) ) {
			return $this->result(
				'unreadable_target',
				'Check whether this link should point to content the current user can read, or replace it with a public target.',
				array(
					'target_id'     => $target_id,
					'target_status' => (string) $post->post_status,
				)
			);
		}

		if ( ! in_array( (string) $post->post_status, array( 'publish' ), true ) ) {
			return $this->result(
				'unpublished_target',
				'Publish the target, choose a readable published replacement, or remove the internal link.',
				array(
					'target_id'     => $target_id,
					'target_status' => (string) $post->post_status,
				)
			);
		}

		$current_permalink = $this->current_permalink( $post );
		if ( '' !== $current_permalink && ! $this->same_local_path( $target_url, $current_permalink ) ) {
			return $this->result(
				'stale_permalink',
				'Update the source link to the current permalink for this target.',
				array(
					'target_id'         => $target_id,
					'indexed_url'       => $target_url,
					'current_permalink' => $current_permalink,
				)
			);
		}

		return $this->result(
			'ok',
			'No broken-target action is needed for this indexed link.',
			array(
				'target_id'         => $target_id,
				'current_permalink' => $current_permalink,
			)
		);
	}

	/**
	 * Return whether an inspected state should be reported in broken-link audit mode.
	 *
	 * @param string $state Link target state.
	 */
	public function is_reportable_state( string $state ): bool {
		return in_array( $state, array( 'missing_target', 'unreadable_target', 'unpublished_target', 'stale_permalink', 'redirected' ), true );
	}

	/**
	 * Build one inspection result.
	 *
	 * @param string               $state                 State key.
	 * @param string               $suggested_next_action Recommended action.
	 * @param array<string, mixed> $evidence              Bounded evidence.
	 * @return array<string, mixed>
	 */
	private function result( string $state, string $suggested_next_action, array $evidence ): array {
		return array(
			'state'                 => $state,
			'suggested_next_action' => $suggested_next_action,
			'evidence'              => $this->bounded_evidence( $evidence ),
		);
	}

	/**
	 * Bound evidence to scalar values safe for MCP responses.
	 *
	 * @param array<string, mixed> $evidence Raw evidence.
	 * @return array<string, mixed>
	 */
	private function bounded_evidence( array $evidence ): array {
		$bounded = array();
		foreach ( $evidence as $key => $value ) {
			if ( is_bool( $value ) || is_int( $value ) ) {
				$bounded[ sanitize_key( (string) $key ) ] = $value;
				continue;
			}

			if ( is_scalar( $value ) ) {
				$bounded[ sanitize_key( (string) $key ) ] = sanitize_text_field( substr( (string) $value, 0, 300 ) );
			}
		}

		return $bounded;
	}

	/**
	 * Resolve a URL to a local post ID when WordPress can do so.
	 *
	 * @param string $url Target URL.
	 */
	private function resolved_post_id( string $url ): int {
		return function_exists( 'url_to_postid' ) ? absint( url_to_postid( $url ) ) : 0;
	}

	/**
	 * Return the current permalink for a post.
	 *
	 * @param \WP_Post $post Target post.
	 */
	private function current_permalink( \WP_Post $post ): string {
		return function_exists( 'get_permalink' ) ? esc_url_raw( (string) get_permalink( $post ) ) : '';
	}

	/**
	 * Return whether the current user can read a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function can_read_post( int $post_id ): bool {
		return ! function_exists( 'current_user_can' ) || current_user_can( 'read_post', $post_id );
	}

	/**
	 * Compare local URL paths and ignore query strings/fragments.
	 *
	 * @param string $first  First URL.
	 * @param string $second Second URL.
	 */
	private function same_local_path( string $first, string $second ): bool {
		$first_path  = $this->normalized_path( $first );
		$second_path = $this->normalized_path( $second );
		if ( '' === $first_path && '' === $second_path ) {
			return $this->normalized_url( $first ) === $this->normalized_url( $second );
		}

		return '' !== $first_path && $first_path === $second_path;
	}

	/**
	 * Normalize URL path for local permalink comparisons.
	 *
	 * @param string $url URL or path.
	 */
	private function normalized_path( string $url ): string {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			$path = str_starts_with( $url, '/' ) ? $url : '';
		}

		return trim( strtolower( $path ), '/' );
	}

	/**
	 * Normalize URL for plain permalink comparisons where the path is empty.
	 *
	 * @param string $url URL or path.
	 */
	private function normalized_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return strtolower( trim( $url ) );
		}

		return strtolower(
			implode(
				'',
				array_filter(
					array(
						$parts['host'] ?? '',
						isset( $parts['query'] ) ? '?' . $parts['query'] : '',
					)
				)
			)
		);
	}

	/**
	 * Return a matching Rank Math redirect for a target URL when available.
	 *
	 * @param string $target_url Target URL.
	 * @return array<string, mixed>
	 */
	private function rank_math_redirect( string $target_url ): array {
		if ( ! is_callable( array( '\RankMath\Redirections\DB', 'match_redirections_source' ) ) ) {
			return array();
		}

		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			return array();
		}

		$source = $this->normalized_path( $target_url );
		if ( '' === $source ) {
			return array();
		}

		$callback = array( '\RankMath\Redirections\DB', 'match_redirections_source' );
		if ( ! is_callable( $callback ) ) {
			return array();
		}

		$matches = $callback( $source );
		if ( ! is_array( $matches ) ) {
			return array();
		}

		foreach ( $matches as $match ) {
			if ( ! is_array( $match ) ) {
				continue;
			}

			$destination = esc_url_raw( (string) ( $match['url_to'] ?? '' ) );
			if ( '' === $destination ) {
				continue;
			}

			return array(
				'id'            => absint( $match['id'] ?? 0 ),
				'source'        => $source,
				'destination'   => $destination,
				'redirect_code' => absint( $match['header_code'] ?? 0 ),
			);
		}

		return array();
	}
}
